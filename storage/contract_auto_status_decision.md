# Contract auto-status — trigger timing decision

## Status

**Pending team review.** Implemented as Option 1 below. Swap to Option 2 or 3 if the team prefers a different cadence — the rules themselves don't change, only when they fire.

## What's being automated

### Contract status transitions

Two contract status transitions move on their own without a human clicking a button:

1. **Signed → Active** when `today ≥ contract.start_date` (and the contract is currently `Signed`).
2. **Active → Completed** when `project.consumed_hours ≥ project.budget_hours` for the contract's linked project.

`Draft`, `Cancelled` contracts are never auto-promoted.

### Project status transitions (added 2026-05-16)

The project lifecycle (`Not Started / On Track / At Risk / Over Budget / Completed`) is **fully automatic** — no PM button. Computed from `consumed_hours`, `budget_hours`, and the linked contract's status:

| Project status | Condition |
|---|---|
| **Completed** | Linked contract status is `Completed` or `Cancelled` (mirrors the contract) |
| **Over Budget** | `consumed_hours > budget_hours` AND contract still active (overrun in progress) |
| **At Risk** | `0.80 ≤ consumed_hours / budget_hours < 1.00` (danger zone before overrun) |
| **On Track** | `0 < consumed_hours < 0.80 × budget_hours` |
| **Not Started** | `consumed_hours == 0` |

When `budget_hours` is 0 or null, the rule is a no-op (no signal to act on).

Manual transitions still work as before — these triggers only run when nothing else has moved the status. Both contract and project rules live in model methods (`Contract::maybeAutoTransition`, `Project::maybeAutoTransition`) so they're easy to swap or tighten without touching the controllers or cron.

## The three options we considered

| | Signed → Active | Active → Completed | Lag |
|---|---|---|---|
| **Option 1 (current implementation)** | Daily cron | Real-time on time-entry approval | Up to 24h for activation, instant for completion |
| **Option 2** | Daily cron | Daily cron | Up to 24h for both |
| **Option 3** | Real-time on relevant events | Real-time on time-entry approval | Instant, but activation depends on a server request happening — a quiet weekend means delayed activation |

## Why we picked Option 1

- **Completion needs to be fast.** When the last hour of budget is approved, the contract should flip Active → Completed in the same request so the approver sees an accurate status immediately. We're already inside `TimeEntryController::approve` and holding the relevant rows — adding the check is one extra `if` statement.
- **Activation is date-driven.** No event fires on "the calendar advanced past start_date." A daily cron is the simplest reliable trigger. Running at 01:00 local server time picks up activations within minutes of midnight.
- **Belt-and-suspenders.** The daily cron also re-checks the completion rule, so any time-entry approval that somehow missed the hook (queue failure, partial write) gets fixed within 24h.

## What lives where

| Concern | Location |
|---|---|
| Contract transition rules | `app/Models/Contract.php` — `maybeAutoTransition(?Project $project, string $trigger): ?string` |
| Project transition rules | `app/Models/Project.php` — `maybeAutoTransition(?Contract $contract, string $trigger): ?string` + `computeAutoStatus()` (pure function for tests) |
| Real-time hook | `app/Http/Controllers/Api/TimeEntryController.php::approve()` — runs contract first, then project (order matters because project Completed mirrors contract) |
| Daily cron | `app/Console/Commands/AutoTransitionContracts.php` — iterates Signed/Active contracts AND sweeps non-Completed projects |
| Schedule registration | `routes/console.php` — runs at 01:30 (after invoices flip overdue at 01:00) |

## How to swap to Option 2 (cron-only)

Remove the real-time call inside `TimeEntryController::approve` (one block; clearly commented). The daily cron already handles both rules.

## How to swap to Option 3 (no cron)

1. Delete `app/Console/Commands/AutoTransitionContracts.php` and the `Schedule::command(...)` line in `routes/console.php`.
2. Hook the Signed → Active check into wherever a contract is read or rendered, e.g. inside `ContractController::index` and `show` before returning the resource. Drawback: contracts that nobody opens stay `Signed` forever even if their start date passed.

## Edge cases the implementation handles

- `start_date` is null → skipped, no activation possible until someone sets it.
- `budget_hours` is 0 or null → skipped, dividing by zero or completing instantly are both wrong answers.
- Contract is `Cancelled` → no auto-transition fires from either trigger.
- Hours go past 100% (overruns) → status flips to `Completed` once, then sticks; subsequent approvals just keep `consumed_hours` climbing.
- Same approval triggered concurrently (race) → the `DB::transaction` + `lockForUpdate` on the time entry serializes the work; only one transition fires.

## Logging

Every transition writes a Laravel `Log::info` line.

**Contract:**
- `event` = `'contract.auto_activated'` or `'contract.auto_completed'`
- `contract_id`, `tenant_id`, `previous_status`
- `trigger` = `'time_entry_approval'` or `'scheduled_command'`

**Project:**
- `event` = `'project.auto_transition'`
- `project_id`, `tenant_id`, `previous_status`, `new_status`
- `budget_hours`, `consumed_hours`, `contract_status` (the values the decision was based on)
- `trigger` = `'time_entry_approval'` or `'scheduled_command'`

Search `storage/logs/laravel.log` for `contract.auto_` or `project.auto_` to audit when transitions fired.

---

**Last updated:** 2026-05-16
**Owner:** _TBD — fill in after team review_
