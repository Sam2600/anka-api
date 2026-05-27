<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\DealOverhead;
use App\Models\Employee;
use App\Models\EstimationResource;
use App\Models\Role;
use Illuminate\Support\Collection;

/**
 * Builds the line-items payload an Invoice needs for the new XLSX export.
 *
 * Source of truth: the linked deal's `estimation_resources` (relational
 * view of the latest accepted estimation) + `deal_overheads`. The numbers
 * are *estimates* — per the spec "get the emp and time that is estimated
 * from Estimation Page". The form previews these so the user can edit
 * before saving the invoice (`line_items` is then snapshotted on the
 * invoice row, locking the values regardless of later estimation edits).
 *
 * Math (per spec OQ-2):
 *   quantity = total_hours / 160          (person-month equivalent)
 *   cost     = employee.monthly_salary    (when an employee is bound)
 *           OR role.rate × 160            (when only a role is bound — converts
 *                                          hourly billable rate to monthly)
 *   amount   = quantity × cost
 *
 * Grouping: one line per (employee, role) combination. Multiple feature
 * rows that share the same employee+role collapse into one line, summing
 * hours — this matches the reference template's aggregated style
 * (Leader 0.75 / Engineer 5.5 / Japanese(Takada-san) 0.25).
 */
class InvoiceLineItemBuilder
{
    private const HOURS_PER_MONTH = 160;

    /**
     * Build the line items for a contract's deal.
     *
     * @return array<int, array<string, mixed>>  shape:
     *   [
     *     ['kind' => 'resource', 'label' => 'Leader',         'quantity' => 0.75, 'cost' => 400000, 'amount' => 300000],
     *     ['kind' => 'resource', 'label' => 'Takada-san (JP)', 'quantity' => 0.25, 'cost' => 1000000, 'amount' => 250000],
     *     ['kind' => 'overhead', 'label' => 'Google Cloud Cost', 'quantity' => 1,    'cost' => 51590,   'amount' => 51590],
     *   ]
     */
    public function buildForContract(Contract $contract): array
    {
        $contract->loadMissing('deal');
        $deal = $contract->deal;
        if (! $deal) {
            return [];
        }

        $resources = EstimationResource::query()
            ->where('deal_id', $deal->id)
            ->with(['role', 'employee'])
            ->get();

        $overheads = DealOverhead::query()
            ->where('deal_id', $deal->id)
            ->get();

        return array_values(array_merge(
            $this->buildResourceLines($resources)->all(),
            $this->buildOverheadLines($overheads)->all(),
        ));
    }

    /**
     * Group resources by (employee_id, role_id). Each group becomes one
     * line whose hours = Σ resources in that group. Resource rows with no
     * employee_id collapse to a role-only line; rows with no role_id
     * collapse to an employee-only line; rows with neither are dropped
     * (would render as an unattributable line, which the spec calls noise).
     */
    private function buildResourceLines(Collection $resources): Collection
    {
        $byGroup = [];

        foreach ($resources as $r) {
            $employeeId = $r->employee_id;
            // role() relationship hangs off job_role_id (see EstimationResource).
            // role_id is the legacy column kept around for chg-009 back-compat.
            $roleId = $r->job_role_id ?? $r->role_id;
            if (! $employeeId && ! $roleId) {
                continue;
            }
            $key = ($employeeId ?? '-').'|'.($roleId ?? '-');
            if (! isset($byGroup[$key])) {
                $byGroup[$key] = [
                    'employee' => $r->employee,
                    'role' => $r->role,
                    'hours' => 0.0,
                ];
            }
            $byGroup[$key]['hours'] += (float) $r->hours;
        }

        return collect($byGroup)->map(function ($g) {
            $label = $this->resourceLabel($g['employee'], $g['role']);
            $cost = $this->monthlyCostFor($g['employee'], $g['role']);
            $quantity = round($g['hours'] / self::HOURS_PER_MONTH, 4);
            $amount = round($quantity * $cost, 2);

            return [
                'kind' => 'resource',
                'label' => $label,
                'quantity' => $quantity,
                'cost' => round($cost, 2),
                'amount' => $amount,
            ];
        })
            ->sortBy('label')
            ->values();
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

    private function resourceLabel(?Employee $employee, ?Role $role): string
    {
        if ($employee && $role) {
            return $employee->name.' ('.($role->title ?? '').')';
        }
        if ($employee) {
            return $employee->name;
        }
        return $role?->title ?? 'Unknown role';
    }

    /**
     * Monthly cost per spec OQ-2:
     *  - Employee bound → employee.monthly_salary (paid salary)
     *  - Role-only     → role.rate × 160 (hourly billable rate → monthly)
     */
    private function monthlyCostFor(?Employee $employee, ?Role $role): float
    {
        if ($employee && (float) $employee->monthly_salary > 0) {
            return (float) $employee->monthly_salary;
        }
        if ($role && (float) $role->rate > 0) {
            return (float) $role->rate * self::HOURS_PER_MONTH;
        }
        return 0.0;
    }
}
