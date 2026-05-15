<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Rich context endpoint feeding the AI Team Builder. Returns each active
 * employee enriched with their rank + a small set of past projects so
 * Claude can make seniority-aware, experience-weighted staffing decisions.
 *
 * Endpoint: GET /deals/{deal}/ai-team-builder-context
 *
 * Why a dedicated endpoint (not GET /employees):
 *  - Past-project context is only useful for the AI Team Builder; bolting
 *    it onto /employees would inflate every page load that uses employees.
 *  - The output shape is intentionally tuned for the AI prompt (truncated
 *    workload descriptions, only relevant projects, cap of 3 per employee).
 *
 * Past-project filter rules (from chg-009 plan):
 *  - Active + Completed work counts. The schema's status enum varies by
 *    tenant ("Active" / "On Track" / "At Risk" / "Completed"), so the
 *    filter excludes only the negative cases ("Cancelled", "Not Started")
 *    rather than allow-listing — that way custom statuses still surface.
 *  - Exclude the current deal's project if it somehow already exists.
 *  - Cap at 3 per employee, most-recent first by start_date.
 *  - Workload description truncated to 500 chars.
 *  - Skip past-projects entirely for employees whose skills don't overlap
 *    any of the deal's required skills — saves prompt tokens on the long
 *    tail (a junior with no relevant skills doesn't need past-projects
 *    to be evaluated).
 */
class AiTeamBuilderContextController extends Controller
{
    private const MAX_PAST_PROJECTS_PER_EMPLOYEE = 3;
    private const PAST_PROJECT_DESCRIPTION_MAX_CHARS = 500;
    /**
     * Statuses we EXCLUDE from past-project context. Everything else counts
     * as "real prior work" — covers tenant-specific variations like the
     * Brycen seed data which uses "On Track" / "At Risk" instead of "Active".
     */
    private const EXCLUDED_PROJECT_STATUSES = ['Cancelled', 'Not Started'];

    public function show(Deal $deal)
    {
        $tenantId = app('tenant_id');

        $deal->load(['ghost_roles']);

        // Extract required-skill keywords from the deal's workload description
        // for the skill-overlap optimisation. Using the same naive token split
        // the frontend's extractRequiredSkills does — we want consistency, not
        // perfection here; a skill that the heuristic misses still goes into
        // the prompt because we only USE this to skip past-projects, not to
        // skip employees.
        $dealKeywords = $this->extractKeywords($deal->workload_description ?? '');

        $employees = Employee::with(['rank', 'skills', 'capacityRole'])
            ->where('tenant_id', $tenantId)
            ->where('status', 'Active')
            ->get();

        // Build per-employee past-projects map in one query rather than N.
        // Eloquent: gather every active/completed assignment for these employees
        // joined to projects + deals, sort newest first, then bucket by employee.
        $employeeIds = $employees->pluck('id')->all();
        $pastByEmployee = $this->loadPastProjects($employeeIds, $tenantId, $deal->id);

        // Note: the chg-009 plan included a "skip past-projects for employees
        // with no skill overlap" optimisation. In practice on agency-scale
        // tenants (~30 employees, ~10k input tokens for the past-projects
        // payload) the saved tokens aren't worth the cases it hides — a
        // generic deal description like "active sales opportunity with
        // scoped workshops" has no keyword overlap with anyone's skills,
        // so the optimisation filtered out 100% of past projects on demo
        // data. Re-enable when we see real prompts hitting token limits.
        unset($dealKeywords); // Reserved for the future optimisation.

        $payload = $employees->map(function (Employee $emp) use ($pastByEmployee) {
            $pastProjects = $pastByEmployee[$emp->id] ?? [];

            return [
                'id' => $emp->id,
                'name' => $emp->name,
                'role' => $emp->role,
                'role_name' => $emp->role_name,
                'capacity_role' => $emp->capacityRole?->code ?? $emp->capacity_role,
                'capacity_role_name' => $emp->capacityRole?->name,
                'monthly_salary' => (float) $emp->monthly_salary,
                'workable_hours' => (int) $emp->workable_hours,
                'cost_per_hour' => (float) $emp->cost_per_hour,
                'status' => $emp->status,
                'rank' => $emp->rank ? [
                    'id' => $emp->rank->id,
                    'code' => $emp->rank->code,
                    'name' => $emp->rank->name,
                    'level' => (int) $emp->rank->level,
                ] : null,
                'skills' => $emp->skills->map(fn ($s) => [
                    'skill_id' => $s->id,
                    'name' => $s->name,
                    'category' => $s->category,
                    'proficiency' => $s->pivot->proficiency,
                ])->values()->all(),
                'past_projects' => $pastProjects,
            ];
        })->values()->all();

        return response()->json([
            'data' => [
                'employees' => $payload,
            ],
        ]);
    }

    /**
     * Load up to MAX_PAST_PROJECTS_PER_EMPLOYEE projects per employee, in
     * one SQL pass. We can't use a SQL `LIMIT` per group portably across
     * Postgres + SQLite, so we over-fetch by ordering then truncate in PHP.
     */
    private function loadPastProjects(array $employeeIds, string $tenantId, string $excludeDealId): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $rows = DB::table('project_team_assignments as pta')
            ->join('projects as p', 'p.id', '=', 'pta.project_id')
            ->join('contracts as c', 'c.id', '=', 'p.contract_id')
            ->join('deals as d', 'd.id', '=', 'c.deal_id')
            ->whereIn('pta.employee_id', $employeeIds)
            ->where('pta.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->whereNotIn('p.status', self::EXCLUDED_PROJECT_STATUSES)
            ->where('d.id', '!=', $excludeDealId)
            ->orderBy('p.start_date', 'desc')
            ->orderBy('p.created_at', 'desc')
            ->select([
                'pta.employee_id',
                'p.id as project_id',
                'p.name as project_name',
                'p.client as project_client',
                'p.status as project_status',
                'p.start_date',
                'd.workload_description as deal_description',
            ])
            ->get();

        $byEmployee = [];
        foreach ($rows as $row) {
            $bucket = $row->employee_id;
            $byEmployee[$bucket] ??= [];
            if (count($byEmployee[$bucket]) >= self::MAX_PAST_PROJECTS_PER_EMPLOYEE) {
                continue;
            }
            $byEmployee[$bucket][] = [
                'id' => $row->project_id,
                'name' => $row->project_name,
                'client' => $row->project_client,
                'status' => $row->project_status,
                'start_date' => $row->start_date,
                'deal_description' => $this->truncate(
                    (string) $row->deal_description,
                    self::PAST_PROJECT_DESCRIPTION_MAX_CHARS,
                ),
            ];
        }

        return $byEmployee;
    }

    private function extractKeywords(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $lower = strtolower($text);
        $tokens = preg_split('/[^a-z0-9+#.]+/i', $lower) ?: [];

        // Drop common stop-words and 1-2 letter tokens — they don't carry
        // skill signal and would match every project trivially.
        $stopwords = [
            'the', 'and', 'for', 'with', 'will', 'that', 'this', 'are', 'our',
            'from', 'have', 'into', 'their', 'project', 'system', 'service',
            'app', 'apps', 'work', 'team', 'all', 'any', 'one', 'two', 'three',
        ];

        $keywords = [];
        foreach ($tokens as $t) {
            if (mb_strlen($t) < 3) {
                continue;
            }
            if (in_array($t, $stopwords, true)) {
                continue;
            }
            $keywords[$t] = true;
        }

        return array_keys($keywords);
    }

    private function truncate(string $s, int $max): string
    {
        if ($s === '' || mb_strlen($s) <= $max) {
            return $s;
        }

        return mb_substr($s, 0, $max - 1).'…';
    }
}
