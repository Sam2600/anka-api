<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectTaskAssignmentResource;
use App\Http\Resources\ProjectTeamAssignmentResource;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectTaskAssignment;
use App\Models\ProjectTeamAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

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

        $apiKey = config('services.anthropic.api_key') ?? env('ANTHROPIC_API_KEY');

        // ── Demo fallback: distribute hours by capacity role when no AI key or on error ──
        if (! $apiKey) {
            return $this->demoAutoAssign($project, $deal, $employees, $tenantId);
        }

        try {
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

            $prompt = $this->buildAutoAssignPrompt($project, $deal, $employeesWithSkills, $requiredSkills);

            $baseUrl = rtrim(config('services.anthropic.base_url') ?: 'https://api.anthropic.com', '/');
            $model = config('services.anthropic.model') ?: 'claude-3-5-sonnet-latest';

            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post($baseUrl.'/v1/messages', [
                'model' => $model,
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
                Log::error('AI AutoAssign: invalid JSON response, falling back to demo mode', ['text' => substr($text, 0, 300)]);

                return $this->demoAutoAssign($project, $deal, $employees, $tenantId);
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
            Log::error('AI AutoAssign error, falling back to demo mode', ['message' => $e->getMessage()]);

            return $this->demoAutoAssign($project, $deal, $employees, $tenantId);
        }
    }

    /**
     * Demo fallback: distributes workload hours proportionally across active employees
     * by matching capacity roles to the deal's ghost roles (or evenly if no deal).
     */
    private function demoAutoAssign(Project $project, $deal, $employees, string $tenantId)
    {
        $totalHours = (float) ($deal?->workload_hours ?? $project->budget_hours ?? 160);
        $timelineMonths = (int) ($deal?->timeline_months ?? 1);

        // Sanity cap: never assign more than total project hours total across all members
        $maxHoursPerPerson = max($totalHours, 160 * $timelineMonths);

        // Get ghost roles from deal to understand desired team composition
        $ghostRoles = $deal?->ghost_roles ?? [];
        $roleTargets = [];
        foreach ($ghostRoles as $gr) {
            $roleType = $gr->role_type ?? 'unknown';
            $roleTargets[$roleType] = ($roleTargets[$roleType] ?? 0) + ($gr->quantity ?? 1);
        }

        $activeEmployees = $employees->where('status', 'Active');

        // Group employees by capacity role
        $byRole = $activeEmployees->groupBy(fn ($e) => $e->capacityRole?->code ?? $e->capacity_role ?? 'unknown');

        $assignments = [];
        $assignedCount = 0;

        foreach ($roleTargets as $roleType => $targetCount) {
            $candidates = $byRole->get($roleType, collect());
            if ($candidates->isEmpty()) {
                continue;
            }

            // Pick up to targetCount employees for this role
            $selected = $candidates->shuffle()->take($targetCount);
            $roleAllocation = $totalHours / max(count($roleTargets), 1);
            $hoursPerPerson = min(
                round($roleAllocation / max($selected->count(), 1)),
                $maxHoursPerPerson
            );

            foreach ($selected as $emp) {
                $empMaxHours = ((int) ($emp->workable_hours ?? 160)) * $timelineMonths;
                $assignments[] = [
                    'employee_id' => $emp->id,
                    'allocated_hours' => min($hoursPerPerson, $empMaxHours),
                ];
                $assignedCount++;
            }
        }

        // If no assignments from ghost roles, distribute evenly across all active employees
        if (empty($assignments) && $activeEmployees->isNotEmpty()) {
            $count = $activeEmployees->count();
            $hoursPerPerson = round($totalHours / max($count, 1));
            foreach ($activeEmployees as $emp) {
                $empMaxHours = ((int) ($emp->workable_hours ?? 160)) * $timelineMonths;
                $assignments[] = [
                    'employee_id' => $emp->id,
                    'allocated_hours' => min($hoursPerPerson, $empMaxHours),
                ];
                $assignedCount++;
            }
        }

        DB::transaction(function () use ($assignments, $project, $tenantId) {
            ProjectTeamAssignment::where('project_id', $project->id)->delete();

            foreach ($assignments as $item) {
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

    // ── Task-level AI assignment (Estimate.xlsx → per-row assignee) ──────────

    public function assignTasks(Request $request, Project $project)
    {
        $tenantId = app('tenant_id');

        $project->load(['contract.deal', 'teamAssignments.employee.rank']);

        $teamAssignments = $project->teamAssignments;
        if ($teamAssignments->isEmpty()) {
            return response()->json([
                'error' => 'Project has no team. Add team members before assigning tasks.',
            ], 422);
        }

        $tasks = $this->readEstimateSheet();
        if (empty($tasks)) {
            return response()->json([
                'error' => 'No task rows found in Estimate.xlsx (Web_Manhour_Detail).',
            ], 422);
        }

        $apiKey = config('services.anthropic.api_key') ?? env('ANTHROPIC_API_KEY');

        if (! $apiKey) {
            return $this->demoAssignTasks($project, $tasks, $teamAssignments, $tenantId);
        }

        try {
            $teamMembers = $teamAssignments->map(fn ($a) => [
                'id'             => $a->employee_id,
                'name'           => optional($a->employee)->name,
                'rank'           => optional(optional($a->employee)->rank)->code,
                'rank_name'      => optional(optional($a->employee)->rank)->name,
                'capacity_role'  => optional(optional($a->employee)->capacityRole)->code
                    ?? optional($a->employee)->capacity_role,
                'workable_hours' => optional($a->employee)->workable_hours,
                'cost_per_hour'  => optional($a->employee)->cost_per_hour,
            ])->values()->toArray();

            $prompt = $this->buildAssignTasksPrompt($project, $tasks, $teamMembers);

            $response = Http::withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model'      => 'claude-3-5-sonnet-latest',
                'max_tokens' => 4096,
                'system'     => 'You are an experienced IT delivery manager assigning feature work to engineers. Return ONLY a JSON array — no markdown, no explanation.',
                'messages'   => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            $body = $response->json();
            $text = trim($body['content'][0]['text'] ?? '');
            if (str_starts_with($text, '```')) {
                $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
                $text = preg_replace('/\s*```$/', '', $text);
            }

            $aiAssignments = json_decode($text, true);
            if (! is_array($aiAssignments)) {
                Log::error('AI AssignTasks: invalid JSON, falling back to demo', ['text' => substr($text, 0, 300)]);

                return $this->demoAssignTasks($project, $tasks, $teamAssignments, $tenantId);
            }

            $assigneeByRow = [];
            $validTeamIds = $teamAssignments->pluck('employee_id')->all();
            foreach ($aiAssignments as $item) {
                if (! isset($item['row_no'], $item['assignee_id'])) {
                    continue;
                }
                if (! in_array($item['assignee_id'], $validTeamIds, true)) {
                    continue;
                }
                $assigneeByRow[(int) $item['row_no']] = $item['assignee_id'];
            }

            return $this->persistTaskAssignments($project, $tasks, $assigneeByRow, $tenantId);
        } catch (\Exception $e) {
            Log::error('AI AssignTasks error, falling back to demo', ['message' => $e->getMessage()]);

            return $this->demoAssignTasks($project, $tasks, $teamAssignments, $tenantId);
        }
    }

    public function taskAssignmentsIndex(Project $project)
    {
        $rows = ProjectTaskAssignment::with('assignee.rank')
            ->where('project_id', $project->id)
            ->orderBy('row_no')
            ->get();

        return ProjectTaskAssignmentResource::collection($rows);
    }

    public function updateTaskAssignment(Request $request, Project $project, ProjectTaskAssignment $assignment)
    {
        if ($assignment->project_id !== $project->id) {
            abort(404);
        }

        $validated = $request->validate([
            'assignee_id'   => 'nullable|uuid|exists:employees,id',
            'planned_start' => 'nullable|date',
            'planned_end'   => 'nullable|date',
            'actual_start'  => 'nullable|date',
            'actual_end'    => 'nullable|date',
            'status'        => 'sometimes|in:未着手,進行中,完了',
        ]);

        $assignment->update($validated);
        $assignment->load('assignee.rank');

        return new ProjectTaskAssignmentResource($assignment);
    }

    private function readEstimateSheet(): array
    {
        $path = public_path('storage/Estimate.xlsx');
        if (! file_exists($path)) {
            Log::error('Estimate.xlsx not found at '.$path);

            return [];
        }

        $spreadsheet = IOFactory::load($path);
        if (! in_array('Web_Manhour_Detail', $spreadsheet->getSheetNames(), true)) {
            Log::error('Web_Manhour_Detail sheet not found in Estimate.xlsx');

            return [];
        }

        $sheet = $spreadsheet->getSheetByName('Web_Manhour_Detail');
        $tasks = [];
        $rowNo = 0;

        // Data rows start at row 5; columns A=機能ID, B=機能名称, C=Status, D=開発工数, AE (col 31)=Total(h).
        foreach ($sheet->getRowIterator(5) as $row) {
            $cells = $sheet->rangeToArray(
                'A'.$row->getRowIndex().':AE'.$row->getRowIndex(),
                null,
                true,
                false
            )[0] ?? [];

            $functionId   = isset($cells[0])  ? (is_string($cells[0])  ? trim($cells[0])  : $cells[0])  : null;
            $functionName = isset($cells[1])  ? (is_string($cells[1])  ? trim($cells[1])  : $cells[1])  : null;
            $difficulty   = isset($cells[2])  ? (is_string($cells[2])  ? trim($cells[2])  : $cells[2])  : null;
            $totalHours   = isset($cells[30]) ? $cells[30] : null;

            if (! $functionName) {
                continue;
            }
            if (! in_array($difficulty, ['簡単', '普通', '難しい'], true)) {
                $difficulty = '普通';
            }

            $rowNo++;
            $tasks[] = [
                'row_no'        => $rowNo,
                'function_id'   => $functionId ? (string) $functionId : null,
                'function_name' => (string) $functionName,
                'difficulty'    => $difficulty,
                'total_hours'   => is_numeric($totalHours) ? round((float) $totalHours, 2) : 0,
            ];
        }

        return $tasks;
    }

    private function buildAssignTasksPrompt(Project $project, array $tasks, array $teamMembers): string
    {
        $projectName = $project->name;
        $client      = $project->client ?? 'N/A';

        return "Project: {$projectName}\n".
            "Client: {$client}\n\n".
            "TEAM MEMBERS (existing project team — assign only from this list):\n".
            $this->jsonEncode($teamMembers)."\n\n".
            "TASKS (from Estimate.xlsx → Web_Manhour_Detail):\n".
            $this->jsonEncode($tasks)."\n\n".
            "Instructions:\n".
            "- Act as an experienced IT delivery manager.\n".
            "- Assign EACH task to exactly ONE team member.\n".
            "- Difficulty → rank mapping: 簡単 (easy) → prefer rank 'Junior', 普通 (normal) → prefer 'Mid', 難しい (hard) → prefer 'Senior' or 'Lead'.\n".
            "- If a rank tier has no members, fall back to the closest available tier.\n".
            "- Spread workload reasonably — do not pile every hard task on a single person if multiple are available.\n".
            "- Return ONLY a JSON array of objects: [{\"row_no\": <int>, \"assignee_id\": \"<uuid from TEAM MEMBERS>\"}, ...].\n".
            "- Every task row_no must appear exactly once. No markdown, no extra keys.";
    }

    private function persistTaskAssignments(Project $project, array $tasks, array $assigneeByRow, string $tenantId)
    {
        DB::transaction(function () use ($tasks, $assigneeByRow, $project, $tenantId) {
            ProjectTaskAssignment::where('project_id', $project->id)->delete();

            foreach ($tasks as $t) {
                ProjectTaskAssignment::create([
                    'tenant_id'         => $tenantId,
                    'project_id'        => $project->id,
                    'row_no'            => $t['row_no'],
                    'function_id'       => $t['function_id'],
                    'function_name'     => $t['function_name'],
                    'difficulty'        => $t['difficulty'],
                    'total_hours'       => $t['total_hours'],
                    'assignee_id'       => $assigneeByRow[$t['row_no']] ?? null,
                    'assignment_source' => 'ai',
                    'status'            => '未着手',
                ]);
            }
        });

        $rows = ProjectTaskAssignment::with('assignee.rank')
            ->where('project_id', $project->id)
            ->orderBy('row_no')
            ->get();

        return ProjectTaskAssignmentResource::collection($rows);
    }

    private function demoAssignTasks(Project $project, array $tasks, $teamAssignments, string $tenantId)
    {
        // Bucket team members by rank code. Missing rank falls into 'Mid'.
        $buckets = ['Junior' => [], 'Mid' => [], 'Senior' => [], 'Lead' => []];
        foreach ($teamAssignments as $a) {
            $tier = optional(optional($a->employee)->rank)->code ?: 'Mid';
            if (! isset($buckets[$tier])) {
                $tier = 'Mid';
            }
            $buckets[$tier][] = $a->employee_id;
        }

        $allIds = array_values(array_filter(array_merge(
            $buckets['Junior'], $buckets['Mid'], $buckets['Senior'], $buckets['Lead']
        )));
        if (empty($allIds)) {
            return response()->json(['error' => 'No team members available.'], 422);
        }

        $pickFrom = function (string $tier) use ($buckets, $allIds, &$cursors) {
            $pool = ! empty($buckets[$tier]) ? $buckets[$tier] : $allIds;
            $key = $tier.'-'.spl_object_hash((object) $pool);
            $cursors[$key] = $cursors[$key] ?? 0;
            $id = $pool[$cursors[$key] % count($pool)];
            $cursors[$key]++;

            return $id;
        };
        $cursors = [];

        $difficultyTier = [
            '簡単'   => 'Junior',
            '普通'   => 'Mid',
            '難しい' => 'Senior',
        ];

        $assigneeByRow = [];
        foreach ($tasks as $t) {
            $tier = $difficultyTier[$t['difficulty']] ?? 'Mid';
            // If the preferred tier is empty, escalate then fall back.
            if (empty($buckets[$tier])) {
                $fallbacks = [
                    'Junior' => ['Mid', 'Senior', 'Lead'],
                    'Mid'    => ['Senior', 'Junior', 'Lead'],
                    'Senior' => ['Lead', 'Mid', 'Junior'],
                ];
                foreach ($fallbacks[$tier] ?? [] as $f) {
                    if (! empty($buckets[$f])) {
                        $tier = $f;
                        break;
                    }
                }
            }
            $assigneeByRow[$t['row_no']] = $pickFrom($tier);
        }

        return $this->persistTaskAssignments($project, $tasks, $assigneeByRow, $tenantId);
    }
}
