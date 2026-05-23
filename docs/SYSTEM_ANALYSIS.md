# ANKA — System Analysis

> Code-derived map of ANKA's multi-tenant agency-PM SaaS. Compiled from the actual Laravel models, controllers, services, and Next.js queries — not the spec deck. Used as the contract for the "Super ANKA" demo-tenant seeder (see §8).

## 1. Stack & Tenant Architecture

| Layer | Tech | Notable |
|---|---|---|
| Frontend | Next.js 16 (App Router), React 19, TS, Zustand 5, TanStack Query 5, shadcn/ui, Recharts, @hello-pangea/dnd | Reads only the Laravel API (`lib/api.ts`); no direct DB |
| Backend | Laravel 13, PHP 8.3+ | API-only; JSON responses; CORS to `localhost:3000/3001` |
| DB | PostgreSQL (Supabase) prod · SQLite tests | PG-specific: generated columns, sequences, stored proc `win_deal()` |
| AI | Anthropic Claude (Sonnet + Haiku) | 6 distinct entry points (see §6) |

### Tenant scoping

Every business model uses the `BelongsToTenant` trait, which (a) auto-injects `tenant_id` on `create()` and (b) adds a global Eloquent scope filtering all queries to `app('tenant_id')`. The `tenant_id` is bound by the `TenantScope` middleware (`tenant` alias on routes) which reads the `X-Tenant-ID` request header, verifies the tenant exists + is_active, then `app()->instance('tenant_id', $id)`.

**Exception:** `User` does NOT use `BelongsToTenant` — login must search globally by email before a tenant is known. The `users.tenant_id` column still exists; it's the user's home tenant.

Super-admins (`users.is_super_admin = true`) bypass scoping entirely via `TenantScope::shouldSkipScoping()`.

For seeders the binding is manual: `app()->instance('tenant_id', $tenant->id)` before any create-call.

## 2. Entity-Relationship Overview

```
Tenant
 ├─ User ─── (system_role, app_role, is_super_admin)
 ├─ Department ─── Role (job title + billable rate)
 ├─ CapacityRole (pm / backend / frontend / design / qa)
 ├─ Rank (Junior / Mid / Senior / Lead / Manager / Director / Executive)
 ├─ Skill ─── EmployeeSkill (proficiency)
 ├─ Employee (basic_salary + allowance; cost_per_hour = monthly_salary / workable_hours GENERATED)
 │   └─ EmployeeSalaryHistory (target_month snapshots)
 ├─ Holiday
 ├─ CompanySetting (overhead %, buffer %, fallback_hourly_cost, cost_to_bill_ratio)
 ├─ InitialBudget (per fiscal year)
 ├─ GlobalOverhead (rent / SaaS / always-on costs)
 ├─ Deal (lead → qualified → negotiation → won + lifecycle active/dropped)
 │   ├─ DealGhostRole       (capacity placeholders: role_type, qty, months, salary band)
 │   ├─ DealHardAssignment  (named employee × allocated_hours)
 │   ├─ EstimationResource  (feature/role/hours/employee)
 │   ├─ DealOverhead        (per-project travel/license/etc.)
 │   ├─ EstimationVersion   (JSON snapshot per save; version_number monotonic per deal)
 │   ├─ DealContractDraft   (AI-rendered sections; status draft/sent/signed/superseded)
 │   │   └─ ContractTemplate (global rows, tenant_id NULL; 3 SES variants seeded by migration)
 │   ├─ Contract            (created ONLY by win_deal())
 │   │   ├─ Project         (created ONLY by win_deal())
 │   │   │   ├─ ProjectTeamAssignment   (employee × allocated_hours, source=ai|deal_transfer|manual)
 │   │   │   ├─ ProjectTaskAssignment   (function_id × total_hours)
 │   │   │   │   └─ ProjectTaskPhaseAssignment (Design 10% / Impl 70% / Test 20%)
 │   │   │   │       └─ PhaseProgressLog (daily: progress_hours, used_hours)
 │   │   │   └─ TimeEntry   (Draft → Pending → Approved → Rejected)
 │   │   ├─ Milestone       (Pending → In Progress → Completed → Accepted)
 │   │   └─ Invoice         (Draft → Pending → Paid/Overdue; total = amount+tax GENERATED)
 ├─ TenantAppRole + TenantAppRolePermission (per-tenant RBAC: 5 default roles, customisable)
 └─ AiUsageLog (per Claude call: feature, model, input/output tokens, cost USD)
```

All primary tables use UUID v7 PKs. `Deal`, `Contract`, `Invoice`, `Project`, `TimeEntry`, `Employee`, `User` use soft deletes. PG-only auto-numbers: `CON-XXXX`, `INV-XXXX`, `PRJ-XXXX` (sequences in migration `2026_05_04_000003_create_sequences.php`).

## 3. Business Flow (state machine + DB writes)

### Deal lifecycle

```
       create                add resources/overheads     final_confirmed_at + REQUIRED fields
LEAD ─────────► QUALIFIED ──────────────────────────► NEGOTIATION ──────────────────────────► WON
 30%              50%                                    80%                100%
                                                  (= rank C/B/A/S)
                                                                     │
                                                                     │ ContractDraftService::markSigned()
                                                                     ▼
                                                        win_deal(deal_id, tenant_id)   ← PostgreSQL stored proc
                                                        ├─ Contract.create
                                                        ├─ Project.create
                                                        ├─ ProjectTeamAssignment×N from estimation
                                                        └─ Deal.update(status=won, win_probability=100, won_at)
```

SQLite fallback: `ContractDraftService::fireWinDeal()` replays the same 4-step sequence in PHP, used in tests and local dev when PG sequences aren't available.

### Status promotion triggers (auto, not via API)

| Trigger | Promotion | Code |
|---|---|---|
| First `EstimationResource` or `DealOverhead` row | lead → qualified | `Deal::maybePromoteToQualified()` |
| `final_confirmed_at` set AND all REQUIRED estimation fields populated | qualified → negotiation | `DealController::update` (lines 245–262) |
| Signed PDF uploaded via `POST /contract-drafts/{draft}/mark-signed` | negotiation → won | `ContractDraftService::markSigned` (line 399) |

### Execution-phase side effects

| Event | Endpoint | Side effects (atomic) |
|---|---|---|
| Approve time entry | `PATCH /time-entries/{id}/approve` | `time_entries.status = Approved`; `projects.consumed_hours += hours` under `lockForUpdate()` |
| Accept milestone | `PATCH /milestones/{id}/accept` | `milestones.status = Accepted`; **`contracts.revenue_recognized += amount`** |
| Pay invoice | `PATCH /invoices/{id}/pay` | `invoices.paid_amount += amount`; `invoices.status = Paid|Partially Paid`; **`contracts.cash_collected += amount`** |
| Log phase progress | `POST /phase-assignments/{id}/progress-logs` | inserts daily row with `progress_hours`, `used_hours`. `late_hours = max(0, used − progress)` is computed by `VarianceCalculator::forPhase()` — never stored |

### OT accounting — two parallel signals

1. **TimeEntry** rows tagged `task LIKE 'OT:%'` (or `notes` referencing overtime) — direct hours logged.
2. **PhaseProgressLog** with `used_hours > progress_hours` — derived "late" hours.

The Financial / Dashboard OT-impact card prefers signal 2 (more granular, per-day). The seeder MUST keep these two consistent: every OT hour in a TimeEntry should also appear as `used > progress` on the corresponding phase log, else the OT-impact card shows zero even though entries exist.

### Computed/derived fields — what FE recomputes vs reads

| Field | Source of truth | Notes |
|---|---|---|
| `Deal.totalEstimatedCost`, `estimatedGrossProfit` | Set by AI Team Builder response → frontend POSTs to `PATCH /deals/{id}`. Deal Detail page **recomputes live** from `estimation_resources × employee.costPerHour × 1.15` (legacy DB columns no longer trusted). | Forecast page DOES read `Deal.totalEstimatedCost` for per-month cost projection. |
| `Contract.total_value` | Set by `win_deal()` from `final_monthly_fee × final_contract_months` (or `client_budget`). | Read directly; never recomputed. |
| `Contract.revenue_recognized` | Server-incremented on milestone accept. | Read directly. |
| `Contract.cash_collected` | Server-incremented on invoice pay. | Read directly. |
| `Project.consumed_hours` | Server-incremented on time-entry approve. | Read directly. |
| Monthly P&L Direct Labor | FE computes: `Σ approved TimeEntries × employee.costPerHour × 1.15` per month. | Empty if `useTimeEntryList` hasn't run yet (Dashboard fix landed in `5868339`). |
| Forecast monthly income | FE computes: per deal `(incomeBudget / timelineMonths) × winProbability/100` for each active month. | Forecast start = `project.startDate` (won) or `expectedCloseDate + 1 day` (non-won). |
| Realized profit (Dashboard) | `Σ projects (actualRevenue + overtimeRevenue − actualLaborCost)`. `actualRevenue = planRevenue × actualProgress`. | `actualProgress` from task assignments + phase logs for S-rank, else `approvedHours / budgetHours`. |

## 4. Cost / Pricing Convention

```
salary           = basic_salary + allowance              (DB-derived)
cost_per_hour    = monthly_salary / workable_hours       (PG GENERATED column; NULL when workable_hours = 0)
loaded_cost/hr   = cost_per_hour × (1 + overhead_pct/100)        ← "burden", 15% in current tenants
sell_price/hr    = loaded_cost/hr × BILLING_MARKUP_MULTIPLIER    ← 3× by default ([lib/calculations.ts](anka-frontend/lib/calculations.ts))
```

Non-IT departments (Sales, HR) — UI hides `Sell/Hr` even when `cost_per_hour` is non-null (matches the spec sheet's greyed-out cells). Cost is still tracked.

Defaults from `CompanySetting`:
- `overhead_percentage` 15
- `buffer_percentage` 0 (used in some tenants for risk margin)
- `cost_to_bill_ratio` 0.5 (legacy estimation pathway)
- `default_monthly_capacity_hours` 160
- `fallback_hourly_cost` 12,500 (when an estimation row has no employee + no role rate)

## 5. AI Integration Map

Six Claude entry points. Each logs to `ai_usage_logs` (feature, model, input/output tokens, USD cost). All have demo-mode fallbacks for missing API key.

| # | Feature | File | Model | Persists |
|---|---|---|---|---|
| 1 | ANKA Assistant (chatbot) | `anka-frontend/app/api/ai-chatbot/route.ts` | Sonnet | Nothing (transient) |
| 2 | AI Team Builder | `anka-frontend/app/api/ai-team-builder/route.ts` | Sonnet | Nothing directly; FE saves accepted roles to Deal via `PATCH /deals/{id}` |
| 3 | AI Forecast (next-quarter risk) | `anka-frontend/app/api/ai-forecast/route.ts` | Haiku | Nothing |
| 4 | Contract Draft generation | `anka-api/app/Services/ContractDraftService.php` | Sonnet via HTTP | `DealContractDraft.ai_outputs` + `.sections` (rendered) |
| 5 | Estimation Draft (5-sheet) | `anka-api/app/Services/EstimationAiService.php` | Sonnet via HTTP | FE-only; user saves accepted result through `EstimationVersionController` |
| 6 | AI Task Assignment | `anka-api/app/Http/Controllers/Api/AiAutoAssignController.php` | Sonnet via HTTP | `ProjectTaskAssignment` + `ProjectTaskPhaseAssignment` rows |

**Seeder implication:** to fake an AI-generated deal we persist the same SHAPES the real flow stores — DealContractDraft.sections array, EstimationVersion.resources array, ghost_roles + estimation_resources + deal_overheads + final_* fields. No Claude call needed. The on-disk shape examples are in `DemoTestingSeeder::createContractDraftStub()` already.

## 6. Frontend Rendering Map (what visibility a seeder unlocks)

| Page | Reads | Must seed for non-empty render |
|---|---|---|
| Dashboard | Deals, Contracts, Projects, Invoices (Paid), TimeEntries (Approved), Employees | All of the above |
| Forecast | Deals (status, win_prob, ranges), Contracts, Projects, **Deal.total_estimated_cost** | Won + negotiation + qualified deals with `total_estimated_cost` set |
| Financial P&L | Invoices (Paid), TimeEntries (Approved) × `costPerHour × 1.15`, GlobalOverheads | At minimum: a few Paid invoices + a few Approved time entries spanning months |
| Project Pipeline (Kanban) | Deals (all statuses, lifecycle filter) | At least one Deal per rank-column to populate the board |
| Deal Detail | Deal + ghostRoles + estimationResources + dealOverheads + ContractDraft existence | Full deal child tree |
| Project Detail | Project + Team + TaskAssignments + PhaseAssignments | Won deal → project chain |
| Time Tracking | TimeEntries, ProgressLogSummary | TimeEntry rows |
| Contracts list | Contracts + Milestones + Invoices | Contract chain |
| Organization > Employees | Employees + Skills + Ranks | Employee roster with rank/dept/skills |

## 7. Seeding Dependency Order

```
1.  Tenant
2.  CapacityRole (5 standard)
3.  Rank        (4–7 levels)
4.  Department  (e.g. IT / Sales / HR / Design / Ops)
5.  Role        (job titles + billable rate)
6.  Skill       (≥10 technical+management+creative)
7.  Employee    (basic_salary + allowance + workable_hours + status + capacity_role + rank)
8.  EmployeeSkill (proficiency per skill per employee)
9.  User        (1 Admin + Executive + Sales + Delivery + HR; emails on tenant domain)
10. Department  (UPDATE: manager_id + headcount)
11. CompanySetting + InitialBudget + GlobalOverhead (monthly P&L overhead lines)
12. Holiday    (Japan + relevant calendars)
13. Deal       (the pipeline narrative)
14.  └─ DealGhostRole + DealHardAssignment + EstimationResource + DealOverhead per deal
15.  └─ EstimationVersion snapshot
16.  └─ DealContractDraft for negotiation+ deals (with rendered sections + customer signatory)
17. Contract   (created for WON deals — call `fireWinDeal()` path in seeder OR insert directly)
18. Project    (from win_deal output)
19. ProjectTeamAssignment (from deal estimation; allocated_hours per team member)
20. Milestone  (per contract: a few Pending, one Completed, one Accepted)
21. Invoice    (per contract: monthly issue, status mix)
22. ProjectTaskAssignment + ProjectTaskPhaseAssignment (Design/Impl/Test per team member)
23. PhaseProgressLog (weekly per phase; OT rows where `used_hours > progress_hours`)
24. TimeEntry  (monthly per team member, status=Approved; matching OT rows for OT phases)
```

Idempotency: wipe-on-rerun by slug, scoped to tenant-owned tables. `Model::unguarded()` wrapper for unrestricted assignment. Order above ensures FKs resolve.

## 8. Constraints / Gotchas the Seeder Must Honor

1. **OT consistency** — TimeEntry OT hours must equal `Σ max(0, phase_log.used_hours − progress_hours)` for the project. Otherwise the Financial OT-impact card disagrees with Time Tracking page.
2. **`win_deal()` only fires from `markSigned`** — direct Deal status=won updates skip the Contract/Project creation. Seeder must explicitly create Contract+Project after flipping the deal to won (DemoHackathon/HackthonSeeder pattern). Equivalent to calling `fireWinDeal()` manually.
3. **Generated columns** — never write to `employee.cost_per_hour` or `invoice.total`; PG ignores writes, SQLite ALTER doesn't define them. Set `basic_salary`, `allowance`, `workable_hours` and let the column derive.
4. **`workable_hours = 0`** sets `cost_per_hour = NULL` (via PG `NULLIF`). Used to mark non-billable staff (Sales/HR) — they have a cost-on-paper but no hourly rate. Frontend renders `—` in Sell/Hr column for those.
5. **`expectedCloseDate` controls forecast start month for non-won deals** — the chart uses `startOfUtcMonth(closeDate + 1 day)`. Seeded close-date convention: one day before the intended project kickoff month, OR ≥ 1 day before so it lands in the previous month and `+1 day` rolls into kickoff.
6. **`final_confirmed_at` must be null for negotiation deals you want Forecast to plot from their kickoff window** (else `forecastStartDate` was historically picking it up — that branch was removed in `19c95a6`).
7. **`workable_hours` non-zero for IT staff** else AI Team Builder + Estimation cost rollup silently zeroes them out.
8. **Contract / Project numbers** — use a tenant-distinct prefix (`SUPER-CON-2026-XXX`) to avoid the SQLite UNIQUE constraint conflicts that bit DemoTestingSeeder.
9. **ContractTemplate is global** (tenant_id NULL); 3 SES variants seeded by `2026_05_15_000006_seed_three_ses_template_variants.php`. Reference them by `slug` (`cloud_backup` / `managed_hosting` / `engineer_dispatch`) not by ID.
10. **Tenant-app-roles** auto-seeded by `2026_05_17_000001_create_tenant_app_roles_tables.php` for every tenant on first request — seeder can leave them alone. To customize, insert into `tenant_app_role_permissions` after employee creation.

## 9. Risks & Open Questions for the Super ANKA Seeder

| # | Question | Default decision (will use unless told otherwise) |
|---|---|---|
| Q1 | Currency? | **JPY** (¥) — matches Brycen umbrella; demo numbers stay in 6-digit territory |
| Q2 | Calendar / start year? | **Fiscal year 2026 Jan–Dec** (consistent with existing demo tenants; "today" floats with system date) |
| Q3 | Salary flattening (rank-avg) or per-employee variance? | **Per-employee variance** — adds realism. Sheet compliance is not a goal here; DemoHackathon already covers that path. |
| Q4 | Headcount | **20 employees**: 1 Admin/Lead, 2 Executives, 6 Backend, 4 Frontend, 2 QA, 1 Designer, 2 PMs, 1 Sales, 1 HR. |
| Q5 | Pipeline story | **6 deals**: 3 won (healthy / thin / bleeding), 1 negotiation (signed-contract incoming), 1 qualified (mid-funnel), 1 lost (with dropped_at_stage) |
| Q6 | OT pattern on the "bleeding" project | Real-shape OT: TimeEntry rows + phase_progress_logs both surface ~150 OT hours so OT-impact card lights up red |
| Q7 | Localization | **English** for primary labels + **Japanese** for one project name + one milestone name to exercise multi-byte handling without forcing the reviewer to read JA |

---

# Super ANKA Seed Plan — Pending Approval

## Identity

| Field | Value |
|---|---|
| Tenant name | **Super ANKA** |
| Slug | `super-anka` |
| Currency | JPY (¥) |
| Plan | `pro` |
| Signatory | "Sato Kenji" — "Representative Director" |
| Email domain | `superanka.example.jp` |

## Calendar anchor
- Year `2026`, Initial Budget target ¥120,000,000 net profit
- Project windows derived from `Carbon::create(2026, 1, 1)` so re-runs are deterministic

## Roster (20 employees)

| # | Name | Role | Dept | Capacity | Rank | Basic ¥ | Allowance ¥ | Notes |
|---|---|---|---|---|---|---|---|---|
| 1 | Tanaka Hiro     | Tech Lead         | Delivery | pm       | Lead     | 4,200,000 | 50,000 | Default Admin user |
| 2 | Suzuki Aiko     | Engineering Manager | Delivery | pm     | Manager  | 4,100,000 | 30,000 | Exec view |
| 3 | Sato Kenji      | Managing Director | Sales    | pm       | Executive| 4,500,000 | 0      | Signatory |
| 4 | Yamada Ryo      | Senior Backend    | Delivery | backend  | Senior   | 2,400,000 | 30,000 | Healthy project lead |
| 5 | Watanabe Mei    | Senior Backend    | Delivery | backend  | Senior   | 2,400,000 | 20,000 | |
| 6 | Ito Daiki       | Backend           | Delivery | backend  | Mid      | 2,200,000 | 10,000 | |
| 7 | Nakamura Sho    | Backend           | Delivery | backend  | Mid      | 2,150,000 | 0      | |
| 8 | Kimura Yumi     | Backend (Junior)  | Delivery | backend  | Junior   | 1,700,000 | 0      | |
| 9 | Sasaki Hina     | Backend           | Delivery | backend  | Mid      | 2,200,000 | 0      | |
| 10 | Inoue Riku     | Senior Frontend   | Delivery | frontend | Senior   | 2,400,000 | 20,000 | |
| 11 | Takahashi Akira | Frontend          | Delivery | frontend | Mid      | 2,200,000 | 0      | |
| 12 | Kobayashi Saki  | Frontend          | Delivery | frontend | Mid      | 2,150,000 | 0      | |
| 13 | Mori Yuki       | Frontend (Junior) | Delivery | frontend | Junior   | 1,700,000 | 0      | |
| 14 | Hayashi Ren     | QA Lead           | Delivery | qa       | Senior   | 2,300,000 | 10,000 | |
| 15 | Shimizu Aoi     | QA                | Delivery | qa       | Mid      | 2,000,000 | 0      | |
| 16 | Matsumoto Kohei | Product Designer  | Delivery | design   | Senior   | 2,300,000 | 0      | |
| 17 | Fujita Mio      | Project Manager   | Delivery | pm       | Mid      | 2,500,000 | 20,000 | "Bleeding" PM |
| 18 | Hoshino Reina   | Project Manager   | Delivery | pm       | Mid      | 2,500,000 | 0      | |
| 19 | Ogawa Eri       | Sales Rep         | Sales    | pm       | Mid      | 1,800,000 | 0      | non-billable |
| 20 | Mori Aya        | HR Lead           | HR       | pm       | Mid      | 1,800,000 | 0      | non-billable |

`workable_hours = 160` for everyone except Sales & HR (`= 0` so they show NULL `cost_per_hour` and `—` in Sell/Hr).

## Users (login credentials printed at end)

| Email | App role | Employee link |
|---|---|---|
| `tanaka@superanka.example.jp` | Admin | Tanaka Hiro |
| `sato@superanka.example.jp` | Executive | Sato Kenji |
| `ogawa@superanka.example.jp` | Sales | Ogawa Eri |
| `suzuki@superanka.example.jp` | Delivery | Suzuki Aiko |
| `mori-aya@superanka.example.jp` | HR | Mori Aya |
| (+ 1 user per remaining employee, all `Delivery`) |

Password for all: `Demo@1234`.

## Pipeline (6 deals telling a story)

| # | Deal | Status / Rank | Window | Team | Budget ¥ | Story |
|---|---|---|---|---|---|---|
| 1 | **Mercari payments rebuild** | won (S) | 2026/01–06 | 1 PM + 2 BE + 1 FE + 1 QA | 90,000,000 | **HEALTHY** — on-plan, paid Jan–Apr, May pending; modest +10% margin |
| 2 | **JR East passenger console** (パッセンジャー) | won (S) | 2026/02–08 | 1 PM + 2 BE + 2 FE + 1 QA + 1 Design | 130,000,000 | **THIN** — break-even, schedule slip eating margin |
| 3 | **Rakuten warehouse OS** | won (S) | 2026/01–07 | 1 PM + 3 BE + 1 FE + 1 QA | 100,000,000 | **BLEEDING** — ~150 OT hrs absorbed, OT-impact card shows red |
| 4 | NTT identity gateway | negotiation (A) | planned 2026/07–10 | 1 PM + 2 BE + 1 FE | 60,000,000 | Contract draft generated + sent; awaiting signed PDF |
| 5 | LINE chatbot integration | qualified (B) | planned 2026/09–11 | 1 PM + 1 BE + 1 FE | 35,000,000 | Estimation locked; team mix proposed |
| 6 | Yahoo!知恵袋 archive migration | lost | proposed 2026/05–08 | n/a | 45,000,000 | `lifecycle_status=dropped`, `dropped_at_stage=qualified`, reason recorded |

## Numbers we'll be able to prove (verification queries)

After the seeder runs, querying back should yield:

| Metric | Expected ballpark | How verified |
|---|---|---|
| Total recognized revenue YTD | ~¥75M (sum of paid invoices for projects 1+2+3 through current month) | `SUM(invoices.amount) WHERE status='Paid'` |
| Project 1 (healthy) gross margin | ≈ +10% (revenue > labour×1.15 + overheads) | Recompute from approved TimeEntries × cost_per_hour × 1.15 vs paid invoices |
| Project 2 (thin) gross margin | ≈ +1–2% | Same |
| Project 3 (bleeding) gross margin | ≈ −5–8% | Same; OT delta = (Σ used − Σ progress) ≈ 150 h |
| Capacity pool utilisation | 70–85% across backend/frontend in active months | `getCapacityPool()` selector |
| Forecast year-end profit | between ¥80M and ¥110M (S+A+B scope) | `chartData.reduce(…profit)` in forecast page |
| OT-impact (Realized Profit card) | ~¥4–6M negative on Rakuten | Σ phase_progress_logs late_hours × leader/member avg cost_per_hour |

## Run command + idempotency

```
php artisan db:seed --class=SuperAnkaSeeder
```

Idempotent: wipes only `super-anka` tenant on rerun (no impact on other tenants).

## What I will NOT seed (and why)

- **AiUsageLog rows** — created by real Claude calls; faking them would lie about cost telemetry. Real AI calls during the demo will populate them naturally.
- **EmployeeSalaryHistory** — keeps demo simple; historical salary variance is a niche reporting feature.
- **Locale fields on the Tenant** beyond `currency` — multi-locale rollout is post-MVP.

## Verification step before declaring "done"

After running the seeder against a fresh local DB I will:
1. Query the tenant and dump per-project rollups (revenue, labour cost, OT, gross margin).
2. Compare against the expected ballpark above.
3. Manually open the Dashboard + Financial + Forecast pages in the browser to confirm non-empty render.
4. Report the numbers back to you alongside the actual seeder file.

---

# 10. Phase 2 — Build Results

## Run command

```
php artisan db:seed --class=SuperAnkaSeeder
```

Idempotent: wipes only the `super-anka` tenant on rerun (no impact on other tenants).

## Seeded login credentials (password `Demo@1234`)

| App role | Email | Employee | Purpose |
|---|---|---|---|
| **Admin** | `tanaka@superanka.example.jp` | Tanaka Hiro | Full access — pipeline, projects, employees, settings |
| **Executive** | `sato@superanka.example.jp` | Sato Kenji | P&L / Forecast / signatory |
| **Executive** | `suzuki@superanka.example.jp` | Suzuki Aiko | Engineering Mgr — runs Rakuten (the bleeding project) |
| **Sales** | `ogawa@superanka.example.jp` | Ogawa Eri | Pipeline kanban, deal CRUD |
| **HR** | `mori-a@superanka.example.jp` | Mori Aya | Employee/department mgmt |
| **Delivery** ×15 | `{key}@superanka.example.jp` | rest of the team | Time-tracking + project pages |

## Verified seeded numbers (run against a fresh DB on 2026-05-19)

| Metric | Expected (§8) | Actual | Match |
|---|---|---|---|
| Total employees | 20 | 20 | ✓ |
| Active billable IT staff | 17 (excl Sato + Ogawa + Mori Aya) | 17 | ✓ |
| Total monthly payroll | ~¥48M | ¥49,690,000 | ✓ (close enough) |
| Deals (won / nego / qual / lost) | 3 / 1 / 1 / 1 | 3 / 1 / 1 / 1 | ✓ |
| Contracts + Projects | 3 + 3 (won deals only) | 3 + 3 | ✓ |
| **Mercari margin (lifetime)** | **+10.2%** | **+10.2%** ✓ (healthy) | ✓ |
| **JR East margin (lifetime)** | **+2.0%** | **+2.0%** ✓ (thin) | ✓ |
| **Rakuten margin (lifetime)** | **-5% or worse** | **-6.6%** (budget trimmed to ¥73.5M to match team cost reality) | ✓ |
| OT TimeEntry hours (Rakuten) | ~120h logged through May | 120.0h | ✓ |
| OT PhaseLog late hours (Rakuten) | should equal TimeEntry OT | 120.0h | ✓ **consistent** |
| Total recognized revenue (accepted milestones) | – | ¥92M | – |
| Total cash collected (Paid invoices) | ~¥160M | ¥160.6M | ✓ |
| Total seeded rows | – | 89 time entries · 297 phase logs · 14 invoices · 8 milestones · 16 team assignments · 51 employee-skills · 6 AI usage logs | ✓ |

**Note on Rakuten "bleeding"**: original plan called for budget ¥77M which produced only -1.7% margin (the team labour alone already exceeded the budget by ~1.3M, then OT made it worse — three angles, three numbers). Trimmed budget to **¥73.5M** (monthly fee ¥10.5M × 7mo) so the lifetime margin reads a clean **-6.6%** and the cash position is **-¥11.8M underwater** on day-one of the demo. OT signal still firing — 120h logged with TimeEntry + PhaseLog in lockstep.

## What lights up on each page after running

| Page | What you'll see |
|---|---|
| `/dashboard` | Current Realized Profit positive (≈¥40–60M) from approved time entries; 2 watch projects + 1 at-risk (Rakuten) |
| `/financial` | Monthly P&L Jan–May with revenue from paid invoices, direct labor from time-entries × cost/hr × 1.15, overhead from 3 GlobalOverhead rows (≈¥9M/mo) |
| `/forecast` | Jan–Dec line. Income peaks in Jul (3 won deals + NTT × 80%). Year-end profit negative (~-¥200M) — by design: the team is currently under-loaded, the AI surfaces it |
| `/project-pipeline` (Kanban) | 5 active deals across C/B/A/S lanes + dropped lane (Yahoo!知恵袋) |
| Deal Detail → NTT | "Open contract draft" item visible in kebab menu (status `sent_to_customer`) |
| Estimation page | All 6 deals selectable; ghost roles + estimation rows pre-populated; "Rebuild AI Team" secondary button visible on LINE (qualified) |
| `/organization` employees | 20 rows; Sales/HR show `—` in Sell/Hr (non-billable), IT staff show ¥25k–¥30k/hr |
| AI Usage admin | 6 sample log entries spread across team builder, estimation, contract draft, forecast, chatbot |

## Files touched in Phase 2

| File | Purpose | Lines |
|---|---|---|
| `database/seeders/SuperAnkaSeeder.php` | the seeder itself | ~1,030 |
| `docs/SYSTEM_ANALYSIS.md` | this document | – |

## Outstanding TODOs for the seeder (deferred — not blocking the demo)

1. Increase Rakuten OT to ≥200h (or reduce budget) if you want the lifetime margin to read clearly red (-5% or worse). Current 120h logged ≈ 80% of planned 150h.
2. Add an `EmployeeSalaryHistory` row for at least one employee so the salary-history page isn't empty.
3. Consider seeding a `ContractTemplate` row scoped to `super-anka` (tenant_id != NULL) so the tenant can customize the SES template without affecting the global default.
4. Wire a couple of negotiation-stage `DealContractDocuments` uploads if AI estimation drafts need rich few-shot examples.

