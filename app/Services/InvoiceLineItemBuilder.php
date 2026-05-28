<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\DealOverhead;
use App\Models\EstimationVersion;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the line-items payload an Invoice needs for the XLSX export.
 *
 * Source of truth: the latest `estimation_versions.sheet_team_structure`
 * JSONB snapshot for the deal. Each member in `members[]` becomes one
 * resource line on the invoice. The quantity is that member's
 * `monthly_allocation` for the invoice's billing month (calculated from
 * the snapshot's `start_date`); cost is `monthly_salary`; amount is
 * quantity × cost.
 *
 * Then `deal_overheads` are appended as flat-amount lines below.
 *
 * No estimation_versions row → no line items (caller must save a version
 * before invoicing). No `sheet_team_structure` on the latest version →
 * also no resource lines (legacy versions from before that column).
 */
class InvoiceLineItemBuilder
{
    /**
     * Build the line items for a contract's deal, using the current month
     * as the billing window.
     *
     * @return array<int, array<string, mixed>>  shape:
     *   [
     *     ['kind' => 'resource', 'label' => 'Leader1',           'quantity' => 1.0, 'cost' => 4050000, 'amount' => 4050000],
     *     ['kind' => 'resource', 'label' => 'Member1',           'quantity' => 1.0, 'cost' => 2050000, 'amount' => 2050000],
     *     ['kind' => 'overhead', 'label' => 'Google Cloud Cost', 'quantity' => 1,   'cost' => 51590,   'amount' => 51590],
     *   ]
     */
    public function buildForContract(Contract $contract): array
    {
        $contract->loadMissing('deal');
        $deal = $contract->deal;
        if (! $deal) {
            return [];
        }

        $version = EstimationVersion::query()
            ->where('deal_id', $deal->id)
            ->orderByDesc('version_number')
            ->first();

        $resourceLines = $version
            ? $this->buildResourceLines($version, Carbon::now())
            : collect();

        $overheads = DealOverhead::query()
            ->where('deal_id', $deal->id)
            ->get();

        return array_values(array_merge(
            $resourceLines->all(),
            $this->buildOverheadLines($overheads)->all(),
        ));
    }

    /**
     * One line per member in sheet_team_structure.members, with quantity
     * tied to the billing month's slot in monthly_allocation. Members
     * whose allocation array doesn't cover the billing month get
     * quantity=0 (visible but zeroed out) — surfaces missing data without
     * silently dropping team members.
     */
    private function buildResourceLines(EstimationVersion $version, Carbon $billingMonth): Collection
    {
        $snapshot = $version->sheet_team_structure ?? [];
        $members = $snapshot['members'] ?? [];
        $startDate = isset($snapshot['start_date'])
            ? Carbon::parse($snapshot['start_date'])->startOfMonth()
            : null;
        $visibleMonths = (int) ($snapshot['visible_months'] ?? 0);

        if (! is_array($members) || empty($members) || ! $startDate || $visibleMonths < 1) {
            return collect();
        }

        $billingMonthStart = $billingMonth->copy()->startOfMonth();
        $monthIndex = $startDate->diffInMonths($billingMonthStart, false);
        $inRange = $monthIndex >= 0 && $monthIndex < $visibleMonths;

        return collect($members)->map(function ($m) use ($monthIndex, $inRange) {
            $name = (string) ($m['name'] ?? 'Unknown');
            $salary = (float) ($m['monthly_salary'] ?? 0);
            $allocation = $m['monthly_allocation'] ?? [];
            $quantity = $inRange ? (float) ($allocation[$monthIndex] ?? 0) : 0.0;

            return [
                'kind' => 'resource',
                'label' => $name,
                'quantity' => round($quantity, 4),
                'cost' => round($salary, 2),
                'amount' => round($quantity * $salary, 2),
            ];
        })->values();
    }

    /**
     * Overhead rows are flat-amount line items rendered below the resource
     * block (Google Cloud Cost, Infrastructure work fee in the template).
     * quantity is always 1 — overheads are billed as-entered.
     */
    private function buildOverheadLines(Collection $overheads): Collection
    {
        return $overheads->map(fn ($o) => [
            'kind' => 'overhead',
            'label' => $o->name ?? '',
            'quantity' => 1,
            'cost' => round((float) $o->cost, 2),
            'amount' => round((float) $o->cost, 2),
        ])->values();
    }
}
