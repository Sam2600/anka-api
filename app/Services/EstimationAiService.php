<?php

namespace App\Services;

use App\Models\AiUsageLog;
use App\Models\CapacityRole;
use App\Models\Deal;
use App\Models\DealContractDocument;
use App\Models\DealHardAssignment;
use App\Models\Employee;
use App\Models\EstimationVersion;
use App\Models\Role;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks Claude to produce a structured first-draft estimation from a Deal's
 * context. Output is shaped per-XLSX-sheet so it can be handed straight to
 * EstimationXlsxService without remapping.
 *
 * Does NOT persist anything — callers (EstimationVersionController@aiDraft)
 * return the JSON to the frontend, which loads it into the simulator's
 * editable state. The user reviews/edits and the normal version-save path
 * is what actually creates the EstimationVersion.
 */
class EstimationAiService
{
    private const MODEL = 'claude-sonnet-4-6';

    private const DEFAULT_BASE_URL = 'https://api.anthropic.com';

    private const PROMPT_TEMPLATE_PATH = 'resources/prompts/estimation_generation.txt';

    private const DELTA_PROMPT_TEMPLATE_PATH = 'resources/prompts/estimation_delta.txt';

    private const CONTRACT_TEXT_LIMIT = 50_000;

    private const FEW_SHOT_LIMIT = 3;

    public function generateDraft(Deal $deal): array
    {
        $apiKey = config('services.anthropic.api_key') ?? env('ANTHROPIC_API_KEY');
        if (! $apiKey) {
            throw new \RuntimeException('ANTHROPIC_API_KEY not configured.');
        }

        $prompt = $this->buildPrompt($deal);

        try {
            return $this->callClaude($apiKey, $prompt, $deal);
        } catch (Throwable $e) {
            // Only retry on JSON-shape failures, where a tightened "JSON only"
            // preamble helps. Skip retry on transport-level failures (timeouts,
            // network) — the proxy is the bottleneck there and a second call
            // just doubles the wait. Detect by looking for the strings our
            // RuntimeException raises from non-JSON output.
            $msg = $e->getMessage();
            $isJsonFailure = str_contains($msg, 'returned non-JSON')
                || str_contains($msg, 'missing key:')
                || str_contains($msg, 'function_ids do not match')
                || str_contains($msg, 'is missing a `role` field');

            if (! $isJsonFailure) {
                Log::warning('EstimationAi: call failed (no retry)', [
                    'deal_id' => $deal->id,
                    'error' => $msg,
                ]);
                throw $e;
            }

            Log::info('EstimationAi: first call returned bad JSON, retrying once', [
                'deal_id' => $deal->id,
                'error' => $msg,
            ]);

            return $this->callClaude(
                $apiKey,
                "Respond with valid JSON only — no markdown, no preface, no fences.\n\n".$prompt,
                $deal,
            );
        }
    }

    /**
     * Suggest a structured diff (add / remove / modify) of scope rows and
     * overheads based on customer meeting notes. Reads-only on the deal —
     * the caller applies the accepted changes and persists a new version.
     *
     * $currentResources and $currentOverheads are the live deal state shaped
     * as the controller passes them: each resource has feature_name + role +
     * hours; each overhead has name + cost. Anything else is ignored.
     */
    public function generateDelta(
        Deal $deal,
        string $contextNotes,
        array $currentResources,
        array $currentOverheads,
        array $currentRoles = [],
    ): array {
        $apiKey = config('services.anthropic.api_key') ?? env('ANTHROPIC_API_KEY');
        if (! $apiKey) {
            throw new \RuntimeException('ANTHROPIC_API_KEY not configured.');
        }

        $prompt = $this->buildDeltaPrompt($deal, $contextNotes, $currentResources, $currentOverheads, $currentRoles);

        try {
            $delta = $this->callClaudeRaw($apiKey, $prompt, $deal, 'estimation_delta');
        } catch (Throwable $e) {
            // Same retry policy as generateDraft: only retry JSON-shape failures
            // because they're fixed by a strict "JSON only" preamble. Transport
            // failures aren't.
            $msg = $e->getMessage();
            $isJsonFailure = str_contains($msg, 'returned non-JSON')
                || str_contains($msg, 'delta missing key:');
            if (! $isJsonFailure) {
                throw $e;
            }

            Log::info('EstimationAi: delta first call returned bad JSON, retrying once', [
                'deal_id' => $deal->id,
                'error' => $msg,
            ]);
            $delta = $this->callClaudeRaw(
                $apiKey,
                "Respond with valid JSON only — no markdown, no preface, no fences.\n\n".$prompt,
                $deal,
                'estimation_delta',
            );
        }

        // Older prompts / the occasional vague-notes response may omit the
        // roles section entirely. Default it to an empty diff so a missing
        // `roles` key never 503s an otherwise-valid scope/overhead delta.
        if (! isset($delta['roles']) || ! is_array($delta['roles'])) {
            $delta['roles'] = ['add' => [], 'remove' => [], 'modify' => []];
        }
        foreach (['add', 'remove', 'modify'] as $op) {
            if (! isset($delta['roles'][$op]) || ! is_array($delta['roles'][$op])) {
                $delta['roles'][$op] = [];
            }
        }

        $this->validateDeltaShape($delta);

        return $delta;
    }

    private function callClaude(string $apiKey, string $prompt, Deal $deal): array
    {
        $baseUrl = rtrim(env('ANTHROPIC_BASE_URL') ?: self::DEFAULT_BASE_URL, '/');

        // 180s was 90s — bumped after the proxy (api.vibecode-claude.online)
        // started consistently exceeding 90s when the prompt grew to include
        // project_overheads. Sonnet itself usually completes in 30-60s; the
        // headroom is for proxy overhead on long structured responses.
        $response = Http::timeout(180)
            ->connectTimeout(15)
            ->withHeaders([
                'x-api-key' => $apiKey,
                'authorization' => 'Bearer '.$apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
            ->post($baseUrl.'/v1/messages', [
                'model' => self::MODEL,
                'max_tokens' => 8192,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if (! $response->successful()) {
            $body = $response->body();
            throw new \RuntimeException("Claude API error ({$response->status()}): ".mb_substr($body, 0, 400));
        }

        $body = $response->json();

        if (isset($body['usage'])) {
            $this->logUsage($deal, $body['usage']);
        }

        $raw = trim($body['content'][0]['text'] ?? '');
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/', '', $raw);
        }

        $draft = json_decode($raw, true);
        if (! is_array($draft)) {
            throw new \RuntimeException('Claude returned non-JSON: '.mb_substr($raw, 0, 200));
        }

        $this->validateShape($draft);
        $this->suggestEmployees($draft, $deal);

        return $draft;
    }

    /**
     * After Claude returns role-per-feature, look at each role's employees and
     * pick the one with the most available hours for the deal's start month.
     * Writes a `suggested_employee_id` onto each sheet3_manhours row.
     *
     * Availability is the same formula used elsewhere in the app:
     *   capacity − Σ(allocated_hours ÷ timeline_months) − Σ(approved time entries)
     *
     * Picks for distinct roles are independent; one employee can be suggested
     * for many features if they're the most available person for their role.
     * Falls back to null when no employee matches the role or every candidate
     * is over-allocated.
     */
    private function suggestEmployees(array &$draft, Deal $deal): void
    {
        $manhours = $draft['sheet3_manhours'] ?? [];
        if (empty($manhours)) {
            return;
        }

        $roles = array_values(array_unique(array_filter(array_map(
            fn ($m) => is_string($m['role'] ?? null) ? trim($m['role']) : null,
            $manhours,
        ))));
        if (empty($roles)) {
            return;
        }

        $start = $deal->expected_close_date
            ? Carbon::parse($deal->expected_close_date)->startOfMonth()
            : now()->startOfMonth();
        $monthStart = $start->copy();
        $monthEnd = $start->copy()->endOfMonth();

        $bestByRole = [];
        foreach ($roles as $roleTitle) {
            try {
                $bestByRole[$roleTitle] = $this->mostAvailableEmployeeForRole(
                    $deal->tenant_id,
                    $roleTitle,
                    $monthStart,
                    $monthEnd,
                );
            } catch (Throwable $e) {
                Log::warning('EstimationAi: employee suggestion failed for role', [
                    'role' => $roleTitle,
                    'error' => $e->getMessage(),
                ]);
                $bestByRole[$roleTitle] = null;
            }
        }

        foreach ($draft['sheet3_manhours'] as &$row) {
            $roleTitle = is_string($row['role'] ?? null) ? trim($row['role']) : null;
            $row['suggested_employee_id'] = $roleTitle ? ($bestByRole[$roleTitle] ?? null) : null;
        }
        unset($row);
    }

    /**
     * Resolve the role title to candidate employees in the same tenant. Picks
     * the active employee with the highest (capacity − committed − logged)
     * hours for the given month window. Returns null when no candidate has
     * non-negative availability.
     */
    private function mostAvailableEmployeeForRole(
        string $tenantId,
        string $roleTitle,
        Carbon $monthStart,
        Carbon $monthEnd,
    ): ?string {
        // Role lookup is fuzzy on the title to absorb minor Claude wording
        // drift (e.g. "Backend Developer" vs the org's "Backend Engineer").
        // If the AI prompt held to the verbatim rule this still matches.
        $role = Role::where('tenant_id', $tenantId)
            ->where(function ($q) use ($roleTitle) {
                $q->where('title', $roleTitle)
                    ->orWhere('title', 'like', '%'.$roleTitle.'%');
            })
            ->first();
        if (! $role) {
            return null;
        }

        $candidates = Employee::where('tenant_id', $tenantId)
            ->where('status', 'Active')
            ->where('job_role_id', $role->id)
            ->get(['id', 'workable_hours']);
        if ($candidates->isEmpty()) {
            return null;
        }

        $bestId = null;
        $bestAvail = PHP_INT_MIN;
        foreach ($candidates as $emp) {
            $committed = DealHardAssignment::query()
                ->where('employee_id', $emp->id)
                ->join('deals', 'deals.id', '=', 'deal_hard_assignments.deal_id')
                ->whereIn('deals.status', ['won', 'negotiation'])
                ->where('deals.tenant_id', $tenantId)
                ->selectRaw('COALESCE(SUM(allocated_hours / NULLIF(deals.timeline_months, 0)), 0) AS committed')
                ->value('committed');

            $logged = (float) DB::table('time_entries')
                ->where('tenant_id', $tenantId)
                ->where('employee_id', $emp->id)
                ->where('status', 'Approved')
                ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->sum('hours');

            $avail = (float) $emp->workable_hours - (float) $committed - $logged;
            if ($avail > $bestAvail) {
                $bestAvail = $avail;
                $bestId = $emp->id;
            }
        }

        // Don't suggest anyone whose availability is negative — sales should
        // see "no one free" rather than pile more onto an over-booked person.
        return $bestAvail >= 0 ? $bestId : null;
    }

    /**
     * Sanity-check the response shape. Throws on the few things that would
     * make downstream XLSX generation impossible. Doesn't enforce every
     * minor detail — Claude generally produces a clean response and the
     * writer is tolerant of missing optional fields.
     */
    private function validateShape(array $draft): void
    {
        $required = ['sheet1_summary', 'sheet2_features', 'sheet3_manhours', 'sheet4_milestone', 'sheet5_team_stack'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $draft)) {
                throw new \RuntimeException("Claude response missing key: {$key}");
            }
        }

        // function_id 1:1 match between sheet2 and sheet3
        $sheet2Ids = array_column($draft['sheet2_features'] ?? [], 'function_id');
        $sheet3Ids = array_column($draft['sheet3_manhours'] ?? [], 'function_id');
        if (count($sheet2Ids) !== count($sheet3Ids) || array_diff($sheet2Ids, $sheet3Ids)) {
            throw new \RuntimeException(
                'sheet2_features and sheet3_manhours function_ids do not match 1:1.',
            );
        }

        // Each manhour row needs a role. Without it, the frontend has no way
        // to choose a sensible role per feature and we'd be back to defaulting
        // every row to store.roles[0] — which is exactly the bug we just fixed.
        foreach ($draft['sheet3_manhours'] ?? [] as $i => $row) {
            if (empty($row['role']) || ! is_string($row['role'])) {
                throw new \RuntimeException("sheet3_manhours[{$i}] is missing a `role` field.");
            }
        }
    }

    private function buildPrompt(Deal $deal): string
    {
        $tplPath = base_path(self::PROMPT_TEMPLATE_PATH);
        if (! is_file($tplPath)) {
            throw new \RuntimeException('Estimation prompt template missing at '.self::PROMPT_TEMPLATE_PATH);
        }

        $template = file_get_contents($tplPath);

        return strtr($template, [
            '{{CLIENT_NAME}}' => $deal->client ?? '(unknown)',
            '{{WORKLOAD_DESCRIPTION}}' => trim((string) ($deal->workload_description ?? '(none provided)')),
            '{{CLIENT_BUDGET}}' => $deal->client_budget !== null ? (string) $deal->client_budget : '(unknown)',
            '{{TIMELINE_MONTHS}}' => $deal->timeline_months !== null ? (string) $deal->timeline_months : '(unknown)',
            '{{TARGET_MARGIN}}' => $deal->target_margin !== null ? $deal->target_margin.'%' : '(not set)',
            '{{EXPECTED_CLOSE_DATE}}' => $deal->expected_close_date ?? '(unset)',
            '{{CONTRACT_DOCUMENTS_BLOCK}}' => $this->buildContractDocsBlock($deal),
            '{{ORG_ROLES_BLOCK}}' => $this->buildRolesBlock($deal),
            '{{FEW_SHOT_DEALS_BLOCK}}' => $this->buildFewShotBlock($deal),
        ]);
    }

    private function buildContractDocsBlock(Deal $deal): string
    {
        // Pull approved+pending contract docs for this deal. The chg-005
        // pipeline already extracted text into analysis_result when the doc
        // was approved; failed/rejected docs are skipped here so the prompt
        // only sees verified content.
        $docs = DealContractDocument::where('deal_id', $deal->id)
            ->whereIn('analysis_status', ['approved', 'pending'])
            ->get();

        if ($docs->isEmpty()) {
            return '(no contract documents attached)';
        }

        $out = [];
        $remaining = self::CONTRACT_TEXT_LIMIT;
        foreach ($docs as $doc) {
            // The extracted text is not persisted as a single column — we
            // re-extract on the fly via the analysis service. Cheap because
            // these documents are small and already on disk.
            try {
                $svc = app(ContractAnalysisService::class);
                $reflect = new \ReflectionMethod($svc, 'extractText');
                $reflect->setAccessible(true);
                $text = (string) $reflect->invoke($svc, $doc);
            } catch (Throwable $e) {
                Log::warning('EstimationAi: contract doc text extraction failed', [
                    'doc_id' => $doc->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $slice = mb_substr($text, 0, max(0, $remaining));
            $remaining -= mb_strlen($slice);
            $out[] = '## '.$doc->original_filename."\n".$slice;
            if ($remaining <= 0) {
                break;
            }
        }

        return $out ? implode("\n\n", $out) : '(no contract documents attached)';
    }

    private function buildRolesBlock(Deal $deal): string
    {
        $roles = Role::query()
            ->where('tenant_id', $deal->tenant_id)
            ->orderBy('title')
            ->get(['title', 'department', 'rate']);

        if ($roles->isEmpty()) {
            return '(no roles defined for this tenant)';
        }

        $lines = $roles->map(fn ($r) => sprintf(
            '- %s (%s) — billable rate %s/hr',
            $r->title,
            $r->department ?? 'unspecified',
            $r->rate ?? 0,
        ));

        return $lines->implode("\n");
    }

    private function buildFewShotBlock(Deal $deal): string
    {
        // Most-recent won deals from the same tenant, with their latest
        // version's resources as the example.
        $past = Deal::query()
            ->where('tenant_id', $deal->tenant_id)
            ->where('status', 'won')
            ->where('id', '!=', $deal->id)
            ->orderBy('updated_at', 'desc')
            ->limit(self::FEW_SHOT_LIMIT)
            ->get();

        if ($past->count() < 2) {
            // Don't include a thin few-shot block — the prompt is clearer
            // without it than with one stale example.
            return '(insufficient won-deal history — skipped)';
        }

        $sections = [];
        foreach ($past as $p) {
            $latestVersion = EstimationVersion::where('deal_id', $p->id)
                ->orderBy('version_number', 'desc')
                ->first();
            $resources = $latestVersion?->resources ?? [];
            $resLines = collect($resources)
                ->filter(fn ($r) => ! isset($r['_sheet1_summary']))
                ->take(20)
                ->map(fn ($r) => '  - '.($r['feature_name'] ?? $r['featureName'] ?? 'unnamed').
                    ' ('.($r['hours'] ?? 0).'h)')
                ->implode("\n");

            $sections[] = sprintf(
                "## Past deal — %s (%s, budget %s, timeline %s mo)\n%s\nFeatures:\n%s",
                $p->name ?? 'unnamed deal',
                $p->client ?? 'unknown client',
                $p->client_budget ?? '?',
                $p->timeline_months ?? '?',
                trim((string) ($p->workload_description ?? '')) ?: '(no description)',
                $resLines ?: '  (no features recorded)',
            );
        }

        return implode("\n\n", $sections);
    }

    private function logUsage(Deal $deal, array $usage, string $feature = 'estimation_generation'): void
    {
        try {
            AiUsageLog::create([
                'tenant_id' => $deal->tenant_id,
                'user_id' => auth()->id(),
                'feature' => $feature,
                'model' => self::MODEL,
                'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
                'estimated_cost_usd' => $this->estimateCost(
                    (int) ($usage['input_tokens'] ?? 0),
                    (int) ($usage['output_tokens'] ?? 0),
                ),
            ]);
        } catch (Throwable $e) {
            Log::warning('EstimationAi: failed to log AI usage', ['error' => $e->getMessage()]);
        }
    }

    private function estimateCost(int $inputTokens, int $outputTokens): float
    {
        // Claude Sonnet 4.6 public pricing — same numbers as ContractAnalysisService.
        return round(($inputTokens / 1_000_000) * 3 + ($outputTokens / 1_000_000) * 15, 6);
    }

    // ── Delta flow helpers (Suggest Changes from Notes) ───────────────────

    /**
     * HTTP-only sibling of callClaude — does NOT run the full-draft validators
     * (validateShape / suggestEmployees), so callers like generateDelta can
     * apply their own shape checks. Same retries/timeouts/usage-logging.
     */
    private function callClaudeRaw(string $apiKey, string $prompt, Deal $deal, string $featureLabel): array
    {
        $baseUrl = rtrim(env('ANTHROPIC_BASE_URL') ?: self::DEFAULT_BASE_URL, '/');

        $response = Http::timeout(180)
            ->connectTimeout(15)
            ->withHeaders([
                'x-api-key' => $apiKey,
                'authorization' => 'Bearer '.$apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
            ->post($baseUrl.'/v1/messages', [
                'model' => self::MODEL,
                'max_tokens' => 4096,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("Claude API error ({$response->status()}): ".mb_substr($response->body(), 0, 400));
        }

        $body = $response->json();

        if (isset($body['usage'])) {
            $this->logUsage($deal, $body['usage'], $featureLabel);
        }

        $raw = trim($body['content'][0]['text'] ?? '');
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/', '', $raw);
        }

        $parsed = json_decode($raw, true);
        if (! is_array($parsed)) {
            throw new \RuntimeException('Claude returned non-JSON: '.mb_substr($raw, 0, 200));
        }

        return $parsed;
    }

    private function buildDeltaPrompt(
        Deal $deal,
        string $contextNotes,
        array $currentResources,
        array $currentOverheads,
        array $currentRoles = [],
    ): string {
        $tplPath = base_path(self::DELTA_PROMPT_TEMPLATE_PATH);
        if (! is_file($tplPath)) {
            throw new \RuntimeException('Estimation delta prompt template missing at '.self::DELTA_PROMPT_TEMPLATE_PATH);
        }

        $template = file_get_contents($tplPath);

        return strtr($template, [
            '{{CLIENT_NAME}}' => $deal->client ?? '(unknown)',
            '{{CLIENT_BUDGET}}' => $deal->client_budget !== null ? (string) $deal->client_budget : '(unknown)',
            '{{CURRENCY}}' => $deal->final_currency ?? '(unset)',
            '{{CURRENT_RESOURCES_BLOCK}}' => $this->formatResourcesForPrompt($currentResources),
            '{{CURRENT_OVERHEADS_BLOCK}}' => $this->formatOverheadsForPrompt($currentOverheads),
            '{{CURRENT_ROLES_BLOCK}}' => $this->formatRolesForPrompt($currentRoles),
            '{{CONTEXT_NOTES}}' => trim($contextNotes) === '' ? '(empty)' : trim($contextNotes),
            '{{ORG_ROLES_BLOCK}}' => $this->buildRolesBlock($deal),
            '{{CAPACITY_ROLES_BLOCK}}' => $this->buildCapacityRolesBlock($deal),
            '{{DELIVERY_SALARY_BLOCK}}' => $this->buildDeliverySalaryBlock($deal),
        ]);
    }

    private function formatResourcesForPrompt(array $resources): string
    {
        if (empty($resources)) {
            return '(none — the deal has no scope rows yet)';
        }
        $lines = [];
        foreach ($resources as $r) {
            $name = $r['feature_name'] ?? $r['featureName'] ?? '?';
            $role = $r['role'] ?? $r['role_title'] ?? $r['roleTitle'] ?? '?';
            $hours = $r['hours'] ?? 0;
            $lines[] = "- \"{$name}\" — role: {$role}, hours: {$hours}";
        }

        return implode("\n", $lines);
    }

    private function formatOverheadsForPrompt(array $overheads): string
    {
        if (empty($overheads)) {
            return '(none)';
        }
        $lines = [];
        foreach ($overheads as $o) {
            $name = $o['name'] ?? '?';
            $cost = $o['cost'] ?? 0;
            $lines[] = "- \"{$name}\" — cost: {$cost}";
        }

        return implode("\n", $lines);
    }

    /**
     * Current project staffing mix (deal ghost roles) as fed by the frontend.
     * Accepts both snake_case and camelCase keys so it works whether the rows
     * come straight off the request or from an internal array.
     */
    private function formatRolesForPrompt(array $roles): string
    {
        if (empty($roles)) {
            return '(none — the deal has no project roles yet)';
        }
        $lines = [];
        foreach ($roles as $r) {
            $type = $r['role_type'] ?? $r['roleType'] ?? '?';
            $qty = $r['quantity'] ?? 1;
            $months = $r['months'] ?? 1;
            $min = $r['min_monthly_salary'] ?? $r['minMonthlySalary'] ?? 0;
            $max = $r['max_monthly_salary'] ?? $r['maxMonthlySalary'] ?? 0;
            $lines[] = "- {$type} — qty {$qty}, {$months} months, monthly salary {$min}–{$max}";
        }

        return implode("\n", $lines);
    }

    /**
     * Tenant's capacity-role taxonomy (code + display name). The delta AI may
     * only emit a `role_type` that appears here verbatim — this is what makes
     * the role mix dynamic instead of a hardcoded frontend/backend/... enum.
     */
    private function buildCapacityRolesBlock(Deal $deal): string
    {
        $roles = CapacityRole::query()
            ->where('tenant_id', $deal->tenant_id)
            ->orderBy('name')
            ->get(['code', 'name']);

        if ($roles->isEmpty()) {
            return '(no capacity roles defined — do not propose any role changes)';
        }

        return $roles
            ->map(fn ($r) => "- {$r->code} ({$r->name})")
            ->implode("\n");
    }

    /**
     * Observed monthly salary ranges per capacity role, computed ONLY from
     * employees in delivery-eligible departments (departments.is_delivery_eligible
     * = true). Non-delivery staff (Sales/HR/Finance) are excluded so their pay
     * doesn't skew the AI's salary anchors. Grounds the role-delta salary
     * proposals in real tenant data.
     */
    private function buildDeliverySalaryBlock(Deal $deal): string
    {
        $rows = Employee::query()
            ->where('employees.tenant_id', $deal->tenant_id)
            ->join('departments', 'employees.department_id', '=', 'departments.id')
            ->join('capacity_roles', 'employees.capacity_role_id', '=', 'capacity_roles.id')
            ->where('departments.is_delivery_eligible', true)
            ->get(['capacity_roles.code as code', 'employees.monthly_salary as monthly_salary']);

        if ($rows->isEmpty()) {
            return '(no salary data for delivery-eligible departments — propose conservative ranges anchored to the deal budget)';
        }

        $lines = $rows
            ->groupBy('code')
            ->map(function ($group, $code) {
                $salaries = $group->pluck('monthly_salary')->map(fn ($s) => (float) $s)->sort()->values();
                $min = (int) round($salaries->first());
                $max = (int) round($salaries->last());
                $count = $salaries->count();

                return "- {$code}: {$count} people, monthly salary {$min}–{$max}";
            })
            ->values();

        return $lines->implode("\n");
    }

    /**
     * Asserts the AI delta JSON has the expected top-level shape so the
     * frontend can render the review panel without defensive null-checks.
     * Throws RuntimeException with "delta missing key:" prefix so the retry
     * path in generateDelta picks it up.
     */
    private function validateDeltaShape(array $delta): void
    {
        foreach (['resources', 'overheads', 'roles', 'summary', 'confidence'] as $key) {
            if (! array_key_exists($key, $delta)) {
                throw new \RuntimeException('delta missing key: '.$key);
            }
        }
        foreach (['resources', 'overheads', 'roles'] as $section) {
            if (! is_array($delta[$section])) {
                throw new \RuntimeException('delta missing key: '.$section.' (must be object)');
            }
            foreach (['add', 'remove', 'modify'] as $op) {
                if (! array_key_exists($op, $delta[$section])) {
                    throw new \RuntimeException('delta missing key: '.$section.'.'.$op);
                }
                if (! is_array($delta[$section][$op])) {
                    throw new \RuntimeException('delta missing key: '.$section.'.'.$op.' (must be array)');
                }
            }
        }
    }
}
