<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectTaskAssignmentResource;
use App\Http\Resources\ProjectTaskPhaseAssignmentResource;
use App\Http\Resources\ProjectTeamAssignmentResource;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectTaskAssignment;
use App\Models\ProjectTaskPhaseAssignment;
use App\Models\ProjectTeamAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    // ── Task-level AI assignment (Estimate.xlsx → per-phase assignee) ─────────

    // Phases are scheduled in `order` ascending within each task. Design phases come
    // first (requirements → system arch → basic doc → detail doc), then Development,
    // then the three test phases. Excel column mappings stay unchanged — only the
    // scheduling order differs.
    private const PHASE_DEFS = [
        ['code' => 'requirement',  'name' => '要件定義',      'cols' => [6, 7],                            'is_execution' => false, 'order' => 1],
        ['code' => 'system_arch',  'name' => '基本全体設計',  'cols' => [8, 9, 10, 11, 12, 13, 14, 15],    'is_execution' => false, 'order' => 2],
        ['code' => 'basic_doc',    'name' => '基本設計',      'cols' => [16, 17],                          'is_execution' => false, 'order' => 3],
        ['code' => 'detail_doc',   'name' => '詳細設計',      'cols' => [18, 19],                          'is_execution' => false, 'order' => 4],
        ['code' => 'development',  'name' => 'Development',  'cols' => [4, 5],                            'is_execution' => true,  'order' => 5],
        ['code' => 'unit_test',    'name' => '単体テスト',    'cols' => [20, 21, 22],                      'is_execution' => true,  'order' => 6],
        ['code' => 'combine_test', 'name' => '結合テスト',    'cols' => [23, 24, 25],                      'is_execution' => true,  'order' => 7],
        ['code' => 'system_test',  'name' => '総合テスト',    'cols' => [26],                              'is_execution' => true,  'order' => 8],
    ];

    // Tasks must finish at least this many days before the project's end_date.
    private const PROJECT_END_BUFFER_DAYS = 10;

    // Hours per workday. Phase duration = ceil(estimated_hours / WORKDAY_HOURS).
    private const WORKDAY_HOURS = 8;

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

        $sheet = $this->readEstimateSheet();
        $tasks = $sheet['tasks'];
        $activePhases = $sheet['active_phases'];

        if (empty($tasks)) {
            return response()->json([
                'error' => 'No task rows found in Estimate.xlsx (Web_Manhour_Detail).',
            ], 422);
        }

        if (! $project->start_date) {
            return response()->json([
                'error' => 'Project is missing start_date. Set it before assigning tasks.',
            ], 422);
        }

        $windowEnd = $project->effectiveEndDate();
        if (! $windowEnd) {
            return response()->json([
                'error' => 'Project has no end_date and no deal.timeline_months to estimate one. Set an end date (or a timeline on the originating deal) before assigning tasks.',
            ], 422);
        }

        $windowStart  = Carbon::parse($project->start_date)->startOfDay();
        $effectiveEnd = $windowEnd->copy()->subDays(self::PROJECT_END_BUFFER_DAYS);

        if ($effectiveEnd->lessThanOrEqualTo($windowStart)) {
            return response()->json([
                'error' => 'Project window is too short. The effective end date must be at least '.(self::PROJECT_END_BUFFER_DAYS + 1).' days after start_date.',
            ], 422);
        }

        $apiKey = config('services.anthropic.api_key') ?? env('ANTHROPIC_API_KEY');

        if (! $apiKey) {
            return $this->demoAssignTasks($project, $tasks, $activePhases, $teamAssignments, $tenantId, $windowStart, $effectiveEnd);
        }

        try {
            $teamMembers = $teamAssignments->map(fn ($a) => [
                'id'             => $a->employee_id,
                'name'           => optional($a->employee)->name,
                'rank_code'      => optional(optional($a->employee)->rank)->code,
                'rank_name'      => optional(optional($a->employee)->rank)->name,
                'capacity_role'  => optional(optional($a->employee)->capacityRole)->code
                    ?? optional($a->employee)->capacity_role,
                'workable_hours' => optional($a->employee)->workable_hours,
                'cost_per_hour'  => optional($a->employee)->cost_per_hour,
            ])->values()->toArray();

            $prompt = $this->buildAssignTasksPrompt($project, $tasks, $activePhases, $teamMembers, $windowStart, $effectiveEnd);

            $response = Http::withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model'      => 'claude-3-5-sonnet-latest',
                'max_tokens' => 8192,
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

                return $this->demoAssignTasks($project, $tasks, $activePhases, $teamAssignments, $tenantId, $windowStart, $effectiveEnd);
            }

            $assigneeByRowPhase = [];
            $validTeamIds = $teamAssignments->pluck('employee_id')->all();
            foreach ($aiAssignments as $item) {
                if (! isset($item['row_no'], $item['phase_code'], $item['assignee_id'])) {
                    continue;
                }
                if (! in_array($item['assignee_id'], $validTeamIds, true)) {
                    continue;
                }
                $assigneeByRowPhase[(int) $item['row_no']][(string) $item['phase_code']] = $item['assignee_id'];
            }

            $assignments = $this->computePlannedDates($tasks, $assigneeByRowPhase, $windowStart, $effectiveEnd);

            return $this->persistTaskAssignments($project, $tasks, $assignments, $tenantId);
        } catch (\Exception $e) {
            Log::error('AI AssignTasks error, falling back to demo', ['message' => $e->getMessage()]);

            return $this->demoAssignTasks($project, $tasks, $activePhases, $teamAssignments, $tenantId, $windowStart, $effectiveEnd);
        }
    }

    /**
     * Compute planned_start / planned_end for every (task, phase) pair using
     * two cursors: a per-task cursor (so phases within a task run sequentially)
     * and a per-assignee cursor (so a single engineer's tasks queue up — they
     * can't be in two places on the same day).
     *
     * Each phase consumes ceil(hours / WORKDAY_HOURS) calendar days. Both
     * cursors advance to the day AFTER the phase ends.
     */
    private function computePlannedDates(array $tasks, array $assigneeByRowPhase, Carbon $windowStart, Carbon $effectiveEnd): array
    {
        // Schedule tasks in row_no order for deterministic output.
        $orderedTasks = $tasks;
        usort($orderedTasks, fn ($a, $b) => $a['row_no'] <=> $b['row_no']);

        $result = [];
        $assigneeCursors = []; // employee_id => Carbon (their next free day)

        foreach ($orderedTasks as $t) {
            $phases = $t['phases'];
            usort($phases, fn ($a, $b) => $a['order'] <=> $b['order']);

            $taskCursor = $windowStart->copy();

            foreach ($phases as $phase) {
                $assigneeId = $assigneeByRowPhase[$t['row_no']][$phase['code']] ?? null;
                if (! $assigneeId) {
                    continue;
                }

                $assigneeCursor = $assigneeCursors[$assigneeId] ?? $windowStart->copy();
                $start = $taskCursor->greaterThan($assigneeCursor) ? $taskCursor->copy() : $assigneeCursor->copy();
                if ($start->greaterThan($effectiveEnd)) {
                    $start = $effectiveEnd->copy();
                }

                $hours     = max(0.0, (float) $phase['hours']);
                $sliceDays = max(1, (int) ceil($hours / self::WORKDAY_HOURS));

                $end = $start->copy()->addDays($sliceDays - 1);
                if ($end->greaterThan($effectiveEnd)) {
                    $end = $effectiveEnd->copy();
                }
                if ($end->lessThan($start)) {
                    $end = $start->copy();
                }

                $result[$t['row_no']][$phase['code']] = [
                    'assignee_id'   => $assigneeId,
                    'planned_start' => $start->toDateString(),
                    'planned_end'   => $end->toDateString(),
                ];

                $next = $end->copy()->addDay();
                $taskCursor                       = $next->copy();
                $assigneeCursors[$assigneeId]     = $next;
            }
        }

        return $result;
    }

    public function taskAssignmentsIndex(Project $project)
    {
        $rows = ProjectTaskAssignment::with('phaseAssignments.assignee.rank')
            ->where('project_id', $project->id)
            ->orderBy('row_no')
            ->get();

        $activePhases = $this->activePhasesFromRows($rows);

        return [
            'data' => ProjectTaskAssignmentResource::collection($rows),
            'meta' => ['active_phases' => $activePhases],
        ];
    }

    public function updateTaskPhaseAssignment(Request $request, Project $project, ProjectTaskPhaseAssignment $phaseAssignment)
    {
        $phaseAssignment->loadMissing('taskAssignment');
        if (! $phaseAssignment->taskAssignment || $phaseAssignment->taskAssignment->project_id !== $project->id) {
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

        if (array_key_exists('assignee_id', $validated)) {
            $validated['assignment_source'] = 'manual';
        }

        $phaseAssignment->update($validated);
        $phaseAssignment->load('assignee.rank');

        return new ProjectTaskPhaseAssignmentResource($phaseAssignment);
    }

    private function readEstimateSheet(): array
    {
        $path = public_path('storage/Estimate.xlsx');
        if (! file_exists($path)) {
            Log::error('Estimate.xlsx not found at '.$path);

            return ['tasks' => [], 'active_phases' => []];
        }

        $spreadsheet = IOFactory::load($path);
        if (! in_array('Web_Manhour_Detail', $spreadsheet->getSheetNames(), true)) {
            Log::error('Web_Manhour_Detail sheet not found in Estimate.xlsx');

            return ['tasks' => [], 'active_phases' => []];
        }

        $sheet = $spreadsheet->getSheetByName('Web_Manhour_Detail');
        $tasks = [];
        $rowNo = 0;
        $activePhaseCodes = [];

        // Data rows start at row 5; we read A:AE (cols 1..31).
        // A=機能ID, B=機能名称, C=Status, AE=Total(h). Per-phase hour columns: see PHASE_DEFS.
        foreach ($sheet->getRowIterator(5) as $row) {
            $rowIndex = $row->getRowIndex();
            $cells = $sheet->rangeToArray(
                'A'.$rowIndex.':AE'.$rowIndex,
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

            $phases = [];
            foreach (self::PHASE_DEFS as $phase) {
                $sum = 0.0;
                foreach ($phase['cols'] as $col1Based) {
                    $val = $cells[$col1Based - 1] ?? null;
                    if (is_numeric($val)) {
                        $sum += (float) $val;
                    }
                }
                if ($sum <= 0) {
                    continue;
                }
                $phases[] = [
                    'code'         => $phase['code'],
                    'name'         => $phase['name'],
                    'order'        => $phase['order'],
                    'is_execution' => $phase['is_execution'],
                    'hours'        => round($sum, 2),
                ];
                $activePhaseCodes[$phase['code']] = true;
            }

            $rowNo++;
            $tasks[] = [
                'row_no'        => $rowNo,
                'function_id'   => $functionId ? (string) $functionId : null,
                'function_name' => (string) $functionName,
                'difficulty'    => $difficulty,
                'total_hours'   => is_numeric($totalHours) ? round((float) $totalHours, 2) : 0,
                'phases'        => $phases,
            ];
        }

        $activePhases = [];
        foreach (self::PHASE_DEFS as $phase) {
            if (isset($activePhaseCodes[$phase['code']])) {
                $activePhases[] = [
                    'code'         => $phase['code'],
                    'name'         => $phase['name'],
                    'order'        => $phase['order'],
                    'is_execution' => $phase['is_execution'],
                ];
            }
        }

        return ['tasks' => $tasks, 'active_phases' => $activePhases];
    }

    private function buildAssignTasksPrompt(
        Project $project,
        array $tasks,
        array $activePhases,
        array $teamMembers,
        Carbon $windowStart,
        Carbon $effectiveEnd
    ): string {
        $projectName = $project->name;
        $client      = $project->client ?? 'N/A';
        $startStr    = $windowStart->toDateString();
        $endStr      = $effectiveEnd->toDateString();
        $buffer      = self::PROJECT_END_BUFFER_DAYS;
        $rawEnd      = $project->effectiveEndDate()->toDateString();

        return "Project: {$projectName}\n".
            "Client: {$client}\n".
            "Project window: {$startStr} → {$rawEnd} (raw end). All work must finish on or before {$endStr} ({$buffer}-day buffer before the raw end).\n\n".
            "ACTIVE PHASES (phases that have estimated hours in this project):\n".
            $this->jsonEncode($activePhases)."\n\n".
            "TEAM MEMBERS (existing project team — assign only from this list):\n".
            $this->jsonEncode($teamMembers)."\n\n".
            "TASKS (one entry per function; phases array carries per-phase estimated hours):\n".
            $this->jsonEncode($tasks)."\n\n".
            "Your job is to pick the BEST team member for every (task, phase) pair. You do NOT need to set dates — the backend will schedule them automatically using a sequential queue (each engineer's tasks run back-to-back, each phase consumes ceil(hours/".self::WORKDAY_HOURS.") calendar days, phases within a task run head-to-tail in this order: 要件定義 → 基本全体設計 → 基本設計 → 詳細設計 → Development → 単体テスト → 結合テスト → 総合テスト — design comes before development because you can't code without a detail spec).\n\n".
            "Instructions:\n".
            "- Act as an experienced IT delivery manager.\n".
            "- For EACH task and for EACH phase that appears in that task's phases array, return ONE entry: {row_no, phase_code, assignee_id}.\n".
            "- ASSIGNEE rules:\n".
            "  * DESIGN phases (phase_code in {requirement, system_arch, basic_doc, detail_doc}): ALWAYS pick a member whose rank_code is 'Lead'. Fallback: Senior → Mid → any.\n".
            "  * EXECUTION phases (phase_code in {development, unit_test, combine_test, system_test}): map by the TASK's difficulty — 簡単→'Junior', 普通→'Mid', 難しい→'Senior'. Fallback chain: 簡単→Mid→Senior→Lead, 普通→Senior→Junior→Lead, 難しい→Lead→Mid→Junior.\n".
            "  * SPREAD WORKLOAD AGGRESSIVELY across qualifying members. Each phase you assign to a person extends their personal schedule by ceil(hours/".self::WORKDAY_HOURS.") days — piling work on one engineer pushes their later tasks far into the future and risks blowing past {$endStr}. If two Seniors qualify, alternate between them.\n".
            "- Return ONLY a JSON array of objects: [{\"row_no\": <int>, \"phase_code\": \"<string>\", \"assignee_id\": \"<uuid from TEAM MEMBERS>\"}, ...].\n".
            "- One entry per (row_no × phase). Do not include phases that are not in the task's phases array. No markdown, no extra keys, no dates.";
    }

    private function persistTaskAssignments(Project $project, array $tasks, array $assignments, string $tenantId)
    {
        DB::transaction(function () use ($tasks, $assignments, $project, $tenantId) {
            // Cascade deletes child rows in project_task_phase_assignments via FK.
            ProjectTaskAssignment::where('project_id', $project->id)->delete();

            foreach ($tasks as $t) {
                $parent = ProjectTaskAssignment::create([
                    'tenant_id'     => $tenantId,
                    'project_id'    => $project->id,
                    'row_no'        => $t['row_no'],
                    'function_id'   => $t['function_id'],
                    'function_name' => $t['function_name'],
                    'difficulty'    => $t['difficulty'],
                    'total_hours'   => $t['total_hours'],
                ]);

                foreach ($t['phases'] as $phase) {
                    $entry = $assignments[$t['row_no']][$phase['code']] ?? null;
                    ProjectTaskPhaseAssignment::create([
                        'tenant_id'          => $tenantId,
                        'task_assignment_id' => $parent->id,
                        'phase_code'         => $phase['code'],
                        'phase_name'         => $phase['name'],
                        'phase_order'        => $phase['order'],
                        'estimated_hours'    => $phase['hours'],
                        'assignee_id'        => is_array($entry) ? ($entry['assignee_id']   ?? null) : $entry,
                        'planned_start'      => is_array($entry) ? ($entry['planned_start'] ?? null) : null,
                        'planned_end'        => is_array($entry) ? ($entry['planned_end']   ?? null) : null,
                        'assignment_source'  => 'ai',
                        'status'             => '未着手',
                    ]);
                }
            }
        });

        $rows = ProjectTaskAssignment::with('phaseAssignments.assignee.rank')
            ->where('project_id', $project->id)
            ->orderBy('row_no')
            ->get();

        $activePhases = $this->activePhasesFromRows($rows);

        return [
            'data' => ProjectTaskAssignmentResource::collection($rows),
            'meta' => ['active_phases' => $activePhases],
        ];
    }

    private function demoAssignTasks(
        Project $project,
        array $tasks,
        array $activePhases,
        $teamAssignments,
        string $tenantId,
        Carbon $windowStart,
        Carbon $effectiveEnd
    ) {
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

        $cursors = [];
        $pickFrom = function (string $tier) use ($buckets, $allIds, &$cursors) {
            $pool = ! empty($buckets[$tier]) ? $buckets[$tier] : $allIds;
            $key = $tier;
            $cursors[$key] = $cursors[$key] ?? 0;
            $id = $pool[$cursors[$key] % count($pool)];
            $cursors[$key]++;

            return $id;
        };

        $resolveTier = function (string $preferred) use ($buckets): string {
            if (! empty($buckets[$preferred])) {
                return $preferred;
            }
            $fallbacks = [
                'Lead'   => ['Senior', 'Mid', 'Junior'],
                'Senior' => ['Lead', 'Mid', 'Junior'],
                'Mid'    => ['Senior', 'Junior', 'Lead'],
                'Junior' => ['Mid', 'Senior', 'Lead'],
            ];
            foreach ($fallbacks[$preferred] ?? [] as $alt) {
                if (! empty($buckets[$alt])) {
                    return $alt;
                }
            }

            return $preferred;
        };

        $designPhases = ['requirement', 'system_arch', 'basic_doc', 'detail_doc'];
        $executionTier = [
            '簡単'   => 'Junior',
            '普通'   => 'Mid',
            '難しい' => 'Senior',
        ];

        // Phase 1: pick assignees only. Phase 2: computePlannedDates() does the cursor math.
        $assigneeByRowPhase = [];
        foreach ($tasks as $t) {
            foreach ($t['phases'] as $phase) {
                $preferred = in_array($phase['code'], $designPhases, true)
                    ? 'Lead'
                    : ($executionTier[$t['difficulty']] ?? 'Mid');

                $tier = $resolveTier($preferred);
                $assigneeByRowPhase[$t['row_no']][$phase['code']] = $pickFrom($tier);
            }
        }

        $assignments = $this->computePlannedDates($tasks, $assigneeByRowPhase, $windowStart, $effectiveEnd);

        return $this->persistTaskAssignments($project, $tasks, $assignments, $tenantId);
    }

    private function activePhasesFromRows($rows): array
    {
        $byCode = [];
        foreach ($rows as $row) {
            foreach ($row->phaseAssignments as $pa) {
                $byCode[$pa->phase_code] = [
                    'code'  => $pa->phase_code,
                    'name'  => $pa->phase_name,
                    'order' => (int) $pa->phase_order,
                ];
            }
        }
        $active = array_values($byCode);
        usort($active, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $active;
    }
}
