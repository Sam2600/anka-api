<?php

namespace App\Services\Ai;

/**
 * Validates an AI-suggested team plan returned by the team-build prompt.
 *
 * Inputs are pre-fetched arrays (no DB access here) so the validator stays
 * testable. Returns a list of structured violations; an empty list means
 * the plan is accepted.
 */
class AiTeamPlanValidator
{
    /**
     * @param  array<int, array{ghost_role_id:string, employee_id:string, allocated_hours?:float|int, rank_match?:string}>  $picks
     * @param  array<int, array{ghost_role_id:string, reason?:string}>  $unfilled
     * @param  array<string, array{role_type:string, rank_code:?string, quantity:int}>  $ghostRoleIndex  keyed by ghost_role_id
     * @param  array<string, array{id:string, name:string, rank_code:?string, capacity_role:?string, workable_hours:float}>  $employeeIndex  keyed by employee_id
     * @param  array<int, string>  $keptEmployeeIds  employees already on team_assignments
     * @return array<int, array{code:string, message:string, context:array<string,mixed>}>
     */
    public function validate(array $picks, array $unfilled, array $ghostRoleIndex, array $employeeIndex, array $keptEmployeeIds): array
    {
        $violations = [];
        $keptSet = array_flip($keptEmployeeIds);
        $seenEmployees = [];
        $perRoleCounts = [];

        foreach ($picks as $i => $pick) {
            $ghostRoleId = $pick['ghost_role_id'] ?? null;
            $employeeId  = $pick['employee_id'] ?? null;

            if (! $ghostRoleId || ! isset($ghostRoleIndex[$ghostRoleId])) {
                $violations[] = [
                    'code'    => 'unknown_ghost_role',
                    'message' => "Pick #{$i} references unknown ghost_role_id `{$ghostRoleId}`.",
                    'context' => ['index' => $i, 'ghost_role_id' => $ghostRoleId],
                ];

                continue;
            }

            if (! $employeeId || ! isset($employeeIndex[$employeeId])) {
                $violations[] = [
                    'code'    => 'unknown_employee',
                    'message' => "Pick #{$i} references unknown or inactive employee_id `{$employeeId}`.",
                    'context' => ['index' => $i, 'employee_id' => $employeeId],
                ];

                continue;
            }

            if (isset($keptSet[$employeeId])) {
                $violations[] = [
                    'code'    => 'employee_already_on_team',
                    'message' => "Employee `{$employeeId}` is already on the team; do not re-pick them.",
                    'context' => ['index' => $i, 'employee_id' => $employeeId],
                ];

                continue;
            }

            if (isset($seenEmployees[$employeeId])) {
                $violations[] = [
                    'code'    => 'duplicate_employee',
                    'message' => "Employee `{$employeeId}` was picked more than once.",
                    'context' => ['index' => $i, 'employee_id' => $employeeId],
                ];

                continue;
            }
            $seenEmployees[$employeeId] = true;

            $allocated = (float) ($pick['allocated_hours'] ?? 0);
            $capacity  = (float) $employeeIndex[$employeeId]['workable_hours'];
            if ($allocated < 0 || ($capacity > 0 && $allocated > $capacity)) {
                $violations[] = [
                    'code'    => 'allocated_hours_out_of_range',
                    'message' => "Pick #{$i} allocated_hours {$allocated} exceeds employee capacity {$capacity}.",
                    'context' => ['index' => $i, 'employee_id' => $employeeId, 'allocated' => $allocated, 'capacity' => $capacity],
                ];
            }

            $perRoleCounts[$ghostRoleId] = ($perRoleCounts[$ghostRoleId] ?? 0) + 1;
        }

        // Picks for a single ghost role can legitimately exceed the role's quantity
        // (the "split" fallback — 1 Senior slot → 2 Mids). But picks must never be
        // fewer than 1 if the role is not also listed in `unfilled`.
        $unfilledIds = array_flip(array_map(fn ($u) => $u['ghost_role_id'] ?? '', $unfilled));
        foreach ($ghostRoleIndex as $grId => $role) {
            $count = $perRoleCounts[$grId] ?? 0;
            if ($count === 0 && ! isset($unfilledIds[$grId])) {
                $violations[] = [
                    'code'    => 'role_not_resolved',
                    'message' => "Ghost role `{$grId}` ({$role['role_type']}) has no picks and is not listed in unfilled.",
                    'context' => ['ghost_role_id' => $grId, 'role_type' => $role['role_type']],
                ];
            }
        }

        return $violations;
    }
}
