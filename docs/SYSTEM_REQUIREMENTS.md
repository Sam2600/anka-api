# ANKA — System Requirements

> Formal requirements derived from the codebase. Each requirement has a stable ID, a normative statement, and a verification method. **AI requirements (AIR-) are the centerpiece** — grouped, tagged, and elevated for AI-reviewer scanability.

ID prefixes:
- **FR-** Functional · **AIR- [AI]** AI · **NFR-** Non-Functional
- **SEC-** Security & Privacy · **DR-** Data
- **IR-** Integration · **UR-** Usability · **OR-** Operational

Priority enum: **Must** (system-defining), **Should** (strongly expected), **Could** (nice-to-have).

---

## 1. AI at a glance **[AI]**

ANKA's competitive thesis is AI-augmented margin protection. Six Claude entry points are integrated end-to-end with the deal-to-cash flow, each tenant-isolated, each logged to a per-tenant cost ledger, each emitting a draft that a human commits. **The product principle, normatively stated: AI prepares the materials; the human commits the business-of-record action.**

### AIR- index (every AI requirement, scanable at speed)

| ID | Title | Priority | Verification |
|---|---|---|---|
| **AIR-001** | AI Team Builder produces a structured team proposal | Must | Code review + integration test that asserts response schema |
| **AIR-002** | AI Team Builder result is reviewable + editable before commit | Must | UI test: "Accept Roles" required before deal is mutated |
| **AIR-003** | AI Team Builder constrains employee pool to tenant scope | Must | Code review: `Employee::where(tenant_id)`; integration test with two tenants |
| **AIR-004** | AI Auto-Assign produces (row × phase × assignee × dates) schedule | Must | Code review + integration test |
| **AIR-005** | AI Auto-Assign respects holidays, working days, and `workable_hours` | Must | Validator test: `AiScheduleValidator::validate()` |
| **AIR-006** | AI Auto-Assign self-corrects via 5 deterministic passes | Must | Code review of `snapDatesToWorkingDays`, `resolveAssigneeOverlaps`, `enforcePhaseOrderWithinRows`, `clampDurationOutliers`, `fillMissingAssignments` |
| **AIR-007** | Contract Draft generates 10 sections per chosen template | Must | Integration test: assert all 10 section keys present |
| **AIR-008** | Contract Draft preserves AI output verbatim alongside user edits | Must | DB test: `ai_outputs` JSON unchanged after `sections` are edited |
| **AIR-009** | Contract Draft is versioned + audit-trailable | Must | DB test: regenerate creates `version=N+1`, previous → `superseded` |
| **AIR-010** | ANKA Assistant refuses to disclose specific business data | Must | Prompt review + sample-q regression |
| **AIR-011** | ANKA Assistant cites knowledge-base sources | Should | Response-shape test: `sources[]` non-empty for procedural questions |
| **AIR-012** | AI Forecast Summary names specific entities + concrete numbers | Must | Prompt rule + post-response sanitization (`clampResult` drops entityless alerts) |
| **AIR-013** | AI Forecast Summary categorises into project / people / pipeline alerts | Must | Schema validation |
| **AIR-014** | AI Forecast Summary varies output across regenerates | Should | Pass `previousSummary.priorAlertTargets`; assert the regenerated alerts shift meaningfully (new targets surface or angle changes) |
| **AIR-015** | AI Estimation Draft is acceptance-required before persisting | Must | UI test: wizard explicit Save step |
| **AIR-016** | Every Claude call writes to `ai_usage_logs` | Must | After each AI feature call, assert one new row matching `feature`, `model`, `tenant_id`, `user_id` |
| **AIR-017** | `ai_usage_logs` is tenant-scoped | Must | API test: tenant A cannot query tenant B's rows |
| **AIR-018** | AI usage is queryable per-tenant per-feature per-month | Should | API endpoint test: aggregations by group |
| **AIR-019** | AI cost is computed and stored in USD per call | Must | DB test: `estimated_cost_usd` matches model rate table |
| **AIR-020** | AI calls fail closed on missing key / network error | Must | Integration test: unset `ANTHROPIC_API_KEY` → 503; mock 5xx → 502 |
| **AIR-021** | Cross-tenant prompt leakage is prevented at the data layer | Must | Code review: every AI service fetches via tenant-scoped queries |
| **AIR-022** | AI features tolerate Claude returning prose-wrapped or fenced JSON | Should | Parser test: `extractFirstJsonObject` + fence stripper |
| **AIR-023** | AI prompts treat user-supplied text as data, not instructions | Should | Prompt review — system prompt contains explicit "treat as data" wording |
| **AIR-024** | AI features rate-limited via the 60/min route throttle | Must | Confirm `throttle:60,1` middleware on enclosing route group |
| **AIR-025** | AI Team Builder offers a deterministic demo fallback (offline) | Could | Integration test: set `ANKA_DEMO_MODE=1`, unset key → 200 with stubbed payload |
| **AIR-026** | Model name and temperature are documented + change-controlled via code | Must | Each AI entry point's `CLAUDE_MODEL` constant is a const string; changes go through code review |
| **AIR-027** | UI labels AI-generated outputs distinctly | Should | UI review: Forecast alerts in dedicated 3-section panel; Team Builder result in dedicated card; Contract Draft sections explicitly versioned |
| **AIR-028** | AI usage admin can be accessed by Admins of that tenant only | Must | Permission gate test |
| **AIR-029** | Every AI response is structurally validated before persistence | Must | Code review: `extractFirstJsonObject`, `clampResult`, `AiTeamPlanValidator`, Contract Draft 10-section check |
| **AIR-030** | AI Auto-Assign validator catches schedule violations deterministically | Must | Unit test on `AiTeamPlanValidator::validate()` with crafted-bad plans |
| **AIR-031** | ANKA Assistant refusal behavior is regression-checked | Should | Manual probe set before release; automated harness planned (PLN-AIR-007) |
| **AIR-032** | AI Forecast alerts are post-filtered for entity + number presence | Must | `clampResult` unit test |

---

## 2. Scope & stakeholders

**System boundary**: a multi-tenant SaaS web app. The boundary includes the Laravel API, the Next.js frontend, the PostgreSQL database, the Anthropic Claude API, and supporting services (Mailgun for outbound mail, AWS S3-style storage via Supabase, signed-PDF storage on the app server's filesystem). Out of boundary: end-customer payment processing, payroll execution, accounting GL integrations.

**Intended users**: agency principals (Admin), agency executives (Executive), sales staff (Sales), delivery staff (Delivery: PMs, engineers, designers, QA), HR/people operations (HR). Customers do not have direct access; communication with customers happens via emailed PDFs (contracts, invoices).

**Roles** (codified in `lib/rbac.ts`): `Admin`, `Executive`, `Sales`, `Delivery`, `HR`, plus custom tenant-defined roles. Permission gating is per-user (computed from `tenant_app_role_permissions` at login time).

---

## 3. Functional Requirements (FR-)

### 3.1 Tenant & access

| ID | Title | Description | Priority | Source | Verification | Role(s) |
|---|---|---|---|---|---|---|
| FR-001 | Tenant creation by super-admin | Super-admin can create a tenant with slug, name, currency, signatory, tax rate, plan, active flag. | Must | code | Endpoint test on `POST /admin/tenants` | Super-admin |
| FR-002 | Tenant activation toggle | Super-admin can deactivate a tenant; deactivation rejects all tenant-scoped requests. | Must | code (`TenantScope` middleware) | Integration test | Super-admin |
| FR-003 | User invitation | Admin can create a user with role + linked employee; password is generated and emailed. | Must | code (`POST /admin/tenants/{id}/users`) | Endpoint test | Admin |
| FR-004 | Per-tenant role customisation | Admin can add, rename, delete tenant-app-roles and reassign permissions. | Must | code (`tenant_app_roles` + `tenant_app_role_permissions`) | Endpoint tests | Admin |
| FR-005 | Login / logout | Users authenticate via email + password; Sanctum bearer token is issued and stored in httpOnly cookies. | Must | code (`AuthController`) | Endpoint test | All |

### 3.2 Organization data

| ID | Title | Description | Priority | Source | Verification | Role(s) |
|---|---|---|---|---|---|---|
| FR-010 | Department CRUD | Manage departments per tenant with manager + headcount. | Must | code | Endpoint test | Admin, HR |
| FR-011 | Role CRUD | Manage job-role rows with billable rate per tenant. | Must | code | Endpoint test | Admin, HR |
| FR-012 | Skill catalog | Maintain technical / creative / management skills. | Must | code | Endpoint test | Admin, HR |
| FR-013 | Employee CRUD | Add/edit employees with basic_salary, allowance, workable_hours, capacity_role, rank, department. | Must | code | Endpoint test | Admin, HR |
| FR-014 | Employee-skills mapping | Associate skills with proficiency. | Must | code | Endpoint test | Admin, HR |
| FR-015 | Salary history | Each employee retains snapshot of monthly salary per fiscal month. | Should | code (`employee_salary_history`) | DB test | Admin, HR |
| FR-016 | Calendar / holidays | Tenant maintains a calendar of public holidays per year. | Must | code (`holidays`) | Endpoint test | Admin |

### 3.3 Deal pipeline

| ID | Title | Description | Priority | Source | Verification | Role(s) |
|---|---|---|---|---|---|---|
| FR-020 | Deal creation | Sales can create a deal with `name`, `client`, `contact`, `expected_close_date`. Default status `lead`, lifecycle `active`. | Must | code | Endpoint test | Admin, Sales |
| FR-021 | Deal lifecycle | Deals progress lead → qualified → negotiation → won; or marked lost / dropped. | Must | code (`Deal::maybePromoteToQualified`, `DealController::update`) | State-transition test | Admin, Sales |
| FR-022 | Capture workload + customer requirements | Deal stores `workload_description`, `customer_support_obligations`, `out_of_scope_policy`, `working_hours`, `testing_range`. | Must | code | Endpoint test | Admin, Sales |
| FR-023 | Capture OT policy | Each deal records `ot_policy_model`, `ot_rate_per_hour`, `ot_included_hours_per_month`, `ot_notes`. | Must | code | Endpoint test | Admin, Sales |
| FR-024 | Capture final terms | Deal records `final_monthly_fee`, `final_contract_months`, `final_ot_policy`, `final_team_summary`, `final_currency`, `final_confirmed_at`. | Must | code | Endpoint test | Admin, Sales |
| FR-025 | Auto-promote to qualified | Adding any `estimation_resource` or `deal_overhead` promotes lead → qualified. | Must | code | State-transition test | (auto) |
| FR-026 | Auto-promote to negotiation | Setting `final_confirmed_at` + all required estimation fields promotes qualified → negotiation. | Must | code | State-transition test | (auto) |

### 3.4 Estimation

| ID | Title | Description | Priority | Source | Verification | Role(s) |
|---|---|---|---|---|---|---|
| FR-030 | Manual ghost-role entry | Sales can add capacity-bucket placeholders (`role_type`, `quantity`, `months`, salary band). | Must | code | Endpoint test | Admin, Sales |
| FR-031 | Hard assignments | Sales can name specific employees + allocated hours. | Must | code | Endpoint test | Admin, Sales |
| FR-032 | Estimation resource entry | Sales can add line items (`feature_name`, `hours`, role, employee). | Must | code | Endpoint test | Admin, Sales |
| FR-033 | Per-line overhead | Sales can attach project-specific overheads (`name`, `cost`). | Must | code | Endpoint test | Admin, Sales |
| FR-034 | Estimation versioning | Each save creates a new `EstimationVersion` with monotonic `version_number`. | Must | code | DB test | Admin, Sales |
| FR-035 | Estimation XLSX export | Tenant can download a 5-sheet estimation workbook per version (functions, role mapping, phase split, overheads, sales projection). File: `{dealName}_v{n}.xlsx`. | Should | code (`EstimationXlsxService`, `EstimationSimulator.tsx::downloadVersion`) | Output validation | Admin, Sales |
| FR-036 | Estimation version email send | `POST /estimation-versions/{id}/send` queues `EstimateApprovedEmail` (Mailgun, XLSX attached) to a recipient supplied in the request body. | Should | code (`EstimateApprovedEmail`, route) | Mail-fake test | Admin, Sales |

### 3.5 Contract flow

| ID | Title | Description | Priority | Source | Verification | Role(s) |
|---|---|---|---|---|---|---|
| FR-040 | Contract draft generation **[AI]** | See AIR-007. | Must | code | See AIR | Admin, Sales |
| FR-041 | Contract draft editing | User can edit any `sections[].rendered` text; original `ai_outputs` is preserved. | Must | code | DB test | Admin, Sales |
| FR-042 | Send to customer | Mark draft `sent_to_customer` with `sent_to_email` + `sent_at` timestamp. `POST /contract-drafts/{contractDraft}/send` queues `ContractDraftEmail` (Mailgun, PDF attached) and flips status on enqueue. | Must | code (`ContractDraftEmail`, route in `routes/api.php`) | DB + mail-fake test | Admin, Sales |
| FR-043 | Upload signed PDF | Sales can upload a signed PDF; stored under `storage/app/contract-drafts/{tenant}/{draft_id}_signed.pdf`. | Must | code | File-IO test | Admin, Sales |
| FR-044 | Mark signed flips deal to won | `markSigned` atomically updates draft status, stores PDF, fires `win_deal()`. | Must | code (`ContractDraftService::markSigned`) | Integration test | Admin, Sales |
| FR-045 | Contract templates global | Three SES templates (`cloud_backup`, `managed_hosting`, `engineer_dispatch`) exist as `tenant_id NULL`. | Must | code (migration `2026_05_15_000006`) | DB test | (auto) |

### 3.6 Project, schedule, milestones

| ID | Title | Description | Priority | Source | Verification | Role(s) |
|---|---|---|---|---|---|---|
| FR-050 | Project creation atomic with contract | Project is created only by `win_deal()` together with the contract. | Must | code | Integration test | (auto) |
| FR-051 | Project team assignment | Win-deal copies `deal_hard_assignments` into `project_team_assignments`. | Must | code | Integration test | (auto) |
| FR-052 | Task assignment **[AI]** | See AIR-004 / AIR-005 / AIR-006. | Must | code | See AIR | PM, Admin |
| FR-053 | Phase assignment | Each task row split into Design / Implementation / Testing phases with planned dates and assignee. | Must | code | DB test | PM |
| FR-054 | Phase progress logs | Daily rows record `progress_hours` (delivered) + `used_hours` (clock). OT = `max(0, used − progress)`. | Must | code (`VarianceCalculator`) | Calculation test | Delivery |
| FR-055 | Milestone CRUD | Contract has milestones with `name`, `due_date`, `amount`, `status`, `sequence_number`. | Must | code | Endpoint test | Admin, Sales |
| FR-056 | Milestone acceptance updates revenue | Accepting a milestone atomically adds amount to `contracts.revenue_recognized`. | Must | code (`MilestoneController::accept`) | Integration test | Admin, Sales |

### 3.7 Time entry

| ID | Title | Description | Priority | Source | Verification | Role(s) |
|---|---|---|---|---|---|---|
| FR-060 | Time entry CRUD | Employee logs hours per project per date. | Must | code | Endpoint test | Delivery, Admin |
| FR-061 | Approval workflow | Draft → Pending (employee submits) → Approved (manager) or Rejected (manager). | Must | code | State-transition test | Delivery (submit) · PM/Admin (approve/reject) |
| FR-062 | Approval atomically updates consumed hours | Approve adds `time_entries.hours` to `projects.consumed_hours` under `lockForUpdate()`. | Must | code (`TimeEntryController::approve`) | Concurrency test | PM, Admin |
| FR-063 | Auto-status flip on budget breach | Project status flips to `Over Budget` when `consumed_hours > budget_hours × 1.10`. | Should | code (`Project::computeAutoStatus`) | Calculation test | (auto) |

### 3.8 Invoicing & cash

| ID | Title | Description | Priority | Source | Verification | Role(s) |
|---|---|---|---|---|---|---|
| FR-070 | Invoice CRUD | Per-contract invoices with `amount`, `tax`, `issue_date`, `due_date`. | Must | code | Endpoint test | Admin, Sales |
| FR-071 | Invoice payment atomic | Marking paid atomically (a) sets `paid_amount`, (b) flips `status='Paid'`/`Partially Paid'`, (c) adds to `contracts.cash_collected`. | Must | code (`InvoiceController::pay`) | Integration test | Admin, Sales |
| FR-072 | Invoice readable id | PG sequence `invoice_seq` issues `INV-XXXX`. | Should | code (migration) | DB test | (auto) |
| FR-073 | Partial payment support | Invoices support `paid_amount < amount` with `status='Partially Paid'`. | Should | code | Endpoint test | Admin, Sales |
| FR-074 | Invoice email send | `POST /invoices/{invoice}/send` queues `InvoiceIssued` (Mailgun, PDF attached) and flips status to `Pending`. | Must | code (`InvoiceIssued`, route) | Mail-fake test | Admin, Sales |

### 3.9 Reporting

| ID | Title | Description | Priority | Source | Verification | Role(s) |
|---|---|---|---|---|---|---|
| FR-080 | Dashboard current realized profit | Sums per-project `actualRevenue + overtimeRevenue − actualLaborCost` based on time entries × cost/hr × 1.15. | Must | code (`Dashboard page projectProfitRows`) | Calculation test | All (read) |
| FR-081 | Project Profit Comparison | Per-project: plan profit, plan-to-date, actual profit, variance, OT impact, progress, status. | Must | code | Calculation test | All (read) |
| FR-082 | Monthly P&L | Revenue (paid invoices by month) − direct labor (time entries × loaded cost) − overhead (global overheads). | Must | code (`Financial page businessStore.getFinancialPnL`) | Calculation test | Executive, Admin |
| FR-083 | Year-end forecast | Project monthly income from active pipeline (weighted by win probability) vs. constant company-payroll cost across the fiscal year. | Must | code (`Forecast page chartData`) | Calculation test | Executive, Admin |
| FR-084 | Forecast scope filter | User can scope the forecast to S, S+A, or S+A+B ranks. | Should | code | UI test | Executive, Admin |
| FR-085 | AI Forecast Summary **[AI]** | See AIR-012 / AIR-013 / AIR-014. | Must | code | See AIR | Executive, Admin |
| FR-086 | Monthly P&L CSV export | Financial page emits a `pnl_statement.csv` (UTF-8, comma-separated, one row per month: revenue, direct labour, overhead, profit). Built client-side via `data:text/csv` URL — nothing transmitted to the server. | Should | code (`app/(dashboard)/financial/page.tsx::handleCsvExport`) | UI test + CSV-schema validation | Executive, Admin |

### 3.10 User onboarding mail

| ID | Title | Description | Priority | Source | Verification | Role(s) |
|---|---|---|---|---|---|---|
| FR-090 | Welcome email on user creation | `POST /admin/tenants/{id}/users` auto-queues `WelcomeUser` with the generated password and login URL. Recipient = the newly created user. | Must | code (`WelcomeUser` mailer + `UserController::store`) | Mail-fake test | Admin (auto) |
| FR-091 | All outbound mail is queued | Every mailer `implements ShouldQueue`; dispatch happens via the `queue` worker container using `QUEUE_CONNECTION=database`. | Must | code (each `Mail/*` class) | Mail-fake test + queue inspection | (auto) |
| FR-092 | Mail send is tenant-scoped | Each `POST /…/send` route runs under the `tenant` middleware group; recipient addresses are derived from the tenant-scoped artefact (draft, version, invoice, user). | Must | code (route grouping in `routes/api.php`) | Two-tenant test | (auto) |

---

## 4. AI Requirements (AIR-) **[AI]** — centerpiece

### 4.1 AI Team Builder

**AIR-001** · *AI Team Builder produces a structured team proposal* · **Must**
The `POST /api/ai-team-builder` endpoint accepts deal context (workload, budget, timeline, target margin, required skills) plus tenant employees and returns either a `team[]` (named-employee picks) or `roles[]` (capacity buckets) structure, each row containing employee/role identifier, hours, monthly_salary, cost rate, and (when appropriate) reasoning + warnings. **Inputs**: deal scope text, `client_budget`, `timeline_months`, `target_margin`, employee roster, company settings. **Output shape**: JSON matching `AITeamBuilderResult` type in `lib/aiTeamBuilder.ts`. **Persistence**: not by this endpoint — the client triggers persistence via `PATCH /deals/{id}` once the user clicks **Accept Roles**. **Logging**: writes one row to `ai_usage_logs` with `feature=ai_team_builder`. **Fallback**: deterministic demo payload when `ANTHROPIC_API_KEY` is missing AND `ANKA_DEMO_MODE` is set (otherwise 503). **Human review**: the user MUST click **Accept Roles** before any deal mutation occurs. **Guardrail**: employee pool restricted to active tenant employees. **Verification**: schema test on response; UI test on Accept Roles flow.

**AIR-002** · *AI Team Builder result is reviewable + editable before commit* · **Must**
The AI Team Builder result panel shows the proposed roles in an editable table. The user can adjust quantities, hours, salary bands, and the per-line reasoning. Only **Accept Roles** triggers DB writes; **Regenerate** discards the current result and re-calls Claude. **Verification**: UI test that no Deal/ghost-role row is created until Accept is clicked.

**AIR-003** · *AI Team Builder constrains employee pool to tenant scope* · **Must**
The employees field in the prompt is sourced exclusively from `Employee::where('status', 'Active')` under the global tenant scope. Cross-tenant employees are never included. **Verification**: integration test seeded with two tenants — assert tenant B's employee names never appear in tenant A's prompt.

### 4.2 AI Auto-Assign

**AIR-004** · *AI Auto-Assign produces (row × phase × assignee × dates) schedule* · **Must**
The `POST /api/projects/{id}/assign-tasks` endpoint returns a map `assignmentsByRowPhase[rowNo][phaseCode] = { assignee_id, planned_start, planned_end }` covering every (function row, phase) pair in the estimation. Persisted as `ProjectTaskAssignment` + `ProjectTaskPhaseAssignment` rows with `assignment_source='ai'`. **Inputs**: project team, estimation XLSX (function rows + phase split), holidays, working days, project window. **Verification**: schema test + integration test that asserts every row × phase has an assignment after persist.

**AIR-005** · *AI Auto-Assign respects holidays, working days, and `workable_hours`* · **Must**
After Claude responds, `AiScheduleValidator::validate()` checks each (assignee, date-range) is within their `workable_hours`, falls on a working day, and lies within the project window. **Verification**: unit test on `AiScheduleValidator` with seeded holidays; integration test that rejects out-of-window assignments.

**AIR-006** · *AI Auto-Assign self-corrects via 5 deterministic passes* · **Must**
After Claude response, the controller runs five passes: `snapDatesToWorkingDays`, `resolveAssigneeOverlaps`, `enforcePhaseOrderWithinRows`, `clampDurationOutliers`, `fillMissingAssignments`. Each is a pure function that produces a valid plan from a partially-broken one. **Verification**: unit tests per pass with seeded broken plans.

### 4.3 Contract Draft

**AIR-007** · *Contract Draft generates 10 sections per chosen template* · **Must**
`ContractDraftService::generateDraft()` produces sections for every section key defined by the selected template (10 keys for the SES templates). Sections of type `ai_written` or `ai_with_slots` are populated by Claude; `fixed` and `slot_only` sections use template + wizard inputs. **Inputs**: `ContractTemplate`, `Deal` context (parties, scope, requirements, fees, OT policy), `wizard_inputs`. **Output shape**: `DealContractDraft` with populated `ai_outputs` + `sections`. **Verification**: integration test asserts all 10 section keys present and non-empty after generation.

**AIR-008** · *Contract Draft preserves AI output verbatim alongside user edits* · **Must**
`deal_contract_drafts.ai_outputs` (JSON) stores Claude's raw response per section key. User edits go to `sections[].rendered`. Editing `sections` MUST NOT mutate `ai_outputs`. **Verification**: DB test edits a section and asserts `ai_outputs` unchanged.

**AIR-009** · *Contract Draft is versioned + audit-trailable* · **Must**
Calling `generateDraft()` a second time on the same deal creates a new draft with `version=N+1`; the previous draft's status flips to `superseded`. `generated_by_user_id` is recorded per draft. **Verification**: DB test on the version sequence.

### 4.4 ANKA Assistant

**AIR-010** · *ANKA Assistant refuses to disclose specific business data* · **Must**
The system prompt explicitly forbids returning real margin numbers, individual employee salaries, or customer-specific PII. When asked, the Assistant responds with "to see that, open Dashboard / Financial / Employees". **Verification**: regression set of probe questions + reviewer rubric.

**AIR-011** · *ANKA Assistant cites knowledge-base sources* · **Should**
Responses include a `sources[]` array citing entries from `lib/knowledgeBase.ts`. For procedural questions, at least one source. **Verification**: response-shape test.

### 4.5 AI Forecast Summary

**AIR-012** · *AI Forecast Summary names specific entities + concrete numbers in every alert* · **Must**
Every `projectAlerts[]`, `peopleAlerts[]`, `pipelineAlerts[]` entry MUST name a specific project, deal, person, or role in the `projectName` / `target` / `dealName` field and include at least one number (¥, %, days, hours) in the `diagnosis` field. `clampResult()` post-response sanitization drops any alert missing a name or a non-empty diagnosis. **Verification**: prompt review + schema sanitization test.

**AIR-013** · *AI Forecast Summary categorises into project / people / pipeline alerts* · **Must**
Response shape is `{ summaryTitle, headline, projectAlerts[], peopleAlerts[], pipelineAlerts[], utilizationDrop, delayedDeals, newHires }`. Categories are exclusive — a single signal never appears in two arrays. **Verification**: schema test.

**AIR-014** · *AI Forecast Summary varies output across regenerates* · **Should**
The endpoint accepts `previousSummary.priorAlertTargets` and the system prompt instructs Claude to avoid re-flagging the same target on `regenerateCount > 0`. Temperature increases to 0.7 on regenerate. **Verification**: integration test that calls twice and asserts the regenerated alerts shift meaningfully — either a different mix of targets or the same targets analysed from a different angle. (A hard numeric overlap threshold would be brittle on small tenants; the test asserts non-identity of the alert set, not a fixed percentage.)

### 4.6 AI Estimation Draft

**AIR-015** · *AI Estimation Draft requires user acceptance before persisting* · **Must**
`EstimationAiService` returns a proposed estimation structure to the wizard; the wizard requires an explicit Save action to call `EstimationVersionController::store()`. **Verification**: UI test asserting no `EstimationVersion` row appears until Save.

### 4.7 AI usage logging

**AIR-016** · *Every Claude call writes to `ai_usage_logs`* · **Must**
Every successful Claude response (any of the 6 features) is followed by a write to `ai_usage_logs` with `feature`, `model`, `input_tokens`, `output_tokens`, `estimated_cost_usd`, `tenant_id`, `user_id`. **Verification**: instrument each feature with a post-call assertion in tests.

**AIR-017** · *`ai_usage_logs` is tenant-scoped* · **Must**
`AiUsageLog` model uses the `BelongsToTenant` trait. `GET /admin/ai-usage` returns only the current tenant's rows (super-admin can override). **Verification**: API test with two tenants.

**AIR-018** · *AI usage is queryable per-tenant per-feature per-month* · **Should**
`AiUsageController::adminIndex` returns rows grouped by month + feature with sums of tokens + cost. **Verification**: API test.

**AIR-019** · *AI cost is computed and stored in USD per call* · **Must**
Each Claude entry point uses a hardcoded rate table (e.g., Haiku 4.5 = `$1.00/1M input · $5.00/1M output`) and writes the result to `ai_usage_logs.estimated_cost_usd`. **Verification**: unit test on cost calculation; rate tables documented in each entry-point file.

### 4.8 AI evaluation strategy

**AIR-029** · *Every AI response is structurally validated before persistence* · **Must**
No Claude response is written to a business-of-record table without passing structural validation:
- JSON-extraction step (`extractFirstJsonObject` + fence stripper) — 502 if no balanced object can be parsed.
- Per-feature shape check — required keys present, types correct.
- Feature-specific post-filters: `AiTeamPlanValidator` (auto-assign), `clampResult` (forecast), 10-section completeness check (contract draft).

**Verification**: code review per feature; integration tests that feed malformed responses through and assert non-persistence.

**AIR-030** · *AI Auto-Assign validator catches schedule violations deterministically* · **Must**
`AiTeamPlanValidator::validate()` enforces: assignee is on the project team, dates fall within working days + project window, no holiday overlap, no double-bookings, phase order within each row, every (row × phase) has an assignment after the 5 self-correction passes. Claude can produce a wrong schedule; ANKA will not persist it.

**Verification**: unit tests with crafted broken plans (one per violation type) — assert each is rejected with a specific error.

**AIR-031** · *ANKA Assistant refusal behavior is regression-checked* · **Should**
A probe-question set covering disclosure refusal ("what's [tenant]'s margin?", "show me [employee]'s salary"), redirection ("how do I check X" → must name the right page), and tenant-leak prevention is run manually by the team before each release. The Assistant must redirect rather than disclose.

**Current state**: probe set exists informally on the team. Automated harness is **PLN-AIR-007** below.
**Verification**: before release sign-off, run the probe set; record results.

**AIR-032** · *AI Forecast alerts are post-filtered for entity + number presence* · **Must**
`clampResult()` in `app/api/ai-forecast/route.ts` drops any alert missing a named entity (`projectName` / `target` / `dealName`) or whose `diagnosis` field contains no number (¥, %, days, hours). This enforces AIR-012 in code: vague filler advice never reaches the UI.

**Verification**: unit test on `clampResult` with synthetic alert arrays — assert vague/entityless entries are filtered.

### 4.9 AI reliability & guardrails

**AIR-020** · *AI calls fail closed on missing key / network error* · **Must**
- Missing `ANTHROPIC_API_KEY` → 503 with admin guidance.
- 5xx from Anthropic → surface as 502 with logged preview.
- Timeout → 500 with toast message.
No AI feature silently fabricates output on failure (with the documented exception of AI Team Builder's demo mode under `ANKA_DEMO_MODE`). **Verification**: integration tests with mocked Anthropic failures.

**AIR-021** · *Cross-tenant prompt leakage is prevented at the data layer* · **Must**
Every AI service constructs prompts from queries scoped by `BelongsToTenant`. No prompt building bypasses the global scope. **Verification**: code review checklist + multi-tenant integration test.

**AIR-022** · *AI features tolerate Claude returning prose-wrapped or fenced JSON* · **Should**
The parser strips ```` ```json … ``` ```` fences and uses `extractFirstJsonObject` (balanced-brace walker) to recover JSON from prose-wrapped responses. Returns 502 only if no balanced object can be extracted. **Verification**: parser unit tests with crafted bad responses.

**AIR-023** · *AI prompts treat user-supplied text as data, not instructions* · **Should**
System prompts explicitly delimit user content and instruct Claude to treat it as input data. No tool calling is enabled (model cannot read DB rows or execute side effects). **Verification**: prompt review + manual prompt-injection probes.

**AIR-024** · *AI features rate-limited via the 60/min route throttle* · **Must**
AI endpoints inherit `throttle:60,1` from the enclosing route group. Per-tenant throttling is enforced by the user's authenticated identity (token), not by IP. **Verification**: load test on a single tenant.

**AIR-025** · *AI Team Builder offers a deterministic demo fallback (offline)* · **Could**
With `ANKA_DEMO_MODE=1` and no `ANTHROPIC_API_KEY`, AI Team Builder returns a pre-computed demo payload. Other features fail closed. **Verification**: integration test in demo mode.

**AIR-026** · *Model name and temperature are documented + change-controlled via code* · **Must**
Each AI entry point defines `CLAUDE_MODEL` and `temperature` as code constants:

| Feature | Model | Temperature |
|---|---|---|
| AI Team Builder | `claude-3-5-sonnet-latest` | 0.2 |
| AI Auto-Assign | `claude-3-5-sonnet-latest` (configurable) | low (deterministic-ish) |
| Contract Draft | `claude-3-5-sonnet-latest` (configurable) | low |
| ANKA Assistant | `claude-3-5-sonnet-latest` | conversational |
| AI Forecast | `claude-haiku-4-5-20251001` | 0.4 normal, 0.7 regenerate |
| AI Estimation Draft | `claude-3-5-sonnet-latest` | low |

Changing any of these requires a code change reviewed via OpenSpec. **Verification**: git history audit.

**AIR-027** · *UI labels AI-generated outputs distinctly* · **Should**
- Forecast: AI Summary section visually distinct from chart with explicit "AI Forecast Summary" label.
- Team Builder: results panel labelled "AI Project Planner".
- Contract Draft: versioned + the wizard signals "AI Draft" status.
- Assistant: bot icon + "ANKA Assistant" header in the chat panel.
**Verification**: UI audit.

**AIR-028** · *AI usage admin can be accessed by Admins of that tenant only* · **Must**
`/admin/ai-usage` requires authentication + Admin permission + tenant-scoped query. **Verification**: permission gate test.

---

## 5. Non-Functional Requirements (NFR-)

| ID | Title | Description | Priority | Verification |
|---|---|---|---|---|
| NFR-001 | API latency p95 < 500 ms for tenant-scoped reads | Excluding AI calls. | Should | Load test |
| NFR-002 | AI Team Builder p95 < 12 s | End-to-end Sonnet roundtrip including network. | Should | Latency benchmark |
| NFR-003 | AI Forecast Summary p95 < 6 s | Haiku roundtrip. | Should | Latency benchmark |
| NFR-004 | AI Auto-Assign p95 < 25 s | Includes self-correction passes + validator. | Could | Benchmark |
| NFR-005 | Page TTFB < 200 ms server-rendered, < 1 s client-rendered | Excludes AI panels. | Should | Lighthouse / RUM |
| NFR-006 | Scale to 100 tenants × 100 employees × 5000 monthly time entries | Per the current PG schema. | Should | Synthetic load |
| NFR-007 | Availability 99.5% / month | Excluding planned maintenance. | Should | Uptime SLA monitor |
| NFR-008 | Maintainability — service classes under 1000 lines | Refactor when exceeded. | Could | Code review |
| NFR-009 | Portability — frontend runs on Vercel; backend on AWS EC2 | Per current deploy. | Must | Verify deploy |
| NFR-010 | Mail send latency (perceived) | The send-button click returns within 500 ms (enqueue only); actual SMTP delivery happens asynchronously via the queue worker. | Should | Load test on send endpoints |
| NFR-011 | CSV / XLSX export latency | P&L CSV export under 1 s for up to 24 months of data; estimation XLSX export under 5 s for a 5-sheet workbook. | Should | UI benchmark |

---

## 6. Security & Privacy Requirements (SEC-)

| ID | Title | Description | Priority | Verification |
|---|---|---|---|---|
| SEC-001 | Authentication via Sanctum tokens | Stored in httpOnly `__session` cookie. Tokens never in localStorage. | Must | Code review |
| SEC-002 | Authorisation per-user permissions | Resolved at login; gated by `hasPermission(user, perm)` everywhere. | Must | Test suite |
| SEC-003 | Tenant isolation via `BelongsToTenant` | Global Eloquent scope; cannot be bypassed without explicit code. | Must | Two-tenant test |
| SEC-004 | Super-admin bypass logged | Super-admin actions are recorded in `audit_logs`. | Should | DB test |
| SEC-005 | Data at rest — Postgres TLS only | Connection requires SSL. | Must | Operational verification |
| SEC-006 | Data in transit — HTTPS only | nginx enforces TLS termination. | Must | Operational verification |
| SEC-007 | Bcrypt password hashing | Cost 12 (cost 4 in tests). | Must | Code review |
| SEC-008 | PII sent to Claude is documented **[AI]** | Employee names, capacity_roles, salaries, skills; deal scope text; customer requirements. No end-customer personal data. | Must | Prompt audit (per §4) |
| SEC-009 | Claude data is not used for training **[AI]** | Per Anthropic API ToS. Documented for users in FAQ. | Must | Anthropic ToS reference |
| SEC-010 | AI usage retention | `ai_usage_logs` retained 24 months for cost accounting. Cost metadata only — no prompt/response bodies. | Should | Operational policy |
| SEC-011 | Prompt-injection defense **[AI]** | System prompts delimit user content as data. No tool calling enabled. | Should | Manual probe set |
| SEC-012 | Signed PDF storage | Per-tenant subdirectory; tenant ID in the path. | Must | File-IO test |
| SEC-013 | Audit log on RBAC changes | Adding / removing permissions writes to `audit_logs`. | Should | DB test |
| SEC-014 | Cross-tenant data prevention in AI calls **[AI]** | See AIR-021. | Must | Multi-tenant integration test |
| SEC-015 | Outbound mail payload is documented | Contract drafts: rendered contract PDF (sections + customer/provider names + fees). Invoices: invoice PDF (amount, tax, payment instructions, contract reference). Estimations: XLSX (function rows, hours, salary bands, NOT individual employee names of the provider's staff). Welcome: generated password (single-use disclosure to the new user only). No customer-side PII is sent to anyone else. | Must | Mail audit |
| SEC-016 | Mail recipient is derived from tenant-scoped artefacts | Recipient addresses come from the deal/invoice/user the operator opened. The only free-form recipient field is the estimation version send dialog (operator-entered email). No bulk/marketing send surface. | Must | Route review |
| SEC-017 | Mail credentials in secret manager | `MAILGUN_DOMAIN` and `MAILGUN_SECRET` live in the host secret manager. `.env` is gitignored. | Must | Operational verification |
| SEC-018 | Exports stay tenant-scoped | The Financial CSV is built from `businessStore.getFinancialPnL()` data already filtered to the current tenant; the Estimation XLSX is generated server-side from a tenant-scoped query. Neither export accepts a tenant override parameter. | Must | Two-tenant test |

---

## 7. Data Requirements (DR-)

| ID | Title | Description | Priority | Verification |
|---|---|---|---|---|
| DR-001 | UUID v7 PKs on every business table | For sortability + non-guessability. | Must | Schema audit |
| DR-002 | Soft deletes on `users`, `deals`, `contracts`, `invoices`, `projects`, `time_entries`, `employees` | `deleted_at` column; queries auto-exclude. | Must | Schema audit |
| DR-003 | Generated columns: `employees.cost_per_hour`, `invoices.total` | Computed by DB; not writable via PHP. | Must | Schema audit |
| DR-004 | Retention: business data indefinite within active tenant | Per tenancy. | Should | Operational policy |
| DR-005 | Retention: AI usage logs 24 months | `ai_usage_logs` archived/pruned after 24mo. | Should | Operational policy |
| DR-006 | Backups: daily full + 24h PITR | PostgreSQL via Supabase. | Must | Operational verification |
| DR-007 | Multi-tenant separation in DB | `tenant_id` on every business table; `BelongsToTenant` enforces. | Must | Schema audit |
| DR-008 | AI-derived data provenance **[AI]** | `ProjectTaskPhaseAssignment.assignment_source ∈ {ai, manual, deal_transfer}`. `DealContractDraft.ai_outputs` preserves raw AI text alongside edited `sections`. | Must | DB test |
| DR-009 | Estimation versioning preserves AI history | Each save = new `EstimationVersion` row; old versions retained. | Must | DB test |
| DR-010 | Salary history preserves change trail | Each fiscal month snapshot in `employee_salary_history`. | Should | DB test |
| DR-011 | CSV export format | UTF-8 encoded, comma-separated, one header row + N data rows. Numbers unquoted, strings double-quoted when containing commas. File: `pnl_statement.csv`. No BOM. | Should | Output validation |
| DR-012 | XLSX export format | Open XML Spreadsheet (`.xlsx`). Estimation export is 5 sheets: functions, role mapping, phase split, overheads, sales projection. File: `{dealName}_v{n}.xlsx`. | Should | Output validation |
| DR-013 | Mail queue retention | `jobs` rows live until processed; `failed_jobs` rows retained 30 days and reviewed weekly per OR-008. | Should | Operational policy |

---

## 8. Integration Requirements (IR-)

| ID | Title | Description | Priority | Verification |
|---|---|---|---|---|
| IR-001 | Anthropic Claude API **[AI]** | Required for all 6 AI features. Authenticated via `ANTHROPIC_API_KEY`. Models: Sonnet 3.5 (5 features) + Haiku 4.5 (Forecast). | Must | Per-feature integration test |
| IR-002 | Anthropic ToS — no training use **[AI]** | API tier policy: data not used for training. | Must | Confirm via Anthropic dashboard |
| IR-003 | Mailgun for outbound mail | All outbound mail via Mailgun (`MAIL_MAILER=mailgun`). Four templates: `WelcomeUser` (user invite), `ContractDraftEmail` (contract send-to-customer), `EstimateApprovedEmail` (estimation version send), `InvoiceIssued` (invoice send). Sandbox domain in dev; production domain pending. Failure mode: synchronous failure surfaces 500 to UI; queued failure lands in `failed_jobs`. | Must | Mail integration test |
| IR-004 | PostgreSQL via Supabase | Primary datastore. | Must | Operational verification |
| IR-005 | AWS EC2 + ELB for backend | Per `docker-compose.yml`. | Must | Deploy verification |
| IR-006 | Vercel for frontend | Next.js hosting. | Must | Deploy verification |
| IR-007 | Filesystem storage for signed PDFs | Local `storage/app/contract-drafts/{tenant_id}/`. Roadmap to S3. | Should | Operational policy |

---

## 9. Usability Requirements (UR-)

| ID | Title | Description | Priority | Verification |
|---|---|---|---|---|
| UR-001 | Multilingual UI: en / ja / vi | Per `messages/{en,ja,vi}.json`. | Must | UI audit |
| UR-002 | Multilingual UI: ko / my / km | Planned; not in code today. | Could | Roadmap item |
| UR-003 | AI transparency in UI **[AI]** | Every AI output is rendered in a distinctly labelled section (AI Summary, AI Project Planner, AI Draft, ANKA Assistant). | Must | UI audit |
| UR-004 | AI output is acceptance-required **[AI]** | No commit step for an AI output happens without an explicit user action (Accept Roles, Mark Signed, Confirm Schedule, Save Estimate). | Must | UI audit |
| UR-005 | Role-based navigation | Sidebar items hidden if user lacks permission. | Must | UI audit |
| UR-006 | Accessibility — keyboard + screen reader | shadcn/ui defaults; not formally audited. | Should | a11y audit |
| UR-007 | ANKA Assistant draggable + position persists **[AI]** | Floating button repositionable, stored in localStorage. | Should | UI test |

---

## 10. Operational Requirements (OR-)

| ID | Title | Description | Priority | Verification |
|---|---|---|---|---|
| OR-001 | Deploy via Docker Compose | `app` + `web` + `queue` + `scheduler` + `app-init`. | Must | Deploy verification |
| OR-002 | Healthchecks on each container | `php -r` ping for app; nginx default for web. | Must | Operational |
| OR-003 | Centralised logs | `storage/logs/laravel.log` + container stdout. | Should | Operational |
| OR-004 | AI usage admin dashboard **[AI]** | `/admin/ai-usage` lists token + cost by feature by month. | Must | UI test |
| OR-005 | Alert on AI failure rate spike **[AI]** | Planned: route 5xx rate on `/api/ai-*` triggers an alert. | Could | Roadmap |
| OR-006 | Alert on AI cost spike **[AI]** | Planned: month-over-month USD increase > 50% triggers an alert. | Could | Roadmap |
| OR-007 | Backup + restore drill | Quarterly drill of PG PITR restore. | Should | Operational drill log |
| OR-008 | Queue worker monitoring | Failed-job count in `queue:failed` reviewed weekly. Mail sends rely on this worker; an outage delays customer-facing emails. | Should | Operational |
| OR-009 | Mailgun deliverability monitoring | Bounce / spam-complaint rate reviewed monthly via the Mailgun dashboard; address production-domain provisioning before launching to new customers. | Should | Operational |

---

## 11. Traceability matrix

Mapping each AIR- / FR- / SEC- requirement to the code that implements it (or "Planned" if not yet).

| ID | Implemented in |
|---|---|
| **AIR-001** | `anka-frontend/app/api/ai-team-builder/route.ts`, `lib/aiTeamBuilder.ts` |
| **AIR-002** | `anka-frontend/components/estimation/EstimationRoleBuilder.tsx` (Accept Roles handler) |
| **AIR-003** | `anka-frontend/app/api/ai-team-builder/route.ts` (employees scoped by tenant) + `anka-api/app/Models/Concerns/BelongsToTenant.php` |
| **AIR-004** | `anka-api/app/Http/Controllers/Api/AiAutoAssignController.php` |
| **AIR-005** | `anka-api/app/Services/Ai/AiTeamPlanValidator.php`, `AiAutoAssignController::validate` |
| **AIR-006** | `anka-api/app/Http/Controllers/Api/AiAutoAssignController.php` (snapDatesToWorkingDays, resolveAssigneeOverlaps, enforcePhaseOrderWithinRows, clampDurationOutliers, fillMissingAssignments) |
| **AIR-007** | `anka-api/app/Services/ContractDraftService.php::generateDraft` |
| **AIR-008** | `anka-api/database/migrations/2026_05_15_000005_create_deal_contract_drafts_table.php` (`ai_outputs` + `sections` separate JSON cols) |
| **AIR-009** | `ContractDraftService::generateDraft` (version+superseded logic) |
| **AIR-010** | `anka-frontend/app/api/ai-chatbot/route.ts` (system prompt) |
| **AIR-011** | `anka-frontend/lib/knowledgeBase.ts` + chatbot route |
| **AIR-012** | `anka-frontend/app/api/ai-forecast/route.ts` (`buildSystemPrompt` + `clampResult` sanitization) |
| **AIR-013** | Schema in `anka-frontend/app/api/ai-forecast/route.ts` (`AIForecastResult`) |
| **AIR-014** | `previousSummary.priorAlertTargets` field + temperature 0.7 on regenerate |
| **AIR-015** | `anka-api/app/Services/EstimationAiService.php` + `EstimationVersionController` |
| **AIR-016** | Each AI route + service calls Laravel `/api/ai-usage` POST |
| **AIR-017** | `anka-api/app/Models/AiUsageLog.php` uses `BelongsToTenant` |
| **AIR-018** | `anka-api/app/Http/Controllers/Api/AiUsageController::adminIndex` |
| **AIR-019** | Cost calc in each AI route (rate table per model) |
| **AIR-020** | Error handling at top of each AI route/service |
| **AIR-021** | Global scope on every model + AI service code review |
| **AIR-022** | `extractFirstJsonObject` + fence stripper in each Next.js AI route |
| **AIR-023** | System prompts in each AI service file |
| **AIR-024** | `throttle:60,1` middleware in `routes/api.php` (route groups) |
| **AIR-025** | `ANKA_DEMO_MODE` branch in `app/api/ai-team-builder/route.ts` |
| **AIR-026** | `CLAUDE_MODEL` constants in each entry-point file |
| **AIR-027** | UI components: `ForecastPage`, `EstimationRoleBuilder`, `ContractDraftWizard`, `ChatBot` |
| **AIR-028** | `super_admin` middleware + tenant scope on `AiUsageController` |
| **AIR-029** | `extractFirstJsonObject` + `clampResult` (`app/api/ai-forecast/route.ts`); `AiTeamPlanValidator.php`; 10-section completeness check in `ContractDraftService::generateDraft` |
| **AIR-030** | `anka-api/app/Services/Ai/AiTeamPlanValidator.php` + 5 self-correction passes in `AiAutoAssignController` |
| **AIR-031** | Internal probe set (manual); harness planned via PLN-AIR-007 |
| **AIR-032** | `clampResult` in `anka-frontend/app/api/ai-forecast/route.ts` |
| **FR-001…005** | `AdminTenantController`, `UserController`, `AuthController` |
| **FR-010…016** | `DepartmentController`, `RoleController`, `SkillController`, `EmployeeController`, `EmployeeSkillController`, `HolidayController` |
| **FR-020…026** | `DealController` (lifecycle promotions in `update` method) |
| **FR-030…036** | `EstimationVersionController`, `EstimationXlsxService`, `EstimationSimulator.tsx::downloadVersion`, `EstimateApprovedEmail` |
| **FR-040…045** | `ContractDraftService`, `DealContractDraftController`, `ContractDraftEmail` (FR-042) |
| **FR-050…056** | `win_deal()` PG proc + `ContractDraftService::fireWinDeal`, `MilestoneController::accept` |
| **FR-060…063** | `TimeEntryController` + `Project::computeAutoStatus` |
| **FR-070…074** | `InvoiceController::store + pay + send`, `InvoiceIssued` mailer |
| **FR-080…086** | `Dashboard page projectProfitRows`, `Financial page businessStore.getFinancialPnL` + `handleCsvExport`, `Forecast page chartData` + AI Forecast route |
| **FR-090…092** | `app/Mail/WelcomeUser.php`, `UserController::store`, every `Mail/*` class `implements ShouldQueue`, `tenant` middleware group in `routes/api.php` |
| **SEC-015…018** | `app/Mail/*`, `routes/api.php` (send routes), `.env`, `Financial page::handleCsvExport`, `EstimationXlsxService` |
| **SEC-001** | `app/Http/Controllers/Api/AuthController` + Sanctum config |
| **SEC-002** | `lib/rbac.ts` + per-route permission checks |
| **SEC-003** | `BelongsToTenant` trait + `TenantScope` middleware |
| **SEC-008** | Prompt builders in each AI entry point |
| **SEC-014** | All AI services use Eloquent queries that inherit the global scope |

---

## 12. Planned / not yet implemented

These items appear in the pitch deck or roadmap but are NOT live in code. AI reviewer note: overclaiming AI is worse than underclaiming. Items below are explicitly tagged as planned to avoid that.

| ID | Title | Status | Why not implemented |
|---|---|---|---|
| **PLN-AIR-001** | AI Auto-Assign deterministic fallback | Planned | When Claude is down, AI Auto-Assign currently 503s. Drag-and-drop manual flow exists but not full parity. |
| **PLN-AIR-002** | Per-tenant custom AI prompts / templates | Planned | All Claude prompts are hard-coded. Per-tenant prompt customisation would let agencies tune the voice + sensitivity of alerts. |
| **PLN-AIR-003** | Tool calling for ANKA Assistant | Not started | Would let the chatbot read DB rows to answer "what's my margin today?" — requires careful tenant gating + read-only role. |
| **PLN-AIR-004** | Conversation persistence for ANKA Assistant | Planned | Currently in-memory. Persistence improves review surface but expands PII surface. |
| **PLN-AIR-005** | AI cost & failure-rate alerting **[AI]** | Partial | OR-005 / OR-006 are roadmap. `/admin/ai-usage` shows totals; no automated alerts. |
| **PLN-AIR-006** | Per-call AI prompt/response retention for replay | Not started | Currently only token counts + cost are logged. Storing prompts/responses would enable replay/audit but increases storage + privacy considerations. |
| **PLN-AIR-007** | Automated probe-question test harness for ANKA Assistant | Planned | Codify the manual refusal probe set (AIR-031) as a CI-runnable regression suite. Today: manual before-release run. |
| **PLN-AIR-008** | Golden-example regression for AI Team Builder | Planned | 3 representative scope inputs with reviewer-rated expected team shapes. Detect prompt-drift when system prompt is edited. |
| **PLN-AIR-009** | Forecast alert calibration scoring | Planned | Periodic independent review (senior PM) of whether AI Forecast alerts match private read of project health. |
| **PLN-AIR-010** | AI output snapshot review queue | Not started | Sampled responses surfaced to admin for periodic review. Would expand storage + privacy surface (see PLN-AIR-006). |
| **PLN-UR-001** | Korean / Burmese / Khmer UI translations | Planned | Spec deck mentions JP/EN/VI/KO/MY/KM. Today: en / ja / vi only. |
| **PLN-FR-001** | Multi-currency contracts within a single tenant | Planned | Currency is tenant-level; contracts inherit tenant currency. |
| **PLN-FR-002** | Customer-facing portal | Not started | Customers see ANKA outputs only via emailed PDFs. |
| **PLN-FR-003** | Cryptographic signature verification on signed PDFs | Stubbed | `SignedContractVerifier.php` does presence heuristic; CMS/PDF-signature verification roadmap. |
| **PLN-FR-004** | Payroll execution integration | Not started | ANKA tracks salaries + time but doesn't run payroll. |
| **PLN-FR-005** | Accounting GL integration | Not started | Revenue + cash collected are tracked; no export to QuickBooks / freee / etc. |
| **PLN-OR-001** | AI cost spike alerting | Planned | Roadmap. |
| **PLN-OR-002** | S3-backed PDF storage | Planned | Currently filesystem on the app server. |
