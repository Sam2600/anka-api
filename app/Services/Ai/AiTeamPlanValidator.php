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
     * @param  array<int, array{slot_id?:string, ghost_role_id?:string, employee_id:string, allocated_hours?:float|int, rank_match?:string}>  $picks
     * @param  array<int, array{slot_id?:string, ghost_role_id?:string, reason?:string}>  $unfilled
     * @param  array<string, array{role_type:string, rank_code:?string, quantity:int}>  $slotIndex  keyed by slot_id or ghost_role_id
     * @param  array<string, array{id:string, name:string, rank_code:?string, capacity_role:?string, workable_hours:float}>  $employeeIndex  keyed by employee_id
     * @param  array<int, string>  $keptEmployeeIds  employees already on team_assignments
     * @return array<int, array{code:string, message:string, context:array<string,mixed>}>
     */
    public function validate(array $picks, array $unfilled, array $slotIndex, array $employeeIndex, array $keptEmployeeIds): array
    {
        $violations = [];
        $keptSet = array_flip($keptEmployeeIds);
        $seenEmployees = [];
        $perSlotCounts = [];

        foreach ($picks as $i => $pick) {
            $slotId = $pick['slot_id'] ?? $pick['ghost_role_id'] ?? null;
            $employeeId = $pick['employee_id'] ?? null;

            if (! $slotId || ! isset($slotIndex[$slotId])) {
                $violations[] = [
                    'code'    => 'unknown_slot',
                    'message' => "Pick #{$i} references unknown slot_id `{$slotId}`.",
                    'context' => ['index' => $i, 'slot_id' => $slotId],
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

            $perSlotCounts[$slotId] = ($perSlotCounts[$slotId] ?? 0) + 1;
        }

        $unfilledIds = array_flip(array_map(fn ($u) => $u['slot_id'] ?? $u['ghost_role_id'] ?? '', $unfilled));
        foreach ($slotIndex as $id => $role) {
            $count = $perSlotCounts[$id] ?? 0;
            if ($count === 0 && ! isset($unfilledIds[$id])) {
                $violations[] = [
                    'code'    => 'role_not_resolved',
                    'message' => "Slot `{$id}` ({$role['role_type']}) has no picks and is not listed in unfilled.",
                    'context' => ['slot_id' => $id, 'role_type' => $role['role_type']],
                ];
            }
        }

        return $violations;
    }
}
