<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds three seniority tiers above the existing "Lead / Tech Lead" rank:
 *   Manager (level 50) → Director (level 70) → Executive (level 90).
 *
 * Levels are spaced so tenants can insert custom intermediates (e.g.
 * "Senior Manager" at 60) without re-bumping every other rank.
 *
 * Auto-promotes a small number of seeded employees per tenant whose
 * role_name plausibly maps to one of these ranks. This makes the
 * Authorized Signatory dropdown non-empty on a fresh `migrate --seed`
 * without requiring a separate manual rank-assignment step.
 *
 * Idempotent:
 *   - Ranks: skip insert when (tenant_id, code) already exists.
 *   - Employee promotions: only promote employees whose rank_id is null
 *     AND whose role_name matches the heuristic. Re-running won't
 *     overwrite manually-assigned ranks.
 */
return new class extends Migration
{
    private const NEW_RANKS = [
        ['code' => 'Manager',   'name' => 'Manager',   'level' => 50],
        ['code' => 'Director',  'name' => 'Director',  'level' => 70],
        ['code' => 'Executive', 'name' => 'Executive', 'level' => 90],
    ];

    public function up(): void
    {
        $now = now();
        $tenants = DB::table('tenants')->get(['id']);

        foreach ($tenants as $tenant) {
            $codeToId = [];

            foreach (self::NEW_RANKS as $row) {
                $existing = DB::table('ranks')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $row['code'])
                    ->value('id');

                if ($existing) {
                    $codeToId[$row['code']] = $existing;
                    continue;
                }

                $id = (string) Str::orderedUuid();
                DB::table('ranks')->insert([
                    'id' => $id,
                    'tenant_id' => $tenant->id,
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'level' => $row['level'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $codeToId[$row['code']] = $id;
            }

            // Auto-promote: only employees with rank_id IS NULL get touched.
            $this->promoteByRoleKeyword($tenant->id, ['director', 'ceo', 'cto', 'cfo'], $codeToId['Executive'] ?? null);
            $this->promoteByRoleKeyword($tenant->id, ['head of', 'managing', 'principal'], $codeToId['Director'] ?? null);
            $this->promoteByRoleKeyword($tenant->id, ['manager', 'architect'], $codeToId['Manager'] ?? null);
        }
    }

    public function down(): void
    {
        // Un-promote: only employees whose current rank is one of the three
        // ranks we created (by code), to avoid clobbering manual changes.
        $rankIds = DB::table('ranks')
            ->whereIn('code', ['Manager', 'Director', 'Executive'])
            ->pluck('id')
            ->all();
        if (! empty($rankIds)) {
            DB::table('employees')
                ->whereIn('rank_id', $rankIds)
                ->update(['rank_id' => null, 'updated_at' => now()]);
        }

        DB::table('ranks')
            ->whereIn('code', ['Manager', 'Director', 'Executive'])
            ->delete();
    }

    /**
     * Promote employees to the target rank when their role_name matches a
     * keyword AND their current rank level is below the target. This makes
     * the migration idempotent (re-running cannot demote anyone), works
     * for both unranked and already-ranked employees (the original seeder
     * assigns every employee Lead/Senior/Mid/Junior heuristically — even
     * an "Account Director" lands at Lead), and never overwrites a
     * manually-assigned higher rank.
     *
     * @param  string[]  $keywords
     */
    private function promoteByRoleKeyword(string $tenantId, array $keywords, ?string $rankId): void
    {
        if (! $rankId || empty($keywords)) {
            return;
        }

        $targetLevel = (int) DB::table('ranks')->where('id', $rankId)->value('level');

        // Pull the candidate employees in PHP rather than via a complex
        // join + raw subselect; the per-tenant employee count is tiny
        // (tens), and this stays readable across PG / SQLite.
        $rows = DB::table('employees as e')
            ->leftJoin('ranks as r', 'r.id', '=', 'e.rank_id')
            ->where('e.tenant_id', $tenantId)
            ->whereNull('e.deleted_at')
            ->where(function ($q) use ($keywords) {
                foreach ($keywords as $kw) {
                    $kw = strtolower($kw);
                    $q->orWhereRaw('LOWER(e.role_name) LIKE ?', ["%{$kw}%"]);
                }
            })
            ->where(function ($q) use ($targetLevel) {
                // Either currently unranked, or current rank is below target.
                $q->whereNull('r.level')->orWhere('r.level', '<', $targetLevel);
            })
            ->pluck('e.id');

        if ($rows->isEmpty()) {
            return;
        }

        DB::table('employees')
            ->whereIn('id', $rows)
            ->update(['rank_id' => $rankId, 'updated_at' => now()]);
    }
};
