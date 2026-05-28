<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidAiScheduleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectTaskAssignmentResource;
use App\Http\Resources\ProjectTaskPhaseAssignmentResource;
use App\Http\Resources\ProjectTeamAssignmentResource;
use App\Models\AiUsageLog;
use App\Models\DealGhostRole;
use App\Models\Employee;
use App\Models\EstimationVersion;
use App\Models\Holiday;
use App\Models\Project;
use App\Models\ProjectTaskAssignment;
use App\Models\ProjectTaskPhaseAssignment;
use App\Models\ProjectTeamAssignment;
use App\Services\Ai\AiTeamPlanValidator;
use App\Services\EmployeeCapacityService;
use App\Services\EstimateFileResolver;
use App\Services\EstimationXlsxService;
use App\Services\Scheduling\AiSchedulePayload;
use App\Services\Scheduling\AiScheduleValidator;
use App\Services\Scheduling\CalendarFactory;
use App\Services\Scheduling\PhaseReassignmentService;
use App\Services\Scheduling\WorkingDayCalendar;
use App\Support\EngagementWindow;
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

            if (isset($body['usage'])) {
                $this->logUsage($tenantId, $body['usage'], 'auto_assign', $model);
            }

            $text = $body['content'][0]['text'] ?? '';

            $text = trim($text);
            if (str_starts_with($text, '```')) {
                $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
                $text = preg_replace('/\s*```$/', '', $text);
            }
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
            $text = str_replace(["\t", "\r", "\n"], ' ', $text);

            $assignments = json_decode($text, true);

            if (! is_array($assignments)) {
                Log::error('AI AutoAssign: invalid JSON response, falling back to demo mode', ['text' => substr($text, 0, 300)]);

                return $this->demoAutoAssign($project, $deal, $employees, $tenantId);
            }

            $teamEndDate = EngagementWindow::computeEndDate(null, null, $project->loadMissing('contract.deal'));

            DB::transaction(function () use ($assignments, $project, $tenantId, $teamEndDate) {
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
                        'team_end_date' => $teamEndDate?->toDateString(),
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

        $teamEndDate = EngagementWindow::computeEndDate(null, null, $project->loadMissing('contract.deal'));

        DB::transaction(function () use ($assignments, $project, $tenantId, $teamEndDate) {
            ProjectTeamAssignment::where('project_id', $project->id)->delete();

            foreach ($assignments as $item) {
                ProjectTeamAssignment::create([
                    'tenant_id' => $tenantId,
                    'project_id' => $project->id,
                    'employee_id' => $item['employee_id'],
                    'allocated_hours' => $item['allocated_hours'],
                    'team_end_date' => $teamEndDate?->toDateString(),
                    'assignment_source' => 'ai',
                ]);
            }
        });

        $project->load('teamAssignments.employee');

        return ProjectTeamAssignmentResource::collection($project->teamAssignments);
    }

    public function index(Project $project)
    {
        $project->load('teamAssignments.employee.department', 'teamAssignments.employee.rank');

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

        $teamEndDate = EngagementWindow::computeEndDate(null, null, $project->loadMissing('contract.deal'));

        $assignment = ProjectTeamAssignment::create([
            'tenant_id' => $tenantId,
            'project_id' => $project->id,
            'employee_id' => $request->input('employee_id'),
            'allocated_hours' => $request->input('allocated_hours'),
            'team_end_date' => $teamEndDate?->toDateString(),
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

    /**
     * "Idle" employee pool — active full-timers (≥160h) who have zero rows in
     * project_team_assignments across the whole tenant. The Team Preview
     * dialog uses this to populate manual replacement / add dropdowns so the
     * user can override AI picks without accidentally staffing someone who is
     * already committed elsewhere. Shape matches the `proposed` row payload
     * from planTeamPreview so the frontend can reuse the same display fields.
     */
    public function availableEmployees(Project $project)
    {
        $project->loadMissing('contract.deal');

        // Mirror planTeamPreview's window source: explicit team_start_date
        // from the latest estimation version's sheet_team_structure wins if
        // present, otherwise fall back to the project's own dates.
        $deal = $project->contract?->deal;
        $teamStructure = $deal ? $this->resolveSheetTeamStructure($deal) : null;
        $explicitStart = $teamStructure['start_date'] ?? null;
        [$start, $end] = EngagementWindow::windowFor($project, $explicitStart);

        $employees = Employee::with(['rank', 'capacityRole'])
            ->idleForRange($start, $end)
            ->orderBy('name')
            ->get();

        return [
            'data' => $employees->map(fn (Employee $e) => [
                'employee_id' => $e->id,
                'name' => $e->name,
                'rank_code' => optional($e->rank)->code,
                'rank_name' => optional($e->rank)->name,
                'capacity_role' => optional($e->capacityRole)->code ?? $e->capacity_role,
                'workable_hours' => (float) $e->workable_hours,
                'monthly_salary' => (float) $e->monthly_salary,
            ])->values(),
        ];
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

    /**
     * Number of distinct calendar months touched by [start, end], inclusive.
     * Used to size monthly_allocation arrays to a project window.
     * Example: 2026-06-15 to 2026-08-03 → 3 (Jun, Jul, Aug).
     */
    private function monthsBetweenInclusive(Carbon $start, Carbon $end): int
    {
        if ($end->lessThan($start)) {
            return 0;
        }
        $startIndex = $start->year * 12 + ($start->month - 1);
        $endIndex = $end->year * 12 + ($end->month - 1);

        return $endIndex - $startIndex + 1;
    }

    // ── Team build preview + confirm (new business flow) ──────────────────────

    /**
     * Preview: ask the AI to fill the project's planned team structure
     * (deal_ghost_roles) from the tenant's available employees, keeping
     * any members already on `project_team_assignments` intact.
     * Returns { kept, proposed, unfilled, roles_to_fill } — NO DB writes.
     */
    public function planTeamPreview(Project $project)
    {
        $tenantId = app('tenant_id');

        $project->load([
            'contract.deal.ghost_roles.rank',
            'teamAssignments.employee.rank',
            'teamAssignments.employee.capacityRole',
        ]);

        $deal = $project->contract?->deal;
        if (! $deal) {
            return response()->json([
                'error' => 'Project has no originating deal; cannot infer planned team structure.',
            ], 422);
        }

        // Try sheet_team_structure from the latest estimation version first;
        // fall back to deal_ghost_roles for backward compatibility.
        $teamStructure = $this->resolveSheetTeamStructure($deal);
        $usingSheetStructure = $teamStructure !== null;

        if ($usingSheetStructure) {
            $slots = $this->buildSlotsFromSheetStructure($teamStructure);
        } else {
            $ghostRoles = $deal->ghost_roles ?? collect();
            if ($ghostRoles->isEmpty()) {
                return response()->json([
                    'error' => 'Originating deal has no planned roles (deal_ghost_roles) or sheet_team_structure. Populate planned team structure first.',
                ], 422);
            }
            $slots = $this->buildSlotsFromGhostRoles($ghostRoles);
        }

        $teamStartDate = $teamStructure['start_date'] ?? null;

        // Group slots by eligible role category for kept-member pairing.
        // Slots with a specific preferred_capacity_role (e.g. "backend") are
        // indexed under that role AND under the broad category ("member").
        $slotsByCategory = [];
        foreach ($slots as $slot) {
            $cat = $slot['role_category'];
            $slotsByCategory[$cat][] = $slot;
        }

        // "Kept" — everyone already on the team. Pair each with the next
        // available slot of matching role category. Try exact role first
        // (e.g. "backend"), then fall back to broad category ("member").
        $kept = [];
        foreach ($project->teamAssignments as $a) {
            $emp = $a->employee;
            $capRole = optional(optional($emp)->capacityRole)->code ?? optional($emp)->capacity_role;
            $cat = $this->capacityRoleToCategory($capRole);

            $matched = null;
            // Try exact capacity_role first (e.g. "backend" slot for backend employee).
            if ($capRole && ! empty($slotsByCategory[$capRole])) {
                $matched = array_shift($slotsByCategory[$capRole]);
            }
            // Fall back to broad category (e.g. "member" slot for backend employee).
            if (! $matched && $cat && ! empty($slotsByCategory[$cat])) {
                $matched = array_shift($slotsByCategory[$cat]);
            }

            $months = (int) ($matched['months'] ?? 0);
            $monthlyAlloc = $matched['monthly_allocation'] ?? null;
            $allocHours = $monthlyAlloc
                ? round(array_sum($monthlyAlloc) * ((float) ($emp->workable_hours ?? 160)), 2)
                : ($emp && $months > 0 ? $this->engagementAvailableHours($emp, $project, $months) : 0.0);

            $kept[] = [
                'employee_id' => $a->employee_id,
                'name' => optional($emp)->name,
                'rank_code' => optional(optional($emp)->rank)->code,
                'rank_name' => optional(optional($emp)->rank)->name,
                'capacity_role' => $capRole,
                'slot_id' => $matched['slot_id'] ?? null,
                'ghost_role_id' => $matched['ghost_role_id'] ?? null,
                'months' => $months,
                'monthly_allocation' => $monthlyAlloc,
                'team_start_date' => $teamStartDate,
                'allocated_hours' => $allocHours,
                'unmatched' => $matched === null,
            ];
        }

        $keptEmployeeIds = array_map(fn ($k) => $k['employee_id'], $kept);

        // Remaining slots → "roles to fill". Each unfilled slot is one row
        // for the AI (no grouping by quantity — each slot is individual).
        $rolesToFill = [];
        foreach ($slotsByCategory as $slots) {
            foreach ($slots as $slot) {
                $rolesToFill[] = $slot + ['quantity_needed' => 1];
            }
        }

        if (empty($rolesToFill)) {
            return response()->json([
                'kept' => $kept,
                'proposed' => [],
                'unfilled' => [],
                'roles_to_fill' => [],
                'team_start_date' => $teamStartDate,
                'message' => 'Planned team structure is already fully staffed; nothing to add.',
            ]);
        }

        [$windowStart, $windowEnd] = EngagementWindow::windowFor($project, $teamStartDate);

        $eligible = Employee::with(['rank', 'capacityRole'])
            ->idleForRange($windowStart, $windowEnd)
            ->whereNotIn('id', $keptEmployeeIds)
            ->get();

        $employeePool = $eligible->map(fn ($emp) => [
            'employee_id' => $emp->id,
            'name' => $emp->name,
            'rank_code' => optional($emp->rank)->code,
            'rank_name' => optional($emp->rank)->name,
            'capacity_role' => optional($emp->capacityRole)->code ?? $emp->capacity_role,
            'workable_hours' => (float) $emp->workable_hours,
            'monthly_salary' => (float) $emp->monthly_salary,
        ])->values()->all();

        // Build indexes for the validator.
        $slotIndex = [];
        foreach ($rolesToFill as $r) {
            $id = $r['slot_id'] ?? $r['ghost_role_id'];
            $slotIndex[$id] = [
                'role_type' => $r['eligible_roles'][0] ?? $r['role_category'],
                'rank_code' => $r['rank_code'] ?? null,
                'quantity' => $r['quantity_needed'],
            ];
        }
        $employeeIndex = [];
        foreach ($employeePool as $e) {
            $employeeIndex[$e['employee_id']] = $e;
        }

        $apiKey = config('services.anthropic.api_key') ?? env('ANTHROPIC_API_KEY');
        $result = null;

        if ($apiKey) {
            try {
                $result = $this->callPlanTeamAi(
                    $apiKey,
                    $project,
                    $deal,
                    $kept,
                    $rolesToFill,
                    $employeePool,
                    $slotIndex,
                    $employeeIndex,
                    $keptEmployeeIds,
                );
            } catch (\Throwable $e) {
                Log::error('AI plan-team error, falling back to deterministic picker', ['message' => $e->getMessage()]);
            }
        }

        if (! $result) {
            $result = $this->demoPlanTeam($rolesToFill, $employeePool);
        }

        // Decorate picks with display fields.
        $slotLookup = [];
        foreach ($rolesToFill as $r) {
            $id = $r['slot_id'] ?? $r['ghost_role_id'];
            $slotLookup[$id] = $r;
        }

        $picksDecorated = [];
        foreach ($result['picks'] as $pick) {
            $emp = $employeeIndex[$pick['employee_id']] ?? null;
            $pickSlotId = $pick['slot_id'] ?? $pick['ghost_role_id'] ?? null;
            $slot = $slotLookup[$pickSlotId] ?? null;
            if (! $emp || ! $slot) {
                continue;
            }
            $months = max(1, (int) ($slot['months'] ?? 1));
            $workable = (float) ($emp['workable_hours'] ?? 160);
            $monthlyAlloc = $slot['monthly_allocation'] ?? null;
            $allocatedHours = $monthlyAlloc
                ? round(array_sum($monthlyAlloc) * $workable, 2)
                : $workable * $months;

            $picksDecorated[] = [
                'slot_id' => $pickSlotId,
                'ghost_role_id' => $slot['ghost_role_id'] ?? null,
                'employee_id' => $pick['employee_id'],
                'employee_name' => $emp['name'],
                'employee_rank' => $emp['rank_code'],
                'capacity_role' => $emp['capacity_role'],
                'role_type' => $slot['eligible_roles'][0] ?? $slot['role_category'],
                'needed_rank' => $slot['rank_code'] ?? null,
                'months' => $months,
                'monthly_allocation' => $monthlyAlloc,
                'team_start_date' => $teamStartDate,
                'monthly_salary' => (float) ($emp['monthly_salary'] ?? 0),
                'allocated_hours' => $allocatedHours,
                'rank_match' => $pick['rank_match'] ?? 'exact',
            ];
        }

        $capacityCheck = $usingSheetStructure
            ? $this->buildCapacityCheckFromSlots($kept, $picksDecorated, $slots)
            : $this->buildCapacityCheck($kept, $picksDecorated, $deal->ghost_roles ?? collect(), $employeeIndex);

        return response()->json([
            'kept' => $kept,
            'proposed' => $picksDecorated,
            'unfilled' => $result['unfilled'] ?? [],
            'roles_to_fill' => $rolesToFill,
            'team_start_date' => $teamStartDate,
            'capacity_check' => $capacityCheck,
        ]);
    }

    // ── Sheet team structure helpers ──────────────────────────────────

    private function resolveSheetTeamStructure($deal): ?array
    {
        $version = EstimationVersion::withoutGlobalScopes()
            ->where('deal_id', $deal->id)
            ->whereNotNull('sheet_team_structure')
            ->orderByDesc('version_number')
            ->orderByDesc('created_at')
            ->first();

        if (! $version) {
            return null;
        }

        $data = $version->sheet_team_structure;
        if (! is_array($data) || empty($data['members'])) {
            return null;
        }

        return $data;
    }

    private function buildSlotsFromSheetStructure(array $ts): array
    {
        $slots = [];
        $startDate = $ts['start_date'] ?? null;
        $visibleMonths = (int) ($ts['visible_months'] ?? 1);

        foreach ($ts['members'] as $idx => $member) {
            $roleType = $member['role_type'] ?? 'S Member';
            $isLeader = $roleType === 'Leader';
            $monthlyAlloc = $member['monthly_allocation'] ?? array_fill(0, $visibleMonths, 1);
            // Pad to visible_months if shorter (some members may have fewer entries).
            while (count($monthlyAlloc) < $visibleMonths) {
                $monthlyAlloc[] = 0;
            }
            $subtotal = round(array_sum($monthlyAlloc), 2);

            $preferred = $member['preferred_capacity_role'] ?? null;
            if ($isLeader) {
                $eligibleRoles = ['pm'];
            } elseif ($preferred && in_array($preferred, ['backend', 'frontend', 'qa', 'design'], true)) {
                $eligibleRoles = [$preferred];
            } else {
                $eligibleRoles = ['backend', 'frontend', 'qa', 'design'];
            }

            $slots[] = [
                'slot_id' => 'slot-'.$idx,
                'ghost_role_id' => null,
                'role_category' => $isLeader ? 'pm' : ($preferred ?? 'member'),
                'eligible_roles' => $eligibleRoles,
                'role_type_label' => $roleType,
                'preferred_capacity_role' => $preferred,
                'rank_code' => null,
                'rank_name' => null,
                'monthly_allocation' => $monthlyAlloc,
                'monthly_salary' => (float) ($member['monthly_salary'] ?? 0),
                'avg_salary' => (float) ($member['monthly_salary'] ?? 0),
                'subtotal' => $subtotal,
                'allocated_hours' => round($subtotal * 160, 2),
                'months' => $visibleMonths,
            ];
        }

        return $slots;
    }

    private function buildSlotsFromGhostRoles($ghostRoles): array
    {
        $slots = [];
        $idx = 0;
        foreach ($ghostRoles as $gr) {
            $months = (int) $gr->months;
            for ($i = 0; $i < (int) $gr->quantity; $i++) {
                $roleType = $gr->role_type;
                $slots[] = [
                    'slot_id' => $gr->id.'-'.$i,
                    'ghost_role_id' => $gr->id,
                    'role_category' => $this->capacityRoleToCategory($roleType) ?: $roleType,
                    'eligible_roles' => [$roleType],
                    'role_type_label' => $roleType,
                    'rank_code' => optional($gr->rank)->code,
                    'rank_name' => optional($gr->rank)->name,
                    'monthly_allocation' => array_fill(0, $months, 1.0),
                    'monthly_salary' => (float) $gr->avg_monthly_salary,
                    'avg_salary' => (float) $gr->avg_monthly_salary,
                    'subtotal' => (float) $months,
                    'allocated_hours' => 160.0 * $months,
                    'months' => $months,
                ];
                $idx++;
            }
        }

        return $slots;
    }

    private function capacityRoleToCategory(?string $capRole): ?string
    {
        if (! $capRole) {
            return null;
        }
        if ($capRole === 'pm') {
            return 'pm';
        }
        if (in_array($capRole, ['backend', 'frontend', 'qa', 'design'], true)) {
            return 'member';
        }

        return $capRole;
    }

    /**
     * Per-role capacity arithmetic surfaced in the plan-team response so the
     * TeamPreviewDialog can warn about over/under-staffed pools and budget
     * overruns before the user confirms.
     *
     * For each role_type in deal_ghost_roles, compute:
     *   - hours_budget = sum(quantity × months × 160) across ghost-role rows
     *   - hours_supply = sum(workable_hours × months) for kept + proposed picks in this role_type
     *     (kept members don't have a months attribute; we assume project timeline for them)
     *   - cost_budget  = sum(avg_monthly_salary × months × quantity) — the role's estimated cost envelope
     *   - cost_used    = sum(employee.monthly_salary × months) for proposed picks (kept members excluded —
     *     their salary may have changed since they were originally assigned, and we don't reassign cost retroactively)
     */
    private function buildCapacityCheck(array $kept, array $proposed, $ghostRoles, array $employeeIndex): array
    {
        $byRole = [];

        foreach ($ghostRoles as $gr) {
            $type = $gr->role_type;
            $months = max(1, (int) $gr->months);
            $qty = max(1, (int) $gr->quantity);
            $avgSalary = (float) $gr->avg_monthly_salary;

            if (! isset($byRole[$type])) {
                $byRole[$type] = [
                    'role_type' => $type,
                    'quantity' => 0,
                    'months_max' => 0,
                    'hours_budget' => 0.0,
                    'cost_budget' => 0.0,
                    'hours_supply' => 0.0,
                    'cost_used' => 0.0,
                ];
            }
            $byRole[$type]['quantity'] += $qty;
            $byRole[$type]['months_max'] = max($byRole[$type]['months_max'], $months);
            $byRole[$type]['hours_budget'] += $qty * $months * 160;
            $byRole[$type]['cost_budget'] += $qty * $months * $avgSalary;
        }

        foreach ($proposed as $p) {
            $type = $p['role_type'] ?? null;
            if (! $type || ! isset($byRole[$type])) {
                continue;
            }
            $months = max(1, (int) ($p['months'] ?? 1));
            $byRole[$type]['hours_supply'] += (float) $p['allocated_hours'];
            $byRole[$type]['cost_used'] += (float) $p['monthly_salary'] * $months;
        }

        foreach ($kept as $k) {
            $type = $k['capacity_role'] ?? null;
            if (! $type || ! isset($byRole[$type])) {
                continue;
            }
            $byRole[$type]['hours_supply'] += (float) $k['allocated_hours'];
        }

        foreach ($byRole as &$row) {
            $row['hours_shortfall'] = max(0.0, $row['hours_budget'] - $row['hours_supply']);
            $row['cost_overrun'] = max(0.0, $row['cost_used'] - $row['cost_budget']);
        }

        return array_values($byRole);
    }

    private function buildCapacityCheckFromSlots(array $kept, array $proposed, array $allSlots): array
    {
        $byCategory = [];
        foreach ($allSlots as $slot) {
            $cat = $slot['role_category'];
            if (! isset($byCategory[$cat])) {
                $byCategory[$cat] = [
                    'role_type' => $cat,
                    'quantity' => 0,
                    'hours_budget' => 0.0,
                    'cost_budget' => 0.0,
                    'hours_supply' => 0.0,
                    'cost_used' => 0.0,
                ];
            }
            $byCategory[$cat]['quantity']++;
            $byCategory[$cat]['hours_budget'] += (float) $slot['allocated_hours'];
            $byCategory[$cat]['cost_budget'] += (float) ($slot['monthly_salary'] ?? 0) * (float) ($slot['subtotal'] ?? $slot['months'] ?? 1);
        }

        foreach ($proposed as $p) {
            $cat = $this->capacityRoleToCategory($p['capacity_role'] ?? null) ?? ($p['role_type'] ?? null);
            if (! $cat || ! isset($byCategory[$cat])) {
                continue;
            }
            $byCategory[$cat]['hours_supply'] += (float) $p['allocated_hours'];
            $byCategory[$cat]['cost_used'] += (float) ($p['monthly_salary'] ?? 0) * (float) ($p['months'] ?? 1);
        }

        foreach ($kept as $k) {
            $cat = $this->capacityRoleToCategory($k['capacity_role'] ?? null);
            if (! $cat || ! isset($byCategory[$cat])) {
                continue;
            }
            $byCategory[$cat]['hours_supply'] += (float) $k['allocated_hours'];
        }

        foreach ($byCategory as &$row) {
            $row['hours_shortfall'] = max(0.0, $row['hours_budget'] - $row['hours_supply']);
            $row['cost_overrun'] = max(0.0, $row['cost_used'] - $row['cost_budget']);
        }

        return array_values($byCategory);
    }

    /**
     * Confirm a previously-previewed team plan. Inserts new picks AND
     * rewrites existing kept rows' allocated_hours so the whole team reflects
     * the current ghost-role months × workable_hours math. Existing rows are
     * never deleted; only allocated_hours is updated.
     */
    public function confirmTeamPlan(Request $request, Project $project)
    {
        $tenantId = app('tenant_id');

        $validated = $request->validate([
            'picks' => 'present|array',
            'picks.*.employee_id' => 'required|uuid|exists:employees,id',
            'picks.*.ghost_role_id' => 'nullable|uuid',
            'picks.*.slot_id' => 'nullable|string',
            'picks.*.allocated_hours' => 'nullable|numeric|min:0|max:10000',
            'picks.*.monthly_allocation' => 'nullable|array',
            'picks.*.monthly_allocation.*' => 'numeric|min:0|max:1',
            'team_start_date' => 'nullable|date',
        ]);

        // Build the same ghost-role slot list plan-team used, so kept members
        // pair against ghost roles the same way at confirm time as at preview
        // time. Proposed picks consume their own ghost_role_id directly; only
        // the kept rows need slot pairing.
        $project->load(['contract.deal.ghost_roles', 'teamAssignments.employee']);

        // Resolve the target project's engagement window up front — both the
        // race guard below AND the idle-pool scope at preview time use the
        // same window, so they stay consistent: an employee blocked here is
        // an employee the preview hid.
        $raceGuardDeal = $project->contract?->deal;
        $raceGuardStructure = $raceGuardDeal ? $this->resolveSheetTeamStructure($raceGuardDeal) : null;
        $raceGuardExplicitStart = $validated['team_start_date']
            ?? ($raceGuardStructure['start_date'] ?? null);
        [$raceStart, $raceEnd] = EngagementWindow::windowFor($project, $raceGuardExplicitStart);

        // Race guard — between preview render and confirm submit, another
        // tab or user could have staffed one of the picked employees on a
        // different project whose window OVERLAPS ours. The dialog is
        // otherwise unaware. Reject with 422 listing the conflicting names
        // so the user knows what changed. Employees already on *this*
        // project's team are exempt (they're the "kept" set whose
        // allocated_hours we rewrite, not new picks).
        $pickedIds = collect($validated['picks'])->pluck('employee_id')->unique();
        $keptIds = $project->teamAssignments->pluck('employee_id');
        $raceStartStr = $raceStart->toDateString();
        $raceEndStr = $raceEnd->toDateString();
        $conflicts = Employee::whereIn('id', $pickedIds)
            ->whereNotIn('id', $keptIds)
            ->whereHas('teamAssignments', function ($q) use ($raceStartStr, $raceEndStr, $project) {
                // Mirror scopeIdleForRange's overlap clause, but exclude any
                // assignment to THIS project — those are stale rows from a
                // prior confirm in the same project's lifecycle and would
                // false-positive against themselves.
                $q->where('project_id', '!=', $project->id)
                    ->where(function ($qq) use ($raceEndStr) {
                        $qq->whereNull('team_start_date')
                            ->orWhere('team_start_date', '<=', $raceEndStr);
                    })->where(function ($qq) use ($raceStartStr) {
                        $qq->whereNull('team_end_date')
                            ->orWhere('team_end_date', '>=', $raceStartStr);
                    });
            })
            ->pluck('name', 'id');
        if ($conflicts->isNotEmpty()) {
            return response()->json([
                'error' => 'These employees are no longer idle and cannot be added: '.$conflicts->values()->implode(', '),
                'conflict_employee_ids' => $conflicts->keys()->values()->all(),
            ], 422);
        }

        // Department guard — defence-in-depth against picking non-delivery
        // staff (Sales/HR/Finance who carry a pm capacity_role for internal
        // coordination, not customer-delivery work). The idle-pool scope
        // already filters these out, so the dialog won't surface them — but
        // an API caller bypassing the dialog, or a stale dialog from before
        // a tenant flipped the department flag, could still try to confirm
        // one. Reject 422 with the list of offenders so the caller knows
        // exactly which pick to drop or which department to re-enable.
        // Exempts already-kept members so existing teams don't break if a
        // department's eligibility flag is flipped after the fact.
        $nonDeliveryPicks = Employee::whereIn('id', $pickedIds)
            ->whereNotIn('id', $keptIds)
            ->whereHas('department', fn ($q) => $q->where('is_delivery_eligible', false))
            ->pluck('name', 'id');
        if ($nonDeliveryPicks->isNotEmpty()) {
            return response()->json([
                'error' => 'These employees belong to a non-delivery department and cannot be staffed on customer projects: '.$nonDeliveryPicks->values()->implode(', '),
                'non_delivery_employee_ids' => $nonDeliveryPicks->keys()->values()->all(),
            ], 422);
        }

        $deal = $project->contract?->deal;

        // Use the same slot source as planTeamPreview: sheet_team_structure
        // first, then ghost_roles fallback.
        $teamStructure = $deal ? $this->resolveSheetTeamStructure($deal) : null;
        $usingSheetStructure = $teamStructure !== null;

        if ($usingSheetStructure) {
            $allSlots = $this->buildSlotsFromSheetStructure($teamStructure);
        } else {
            $ghostRoles = $deal?->ghost_roles ?? collect();
            $allSlots = $ghostRoles->isNotEmpty()
                ? $this->buildSlotsFromGhostRoles($ghostRoles)
                : [];
        }

        $confirmStartDate = $validated['team_start_date']
            ?? ($teamStructure['start_date'] ?? null);

        // Group slots by category for kept-member pairing.
        $slotsByCategory = [];
        foreach ($allSlots as $slot) {
            $slotsByCategory[$slot['role_category']][] = $slot;
        }

        // Subtract slots claimed by proposed picks BEFORE pairing kept members.
        foreach ($validated['picks'] as $pick) {
            $pickSlotId = $pick['slot_id'] ?? $pick['ghost_role_id'] ?? null;
            if (! $pickSlotId) {
                continue;
            }
            foreach ($slotsByCategory as $cat => &$slots) {
                foreach ($slots as $idx => $slot) {
                    $id = $slot['slot_id'] ?? $slot['ghost_role_id'] ?? null;
                    if ($id === $pickSlotId) {
                        array_splice($slots, $idx, 1);
                        break 2;
                    }
                }
            }
            unset($slots);
        }

        $existing = $project->teamAssignments->keyBy('employee_id');
        $existingSet = $existing->keys()->flip()->all();

        $employeeIds = collect($validated['picks'])->pluck('employee_id')->unique()->all();
        $employeesById = Employee::whereIn('id', $employeeIds)->get()->keyBy('id');

        $ghostRoleIds = collect($validated['picks'])
            ->pluck('ghost_role_id')
            ->filter()
            ->unique()
            ->all();
        $ghostRoleMonths = empty($ghostRoleIds)
            ? collect()
            : DealGhostRole::whereIn('id', $ghostRoleIds)
                ->pluck('months', 'id');

        $inserted = 0;
        $updated = 0;

        // Build slot_id → employee name map for xlsx Assignee column update.
        $slotEmployeeMap = [];
        foreach ($validated['picks'] as $pick) {
            $slotId = $pick['slot_id'] ?? null;
            $emp = $employeesById[$pick['employee_id']] ?? null;
            if ($slotId && $emp && str_starts_with($slotId, 'slot-')) {
                $slotEmployeeMap[$slotId] = $emp->name;
            }
        }

        $teamStartDate = $validated['team_start_date'] ?? null;

        DB::transaction(function () use (
            $validated, $project, $tenantId, $existingSet, $existing,
            $ghostRoleMonths, $employeesById, $slotsByCategory, $confirmStartDate,
            &$slotEmployeeMap,
            &$inserted, &$updated,
        ) {
            // 1. Insert new picks.
            foreach ($validated['picks'] as $pick) {
                if (isset($existingSet[$pick['employee_id']])) {
                    continue;
                }

                $months = isset($pick['ghost_role_id'])
                    ? (int) ($ghostRoleMonths[$pick['ghost_role_id']] ?? 0)
                    : 0;
                $emp = $employeesById[$pick['employee_id']] ?? null;
                $monthlyAlloc = $pick['monthly_allocation'] ?? null;
                $allocated = $pick['allocated_hours']
                    ?? ($monthlyAlloc
                        ? round(array_sum($monthlyAlloc) * ((float) ($emp?->workable_hours ?? 160)), 2)
                        : ($emp && $months > 0
                            ? $this->engagementAvailableHours($emp, $project, $months)
                            : ($emp ? (float) ($emp->workable_hours ?? 160) : 160)));

                $teamEndDate = EngagementWindow::computeEndDate(
                    $confirmStartDate ? Carbon::parse($confirmStartDate)->startOfDay() : null,
                    $monthlyAlloc,
                    $project,
                );

                ProjectTeamAssignment::create([
                    'tenant_id' => $tenantId,
                    'project_id' => $project->id,
                    'employee_id' => $pick['employee_id'],
                    'allocated_hours' => $allocated,
                    'monthly_allocation' => $monthlyAlloc,
                    'team_start_date' => $confirmStartDate,
                    'team_end_date' => $teamEndDate?->toDateString(),
                    'assignment_source' => 'ai',
                ]);
                $inserted++;
            }

            // 2. Rewrite kept rows by re-pairing each kept member with the
            //    next available slot of matching role category. Try exact
            //    capacity_role first, then fall back to broad category.
            foreach ($existing as $employeeId => $row) {
                $emp = $row->employee;
                $capRole = optional(optional($emp)->capacityRole)->code ?? optional($emp)->capacity_role;
                $cat = $this->capacityRoleToCategory($capRole);

                $matched = null;
                if ($capRole && ! empty($slotsByCategory[$capRole])) {
                    $matched = array_shift($slotsByCategory[$capRole]);
                }
                if (! $matched && $cat && ! empty($slotsByCategory[$cat])) {
                    $matched = array_shift($slotsByCategory[$cat]);
                }

                if ($matched && isset($matched['slot_id']) && str_starts_with($matched['slot_id'], 'slot-')) {
                    $slotEmployeeMap[$matched['slot_id']] = $emp->name ?? 'Unknown';
                }

                $monthlyAlloc = $matched['monthly_allocation'] ?? null;
                $months = (int) ($matched['months'] ?? 0);
                $newAllocated = $monthlyAlloc
                    ? round(array_sum($monthlyAlloc) * ((float) ($emp->workable_hours ?? 160)), 2)
                    : ($emp && $months > 0 ? $this->engagementAvailableHours($emp, $project, $months) : 0.0);

                $changed = abs((float) $row->allocated_hours - $newAllocated) > 0.001;
                $allocChanged = $monthlyAlloc !== $row->monthly_allocation;
                if ($changed || $allocChanged) {
                    $row->allocated_hours = $newAllocated;
                    $row->monthly_allocation = $monthlyAlloc;
                    $row->team_start_date = $confirmStartDate;
                    $row->team_end_date = EngagementWindow::computeEndDate(
                        $confirmStartDate ? Carbon::parse($confirmStartDate)->startOfDay() : null,
                        $monthlyAlloc,
                        $project,
                    )?->toDateString();
                    $row->save();
                    $updated++;
                }
            }
        });

        if ($usingSheetStructure && ! empty($slotEmployeeMap)) {
            app(EstimationXlsxService::class)
                ->updateTeamStructureNames($project, $slotEmployeeMap);
        }

        $project->load('teamAssignments.employee');

        return response()->json([
            'data' => ProjectTeamAssignmentResource::collection($project->teamAssignments),
            'inserted' => $inserted,
            'updated' => $updated,
        ]);
    }

    /**
     * Calls Anthropic, parses the response, validates, retries once on
     * violations, returns ['picks' => [...], 'unfilled' => [...]] or throws.
     */
    private function callPlanTeamAi(
        string $apiKey,
        Project $project,
        $deal,
        array $kept,
        array $rolesToFill,
        array $employeePool,
        array $slotIndex,
        array $employeeIndex,
        array $keptEmployeeIds,
    ): ?array {
        $prompt = $this->buildPlanTeamPrompt($project, $deal, $kept, $rolesToFill, $employeePool);

        $baseUrl = rtrim(config('services.anthropic.base_url') ?: 'https://api.anthropic.com', '/');
        $model = config('services.anthropic.model') ?: 'claude-3-5-sonnet-latest';

        $messages = [
            ['role' => 'user', 'content' => $prompt],
        ];

        $retries = (int) (config('services.anthropic.schedule_retries') ?? 1);
        $validator = new AiTeamPlanValidator;

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post($baseUrl.'/v1/messages', [
                'model' => $model,
                'max_tokens' => 4096,
                'system' => 'You are an HR staffing assistant. Pick the best employees to fill each planned role on this project. Return ONLY a JSON object matching the schema in the user message — no markdown, no commentary.',
                'messages' => $messages,
            ]);

            $body = $response->json();

            if (isset($body['usage'])) {
                $this->logUsage($project->tenant_id, $body['usage'], 'auto_assign', $model);
            }

            $text = trim($body['content'][0]['text'] ?? '');
            if (str_starts_with($text, '```')) {
                $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
                $text = preg_replace('/\s*```$/', '', $text);
            }
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
            $text = str_replace(["\t", "\r", "\n"], ' ', $text);

            $parsed = json_decode($text, true);
            if (! is_array($parsed) || ! isset($parsed['picks']) || ! is_array($parsed['picks'])) {
                Log::warning('AI plan-team: invalid JSON response', ['text' => substr($text, 0, 400)]);
                if ($attempt === $retries) {
                    return null;
                }
                $messages[] = ['role' => 'assistant', 'content' => $text];
                $messages[] = ['role' => 'user', 'content' => 'Your last reply was not valid JSON matching the schema. Return ONLY the JSON object now.'];

                continue;
            }

            // Normalize: AI may return slot_id or ghost_role_id — map both.
            $picks = array_map(function ($p) {
                $p['slot_id'] = $p['slot_id'] ?? $p['ghost_role_id'] ?? null;

                return $p;
            }, $parsed['picks']);
            $unfilled = $parsed['unfilled'] ?? [];

            $violations = $validator->validate($picks, $unfilled, $slotIndex, $employeeIndex, $keptEmployeeIds);
            if (empty($violations)) {
                return ['picks' => $picks, 'unfilled' => $unfilled];
            }

            if ($attempt === $retries) {
                Log::warning('AI plan-team: validator rejected even after retry', ['violations' => $violations]);

                return null;
            }

            $messages[] = ['role' => 'assistant', 'content' => $text];
            $messages[] = ['role' => 'user', 'content' => 'Your previous response failed validation: '.$this->jsonEncode($violations).' Re-emit the JSON object correcting these issues.'];
        }

        return null;
    }

    /**
     * Deterministic fallback when the AI call fails or returns nothing usable.
     * Matches each ghost role to the closest-rank employee whose capacity_role
     * matches role_type. Applies the rank-fallback ladder (Senior → Mid → Junior).
     */
    private function demoPlanTeam(array $rolesToFill, array $employeePool): array
    {
        $rankLevel = ['Junior' => 10, 'Mid' => 20, 'Senior' => 30, 'Lead' => 40];
        $available = $employeePool;
        $picks = [];
        $unfilled = [];

        foreach ($rolesToFill as $role) {
            $needed = $role['quantity_needed'];
            $wantedRankLevel = $rankLevel[$role['rank_code'] ?? ''] ?? null;
            $eligibleRoles = $role['eligible_roles'] ?? [$role['role_category']];
            $slotId = $role['slot_id'] ?? $role['ghost_role_id'] ?? null;

            for ($i = 0; $i < $needed; $i++) {
                $bestIdx = null;
                $bestScore = PHP_INT_MIN;

                foreach ($available as $idx => $emp) {
                    if (! in_array($emp['capacity_role'], $eligibleRoles, true)) {
                        continue;
                    }
                    $score = 100;
                    if ($wantedRankLevel !== null) {
                        $empLevel = $rankLevel[$emp['rank_code'] ?? ''] ?? 0;
                        $delta = abs($empLevel - $wantedRankLevel);
                        $score -= $delta;
                        if ($empLevel > $wantedRankLevel) {
                            $score -= 5;
                        }
                    }
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestIdx = $idx;
                    }
                }

                if ($bestIdx === null) {
                    $unfilled[] = [
                        'slot_id' => $slotId,
                        'ghost_role_id' => $role['ghost_role_id'] ?? null,
                        'reason' => 'no available employee with capacity_role in ['.implode(',', $eligibleRoles).']',
                    ];
                    break;
                }

                $picked = $available[$bestIdx];
                array_splice($available, $bestIdx, 1);

                $pickedLevel = $rankLevel[$picked['rank_code'] ?? ''] ?? 0;
                $rankMatch = 'exact';
                if ($wantedRankLevel !== null && $pickedLevel < $wantedRankLevel) {
                    $rankMatch = 'downgrade';
                } elseif ($wantedRankLevel !== null && $pickedLevel > $wantedRankLevel) {
                    $rankMatch = 'upgrade';
                }

                $allocatedHours = $role['allocated_hours']
                    ?? (float) $picked['workable_hours'] * max(1, (int) ($role['months'] ?? 1));

                $picks[] = [
                    'slot_id' => $slotId,
                    'ghost_role_id' => $role['ghost_role_id'] ?? null,
                    'employee_id' => $picked['employee_id'],
                    'allocated_hours' => $allocatedHours,
                    'rank_match' => $rankMatch,
                ];
            }
        }

        return ['picks' => $picks, 'unfilled' => $unfilled];
    }

    private function buildPlanTeamPrompt(Project $project, $deal, array $kept, array $rolesToFill, array $employeePool): string
    {
        $projectMeta = [
            'name' => $project->name,
            'client' => $project->client,
            'start_date' => optional($project->start_date)->toDateString(),
            'end_date' => optional($project->end_date)->toDateString(),
            'timeline_months' => (int) ($deal?->timeline_months ?? 0),
            'workload_description' => $deal?->workload_description,
        ];

        $keptJson = $this->jsonEncode($kept);
        $rolesJson = $this->jsonEncode($rolesToFill);
        $employeesJson = $this->jsonEncode($employeePool);
        $projectMetaJson = $this->jsonEncode($projectMeta);

        $hasMonthlyAlloc = collect($rolesToFill)->contains(fn ($r) => ! empty($r['monthly_allocation']));
        $allocSection = '';
        if ($hasMonthlyAlloc) {
            $allocSection = <<<'ALLOC'

MONTHLY ALLOCATION
Each role slot includes a `monthly_allocation` array — fractions of 160h per month.
- 1.0 = full capacity (160h/month), 0.8 = 128h, 0.5 = 80h, 0 = not working that month.
- `subtotal` = sum of monthly_allocation = total person-months.
- `allocated_hours` = subtotal × 160.
The employee you pick inherits this allocation schedule. An employee filling a slot
with monthly_allocation [1, 0.5, 0] will work full-time in month 1, half-time in
month 2, and not at all in month 3.

Roles with `eligible_roles` containing multiple options (e.g. ["backend","frontend","qa"])
mean ANY of those capacity_roles can fill the slot. Pick the best match for the project.

ALLOC;
        }

        return <<<PROMPT
Pick the best employees from the available pool to fill the planned roles
on this project. DO NOT re-pick or evict anyone already on the team.

PROJECT
{$projectMetaJson}

ALREADY-STAFFED MEMBERS (preserve as-is — these will be kept regardless):
{$keptJson}

PLANNED ROLES STILL TO FILL (after subtracting already-staffed counts):
{$rolesJson}

AVAILABLE EMPLOYEES (active, full-time, not already on this project's team):
{$employeesJson}
{$allocSection}
SELECTION RULES
1. Match capacity_role: the employee's capacity_role must be in the slot's `eligible_roles` list.
   - If eligible_roles has ONE entry (e.g. ["backend"]), you MUST pick that exact capacity_role.
   - If eligible_roles has MULTIPLE entries (e.g. ["backend","frontend","qa","design"]), pick the capacity_role that best fits the project's workload_description and ensures a balanced team (don't pick all backend — consider if the project needs QA, frontend, etc.).
2. Prefer rank that matches the role's rank_code. When rank_code is null, infer from salary band.
3. FALLBACK LADDER when target rank unavailable:
   - Missing Lead → Senior ("downgrade"). Missing Senior → Mid or 2×Junior ("split"). Missing Mid → Junior ("downgrade"). Missing Junior → Mid ("upgrade", last resort).
4. Never pick the same employee twice. Never pick anyone in ALREADY-STAFFED MEMBERS.
5. HARD RULE — allocated_hours for each pick MUST equal the slot's `allocated_hours` value (derived from monthly_allocation × 160). Do not recompute — use the value from the slot.
6. If a role cannot be filled, report it in `unfilled` with a `reason` string.

OUTPUT — return ONLY this JSON object (no markdown fences, no commentary):
{
  "picks": [
    { "slot_id": "slot-0", "employee_id": "uuid", "allocated_hours": 384, "rank_match": "exact|downgrade|upgrade|split" }
  ],
  "unfilled": [
    { "slot_id": "slot-1", "reason": "no eligible employee available" }
  ]
}

IMPORTANT: Use "slot_id" (not "ghost_role_id") as the key to identify each role slot.
PROMPT;
    }

    // ── Task-level AI assignment (Estimate.xlsx → per-phase assignee) ─────────

    // Canonical phase catalog keyed by the Japanese label that appears in row 3
    // of the Web_Manhour_Detail sheet. Column ranges are NOT fixed here — they
    // are detected per file by `detectPhasesFromSheet()` because different
    // projects may ship Estimate.xlsx files with different phase subsets and
    // column layouts. `development` has no row-3 header (its hours always live
    // at cols 4–5: 開発工数 + コードレビュー) and is injected separately.
    private const PHASE_CATALOG = [
        '要件定義' => ['code' => 'requirement',  'order' => 1, 'is_execution' => false],
        '基本全体設計' => ['code' => 'system_arch',  'order' => 2, 'is_execution' => false],
        '基本設計' => ['code' => 'basic_doc',    'order' => 3, 'is_execution' => false],
        '詳細設計' => ['code' => 'detail_doc',   'order' => 4, 'is_execution' => false],
        '単体テスト' => ['code' => 'unit_test',    'order' => 6, 'is_execution' => true],
        '結合テスト' => ['code' => 'combine_test', 'order' => 7, 'is_execution' => true],
        '総合テスト' => ['code' => 'system_test',  'order' => 8, 'is_execution' => true],
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

        $resolver = app(EstimateFileResolver::class);
        $resolvedPath = $resolver->latestForProject($project)
            ?? $resolver->tenantFallbackPath($project->tenant_id);

        if ($resolvedPath === null) {
            return response()->json([
                'error' => 'No estimation file available for this project. Upload an estimation (xlsx) before assigning tasks.',
            ], 422);
        }

        $sheet = $this->readEstimateSheet($resolvedPath);
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

        $windowStart = Carbon::parse($project->start_date)->startOfDay();
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

        $teamMembers = $teamAssignments->map(function ($a) use ($windowStart, $effectiveEnd) {
            $workable = (float) (optional($a->employee)->workable_hours ?? 160);
            $allocated = (float) $a->allocated_hours;
            $monthlyAlloc = $a->monthly_allocation;

            // Pad / truncate monthly_allocation to the project's month span
            // counted from team_start_date (or windowStart if missing). Short
            // arrays get 0.0 entries appended — making "absent" months explicit
            // so the AI's HARD zero-month rule and the validator have something
            // to bite on. Longer arrays are clipped with a warning.
            $teamStart = $a->team_start_date
                ? Carbon::parse($a->team_start_date)->startOfDay()
                : $windowStart->copy();
            $expectedMonths = $this->monthsBetweenInclusive($teamStart, $effectiveEnd);

            if (is_array($monthlyAlloc) && $expectedMonths > 0) {
                if (count($monthlyAlloc) < $expectedMonths) {
                    $monthlyAlloc = array_pad($monthlyAlloc, $expectedMonths, 0.0);
                } elseif (count($monthlyAlloc) > $expectedMonths) {
                    Log::warning('AI Schedule: truncating overlong monthly_allocation', [
                        'employee_id' => $a->employee_id,
                        'received' => count($monthlyAlloc),
                        'expected' => $expectedMonths,
                    ]);
                    $monthlyAlloc = array_slice($monthlyAlloc, 0, $expectedMonths);
                }
                $monthlyAlloc = array_map('floatval', $monthlyAlloc);
            }

            // When monthly_allocation is present, engagement_months = count of
            // non-zero months (the calendar span). Without it, derive from hours.
            if (! empty($monthlyAlloc)) {
                $engagementMonths = count(array_filter($monthlyAlloc, fn ($v) => $v > 0));
            } else {
                $engagementMonths = $workable > 0 ? $allocated / $workable : 0;
            }

            $member = [
                'id' => $a->employee_id,
                'name' => optional($a->employee)->name,
                'rank_code' => optional(optional($a->employee)->rank)->code,
                'rank_name' => optional(optional($a->employee)->rank)->name,
                'capacity_role' => optional(optional($a->employee)->capacityRole)->code
                    ?? optional($a->employee)->capacity_role,
                'workable_hours' => $workable,
                'cost_per_hour' => optional($a->employee)->cost_per_hour,
                'allocated_hours' => $allocated,
                'engagement_months' => round($engagementMonths, 2),
            ];

            if (! empty($monthlyAlloc)) {
                $member['monthly_allocation'] = $monthlyAlloc;
                $member['team_start_date'] = optional($a->team_start_date)->toDateString();
            }

            return $member;
        })->values()->toArray();
        $teamIds = $teamAssignments->pluck('employee_id')->all();
        $allocatedHoursByAssignee = $teamAssignments
            ->pluck('allocated_hours', 'employee_id')
            ->map(fn ($v) => (float) $v)
            ->all();
        $engagementMonthsByAssignee = $teamAssignments
            ->mapWithKeys(function ($a) {
                $workable = (float) (optional($a->employee)->workable_hours ?? 160);
                $months = $workable > 0 ? ((float) $a->allocated_hours) / $workable : 0;

                return [$a->employee_id => $months];
            })
            ->all();
        $capacityRoleByAssignee = $teamAssignments
            ->mapWithKeys(fn ($a) => [
                $a->employee_id => optional(optional($a->employee)->capacityRole)->code
                    ?? optional($a->employee)->capacity_role
                    ?? 'unknown',
            ])
            ->all();
        // Rank-fit input for the new rank_mismatch (SOFT) validator rule.
        // Members without a rank fall to Mid (level 20) — same default the
        // demo path uses, so the validator's behaviour matches the assigner.
        $rankLevelByAssignee = $teamAssignments
            ->mapWithKeys(fn ($a) => [
                $a->employee_id => (int) (optional(optional($a->employee)->rank)->level ?? 20),
            ])
            ->all();

        // Monthly-allocation inputs for the new HARD validator rules
        // (zero-month + monthly hour budget). Mirror the padding done above
        // for $teamMembers so prompt and validator see the same arrays.
        $monthlyAllocationByAssignee = [];
        $teamStartByAssignee = [];
        $workableHoursByAssignee = [];
        foreach ($teamAssignments as $a) {
            $alloc = $a->monthly_allocation;
            if (! is_array($alloc) || empty($alloc)) {
                continue;
            }
            $teamStart = $a->team_start_date
                ? Carbon::parse($a->team_start_date)->startOfDay()
                : $windowStart->copy();
            $expectedMonths = $this->monthsBetweenInclusive($teamStart, $effectiveEnd);
            if ($expectedMonths > 0) {
                if (count($alloc) < $expectedMonths) {
                    $alloc = array_pad($alloc, $expectedMonths, 0.0);
                } elseif (count($alloc) > $expectedMonths) {
                    $alloc = array_slice($alloc, 0, $expectedMonths);
                }
            }
            $monthlyAllocationByAssignee[$a->employee_id] = array_map('floatval', $alloc);
            $teamStartByAssignee[$a->employee_id] = $teamStart->toDateString();
            $workableHoursByAssignee[$a->employee_id] =
                (float) (optional($a->employee)->workable_hours ?? 160);
        }

        $dbHolidays = $this->dbHolidaysForPrompt($tenantId, $windowStart, $effectiveEnd);
        $prompt = $this->buildAssignTasksPrompt($project, $tasks, $activePhases, $teamMembers, $windowStart, $effectiveEnd, $dbHolidays, $rawSheet);

        $retriesLeft = (int) config('services.anthropic.schedule_retries', 2);
        $maxTokens = (int) config('services.anthropic.schedule_max_tokens', 16384);
        $model = config('services.anthropic.schedule_model', 'claude-3-5-sonnet-latest');
        $baseUrl = rtrim(config('services.anthropic.base_url') ?: 'https://api.anthropic.com', '/');
        $requestTimeout = (int) (config('services.anthropic.request_timeout') ?? 120);
        $connectTimeout = (int) (config('services.anthropic.connect_timeout') ?? 15);
        $conversation = [['role' => 'user', 'content' => $prompt]];

        try {
            while (true) {
                $response = Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                    ->timeout($requestTimeout)
                    ->connectTimeout($connectTimeout)
                    ->post($baseUrl.'/v1/messages', [
                        'model' => $model,
                        'max_tokens' => $maxTokens,
                        'system' => 'You are an experienced IT delivery manager scheduling feature work across engineers. Return ONLY a JSON object matching the schema in the user message — no markdown fences, no commentary.',
                        'messages' => $conversation,
                    ]);

                $body = $response->json();

                if (isset($body['usage'])) {
                    $this->logUsage($tenantId, $body['usage'], 'auto_assign', $model);
                }

                $text = trim($body['content'][0]['text'] ?? '');

                try {
                    $payload = AiSchedulePayload::fromRaw($text);
                } catch (InvalidAiScheduleException $e) {
                    Log::error('AI Schedule: unparseable payload, falling back', [
                        'error' => $e->getMessage(),
                        'json_error' => json_last_error_msg(),
                        'text_length' => strlen($text),
                        'preview' => substr($text, 0, 300),
                        'tail' => substr($text, -200),
                        'stop_reason' => $body['stop_reason'] ?? null,
                        'http_status' => $response->status(),
                        'api_error' => $body['error'] ?? null,
                        'response_preview' => substr($response->body(), 0, 500),
                        'base_url' => $baseUrl,
                    ]);

                    return $this->demoAssignTasks($project, $tasks, $activePhases, $teamAssignments, $tenantId, $windowStart, $effectiveEnd, $fallbackCalendar);
                }

                $calendar = $this->buildCalendarFromAi($payload, $tenantId, $windowStart, $effectiveEnd);

                // Surgical self-correction layer between AI and validator.
                // Cheap, deterministic fixes for cosmetic calendar mistakes
                // and overlap greediness that the AI tends to make on busy
                // schedules. See docs/ai-schedule-self-correction.md.
                $snapped = $payload->snapDatesToWorkingDays($calendar);
                if ($snapped > 0) {
                    Log::info('AI Schedule: snapped non-working dates to working days', ['snapped_count' => $snapped]);
                }
                $shifted = $payload->resolveAssigneeOverlaps($calendar, $effectiveEnd);
                if ($shifted > 0) {
                    Log::info('AI Schedule: resolved overlapping assignee ranges', ['shifted_count' => $shifted]);
                }
                $reordered = $payload->enforcePhaseOrderWithinRows($calendar, $tasks, $effectiveEnd);
                if ($reordered > 0) {
                    Log::info('AI Schedule: fixed phase-order within rows', ['reordered_count' => $reordered]);
                }
                $clamped = $payload->clampDurationOutliers($calendar, $tasks, self::WORKDAY_HOURS, AiScheduleValidator::DURATION_TOLERANCE);
                if ($clamped > 0) {
                    Log::info('AI Schedule: clamped duration outliers', ['clamped_count' => $clamped]);
                }
                $filled = $this->fillMissingAssignments(
                    $payload, $tasks, $teamAssignments, $calendar,
                    $windowStart, $effectiveEnd, $allocatedHoursByAssignee,
                    $capacityRoleByAssignee,
                );
                if ($filled > 0) {
                    Log::info('AI Schedule: filled missing (row, phase) pairs', ['filled_count' => $filled]);
                }

                $violations = (new AiScheduleValidator)->validate(
                    $payload,
                    $tasks,
                    $teamIds,
                    $calendar,
                    $windowStart,
                    $effectiveEnd,
                    $allocatedHoursByAssignee,
                    $engagementMonthsByAssignee,
                    $capacityRoleByAssignee,
                    $rankLevelByAssignee,
                    $monthlyAllocationByAssignee,
                    $teamStartByAssignee,
                    $workableHoursByAssignee,
                );

                if (empty($violations)) {
                    return $this->persistAiAssignments($project, $tasks, $payload, $tenantId, $windowStart, $effectiveEnd, $calendar);
                }

                // Split into hard (must-retry) and soft (advisory) violations.
                // If only soft remain, persist anyway — the schedule is honest
                // and the warnings tell the operator the team is under-sized.
                //
                // double_booking is soft AFTER the overlap fixer has run:
                // Fixer 2 already shifted every pair it could without
                // overshooting effectiveEnd, so residuals are genuine
                // capacity-overflow signals (team can't fit the scope in the
                // window). Same class of "fix the team structure" finding as
                // over_allocation and engagement_window_exceeded — not worth
                // a fallback to demo which has its own over-cap issues.
                // rank_mismatch is advisory — the prompt classifies rank-fit
                // as PREFER (not HARD), so violations represent quality risk
                // worth logging but should not force a retry / fallback.
                $softCodes = ['over_allocation', 'engagement_window_exceeded', 'rank_mismatch'];
                if ($shifted > 0) {
                    $softCodes[] = 'double_booking';
                }
                if ($reordered > 0) {
                    // Same reasoning as double_booking: the phase-order fixer
                    // tried its best; residuals are window-bound refusals and
                    // are honest under-capacity signals, not blockers.
                    $softCodes[] = 'phase_order_violation';
                }
                $hardViolations = [];
                $softViolations = [];
                foreach ($violations as $v) {
                    if (in_array($v['code'], $softCodes, true)) {
                        $softViolations[] = $v;
                    } else {
                        $hardViolations[] = $v;
                    }
                }
                if (empty($hardViolations)) {
                    Log::warning('AI Schedule: persisting with soft violations', [
                        'soft_count' => count($softViolations),
                        'codes' => array_count_values(array_column($softViolations, 'code')),
                    ]);

                    // Surface rank_mismatch details separately — operator
                    // wants to see WHICH (row, phase, assignee) was a stretch
                    // so they can judge quality risk per task, not just a
                    // bucket count. Sample first 10 to keep the log readable.
                    $rankMismatches = array_values(array_filter(
                        $softViolations,
                        fn ($v) => $v['code'] === 'rank_mismatch'
                    ));
                    if (! empty($rankMismatches)) {
                        Log::warning('AI Schedule: rank_mismatch details', [
                            'total' => count($rankMismatches),
                            'samples' => array_map(
                                fn ($v) => [
                                    'row_no' => $v['context']['row_no'] ?? null,
                                    'phase_code' => $v['context']['phase_code'] ?? null,
                                    'assignee_rank' => $v['context']['assignee_rank'] ?? null,
                                    'required_rank' => $v['context']['required_rank'] ?? null,
                                    'difficulty' => $v['context']['difficulty'] ?? null,
                                ],
                                array_slice($rankMismatches, 0, 10)
                            ),
                        ]);
                    }

                    return $this->persistAiAssignments($project, $tasks, $payload, $tenantId, $windowStart, $effectiveEnd, $calendar);
                }

                if ($retriesLeft <= 0) {
                    // Tally violation codes so we can see at a glance whether
                    // the AI was dropping phases (missing_assignment) or just
                    // failing on soft caps (over_allocation / engagement_*).
                    $codeTally = [];
                    foreach ($violations as $v) {
                        $codeTally[$v['code']] = ($codeTally[$v['code']] ?? 0) + 1;
                    }
                    $expectedAssignments = 0;
                    foreach ($tasks as $t) {
                        $expectedAssignments += count($t['phases']);
                    }
                    Log::warning('AI Schedule: exhausted retries, falling back', [
                        'violation_count' => count($violations),
                        'violations_by_code' => $codeTally,
                        'first_violation' => $violations[0]['code'] ?? null,
                        'response_length' => strlen($text),
                        'assignments_returned' => count($payload->assignmentsByRowPhase ?? []),
                        'assignments_expected_pairs' => $expectedAssignments,
                        'model' => $model,
                        'max_tokens' => $maxTokens,
                    ]);

                    return $this->demoAssignTasks($project, $tasks, $activePhases, $teamAssignments, $tenantId, $windowStart, $effectiveEnd, $fallbackCalendar);
                }

                // Send only hard violations back to the AI — soft ones are
                // acceptable and don't need correction. Including them would
                // waste tokens and possibly confuse the AI into making the
                // hard situation worse.
                $conversation[] = ['role' => 'assistant', 'content' => $text];
                $conversation[] = ['role' => 'user', 'content' => $this->buildRetryPrompt($hardViolations)];
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
     * Holiday-aware allocated_hours for an N-month engagement that starts at
     * the project's start_date. Falls back to the legacy `workable_hours ×
     * months` formula when the project has no start_date (preserves behaviour
     * for projects mid-migration). When start_date is present, sums the real
     * available working hours across each engagement month — so months with
     * more holidays produce a smaller cap automatically.
     */
    private function engagementAvailableHours(Employee $employee, Project $project, int $months): float
    {
        $workable = (float) ($employee->workable_hours ?? 160);
        if ($months <= 0) {
            return 0.0;
        }
        if (! $project->start_date) {
            return $workable * $months;
        }

        $start = Carbon::parse($project->start_date)->startOfDay();
        $end = $start->copy()->addMonths($months)->subDay();

        return app(EmployeeCapacityService::class)
            ->windowAvailableHours($employee, $start, $end);
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
                'date' => $h->date?->toDateString(),
                'name' => $h->name,
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
    /**
     * Fill any (row, phase) pair the AI dropped from its response. For each
     * missing pair: pick an eligible assignee via PHASE_CAPACITY_ROLES + cap-
     * aware round-robin, anchor planned_start at the end of the previous
     * phase of the same task (or project start for the first phase), compute
     * planned_end = start + ceil(hours / WORKDAY_HOURS) working days.
     *
     * Mutates the payload. Returns count of filled pairs.
     */
    private function fillMissingAssignments(
        AiSchedulePayload $payload,
        array $tasks,
        $teamAssignments,
        WorkingDayCalendar $calendar,
        Carbon $windowStart,
        Carbon $effectiveEnd,
        array $allocatedHoursByAssignee,
        array $capacityRoleByAssignee,
    ): int {
        // Build cap-aware (capacity_role, rank) buckets — same shape as demo.
        $byRoleAndRank = [];
        foreach ($teamAssignments as $a) {
            if ((float) $a->allocated_hours <= 0.0) {
                continue;
            }
            $tier = optional(optional($a->employee)->rank)->code ?: 'Mid';
            if (! in_array($tier, ['Junior', 'Mid', 'Senior', 'Lead'], true)) {
                $tier = 'Lead';
            }
            $role = $capacityRoleByAssignee[$a->employee_id] ?? 'unknown';
            $byRoleAndRank[$role][$tier][] = $a->employee_id;
        }

        // Pre-compute what the AI already used per member so cap math is
        // accurate when we choose the fill assignee.
        $usedHoursByEmp = [];
        $hoursByPair = [];
        foreach ($tasks as $t) {
            foreach ($t['phases'] as $p) {
                $hoursByPair[$t['row_no']][$p['code']] = (float) $p['hours'];
            }
        }
        foreach ($payload->assignmentsByRowPhase as $rowNo => $byPhase) {
            foreach ($byPhase as $phaseCode => $entry) {
                $h = $hoursByPair[$rowNo][$phaseCode] ?? 0;
                $usedHoursByEmp[$entry['assignee_id']] = ($usedHoursByEmp[$entry['assignee_id']] ?? 0) + $h;
            }
        }

        $rankFallback = [
            'Lead' => ['Lead', 'Senior', 'Mid', 'Junior'],
            'Senior' => ['Senior', 'Lead', 'Mid', 'Junior'],
            'Mid' => ['Mid', 'Senior', 'Junior', 'Lead'],
            'Junior' => ['Junior', 'Mid', 'Senior', 'Lead'],
        ];
        $designPhases = ['requirement', 'system_arch', 'basic_doc', 'detail_doc'];
        $executionTier = ['簡単' => 'Junior', '普通' => 'Mid', '難しい' => 'Senior'];
        $allocTolerance = 1.0 + AiScheduleValidator::ALLOCATION_TOLERANCE;
        $cursors = [];

        $pick = function (string $phaseCode, string $preferredTier, float $hours) use (
            $byRoleAndRank, $allocatedHoursByAssignee, $rankFallback, $allocTolerance,
            &$cursors, &$usedHoursByEmp,
        ) {
            $eligibleRoles = AiScheduleValidator::PHASE_CAPACITY_ROLES[$phaseCode] ?? [];
            foreach ($eligibleRoles as $role) {
                foreach ($rankFallback[$preferredTier] ?? [$preferredTier] as $tier) {
                    $pool = $byRoleAndRank[$role][$tier] ?? [];
                    if (empty($pool)) {
                        continue;
                    }
                    $key = "{$role}|{$tier}";
                    $cursors[$key] = $cursors[$key] ?? 0;
                    $n = count($pool);
                    // Under-cap pass.
                    for ($i = 0; $i < $n; $i++) {
                        $idx = ($cursors[$key] + $i) % $n;
                        $id = $pool[$idx];
                        $cap = $allocatedHoursByAssignee[$id] ?? PHP_FLOAT_MAX;
                        $used = $usedHoursByEmp[$id] ?? 0.0;
                        if ($used + $hours <= $cap * $allocTolerance) {
                            $cursors[$key] = $idx + 1;
                            $usedHoursByEmp[$id] = $used + $hours;

                            return $id;
                        }
                    }
                }
            }
            // Last resort: take first eligible pool, accept overshoot.
            foreach ($eligibleRoles as $role) {
                foreach ($rankFallback[$preferredTier] ?? [$preferredTier] as $tier) {
                    $pool = $byRoleAndRank[$role][$tier] ?? [];
                    if (! empty($pool)) {
                        $id = $pool[0];
                        $usedHoursByEmp[$id] = ($usedHoursByEmp[$id] ?? 0) + $hours;

                        return $id;
                    }
                }
            }

            return null;
        };

        // Walk every expected (row, phase). For missing ones, pick assignee
        // and compute dates from previous phase's end (within the same row).
        $filled = 0;
        foreach ($tasks as $t) {
            // Sort row's phases by order so we anchor on previous phase's end.
            $phases = $t['phases'];
            usort($phases, fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

            $prevEnd = null;
            foreach ($phases as $phase) {
                $entry = $payload->assignmentsByRowPhase[$t['row_no']][$phase['code']] ?? null;
                if ($entry !== null) {
                    $prevEnd = Carbon::parse($entry['planned_end'])->startOfDay();

                    continue;
                }

                $preferredTier = in_array($phase['code'], $designPhases, true)
                    ? 'Lead'
                    : ($executionTier[$t['difficulty']] ?? 'Mid');

                $hours = (float) ($phase['hours'] ?? 0);
                $assigneeId = $pick($phase['code'], $preferredTier, $hours);
                if ($assigneeId === null) {
                    continue; // Validator will surface as missing_assignment.
                }

                $anchor = $prevEnd !== null
                    ? $calendar->nextWorkingDay($prevEnd->copy()->addDay(), $assigneeId)
                    : $calendar->nextWorkingDay($windowStart, $assigneeId);
                if ($anchor->greaterThan($effectiveEnd)) {
                    $anchor = $effectiveEnd->copy();
                }
                $days = max(1, (int) ceil($hours / self::WORKDAY_HOURS));
                $end = $calendar->addWorkingDays($anchor, $days - 1, $assigneeId);
                if ($end->greaterThan($effectiveEnd)) {
                    $end = $effectiveEnd->copy();
                }

                $payload->assignmentsByRowPhase[$t['row_no']][$phase['code']] = [
                    'assignee_id' => $assigneeId,
                    'planned_start' => $anchor->toDateString(),
                    'planned_end' => $end->toDateString(),
                ];
                $prevEnd = $end;
                $filled++;
            }
        }

        return $filled;
    }

    private function buildRetryPrompt(array $violations): string
    {
        // Sort violations so the AI sees the hard ones first. missing_assignment
        // and capacity_role_mismatch MUST be fixed; over_allocation /
        // engagement_window_exceeded are best-effort and can stand if no
        // alternative exists.
        $hardCodes = ['missing_assignment', 'capacity_role_mismatch', 'unknown_assignee', 'unknown_row', 'unknown_phase', 'inverted_range', 'out_of_window', 'phase_order_violation'];
        $softCodes = ['over_allocation', 'engagement_window_exceeded'];

        $hard = [];
        $soft = [];
        $other = [];
        foreach ($violations as $v) {
            if (in_array($v['code'], $hardCodes, true)) {
                $hard[] = $v;
            } elseif (in_array($v['code'], $softCodes, true)) {
                $soft[] = $v;
            } else {
                $other[] = $v;
            }
        }

        $lines = ['Your previous response failed validation. Fix the issues below and resend the COMPLETE corrected JSON object in the same schema.'];
        $lines[] = '';
        $lines[] = 'MUST FIX (hard errors — schedule cannot persist with these):';
        if (empty($hard) && empty($other)) {
            $lines[] = '  (none — only soft preferences below)';
        } else {
            $i = 1;
            foreach (array_merge($hard, $other) as $v) {
                $lines[] = "  {$i}. [{$v['code']}] {$v['message']}";
                $i++;
            }
        }

        if (! empty($soft)) {
            $lines[] = '';
            $lines[] = 'SHOULD MINIMIZE (soft preferences — best-effort, OK to leave as-is if you have no alternative within the eligible capacity_role pool):';
            $i = 1;
            foreach ($soft as $v) {
                $lines[] = "  {$i}. [{$v['code']}] {$v['message']}";
                $i++;
            }
            $lines[] = '';
            $lines[] = 'Reminder: do NOT drop any phase to satisfy a soft rule. Coverage of every (row, phase) is mandatory. If a pool is over-capacity, spread overshoot across that pool or fall through to the next eligible capacity_role per the table — never miss a phase.';
        }

        $lines[] = '';
        $lines[] = 'Return ONLY the corrected JSON object. No markdown fences, no explanation, no commentary.';

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
        $epsilon = 0.001;
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
                    'date' => $windowStart->copy(),
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
                    $start = $candidate->copy();
                    $end = $candidate->copy();
                    $startDayHours = $hours;
                    $newAssigneeHoursUsed = $assigneeUsedToday + $hours;
                } else {
                    // Split across days. Day 1 gets today's remainder; the rest
                    // spills across full 8h middle days plus a remainder on
                    // the last day.
                    $start = $candidate->copy();
                    $startDayHours = $remainingToday;

                    $leftover = $hours - $remainingToday;
                    $fullMiddleDays = (int) floor(($leftover + $epsilon) / $workdayHours);
                    $lastDayHours = $leftover - ($fullMiddleDays * $workdayHours);
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
                    'assignee_id' => $assigneeId,
                    'planned_start' => $start->toDateString(),
                    'planned_end' => $end->toDateString(),
                    'start_day_hours' => round($startDayHours, 2),
                ];

                $assigneeCursors[$assigneeId] = [
                    'date' => $end->copy(),
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

        $activePhases = $this->activePhasesFromRows($rows, $project);

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
            'assignee_id' => 'nullable|uuid|exists:employees,id',
            'planned_start' => 'nullable|date',
            'planned_end' => 'nullable|date',
            'actual_start' => 'nullable|date',
            'actual_end' => 'nullable|date',
            'status' => 'sometimes|in:未着手,進行中,完了',
        ]);

        if (array_key_exists('assignee_id', $validated)) {
            $validated['assignment_source'] = 'manual';
        }

        $phaseAssignment->update($validated);
        $phaseAssignment->load('assignee.rank');

        return new ProjectTaskPhaseAssignmentResource($phaseAssignment);
    }

    public function checkReassignment(Request $request, Project $project, ProjectTaskPhaseAssignment $phaseAssignment)
    {
        $phaseAssignment->loadMissing('taskAssignment');
        if (! $phaseAssignment->taskAssignment || $phaseAssignment->taskAssignment->project_id !== $project->id) {
            abort(404);
        }

        $validated = $request->validate([
            'assignee_id' => 'required|uuid|exists:employees,id',
        ]);

        if (! $phaseAssignment->planned_start || ! $phaseAssignment->planned_end) {
            return response()->json([
                'has_conflicts' => false,
                'conflicts' => [],
                'reverse_conflicts' => [],
                'readjusted_dates' => null,
                'cascade_preview' => [],
                'warnings' => [],
                'remaining_hours' => (float) $phaseAssignment->estimated_hours,
            ]);
        }

        $tenantId = app('tenant_id');
        $project->load('contract.deal');
        $windowStart = $project->start_date ? Carbon::parse($project->start_date)->startOfDay() : Carbon::today()->subYear();
        $windowEnd = $project->effectiveEndDate() ?? Carbon::today()->addYear();
        $calendar = CalendarFactory::forTenant($tenantId, $windowStart, $windowEnd);
        $service = new PhaseReassignmentService($calendar);

        $conflicts = $service->detectConflicts(
            $validated['assignee_id'],
            $phaseAssignment->planned_start,
            $phaseAssignment->planned_end,
            $phaseAssignment->id,
        );

        $readjustedDates = null;
        $cascadePreview = [];
        $warnings = [];

        $reverseConflicts = [];

        if (count($conflicts) > 0) {
            $readjustedDates = $service->calculateReadjustedDates(
                $validated['assignee_id'],
                $phaseAssignment->planned_start,
                $phaseAssignment->planned_end,
                (float) $phaseAssignment->estimated_hours,
                $phaseAssignment->id,
            );

            $cascadeResult = $service->cascadeShift(
                $validated['assignee_id'],
                Carbon::parse($readjustedDates['planned_end']),
                $phaseAssignment->id,
                dryRun: true,
            );
            $cascadePreview = $cascadeResult['shifted'];
            $warnings = $cascadeResult['warnings'];

            $currentAssigneeId = $phaseAssignment->assignee_id;
            if ($currentAssigneeId) {
                foreach ($conflicts as $conflict) {
                    $conflictPhase = ProjectTaskPhaseAssignment::find($conflict['phase_assignment_id']);
                    if (! $conflictPhase || ! $conflictPhase->planned_start || ! $conflictPhase->planned_end) {
                        continue;
                    }

                    $aConflicts = $service->detectConflicts(
                        $currentAssigneeId,
                        $conflictPhase->planned_start,
                        $conflictPhase->planned_end,
                        $conflictPhase->id,
                    );

                    if (count($aConflicts) > 0) {
                        $reverseConflicts[] = [
                            'swap_phase_id' => $conflict['phase_assignment_id'],
                            'swap_phase_name' => $conflict['phase_name'],
                            'conflicts' => $aConflicts,
                        ];
                    }
                }
            }
        }

        return response()->json([
            'has_conflicts' => count($conflicts) > 0,
            'conflicts' => $conflicts,
            'reverse_conflicts' => $reverseConflicts,
            'readjusted_dates' => $readjustedDates,
            'cascade_preview' => $cascadePreview,
            'warnings' => $warnings,
            'remaining_hours' => $service->remainingHours($phaseAssignment),
        ]);
    }

    public function reassignPhase(Request $request, Project $project, ProjectTaskPhaseAssignment $phaseAssignment)
    {
        $phaseAssignment->loadMissing('taskAssignment');
        if (! $phaseAssignment->taskAssignment || $phaseAssignment->taskAssignment->project_id !== $project->id) {
            abort(404);
        }

        $validated = $request->validate([
            'assignee_id' => 'required|uuid|exists:employees,id',
            'mode' => 'required|in:direct,readjust,swap,assign_anyway',
            'swap_with_phase_assignment_id' => 'required_if:mode,swap|uuid|exists:project_task_phase_assignments,id',
        ]);

        $tenantId = app('tenant_id');
        $project->load('contract.deal');
        $windowStart = $project->start_date ? Carbon::parse($project->start_date)->startOfDay() : Carbon::today()->subYear();
        $windowEnd = $project->effectiveEndDate() ?? Carbon::today()->addYear();
        $calendar = CalendarFactory::forTenant($tenantId, $windowStart, $windowEnd);
        $service = new PhaseReassignmentService($calendar);

        if ($validated['mode'] === 'swap') {
            $phaseB = ProjectTaskPhaseAssignment::findOrFail($validated['swap_with_phase_assignment_id']);
            $result = $service->executeSwap($phaseAssignment, $phaseB);

            return response()->json([
                'phase_a' => new ProjectTaskPhaseAssignmentResource($result['phase_a']),
                'phase_b' => new ProjectTaskPhaseAssignmentResource($result['phase_b']),
                'shifted_phases' => [],
                'warnings' => $result['warnings'],
            ]);
        }

        if ($validated['mode'] === 'assign_anyway') {
            return DB::transaction(function () use ($phaseAssignment, $validated) {
                $phaseAssignment->lockForUpdate();
                $phaseAssignment->update([
                    'assignee_id' => $validated['assignee_id'],
                    'assignment_source' => 'manual',
                ]);
                $phaseAssignment->load('assignee.rank');

                return response()->json([
                    'phase' => new ProjectTaskPhaseAssignmentResource($phaseAssignment),
                    'shifted_phases' => [],
                    'warnings' => [],
                ]);
            });
        }

        $newStart = null;
        $newEnd = null;
        $cascade = false;

        if ($validated['mode'] === 'readjust' && $phaseAssignment->planned_start && $phaseAssignment->planned_end) {
            $readjusted = $service->calculateReadjustedDates(
                $validated['assignee_id'],
                $phaseAssignment->planned_start,
                $phaseAssignment->planned_end,
                (float) $phaseAssignment->estimated_hours,
                $phaseAssignment->id,
            );
            $newStart = $readjusted['planned_start'];
            $newEnd = $readjusted['planned_end'];
            $cascade = true;
        }

        $result = $service->executeReassignment(
            $phaseAssignment,
            $validated['assignee_id'],
            $newStart,
            $newEnd,
            $cascade,
        );

        return response()->json([
            'phase' => new ProjectTaskPhaseAssignmentResource($result['phase']),
            'shifted_phases' => $result['shifted_phases'],
            'warnings' => $result['warnings'],
        ]);
    }

    /**
     * Manager-edited planned dates for a single phase. Validates working-day
     * placement (weekends + tenant holidays), then cascades two ways inside a
     * single transaction:
     *   1) same-task — any later phase in this task_assignment_id whose
     *      planned_start now <= the saved phase's planned_end shifts forward.
     *   2) same-assignee — the assignee's later phases across all projects
     *      shift via PhaseReassignmentService::cascadeShift().
     * Variance is derived on read so nothing to recompute here.
     */
    public function updatePhasePlannedDates(Request $request, Project $project, ProjectTaskPhaseAssignment $phaseAssignment)
    {
        $phaseAssignment->loadMissing('taskAssignment');
        if (! $phaseAssignment->taskAssignment || $phaseAssignment->taskAssignment->project_id !== $project->id) {
            abort(404);
        }

        $validated = $request->validate([
            'planned_start' => 'required|date',
            'planned_end' => 'required|date|after_or_equal:planned_start',
        ]);

        $newStart = Carbon::parse($validated['planned_start'])->startOfDay();
        $newEnd = Carbon::parse($validated['planned_end'])->startOfDay();

        $tenantId = app('tenant_id');
        $project->load('contract.deal');
        $windowStart = $project->start_date ? Carbon::parse($project->start_date)->startOfDay() : Carbon::today()->subYear();
        $windowEnd = $project->effectiveEndDate() ?? Carbon::today()->addYear();
        if ($newEnd->greaterThan($windowEnd)) {
            $windowEnd = $newEnd->copy()->addMonths(2);
        }
        $calendar = CalendarFactory::forTenant($tenantId, $windowStart, $windowEnd);

        $assigneeId = $phaseAssignment->assignee_id;

        if (! $calendar->isWorkingDay($newStart, $assigneeId)) {
            return response()->json([
                'message' => 'planned_start falls on a weekend or holiday.',
                'errors' => ['planned_start' => ['Pick a working day.']],
            ], 422);
        }
        if (! $calendar->isWorkingDay($newEnd, $assigneeId)) {
            return response()->json([
                'message' => 'planned_end falls on a weekend or holiday.',
                'errors' => ['planned_end' => ['Pick a working day.']],
            ], 422);
        }

        $priorMaxStart = ProjectTaskPhaseAssignment::where('task_assignment_id', $phaseAssignment->task_assignment_id)
            ->where('phase_order', '<', $phaseAssignment->phase_order)
            ->whereNotNull('planned_start')
            ->max('planned_start');
        if ($priorMaxStart && $newStart->lessThan(Carbon::parse($priorMaxStart))) {
            return response()->json([
                'message' => "planned_start must be on or after the previous phase's start in this task.",
                'errors' => ['planned_start' => ["Earliest allowed is {$priorMaxStart}."]],
            ], 422);
        }

        return DB::transaction(function () use ($phaseAssignment, $project, $newStart, $newEnd, $calendar, $assigneeId) {
            $phaseAssignment->lockForUpdate();
            $editedAt = now();

            $phaseAssignment->update([
                'planned_start' => $newStart->toDateString(),
                'planned_end' => $newEnd->toDateString(),
                'planned_dates_edited_at' => $editedAt,
            ]);

            $cascaded = [];
            $warnings = [];
            $visited = [$phaseAssignment->id => true];
            $projectEnd = $project->effectiveEndDate();

            // Pass 1: same-task cascade triggered by the anchor edit.
            $this->cascadeWithinTask(
                $phaseAssignment->task_assignment_id,
                $phaseAssignment->phase_order,
                $newEnd,
                $calendar,
                $projectEnd,
                $editedAt,
                $cascaded,
                $warnings,
                $visited,
            );

            // Pass 2: same-assignee cascade (cascadeShift does its own DB writes).
            if ($assigneeId) {
                $service = new PhaseReassignmentService($calendar);
                $assigneeResult = $service->cascadeShift(
                    $assigneeId,
                    $newEnd,
                    $phaseAssignment->id,
                    dryRun: false,
                );
                $shiftedIds = [];
                foreach ($assigneeResult['shifted'] as $row) {
                    $phaseId = $row['phase_assignment_id'];
                    if (isset($visited[$phaseId])) {
                        continue;
                    }
                    $visited[$phaseId] = true;
                    $shiftedIds[] = $phaseId;
                    $cascaded[] = [
                        'phase_assignment_id' => $phaseId,
                        'phase_name' => $row['phase_name'],
                        'phase_code' => null,
                        'task_assignment_id' => null,
                        'reason' => 'same_assignee',
                        'original_start' => $row['original_start'],
                        'original_end' => $row['original_end'],
                        'new_start' => $row['new_start'],
                        'new_end' => $row['new_end'],
                    ];
                }
                // Stamp planned_dates_edited_at on the rows cascadeShift touched
                // so the frontend banner picks them up too.
                if (! empty($shiftedIds)) {
                    ProjectTaskPhaseAssignment::whereIn('id', $shiftedIds)
                        ->update(['planned_dates_edited_at' => $editedAt]);
                }
                $warnings = array_merge($warnings, $assigneeResult['warnings']);

                // Pass 3 (A1 transitivity): each same-assignee shifted row may
                // belong to a different task whose own later phases now overlap.
                // Re-run same-task cascade per shifted row; visited prevents loops.
                foreach ($assigneeResult['shifted'] as $row) {
                    $shifted = ProjectTaskPhaseAssignment::find($row['phase_assignment_id']);
                    if (! $shifted) {
                        continue;
                    }
                    $this->cascadeWithinTask(
                        $shifted->task_assignment_id,
                        $shifted->phase_order,
                        Carbon::parse($row['new_end']),
                        $calendar,
                        $projectEnd,
                        $editedAt,
                        $cascaded,
                        $warnings,
                        $visited,
                    );
                }
            }

            $phaseAssignment->load('assignee.rank');

            return response()->json([
                'phase' => new ProjectTaskPhaseAssignmentResource($phaseAssignment),
                'cascaded_phases' => $cascaded,
                'warnings' => $warnings,
            ]);
        });
    }

    /**
     * Shift later phases of one task forward when they overlap an anchor end.
     * Idempotent via the $visited set keyed by phase_assignment_id. Stamps
     * planned_dates_edited_at + nulls start_day_hours when duration changes,
     * so VarianceCalculator falls back to even-distribution.
     */
    private function cascadeWithinTask(
        string $taskAssignmentId,
        int $afterPhaseOrder,
        Carbon $anchorEnd,
        WorkingDayCalendar $calendar,
        ?Carbon $projectEnd,
        Carbon|\DateTimeInterface $editedAt,
        array &$cascaded,
        array &$warnings,
        array &$visited,
    ): void {
        $later = ProjectTaskPhaseAssignment::where('task_assignment_id', $taskAssignmentId)
            ->where('phase_order', '>', $afterPhaseOrder)
            ->whereNotNull('planned_start')
            ->whereNotNull('planned_end')
            ->orderBy('phase_order')
            ->lockForUpdate()
            ->get();

        $prevEnd = $anchorEnd->copy();
        foreach ($later as $phase) {
            if (isset($visited[$phase->id])) {
                // Already shifted via a different path — re-read fresh state
                // to keep this loop's prevEnd consistent with that update.
                $phase->refresh();
                $prevEnd = Carbon::parse($phase->planned_end)->greaterThan($prevEnd)
                    ? Carbon::parse($phase->planned_end)
                    : $prevEnd;

                continue;
            }

            $laterStart = Carbon::parse($phase->planned_start);
            $laterEnd = Carbon::parse($phase->planned_end);

            if ($laterStart->lessThanOrEqualTo($prevEnd)) {
                $oldDuration = max(1, $calendar->workingDaysBetween($laterStart, $laterEnd, $phase->assignee_id));
                $shiftStart = $calendar->nextWorkingDay($prevEnd->copy()->addDay(), $phase->assignee_id);
                $shiftEnd = $oldDuration > 1
                    ? $calendar->addWorkingDays($shiftStart->copy(), $oldDuration - 1, $phase->assignee_id)
                    : $shiftStart->copy();
                $newDuration = max(1, $calendar->workingDaysBetween($shiftStart, $shiftEnd, $phase->assignee_id));

                $updates = [
                    'planned_start' => $shiftStart->toDateString(),
                    'planned_end' => $shiftEnd->toDateString(),
                    'planned_dates_edited_at' => $editedAt,
                ];
                if ($oldDuration !== $newDuration) {
                    $updates['start_day_hours'] = null;
                }
                $phase->update($updates);
                $visited[$phase->id] = true;

                if ($projectEnd && $shiftEnd->greaterThan($projectEnd)) {
                    $warnings[] = "Phase \"{$phase->phase_name}\" was pushed past project end date ({$projectEnd->toDateString()})";
                }

                $cascaded[] = [
                    'phase_assignment_id' => $phase->id,
                    'phase_name' => $phase->phase_name,
                    'phase_code' => $phase->phase_code,
                    'task_assignment_id' => $phase->task_assignment_id,
                    'reason' => 'same_task',
                    'original_start' => $laterStart->toDateString(),
                    'original_end' => $laterEnd->toDateString(),
                    'new_start' => $shiftStart->toDateString(),
                    'new_end' => $shiftEnd->toDateString(),
                ];

                $prevEnd = $shiftEnd->copy();
            } else {
                $prevEnd = $laterEnd->copy();
            }
        }
    }

    private function readEstimateSheet(?string $absolutePath = null): array
    {
        // Caller is required to resolve the path (project-specific xlsx_path
        // or the per-tenant fallback). The legacy `public/storage/Estimate.xlsx`
        // shared-file fallback was removed because it read the same file
        // across every tenant — a cross-tenant data leak risk.
        if ($absolutePath === null || ! file_exists($absolutePath)) {
            Log::error('Estimate xlsx not resolved or missing on disk', [
                'absolute_path' => $absolutePath,
            ]);

            return ['tasks' => [], 'active_phases' => [], 'raw_markdown' => ''];
        }

        $path = $absolutePath;

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
        $detection = $this->detectPhasesFromSheet($sheet);
        $phaseDefs = $detection['phase_defs'];
        $totalCol = $detection['total_col'];      // 1-based, or null
        $highestColStr = $sheet->getHighestColumn();

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

            $functionId = isset($cells[0]) ? (is_string($cells[0]) ? trim($cells[0]) : $cells[0]) : null;
            $functionName = isset($cells[1]) ? (is_string($cells[1]) ? trim($cells[1]) : $cells[1]) : null;
            $difficulty = isset($cells[2]) ? (is_string($cells[2]) ? trim($cells[2]) : $cells[2]) : null;
            $totalHours = ($totalCol !== null && isset($cells[$totalCol - 1])) ? $cells[$totalCol - 1] : null;

            if (! $functionName) {
                continue;
            }
            // Skip summary / total / team-composition rows that sit at the
            // bottom of the sheet (Leader | 1 | 8.361h, Developer | 3 | 51.9h,
            // 1人(Hr) / 1人(Days) / Months totals, etc). Two complementary signals:
            //  - function_name purely numeric → it's a headcount in col 2,
            //    not a feature name. Real features are descriptive text.
            //  - function_id matches team-composition / unit-total keywords.
            $fnStr = (string) $functionName;
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
                    'code' => $phase['code'],
                    'name' => $phase['name'],
                    'order' => $phase['order'],
                    'is_execution' => $phase['is_execution'],
                    'hours' => round($sum, 2),
                ];
            }

            $rowNo++;
            $tasks[] = [
                'row_no' => $rowNo,
                'function_id' => $functionId ? (string) $functionId : null,
                'function_name' => (string) $functionName,
                'difficulty' => $difficulty,
                'total_hours' => is_numeric($totalHours) ? round((float) $totalHours, 2) : 0,
                'phases' => $phases,
            ];
        }

        // Drop phases that have zero estimated hours across every task. The
        // template can declare a phase that nothing actually uses; surfacing it
        // clutters the schedule with title-only rows that never get assigned.
        $phasesWithHours = [];
        foreach ($tasks as $task) {
            foreach ($task['phases'] as $p) {
                if (($p['hours'] ?? 0) > 0) {
                    $phasesWithHours[$p['code']] = true;
                }
            }
        }
        $activePhases = [];
        foreach ($phaseDefs as $phase) {
            if (! isset($phasesWithHours[$phase['code']])) {
                continue;
            }
            $activePhases[] = [
                'code' => $phase['code'],
                'name' => $phase['name'],
                'order' => $phase['order'],
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
                    'meta' => self::PHASE_CATALOG[$firstLine],
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
                'code' => $meta['code'],
                'name' => $phaseStarts[$startCol]['label'],
                'cols' => $cols,
                'order' => $meta['order'],
                'is_execution' => $meta['is_execution'],
            ];
        }

        // 4. Always inject Development at cols 4–5 when its row-4 label
        //    confirms presence.
        $devHeader = (string) $sheet->getCell([4, 4])->getValue();
        if (stripos($devHeader, 'Develop') !== false || str_contains($devHeader, '開発工数')) {
            $phaseDefs[] = [
                'code' => 'development',
                'name' => '実装',
                'cols' => [4, 5],
                'order' => 5,
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
    private function renderSheetAsMarkdown(Worksheet $sheet, int $maxDataRows = 150): string
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

        $lines = [];
        $lines[] = '| '.implode(' | ', $headers).' |';
        $lines[] = '|'.str_repeat(' --- |', count($headers));

        $dataStart = 5;
        $dataEnd = min($highestRow, $dataStart + $maxDataRows - 1);
        $included = 0;
        $skipped = 0;

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
        $client = $project->client ?? 'N/A';
        $startStr = $windowStart->toDateString();
        $endStr = $effectiveEnd->toDateString();
        $buffer = self::PROJECT_END_BUFFER_DAYS;
        $rawEnd = $project->effectiveEndDate()->toDateString();
        $defaultHpd = self::WORKDAY_HOURS;
        $teamSize = count($teamMembers);

        $rawSheetBlock = $rawSheet !== ''
            ? "RAW SHEET CONTENT (your direct view of Estimate.xlsx, sheet `Web_Manhour_Detail` — sanity-check the PARSED TASKS array below against this. If you notice something the parse missed, prefer what's in the sheet):\n\n".$rawSheet."\n\n"
            : '';

        $hasMonthlyAlloc = collect($teamMembers)->contains(fn ($m) => ! empty($m['monthly_allocation']));
        $monthlyAllocBlock = '';
        if ($hasMonthlyAlloc) {
            $monthlyAllocBlock = "MONTHLY ALLOCATION (per-member monthly HOUR BUDGET):\n".
                "Some team members include a `monthly_allocation` array and `team_start_date`.\n".
                "- monthly_allocation[i] × workable_hours is that member's TOTAL hour BUDGET for the i-th calendar month, indexed from team_start_date.\n".
                "  Example: workable_hours=160, allocation=[0.5, 1, 1, 1, 1, 0] starting 2026-06-01 →\n".
                "    June budget = 80h, July budget = 160h, ..., October budget = 160h, November budget = 0h (off the project).\n".
                "- HARD: NEVER assign any phase to a member in a calendar month where their allocation = 0. That month is unavailable.\n".
                "- HARD: For each member, the sum of estimated_hours of their assignments whose [planned_start, planned_end] overlaps a given calendar month must NOT exceed that month's budget.\n".
                "  (For phases spanning a month boundary, apportion hours by working-day overlap with each month.)\n".
                "- You may schedule a member at the normal {$defaultHpd}h/day pace on any working day; the MONTHLY BUDGET is what bounds them. A member with an 8h task in a 0.5 month may legitimately finish it in one working day at full pace, then idle waiting for an upstream phase to unblock the next task.\n".
                "- Reduced-month allocation typically means the member is BLOCKED waiting on upstream work (e.g. devs waiting for the leader's docs), NOT throttled to fewer hours per workday. Phase order within a task already prevents downstream phases from starting before upstream finishes — combine that with the monthly budget to express the dependency-blocked time.\n".
                "- The `capacity.hours_per_day` output is only for genuine daily throttling (e.g. a member shared with another project who can only spare 4h/day every day). Do NOT use it as a proxy for partial-month availability — use the monthly_allocation array for that.\n\n";
        }

        return "Project: {$projectName}\n".
            "Client: {$client}\n".
            "Project window: {$startStr} → {$rawEnd} (raw end). All work must finish on or before {$endStr} ({$buffer}-day buffer before the raw end).\n\n".
            "ACTIVE PHASES (phases that have estimated hours in this project):\n".
            $this->jsonEncode($activePhases)."\n\n".
            "TEAM MEMBERS (existing project team — assign only from this list. Team size: {$teamSize}):\n".
            $this->jsonEncode($teamMembers)."\n\n".
            $monthlyAllocBlock.
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
            "ASSIGNEE RULES (who) — coverage and capacity_role are HARD; caps and windows are SOFT preferences.\n".
            "\n".
            "Priority order when picking each assignee:\n".
            "  1. HARD  — coverage: every (row_no, phase_code) pair in PARSED TASKS MUST receive an assignment. Returning fewer assignments than there are (row × active_phase) pairs is the worst outcome. NEVER skip a phase to satisfy any other rule.\n".
            "  2. HARD  — capacity_role match: each phase has an eligible capacity_role list (see table below). NEVER assign a phase to a member whose capacity_role isn't on that list. A pm MUST NOT be assigned development or testing. A qa MUST NOT be assigned development. A member with `allocated_hours = 0` is on the team but has no engagement for this project — do not assign any phase to them.\n".
            "  3. SOFT  — per-member cap: try to keep Σ(estimated_hours) per assignee within their `allocated_hours`. Some overshoot is acceptable when there's no eligible alternative; in that case spread the overshoot across the pool rather than piling it on one person, and prefer the member with the most remaining capacity. Overshooting a cap is always better than skipping a phase.\n".
            "  4. SOFT  — engagement window: try to keep each member's phases inside `engagement_months × 31` calendar days. A 3-month QA's phases should cluster (typically late in the project). Some spread is acceptable when forced — but again, never drop a phase.\n".
            "  5. PREFER — rank fit: within an eligible capacity_role, prefer highest rank for doc/design (Manager/Lead > Senior > Mid > Junior); for execution phases, rank by TASK difficulty — 簡単→Junior, 普通→Mid, 難しい→Senior (fallback 簡単→Mid→Senior→Lead, 普通→Senior→Junior→Lead, 難しい→Lead→Mid→Junior).\n".
            "  6. PREFER — rotation: when multiple members qualify, rotate; don't pile everything on one person.\n".
            "\n".
            "Eligible capacity_role per phase (use the FIRST role with remaining capacity, fall through ONLY if it's saturated or absent from the team):\n".
            "  | phase_code                                       | eligible capacity_role (in preference order)                                                                  |\n".
            "  |--------------------------------------------------|-----------------------------------------------------------------------------------------------------------------|\n".
            "  | requirement, system_arch, basic_doc, detail_doc  | pm → backend → frontend  (pm preferred; senior dev documents only when pm is unavailable or over-cap)            |\n".
            "  | development                                      | backend → frontend  (managers and qa never code)                                                                |\n".
            "  | unit_test, combine_test, system_test             | qa → backend → frontend  (devs self-test only when qa is over-cap)                                              |\n".
            "\n".
            "What happens when the team is genuinely under-sized: spread the overshoot — DO NOT skip phases. After you finish, every (row, phase) in PARSED TASKS must appear in your `assignments` array. The server will surface over_allocation / engagement_window_exceeded as warnings; that's expected when the team is tight and is the operator's signal to add headcount. A missing assignment, by contrast, is a hard error we cannot recover from.\n\n".
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
            '- Return ONLY the JSON object — no prose, no fences.';
    }

    private function persistTaskAssignments(Project $project, array $tasks, array $assignments, string $tenantId)
    {
        DB::transaction(function () use ($tasks, $assignments, $project, $tenantId) {
            // Cascade deletes child rows in project_task_phase_assignments via FK.
            ProjectTaskAssignment::where('project_id', $project->id)->delete();

            foreach ($tasks as $t) {
                $parent = ProjectTaskAssignment::create([
                    'tenant_id' => $tenantId,
                    'project_id' => $project->id,
                    'row_no' => $t['row_no'],
                    'function_id' => $t['function_id'],
                    'function_name' => $t['function_name'],
                    'difficulty' => $t['difficulty'],
                    'total_hours' => $t['total_hours'],
                ]);

                foreach ($t['phases'] as $phase) {
                    $entry = $assignments[$t['row_no']][$phase['code']] ?? null;
                    ProjectTaskPhaseAssignment::create([
                        'tenant_id' => $tenantId,
                        'task_assignment_id' => $parent->id,
                        'phase_code' => $phase['code'],
                        'phase_name' => $phase['name'],
                        'phase_order' => $phase['order'],
                        'estimated_hours' => $phase['hours'],
                        'start_day_hours' => is_array($entry) ? ($entry['start_day_hours'] ?? null) : null,
                        'assignee_id' => is_array($entry) ? ($entry['assignee_id'] ?? null) : $entry,
                        'planned_start' => is_array($entry) ? ($entry['planned_start'] ?? null) : null,
                        'planned_end' => is_array($entry) ? ($entry['planned_end'] ?? null) : null,
                        'assignment_source' => 'ai',
                        'status' => '未着手',
                    ]);
                }
            }
        });

        $rows = ProjectTaskAssignment::with('phaseAssignments.assignee.rank')
            ->where('project_id', $project->id)
            ->orderBy('row_no')
            ->get();

        $activePhases = $this->activePhasesFromRows($rows, $project);

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
        // Bucket team members by (capacity_role, rank). Members with
        // allocated_hours = 0 are dropped — their team-structure slot doesn't
        // include this project. Manager rank is mapped to Lead so it tops
        // the rank preference chain.
        $allocatedHoursByEmp = [];
        $byRoleAndRank = []; // [capacity_role][rank_code] => [employee_id, ...]
        foreach ($teamAssignments as $a) {
            $cap = (float) $a->allocated_hours;
            if ($cap <= 0.0) {
                continue;
            }
            $tier = optional(optional($a->employee)->rank)->code ?: 'Mid';
            if (! in_array($tier, ['Junior', 'Mid', 'Senior', 'Lead'], true)) {
                $tier = 'Lead'; // Manager / unknown non-ladder ranks
            }
            $role = optional(optional($a->employee)->capacityRole)->code
                ?? optional($a->employee)->capacity_role
                ?? 'unknown';

            $allocatedHoursByEmp[$a->employee_id] = $cap;
            $byRoleAndRank[$role][$tier][] = $a->employee_id;
        }

        if (empty($allocatedHoursByEmp)) {
            return response()->json(['error' => 'No team members with allocated_hours > 0 are available.'], 422);
        }

        $cursors = [];
        $assignedHoursByEmp = [];
        $allocTolerance = 1.0 + AiScheduleValidator::ALLOCATION_TOLERANCE;

        // Rank fallback chain — used INSIDE each capacity_role pool.
        $rankFallback = [
            'Lead' => ['Lead', 'Senior', 'Mid', 'Junior'],
            'Senior' => ['Senior', 'Lead', 'Mid', 'Junior'],
            'Mid' => ['Mid', 'Senior', 'Junior', 'Lead'],
            'Junior' => ['Junior', 'Mid', 'Senior', 'Lead'],
        ];

        // Pick a member for $phaseCode at $preferredTier rank. Walks eligible
        // capacity_roles (from PHASE_CAPACITY_ROLES) in preference order, and
        // within each role walks the rank fallback chain. NEVER assigns
        // outside the eligible capacity_role list — a pm-role member will
        // never get a dev/test phase, a qa-role member will never get a doc
        // phase unless qa explicitly appears in that phase's eligibility.
        $pickFrom = function (string $phaseCode, string $preferredTier, float $phaseHours) use (
            $byRoleAndRank, $allocatedHoursByEmp, $allocTolerance, $rankFallback,
            &$cursors, &$assignedHoursByEmp,
        ) {
            $eligibleRoles = AiScheduleValidator::PHASE_CAPACITY_ROLES[$phaseCode] ?? [];
            $tiers = $rankFallback[$preferredTier] ?? [$preferredTier];

            // Pass 1: respect capacity_role + rank + remaining capacity.
            foreach ($eligibleRoles as $role) {
                foreach ($tiers as $tier) {
                    $pool = $byRoleAndRank[$role][$tier] ?? [];
                    if (empty($pool)) {
                        continue;
                    }
                    $key = "{$role}|{$tier}";
                    $cursors[$key] = $cursors[$key] ?? 0;
                    $n = count($pool);
                    for ($i = 0; $i < $n; $i++) {
                        $idx = ($cursors[$key] + $i) % $n;
                        $id = $pool[$idx];
                        $cap = $allocatedHoursByEmp[$id] ?? PHP_FLOAT_MAX;
                        $used = $assignedHoursByEmp[$id] ?? 0.0;
                        if ($used + $phaseHours <= $cap * $allocTolerance) {
                            $cursors[$key] = $idx + 1;
                            $assignedHoursByEmp[$id] = $used + $phaseHours;

                            return $id;
                        }
                    }
                }
            }

            // Pass 2: everyone in eligible roles is at cap — round-robin
            // within eligible roles only. Operator should fix the team
            // structure (add headcount in this role_type).
            foreach ($eligibleRoles as $role) {
                foreach ($tiers as $tier) {
                    $pool = $byRoleAndRank[$role][$tier] ?? [];
                    if (empty($pool)) {
                        continue;
                    }
                    $key = "{$role}|{$tier}";
                    $cursors[$key] = $cursors[$key] ?? 0;
                    $id = $pool[$cursors[$key] % count($pool)];
                    $cursors[$key]++;
                    $assignedHoursByEmp[$id] = ($assignedHoursByEmp[$id] ?? 0.0) + $phaseHours;

                    return $id;
                }
            }

            return null; // No eligible team member at all.
        };

        $designPhases = ['requirement', 'system_arch', 'basic_doc', 'detail_doc'];
        $executionTier = [
            '簡単' => 'Junior',
            '普通' => 'Mid',
            '難しい' => 'Senior',
        ];

        // Phase 1: pick assignees only. Phase 2: computePlannedDates() does the cursor math.
        $assigneeByRowPhase = [];
        foreach ($tasks as $t) {
            foreach ($t['phases'] as $phase) {
                $preferredTier = in_array($phase['code'], $designPhases, true)
                    ? 'Lead'
                    : ($executionTier[$t['difficulty']] ?? 'Mid');

                $id = $pickFrom($phase['code'], $preferredTier, (float) ($phase['hours'] ?? 0));
                if ($id === null) {
                    Log::warning('AI Schedule (demo): no eligible team member for phase', [
                        'project_id' => $project->id,
                        'row_no' => $t['row_no'],
                        'phase_code' => $phase['code'],
                    ]);

                    continue;
                }
                $assigneeByRowPhase[$t['row_no']][$phase['code']] = $id;
            }
        }

        // Surface members the demo had to push past their cap so the operator
        // sees the same signal the AI validator would have raised. Schedule
        // still persists — the warning prompts a team-structure fix.
        $overCap = [];
        foreach ($assignedHoursByEmp as $id => $used) {
            $cap = $allocatedHoursByEmp[$id] ?? null;
            if ($cap !== null && $used > $cap * $allocTolerance) {
                $overCap[] = [
                    'employee_id' => $id,
                    'assigned_hours' => $used,
                    'allocated_hours' => $cap,
                    'overshoot' => $used - $cap,
                ];
            }
        }
        if (! empty($overCap)) {
            Log::warning('AI Schedule (demo): exceeded per-member allocated_hours cap', [
                'project_id' => $project->id,
                'members' => $overCap,
            ]);
        }

        $assignments = $this->computePlannedDates($tasks, $assigneeByRowPhase, $windowStart, $effectiveEnd, $calendar);

        return $this->persistTaskAssignments($project, $tasks, $assignments, $tenantId);
    }

    private function activePhasesFromRows($rows, ?Project $project = null): array
    {
        $byCode = [];
        foreach ($rows as $row) {
            foreach ($row->phaseAssignments as $pa) {
                $byCode[$pa->phase_code] = [
                    'code' => $pa->phase_code,
                    'name' => $pa->phase_name,
                    'order' => (int) $pa->phase_order,
                ];
            }
        }

        // Also surface phases that are declared in the Estimate.xlsx with
        // actual hours but have no persisted assignments yet (e.g. between
        // task-detection and AI assignment). Phases with zero hours across
        // all tasks are filtered out upstream in readEstimateSheet().
        try {
            $resolvedPath = null;
            if ($project) {
                $resolver = app(EstimateFileResolver::class);
                $resolvedPath = $resolver->latestForProject($project)
                    ?? $resolver->tenantFallbackPath($project->tenant_id);
            }
            // No file resolved → fall through to DB-only phases, no warning.
            // The /assign-tasks endpoint enforces the 422; the read endpoint
            // can legitimately render before any AI run.
            if ($resolvedPath === null) {
                return array_values($byCode);
            }
            $declared = $this->readEstimateSheet($resolvedPath)['active_phases'] ?? [];
            foreach ($declared as $p) {
                if (! isset($byCode[$p['code']])) {
                    $byCode[$p['code']] = [
                        'code' => $p['code'],
                        'name' => $p['name'],
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

    /**
     * Per chg-018: every Claude call must land in ai_usage_logs so the
     * super-admin cost ledger and per-tenant aggregation are complete.
     * Best-effort — a logger failure must NOT break the AI flow.
     */
    private function logUsage(string $tenantId, array $usage, string $feature, string $model): void
    {
        try {
            $inputTokens = (int) ($usage['input_tokens'] ?? 0);
            $outputTokens = (int) ($usage['output_tokens'] ?? 0);
            // Claude 3.5 Sonnet public pricing — keep in sync with
            // EstimationAiService::estimateCost until chg-018 centralises rates.
            $costUsd = round(($inputTokens / 1_000_000) * 3 + ($outputTokens / 1_000_000) * 15, 6);

            AiUsageLog::create([
                'tenant_id' => $tenantId,
                'user_id' => auth()->id(),
                'feature' => $feature,
                'model' => $model,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'estimated_cost_usd' => $costUsd,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AiAutoAssign: failed to log AI usage', ['error' => $e->getMessage()]);
        }
    }
}
