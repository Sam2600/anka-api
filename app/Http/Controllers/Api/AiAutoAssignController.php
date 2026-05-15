<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidAiScheduleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectTaskAssignmentResource;
use App\Http\Resources\ProjectTaskPhaseAssignmentResource;
use App\Http\Resources\ProjectTeamAssignmentResource;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Project;
use App\Models\ProjectTaskAssignment;
use App\Models\ProjectTaskPhaseAssignment;
use App\Models\ProjectTeamAssignment;
use App\Services\Scheduling\AiSchedulePayload;
use App\Services\Scheduling\AiScheduleValidator;
use App\Services\Scheduling\CalendarFactory;
use App\Services\Scheduling\WorkingDayCalendar;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

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

    // ── Task-level AI assignment (Estimate.xlsx → per-phase assignee) ─────────

    // Canonical phase catalog keyed by the Japanese label that appears in row 3
    // of the Web_Manhour_Detail sheet. Column ranges are NOT fixed here — they
    // are detected per file by `detectPhasesFromSheet()` because different
    // projects may ship Estimate.xlsx files with different phase subsets and
    // column layouts. `development` has no row-3 header (its hours always live
    // at cols 4–5: 開発工数 + コードレビュー) and is injected separately.
    private const PHASE_CATALOG = [
        '要件定義'     => ['code' => 'requirement',  'order' => 1, 'is_execution' => false],
        '基本全体設計' => ['code' => 'system_arch',  'order' => 2, 'is_execution' => false],
        '基本設計'     => ['code' => 'basic_doc',    'order' => 3, 'is_execution' => false],
        '詳細設計'     => ['code' => 'detail_doc',   'order' => 4, 'is_execution' => false],
        '単体テスト'   => ['code' => 'unit_test',    'order' => 6, 'is_execution' => true],
        '結合テスト'   => ['code' => 'combine_test', 'order' => 7, 'is_execution' => true],
        '総合テスト'   => ['code' => 'system_test',  'order' => 8, 'is_execution' => true],
    ];

    // Row-4 column labels that mark project-wide overhead columns (Test Data,
    // Manual, Risk, Management) which sit between the last phase's columns and
    // the Total column. These are NEVER counted as phase hours — when the
    // detector walks a phase's column range it stops at the first column whose
    // row-4 label matches any of these keywords.
    private const NON_PHASE_LABEL_KEYWORDS = [
        'テストデータ', 'マニュアル', 'リスク', '管理工数',
        'Test Data', 'Manual', 'Risk', 'Management', 'Total',
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
        $rawSheet = $sheet['raw_markdown'] ?? '';

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

        $fallbackCalendar = $this->buildCalendar($tenantId, $windowStart, $effectiveEnd);

        $apiKey = config('services.anthropic.api_key') ?? env('ANTHROPIC_API_KEY');

        if (! $apiKey) {
            return $this->demoAssignTasks($project, $tasks, $activePhases, $teamAssignments, $tenantId, $windowStart, $effectiveEnd, $fallbackCalendar);
        }

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
        $teamIds = $teamAssignments->pluck('employee_id')->all();

        $dbHolidays = $this->dbHolidaysForPrompt($tenantId, $windowStart, $effectiveEnd);
        $prompt = $this->buildAssignTasksPrompt($project, $tasks, $activePhases, $teamMembers, $windowStart, $effectiveEnd, $dbHolidays, $rawSheet);

        $retriesLeft  = (int) config('services.anthropic.schedule_retries', 2);
        $maxTokens    = (int) config('services.anthropic.schedule_max_tokens', 16384);
        $model        = config('services.anthropic.schedule_model', 'claude-3-5-sonnet-latest');
        $conversation = [['role' => 'user', 'content' => $prompt]];

        try {
            while (true) {
                $response = Http::withHeaders([
                    'x-api-key'         => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $model,
                    'max_tokens' => $maxTokens,
                    'system'     => 'You are an experienced IT delivery manager scheduling feature work across engineers. Return ONLY a JSON object matching the schema in the user message — no markdown fences, no commentary.',
                    'messages'   => $conversation,
                ]);

                $body = $response->json();
                $text = trim($body['content'][0]['text'] ?? '');

                try {
                    $payload = AiSchedulePayload::fromRaw($text);
                } catch (InvalidAiScheduleException $e) {
                    Log::error('AI Schedule: unparseable payload, falling back', [
                        'error'   => $e->getMessage(),
                        'preview' => substr($text, 0, 300),
                    ]);

                    return $this->demoAssignTasks($project, $tasks, $activePhases, $teamAssignments, $tenantId, $windowStart, $effectiveEnd, $fallbackCalendar);
                }

                $calendar   = $this->buildCalendarFromAi($payload, $tenantId, $windowStart, $effectiveEnd);
                $violations = (new AiScheduleValidator)->validate($payload, $tasks, $teamIds, $calendar, $windowStart, $effectiveEnd);

                if (empty($violations)) {
                    return $this->persistAiAssignments($project, $tasks, $payload, $tenantId, $windowStart, $effectiveEnd, $calendar);
                }

                if ($retriesLeft <= 0) {
                    Log::warning('AI Schedule: exhausted retries, falling back', [
                        'violation_count' => count($violations),
                        'first_violation' => $violations[0]['code'] ?? null,
                    ]);

                    return $this->demoAssignTasks($project, $tasks, $activePhases, $teamAssignments, $tenantId, $windowStart, $effectiveEnd, $fallbackCalendar);
                }

                $conversation[] = ['role' => 'assistant', 'content' => $text];
                $conversation[] = ['role' => 'user', 'content' => $this->buildRetryPrompt($violations)];
                $retriesLeft--;
            }
        } catch (\Exception $e) {
            Log::error('AI Schedule: exception, falling back to demo', ['message' => $e->getMessage()]);

            return $this->demoAssignTasks($project, $tasks, $activePhases, $teamAssignments, $tenantId, $windowStart, $effectiveEnd, $fallbackCalendar);
        }
    }

    /**
     * Merge DB holidays (the source of truth) with any extra calendar entries
     * Claude proposed in its response. AI can only ADD blocks — never remove
     * a DB-confirmed closure.
     */
    private function buildCalendarFromAi(AiSchedulePayload $payload, string $tenantId, Carbon $windowStart, Carbon $effectiveEnd): WorkingDayCalendar
    {
        $calendar = CalendarFactory::forTenant($tenantId, $windowStart, $effectiveEnd);

        foreach ($payload->recurringHolidays as $entry) {
            $calendar->blockRecurringMonthDay($entry['month'], $entry['day']);
        }
        foreach ($payload->blockedDates as $entry) {
            $calendar->blockGlobalDate($entry['date']);
        }

        return $calendar;
    }

    /**
     * Snapshot of DB-stored holidays used to prime Claude — so AI knows which
     * dates are already on the books and only needs to contribute extras it
     * knows about (national holidays absent from the table, project-specific
     * closures, etc.).
     */
    private function dbHolidaysForPrompt(string $tenantId, Carbon $windowStart, Carbon $effectiveEnd): array
    {
        return Holiday::where('tenant_id', $tenantId)
            ->where(function ($q) use ($windowStart, $effectiveEnd) {
                $q->whereBetween('date', [$windowStart->toDateString(), $effectiveEnd->toDateString()])
                    ->orWhere('is_recurring', true);
            })
            ->get()
            ->map(fn (Holiday $h) => [
                'date'         => $h->date?->toDateString(),
                'name'         => $h->name,
                'is_recurring' => (bool) $h->is_recurring,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Take Claude's assignee picks and compute dates deterministically via
     * computePlannedDates() — hour-packing applies uniformly to both AI and
     * demo paths. Claude's planned_start/planned_end are intentionally ignored
     * here; they're still validated against the schema by AiScheduleValidator
     * (for assignee-rule + window checks) but the dates we actually persist
     * come from the deterministic scheduler.
     */
    private function persistAiAssignments(
        Project $project,
        array $tasks,
        AiSchedulePayload $payload,
        string $tenantId,
        Carbon $windowStart,
        Carbon $effectiveEnd,
        WorkingDayCalendar $calendar
    ) {
        $assigneeByRowPhase = [];
        foreach ($payload->assignmentsByRowPhase as $rowNo => $byPhase) {
            foreach ($byPhase as $phaseCode => $entry) {
                $assigneeByRowPhase[$rowNo][$phaseCode] = $entry['assignee_id'];
            }
        }

        $assignments = $this->computePlannedDates($tasks, $assigneeByRowPhase, $windowStart, $effectiveEnd, $calendar);

        return $this->persistTaskAssignments($project, $tasks, $assignments, $tenantId);
    }

    /**
     * Build the corrective follow-up sent back to Claude after validation fails.
     * Lists violations with their codes/context so AI can target the exact
     * issues. Asks for the full corrected JSON in the same schema — easier for
     * AI to produce than a diff and easier for us to re-validate.
     */
    private function buildRetryPrompt(array $violations): string
    {
        $lines = ["Your previous response failed validation. Fix the following ".count($violations)." problem(s) and resend the COMPLETE corrected JSON object in the same schema:\n"];
        foreach ($violations as $i => $v) {
            $idx = $i + 1;
            $lines[] = "{$idx}. [{$v['code']}] {$v['message']}";
        }
        $lines[] = "\nReturn ONLY the corrected JSON object. No markdown fences, no explanation, no commentary.";

        return implode("\n", $lines);
    }

    /**
     * Build a per-request working-day calendar. Thin wrapper around
     * CalendarFactory — same factory is used by the schedule-tracking side
     * so blocked-day rules stay in lockstep with planning.
     */
    private function buildCalendar(string $tenantId, Carbon $windowStart, Carbon $effectiveEnd): WorkingDayCalendar
    {
        return CalendarFactory::forTenant($tenantId, $windowStart, $effectiveEnd);
    }

    /**
     * Hour-packed scheduler. Each phase consumes from its assignee's daily
     * 8-hour budget. Phases that fit today's remaining budget land atomically
     * on a single day; phases that don't fit split across days (today gets the
     * remainder, full middle days = 8h, last day = remainder of remainder).
     *
     * Cursor model:
     *  - Task cursor (per task)        — DATE ONLY. Phase N+1's planned_start
     *    must be >= phase N's planned_end. We don't track task-level hours
     *    because different assignees of the same task use their own day budgets
     *    independently.
     *  - Assignee cursor (per engineer) — { date, hours_used_on_date }. When an
     *    engineer's next phase lands on the same day their cursor is parked on,
     *    that day's remaining budget = 8 − hours_used_on_date.
     *
     * Each entry in the returned [row_no][phase_code] map carries:
     *  - assignee_id
     *  - planned_start, planned_end (ISO date strings)
     *  - start_day_hours (hours allocated to planned_start specifically — used
     *    by VarianceCalculator to reconstruct the per-day plan for multi-day
     *    phases without storing a per-day child table).
     */
    private function computePlannedDates(
        array $tasks,
        array $assigneeByRowPhase,
        Carbon $windowStart,
        Carbon $effectiveEnd,
        WorkingDayCalendar $calendar
    ): array {
        $orderedTasks = $tasks;
        usort($orderedTasks, fn ($a, $b) => $a['row_no'] <=> $b['row_no']);

        $result = [];
        /** @var array<string, array{date: Carbon, hours_used: float}> */
        $assigneeCursors = [];
        $epsilon      = 0.001;
        $workdayHours = (float) self::WORKDAY_HOURS;

        foreach ($orderedTasks as $t) {
            $phases = $t['phases'];
            usort($phases, fn ($a, $b) => $a['order'] <=> $b['order']);

            $taskCursorDate = $windowStart->copy();

            foreach ($phases as $phase) {
                $assigneeId = $assigneeByRowPhase[$t['row_no']][$phase['code']] ?? null;
                if (! $assigneeId) {
                    continue;
                }

                $hours = max(0.0, (float) $phase['hours']);

                $assigneeCursor = $assigneeCursors[$assigneeId] ?? [
                    'date'       => $windowStart->copy(),
                    'hours_used' => 0.0,
                ];

                $rawDate = $taskCursorDate->greaterThan($assigneeCursor['date'])
                    ? $taskCursorDate->copy()
                    : $assigneeCursor['date']->copy();
                $candidate = $calendar->nextWorkingDay($rawDate, $assigneeId);
                if ($candidate->greaterThan($effectiveEnd)) {
                    $candidate = $effectiveEnd->copy();
                }

                // Skip past days the assignee has fully booked (cursor parked
                // on a day with hours_used == 8 after a previous placement).
                $safety = 0;
                while ($safety++ < 365) {
                    $assigneeUsedToday = $candidate->equalTo($assigneeCursor['date'])
                        ? $assigneeCursor['hours_used']
                        : 0.0;
                    if ($workdayHours - $assigneeUsedToday > $epsilon) {
                        break;
                    }
                    $next = $calendar->nextWorkingDay($candidate->copy()->addDay(), $assigneeId);
                    if ($next->greaterThan($effectiveEnd)) {
                        $candidate = $effectiveEnd->copy();
                        break;
                    }
                    $candidate = $next;
                }

                $assigneeUsedToday = $candidate->equalTo($assigneeCursor['date'])
                    ? $assigneeCursor['hours_used']
                    : 0.0;
                $remainingToday = max(0.0, $workdayHours - $assigneeUsedToday);

                if ($hours <= $remainingToday + $epsilon || $hours <= $epsilon) {
                    // Atomic same-day placement — fits in today's budget.
                    $start                = $candidate->copy();
                    $end                  = $candidate->copy();
                    $startDayHours        = $hours;
                    $newAssigneeHoursUsed = $assigneeUsedToday + $hours;
                } else {
                    // Split across days. Day 1 gets today's remainder; the rest
                    // spills across full 8h middle days plus a remainder on
                    // the last day.
                    $start         = $candidate->copy();
                    $startDayHours = $remainingToday;

                    $leftover       = $hours - $remainingToday;
                    $fullMiddleDays = (int) floor(($leftover + $epsilon) / $workdayHours);
                    $lastDayHours   = $leftover - ($fullMiddleDays * $workdayHours);
                    if ($lastDayHours <= $epsilon) {
                        $lastDayHours = 0.0;
                    }

                    $extraDays = $fullMiddleDays + ($lastDayHours > 0 ? 1 : 0);
                    $end = $extraDays > 0
                        ? $calendar->addWorkingDays($start, $extraDays, $assigneeId)
                        : $start->copy();

                    if ($end->greaterThan($effectiveEnd)) {
                        $end = $effectiveEnd->copy();
                    }

                    // If the last day is a "full" day (remainder absorbed into
                    // middle days), mark cursor as fully used so the next phase
                    // rolls to the day after.
                    $newAssigneeHoursUsed = $lastDayHours > 0 ? $lastDayHours : $workdayHours;
                }

                if ($end->lessThan($start)) {
                    $end = $start->copy();
                }

                $result[$t['row_no']][$phase['code']] = [
                    'assignee_id'     => $assigneeId,
                    'planned_start'   => $start->toDateString(),
                    'planned_end'     => $end->toDateString(),
                    'start_day_hours' => round($startDayHours, 2),
                ];

                $assigneeCursors[$assigneeId] = [
                    'date'       => $end->copy(),
                    'hours_used' => $newAssigneeHoursUsed,
                ];
                $taskCursorDate = $end->copy();
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

            return ['tasks' => [], 'active_phases' => [], 'raw_markdown' => ''];
        }

        $spreadsheet = IOFactory::load($path);
        if (! in_array('Web_Manhour_Detail', $spreadsheet->getSheetNames(), true)) {
            Log::error('Web_Manhour_Detail sheet not found in Estimate.xlsx');

            return ['tasks' => [], 'active_phases' => [], 'raw_markdown' => ''];
        }

        $sheet = $spreadsheet->getSheetByName('Web_Manhour_Detail');
        $tasks = [];
        $rowNo = 0;

        $rawMarkdown = $this->renderSheetAsMarkdown($sheet);

        // Detect this file's actual phase layout from row 3 (phase headers) and
        // row 4 (per-column labels). Different projects ship different Estimate
        // files — phases present, their order, and the columns they occupy all
        // vary file-to-file. Static column maps would silently mis-label.
        $detection      = $this->detectPhasesFromSheet($sheet);
        $phaseDefs      = $detection['phase_defs'];
        $totalCol       = $detection['total_col'];      // 1-based, or null
        $highestColStr  = $sheet->getHighestColumn();

        // Data rows start at row 5. A=機能ID, B=機能名称, C=Status. Per-phase
        // hour columns and the Total column position are file-specific.
        foreach ($sheet->getRowIterator(5) as $row) {
            $rowIndex = $row->getRowIndex();
            $cells = $sheet->rangeToArray(
                'A'.$rowIndex.':'.$highestColStr.$rowIndex,
                null,
                true,
                false
            )[0] ?? [];

            $functionId   = isset($cells[0]) ? (is_string($cells[0]) ? trim($cells[0]) : $cells[0]) : null;
            $functionName = isset($cells[1]) ? (is_string($cells[1]) ? trim($cells[1]) : $cells[1]) : null;
            $difficulty   = isset($cells[2]) ? (is_string($cells[2]) ? trim($cells[2]) : $cells[2]) : null;
            $totalHours   = ($totalCol !== null && isset($cells[$totalCol - 1])) ? $cells[$totalCol - 1] : null;

            if (! $functionName) {
                continue;
            }
            // Skip summary / total / team-composition rows that sit at the
            // bottom of the sheet (Leader | 1 | 8.361h, Developer | 3 | 51.9h,
            // 1人(Hr) / 1人(Days) / Months totals, etc). Two complementary signals:
            //  - function_name purely numeric → it's a headcount in col 2,
            //    not a feature name. Real features are descriptive text.
            //  - function_id matches team-composition / unit-total keywords.
            $fnStr  = (string) $functionName;
            $fidStr = (string) ($functionId ?? '');
            if (preg_match('/^\s*\d+(\.\d+)?\s*$/', $fnStr)) {
                continue;
            }
            if ($fidStr !== '' && preg_match('/(Leader|Developer|UIUX|1人|2人|3人|\(Hr\)|\(Days\)|\(Months\)|Total)/u', $fidStr)) {
                continue;
            }
            if (! in_array($difficulty, ['簡単', '普通', '難しい'], true)) {
                $difficulty = '普通';
            }

            $phases = [];
            foreach ($phaseDefs as $phase) {
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

        // Surface every phase declared in the file's row 3 — even ones with
        // zero hours across all tasks. PMs want to see "this phase exists in
        // the template but has no work yet" rather than have it silently
        // hidden. Per-task phase rows (above) are still filtered to non-zero
        // entries so empty cells render as blank in the UI grid.
        $activePhases = [];
        foreach ($phaseDefs as $phase) {
            $activePhases[] = [
                'code'         => $phase['code'],
                'name'         => $phase['name'],
                'order'        => $phase['order'],
                'is_execution' => $phase['is_execution'],
            ];
        }

        return ['tasks' => $tasks, 'active_phases' => $activePhases, 'raw_markdown' => $rawMarkdown];
    }

    /**
     * Inspect a Web_Manhour_Detail sheet and return the phase column layout it
     * actually uses. Each Estimate.xlsx may ship a different phase subset; we
     * read the truth from the file rather than a hard-coded constant.
     *
     * Detection rules:
     *  - Row 3 holds phase header labels (Japanese, optionally with English on
     *    a second line). We match the first line against PHASE_CATALOG.
     *  - Each phase's column range runs from its row-3 header column up to the
     *    column before the next row-3 header (or the column before Total for
     *    the last phase), excluding tail-overhead columns (Risk / Management /
     *    Test Data / Manual) identified via row-4 NON_PHASE_LABEL_KEYWORDS.
     *  - Development has no row-3 header and is always at cols 4–5, recognised
     *    by row-4 label "Develop" or "開発工数".
     *  - Total column is detected from row 4 (cell containing "Total").
     *
     * @return array{phase_defs: array<int, array{code: string, name: string, cols: array<int, int>, order: int, is_execution: bool}>, total_col: ?int}
     */
    private function detectPhasesFromSheet(Worksheet $sheet): array
    {
        $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        // 1. Find Total column from row 4.
        $totalCol = null;
        for ($c = 1; $c <= $highestColIndex; $c++) {
            $v = (string) $sheet->getCell([$c, 4])->getValue();
            if (stripos($v, 'Total') !== false) {
                $totalCol = $c;
                break;
            }
        }
        $effectiveLastCol = $totalCol ? $totalCol - 1 : $highestColIndex;

        // 2. Walk row 3 left-to-right collecting (col → catalog meta) for each
        //    Japanese phase label we recognise.
        $phaseStarts = [];
        for ($c = 1; $c <= $effectiveLastCol; $c++) {
            $cellVal = trim((string) $sheet->getCell([$c, 3])->getValue());
            if ($cellVal === '') {
                continue;
            }
            $firstLine = trim(strtok($cellVal, "\n"));
            if (isset(self::PHASE_CATALOG[$firstLine])) {
                $phaseStarts[$c] = [
                    'label' => $firstLine,
                    'meta'  => self::PHASE_CATALOG[$firstLine],
                ];
            }
        }
        ksort($phaseStarts);
        $phaseStartCols = array_keys($phaseStarts);

        // 3. Compute each phase's column range, stopping at tail-overhead labels.
        $phaseDefs = [];
        foreach ($phaseStartCols as $i => $startCol) {
            $boundaryCol = isset($phaseStartCols[$i + 1])
                ? $phaseStartCols[$i + 1] - 1
                : $effectiveLastCol;

            $cols = [];
            for ($c = $startCol; $c <= $boundaryCol; $c++) {
                $row4Label = (string) $sheet->getCell([$c, 4])->getValue();
                if ($this->isNonPhaseColumn($row4Label)) {
                    break;
                }
                $cols[] = $c;
            }

            $meta = $phaseStarts[$startCol]['meta'];
            $phaseDefs[] = [
                'code'         => $meta['code'],
                'name'         => $phaseStarts[$startCol]['label'],
                'cols'         => $cols,
                'order'        => $meta['order'],
                'is_execution' => $meta['is_execution'],
            ];
        }

        // 4. Always inject Development at cols 4–5 when its row-4 label
        //    confirms presence.
        $devHeader = (string) $sheet->getCell([4, 4])->getValue();
        if (stripos($devHeader, 'Develop') !== false || str_contains($devHeader, '開発工数')) {
            $phaseDefs[] = [
                'code'         => 'development',
                'name'         => 'Development',
                'cols'         => [4, 5],
                'order'        => 5,
                'is_execution' => true,
            ];
        }

        usort($phaseDefs, fn ($a, $b) => $a['order'] <=> $b['order']);

        return ['phase_defs' => $phaseDefs, 'total_col' => $totalCol];
    }

    private function isNonPhaseColumn(string $row4Label): bool
    {
        $label = trim($row4Label);
        if ($label === '') {
            return false;
        }
        foreach (self::NON_PHASE_LABEL_KEYWORDS as $kw) {
            if (str_contains($label, $kw)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render the Web_Manhour_Detail sheet (cols A..AE) as a markdown table so
     * Claude can read the source xlsx directly — not just our parsed JSON
     * summary. Row 1 becomes the header; data starts at row 5 (rows 2-4 are
     * sub-headers/spacers we skip). Capped at 150 data rows to bound prompt size.
     */
    private function renderSheetAsMarkdown(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $maxDataRows = 150): string
    {
        $highestRow = $sheet->getHighestRow();
        if ($highestRow < 1) {
            return '';
        }

        $headerCells = $sheet->rangeToArray('A1:AE1', null, true, false)[0] ?? [];
        $headers = array_map(
            fn ($c) => $this->mdEscape($c),
            $headerCells,
        );
        // Default placeholder for empty header cells so the table stays well-formed.
        $headers = array_map(fn ($h, $i) => $h !== '' ? $h : 'Col'.($i + 1), $headers, array_keys($headers));

        $lines   = [];
        $lines[] = '| '.implode(' | ', $headers).' |';
        $lines[] = '|'.str_repeat(' --- |', count($headers));

        $dataStart = 5;
        $dataEnd   = min($highestRow, $dataStart + $maxDataRows - 1);
        $included  = 0;
        $skipped   = 0;

        for ($r = $dataStart; $r <= $dataEnd; $r++) {
            $cells = $sheet->rangeToArray('A'.$r.':AE'.$r, null, true, false)[0] ?? [];
            // Skip fully-empty rows so the table doesn't fill with noise.
            $isEmpty = true;
            foreach ($cells as $c) {
                if ($c !== null && $c !== '') {
                    $isEmpty = false;
                    break;
                }
            }
            if ($isEmpty) {
                $skipped++;
                continue;
            }
            $lines[] = '| '.implode(' | ', array_map(fn ($c) => $this->mdEscape($c), $cells)).' |';
            $included++;
        }

        if ($highestRow > $dataEnd) {
            $remaining = $highestRow - $dataEnd;
            $lines[] = "_… {$remaining} more row(s) truncated to keep prompt size bounded; the PARSED TASKS JSON below contains every non-empty row._";
        }

        return implode("\n", $lines);
    }

    private function mdEscape($value): string
    {
        if ($value === null) {
            return '';
        }
        $s = is_string($value) ? $value : (string) $value;
        // Pipes break markdown table cells; newlines collapse rows.
        $s = str_replace(['|', "\r\n", "\n", "\r"], ['\\|', ' ', ' ', ' '], $s);

        return trim($s);
    }

    private function buildAssignTasksPrompt(
        Project $project,
        array $tasks,
        array $activePhases,
        array $teamMembers,
        Carbon $windowStart,
        Carbon $effectiveEnd,
        array $dbHolidays = [],
        string $rawSheet = ''
    ): string {
        $projectName = $project->name;
        $client      = $project->client ?? 'N/A';
        $startStr    = $windowStart->toDateString();
        $endStr      = $effectiveEnd->toDateString();
        $buffer      = self::PROJECT_END_BUFFER_DAYS;
        $rawEnd      = $project->effectiveEndDate()->toDateString();
        $defaultHpd  = self::WORKDAY_HOURS;
        $teamSize    = count($teamMembers);

        $rawSheetBlock = $rawSheet !== ''
            ? "RAW SHEET CONTENT (your direct view of Estimate.xlsx, sheet `Web_Manhour_Detail` — sanity-check the PARSED TASKS array below against this. If you notice something the parse missed, prefer what's in the sheet):\n\n".$rawSheet."\n\n"
            : '';

        return "Project: {$projectName}\n".
            "Client: {$client}\n".
            "Project window: {$startStr} → {$rawEnd} (raw end). All work must finish on or before {$endStr} ({$buffer}-day buffer before the raw end).\n\n".
            "ACTIVE PHASES (phases that have estimated hours in this project):\n".
            $this->jsonEncode($activePhases)."\n\n".
            "TEAM MEMBERS (existing project team — assign only from this list. Team size: {$teamSize}):\n".
            $this->jsonEncode($teamMembers)."\n\n".
            $rawSheetBlock.
            "PARSED TASKS (extracted from the sheet above; phases array carries per-phase estimated hours and ordering):\n".
            $this->jsonEncode($tasks)."\n\n".
            "DB-CONFIRMED HOLIDAYS (always non-working — your `calendar` field can ADD more but cannot remove these):\n".
            $this->jsonEncode($dbHolidays)."\n\n".
            "You are the delivery manager. Schedule EVERY (task, phase) pair: pick the right person, pick the planned_start and planned_end dates, and tell us about any holidays or capacity constraints we should know about.\n\n".
            "OUTPUT SCHEMA — return ONLY this JSON object (no markdown fences, no commentary, no extra keys):\n".
            "{\n".
            "  \"calendar\": {\n".
            "    \"skip_weekends\": true,\n".
            "    \"recurring_holidays\": [{\"month\": 1, \"day\": 1, \"reason\": \"New Year\"}],\n".
            "    \"blocked_dates\":      [{\"date\": \"YYYY-MM-DD\", \"reason\": \"Memorial Day\"}]\n".
            "  },\n".
            "  \"capacity\": [\n".
            "    {\"employee_id\": \"<uuid>\", \"hours_per_day\": 4, \"reason\": \"shared with Project Y\"}\n".
            "  ],\n".
            "  \"assignments\": [\n".
            "    {\"row_no\": <int>, \"phase_code\": \"<string>\", \"assignee_id\": \"<uuid from TEAM MEMBERS>\",\n".
            "     \"planned_start\": \"YYYY-MM-DD\", \"planned_end\": \"YYYY-MM-DD\"}\n".
            "  ]\n".
            "}\n\n".
            "ASSIGNEE RULES (who):\n".
            "- DESIGN phases (phase_code in {requirement, system_arch, basic_doc, detail_doc}): pick rank_code='Lead'. Fallback: Senior → Mid → any.\n".
            "- EXECUTION phases (phase_code in {development, unit_test, combine_test, system_test}): map by the TASK's difficulty — 簡単→'Junior', 普通→'Mid', 難しい→'Senior'. Fallback chain: 簡単→Mid→Senior→Lead, 普通→Senior→Junior→Lead, 難しい→Lead→Mid→Junior.\n".
            "- When multiple team members qualify for a phase, rotate through them — don't pile everything on one person.\n\n".
            "PARALLELISM REQUIREMENT — CRITICAL:\n".
            "- The team has {$teamSize} engineers. On most working days in the project window, MULTIPLE engineers should have active work simultaneously. A schedule where only 1 person is active on most days is a FAILURE.\n".
            "- Different TASKS are INDEPENDENT — they can (and should) run in parallel across different engineers.\n".
            "- WITHIN a single task, phases run sequentially (requirement → ... → system_test) because each phase depends on the previous one.\n".
            "- ACROSS tasks, hand off as soon as a phase completes — DO NOT wait for all design to finish before any development starts. Example: while Lead Alice is doing Task 5's detail_doc, Dev Bob should already be developing Task 1 (whose detail_doc finished earlier), Dev Carol should be unit-testing Task 2, etc.\n".
            "- Anti-pattern to AVOID: serializing all design phases of all tasks on the Lead before any execution work begins. Instead, after the Lead finishes Task 1's detail_doc, Task 1 immediately enters Development (different person) while the Lead moves to Task 2's requirement.\n".
            "- Concretely: pick mid-window date {$startStr} + (window_days / 2). On that date, ideally at least min({$teamSize}, count_of_in_flight_tasks) engineers should have a phase that overlaps that date. If you find your schedule has fewer, you're serializing too much — interleave tasks.\n\n".
            "DATE RULES (when):\n".
            "- All planned_start and planned_end values MUST fall within [{$startStr}, {$endStr}] inclusive.\n".
            "- Default capacity is {$defaultHpd} hours per day per engineer. Use the `capacity` array to override an individual when you have a reason (e.g. shared with another project).\n".
            "- For each phase, planned_end − planned_start should give approximately ceil(estimated_hours / hours_per_day) WORKING days (weekends + holidays excluded). The backend tolerates ±50% but stay close to the estimate.\n".
            "- Within a single task, phases run head-to-tail in `order` ascending: 要件定義(1) → 基本全体設計(2) → 基本設計(3) → 詳細設計(4) → Development(5) → 単体テスト(6) → 結合テスト(7) → 総合テスト(8). A phase's planned_start must be ≥ the previous phase's planned_start in the same task.\n".
            "- A single assignee cannot have overlapping date ranges across phases. If Sam owns row 1 phase A from May 15..18, Sam's next phase (any task) must start May 19 or later.\n".
            "- planned_start and planned_end must both be working days (skip Saturdays, Sundays, and any blocked date listed in DB-CONFIRMED HOLIDAYS or in the `calendar` you return).\n\n".
            "CALENDAR FIELD:\n".
            "- `recurring_holidays`: month/day entries that repeat every year (e.g. New Year, national days). The DB list above is the existing set — only add holidays that AREN'T already covered.\n".
            "- `blocked_dates`: one-off ISO dates (national observances tied to a specific year, client closures, etc.). Reason is optional but helpful.\n".
            "- Leave the arrays empty if you have nothing to add. Never remove or override DB-confirmed holidays.\n\n".
            "CAPACITY FIELD:\n".
            "- Optional. Include an entry only when you intentionally want hours_per_day < {$defaultHpd} for someone (or > if surge capacity makes sense). Otherwise omit them and they default to {$defaultHpd}.\n\n".
            "Final reminders:\n".
            "- Every (row_no × phase_code) appearing in PARSED TASKS must appear in `assignments`. No duplicates.\n".
            "- assignee_id must be one of the uuids in TEAM MEMBERS.\n".
            "- All dates ISO format `YYYY-MM-DD`.\n".
            "- Maximize parallelism — multiple engineers active on most working days.\n".
            "- Return ONLY the JSON object — no prose, no fences.";
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
                        'start_day_hours'    => is_array($entry) ? ($entry['start_day_hours'] ?? null) : null,
                        'assignee_id'        => is_array($entry) ? ($entry['assignee_id']     ?? null) : $entry,
                        'planned_start'      => is_array($entry) ? ($entry['planned_start']   ?? null) : null,
                        'planned_end'        => is_array($entry) ? ($entry['planned_end']     ?? null) : null,
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
        Carbon $effectiveEnd,
        WorkingDayCalendar $calendar
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

        $assignments = $this->computePlannedDates($tasks, $assigneeByRowPhase, $windowStart, $effectiveEnd, $calendar);

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

        // Also surface phases that are declared in the Estimate.xlsx's row 3
        // headers but have zero hours across all tasks (so no DB rows exist
        // for them). PMs want to see "this phase exists in the template but
        // has no work yet" rather than have the column silently hidden.
        // Cheap: one xlsx read; can move to a per-project cache later.
        try {
            $declared = $this->readEstimateSheet()['active_phases'] ?? [];
            foreach ($declared as $p) {
                if (! isset($byCode[$p['code']])) {
                    $byCode[$p['code']] = [
                        'code'  => $p['code'],
                        'name'  => $p['name'],
                        'order' => (int) $p['order'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Missing / unreadable file → fall back to DB-only phases.
            Log::warning('activePhasesFromRows: could not read estimate for phase merge: '.$e->getMessage());
        }

        $active = array_values($byCode);
        usort($active, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $active;
    }
}
