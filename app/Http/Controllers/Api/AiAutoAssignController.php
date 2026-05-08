<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectTeamAssignmentResource;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectTeamAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiAutoAssignController extends Controller
{
    public function autoAssign(Request $request, Project $project)
    {
        $tenantId = app('tenant_id');

        $project->load(['contract.deal', 'teamAssignments.employee']);

        $deal = $project->contract?->deal;

        $employees = Employee::with('skills')
            ->where('tenant_id', $tenantId)
            ->where('status', 'Active')
            ->get();

        $requiredSkills = $deal?->workload_description
            ? $this->extractSkillsFromDescription($deal->workload_description)
            : [];

        $employeesWithSkills = $employees->map(fn ($emp) => [
            'id' => $emp->id,
            'name' => $emp->name,
            'capacity_role' => $emp->capacityRole?->name ?? $emp->capacity_role ?? 'unknown',
            'workable_hours' => $emp->workable_hours,
            'monthly_salary' => $emp->monthly_salary,
            'cost_per_hour' => $emp->cost_per_hour,
            'skills' => $emp->skills->map(fn ($s) => [
                'name' => $s->name,
                'category' => $s->category,
                'proficiency' => $s->pivot->proficiency,
            ])->toArray(),
        ])->toArray();

        $apiKey = config('services.anthropic.api_key') ?? env('ANTHROPIC_API_KEY');

        if (! $apiKey) {
            return response()->json(['error' => 'AI service not configured'], 500);
        }

        try {
            $prompt = $this->buildAutoAssignPrompt($project, $deal, $employeesWithSkills, $requiredSkills);

            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-3-5-sonnet-latest',
                'max_tokens' => 2048,
                'system' => 'You are an HR staffing assistant. Return ONLY a JSON array of employee IDs with allocated hours. No markdown, no explanation.',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            $body = $response->json();
            $text = $body['content'][0]['text'] ?? '';

            $text = trim($text);
            if (str_starts_with($text, '```')) {
                $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
                $text = preg_replace('/\s*```$/', '', $text);
            }

            $assignments = json_decode($text, true);

            if (! is_array($assignments)) {
                Log::error('AI AutoAssign: invalid JSON response', ['text' => substr($text, 0, 300)]);

                return response()->json(['error' => 'AI returned invalid response'], 500);
            }

            DB::transaction(function () use ($assignments, $project, $tenantId) {
                ProjectTeamAssignment::where('project_id', $project->id)->delete();

                foreach ($assignments as $item) {
                    if (empty($item['employee_id']) || empty($item['allocated_hours'])) {
                        continue;
                    }

                    ProjectTeamAssignment::create([
                        'tenant_id' => $tenantId,
                        'project_id' => $project->id,
                        'employee_id' => $item['employee_id'],
                        'allocated_hours' => $item['allocated_hours'],
                        'assignment_source' => 'ai',
                    ]);
                }
            });

            $project->load('teamAssignments.employee');

            return ProjectTeamAssignmentResource::collection($project->teamAssignments);

        } catch (\Exception $e) {
            Log::error('AI AutoAssign error', ['message' => $e->getMessage()]);

            return response()->json(['error' => 'Failed to auto-assign team: '.$e->getMessage()], 500);
        }
    }

    public function index(Project $project)
    {
        $project->load('teamAssignments.employee');

        return ProjectTeamAssignmentResource::collection($project->teamAssignments);
    }

    public function store(Request $request, Project $project)
    {
        $tenantId = app('tenant_id');

        $request->validate([
            'employee_id' => 'required|uuid|exists:employees,id',
            'allocated_hours' => 'required|numeric|min:0',
        ]);

        $exists = ProjectTeamAssignment::where('project_id', $project->id)
            ->where('employee_id', $request->input('employee_id'))
            ->exists();

        if ($exists) {
            return response()->json(['error' => 'Employee already assigned to this project'], 409);
        }

        $assignment = ProjectTeamAssignment::create([
            'tenant_id' => $tenantId,
            'project_id' => $project->id,
            'employee_id' => $request->input('employee_id'),
            'allocated_hours' => $request->input('allocated_hours'),
            'assignment_source' => 'manual',
        ]);

        $assignment->load('employee');

        return new ProjectTeamAssignmentResource($assignment);
    }

    public function destroy(Project $project, ProjectTeamAssignment $assignment)
    {
        if ($assignment->project_id !== $project->id) {
            abort(404);
        }

        $assignment->delete();

        return response()->noContent();
    }

    private function extractSkillsFromDescription(string $description): array
    {
        $skillKeywords = [
            'react', 'vue', 'angular', 'javascript', 'typescript',
            'python', 'django', 'fastapi', 'flask',
            'node', 'express', 'laravel', 'php',
            'postgresql', 'mysql', 'mongodb', 'redis',
            'aws', 'azure', 'gcp', 'docker', 'kubernetes',
            'figma', 'sketch', 'adobe', 'photoshop',
            'project management', 'pmp', 'agile', 'scrum',
            'legal', 'compliance', 'finance', 'accounting',
            'sales', 'crm', 'marketing', 'seo',
            'data analysis', 'machine learning', 'ai', 'ml',
        ];

        $descriptionLower = strtolower($description);
        $found = [];

        foreach ($skillKeywords as $keyword) {
            if (str_contains($descriptionLower, $keyword)) {
                $found[] = $keyword;
            }
        }

        return array_unique($found);
    }

    private function buildAutoAssignPrompt(Project $project, $deal, array $employees, array $requiredSkills): string
    {
        $timelineMonths = $deal?->timeline_months ?? 3;
        $workloadHours = $deal?->workload_hours ?? 160;

        $skillsSection = ! empty($requiredSkills)
            ? 'Required skills: '.implode(', ', $requiredSkills)."\n"
            : "No specific skills required — assign based on capacity role.\n";

        return <<<PROMPT
Project: {$project->name}
Client: {$project->client}
Timeline: {$timelineMonths} months
Estimated workload: {$workloadHours} hours

{$skillsSection}
Employee Pool (with skills and monthly capacity):

{$this->jsonEncode($employees)}

Instructions:
- Assign employees from the pool to this project based on their skills and capacity
- Each employee has {$timelineMonths} months of capacity at their workable_hours/month
- allocated_hours should be realistic based on project workload
- Return ONLY a JSON array like: [{"employee_id": "uuid", "allocated_hours": 80}]
- Only include employees with available capacity
- If no skills are required, assign by capacity role (prefer matching roles)
PROMPT;
    }

    private function jsonEncode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT);
    }
}
