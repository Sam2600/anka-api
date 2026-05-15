# anka-api — Estimation Module Spec

## Architecture Note

⚠️ **There is no separate `Estimation` model or `estimations` table.**

Estimation data is embedded directly in the `Deal` record and its child tables. The "Estimation Engine" in the frontend is a calculator view of Deal data, not a separate database entity.

---

## Estimation Data Structure (embedded in Deal)

### Deal fields used for estimation

| Field | Type | Purpose |
|---|---|---|
| `client_budget` | decimal(14,2) | What the client is willing to pay |
| `timeline_months` | integer | Expected project duration |
| `workload_hours` | decimal(10,2) | Total estimated hours |
| `workload_description` | text | Scope description |
| `target_margin` | decimal(5,2) | Desired profit margin % |
| `base_labor_cost` | decimal(14,2) | Sum of ghost role salary costs |
| `overhead_cost` | decimal(14,2) | Overhead % of base labor |
| `buffer_cost` | decimal(14,2) | Risk buffer % of base labor |
| `total_estimated_cost` | decimal(14,2) | base + overhead + buffer |
| `estimated_gross_profit` | decimal(14,2) | client_budget - total_estimated_cost |

### `estimation_resources` table — line item scope

Each row is one feature/task with a role and hour estimate.

| Field | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `tenant_id` | UUID FK | |
| `deal_id` | UUID FK → deals | CASCADE delete |
| `job_role_id` | UUID FK → roles | nullable, SET NULL |
| `role_id` | text | alternate string identifier |
| `feature_name` | varchar(255) | e.g. "User Authentication" |
| `hours` | decimal(10,2) | estimated hours for this feature |

### `deal_ghost_roles` table — team composition estimate

| Field | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `deal_id` | UUID FK → deals | CASCADE delete |
| `role_type` | enum | frontend, backend, pm, qa, design |
| `quantity` | integer | number of people |
| `months` | integer | engagement duration |
| `avg_monthly_salary` | decimal(14,2) | average monthly cost per person |

### `deal_overheads` table — deal-specific cost items

| Field | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `deal_id` | UUID FK → deals | CASCADE delete |
| `name` | varchar(255) | e.g. "Cloud hosting", "Software licenses" |
| `cost` | decimal(14,2) | one-time or total cost |

---

## Estimation Status Values

⚠️ **NOT IMPLEMENTED** — There is no estimation status (draft, sent, approved, rejected). The only relevant status is the parent **Deal status**.

When a Deal is `won`, it is treated as "estimation complete/approved" — the `win_deal()` SP is then called to create the Contract.

---

## API Endpoints for Estimation Data

Estimation data is accessed and mutated through the **Deal endpoints** — there are no `/estimation*` routes.

### Reading estimation data
```
GET /api/deals/{deal}
```
Returns the full DealResource including:
- `estimation_resources` array
- `ghost_roles` array
- `deal_overheads` array
- All calculated fields: `base_labor_cost`, `overhead_cost`, `buffer_cost`, `total_estimated_cost`, `estimated_gross_profit`

### Updating estimation data
```
PUT /api/deals/{deal}
```
Send any combination of:
```json
{
  "target_margin": 30,
  "base_labor_cost": 50000,
  "overhead_cost": 5000,
  "buffer_cost": 2500,
  "total_estimated_cost": 57500,
  "estimated_gross_profit": 42500,
  "estimation_resources": [
    { "feature_name": "Login", "role_id": "uuid", "hours": 40 }
  ],
  "ghost_roles": [
    { "role_type": "backend", "quantity": 2, "months": 3, "avg_monthly_salary": 5000 }
  ],
  "deal_overheads": [
    { "name": "Hosting", "cost": 500 }
  ]
}
```
Child arrays use **replace-all semantics** — all existing rows are deleted and replaced.

---

## Links to Adjacent Modules

| Link | Status | Detail |
|---|---|---|
| Deal (upstream) | ✅ EXISTS | Estimation IS the Deal — `deal_id` is the primary key concept |
| Contract (downstream) | ✅ EXISTS | Triggered by `POST /deals/{deal}/win` → `win_deal()` SP |
| Separate Estimation → Contract link | ⚠️ NOT IMPLEMENTED | No `estimation_id` column on contracts; no approval workflow |

---

## Business Rules

1. Estimation data is optional — a Deal can be won without any estimation resources.
2. Child rows are always replaced as a complete set — partial updates to individual rows are not supported.
3. Calculated fields (`base_labor_cost`, `overhead_cost`, etc.) are stored in the `deals` table, not computed on the fly. The frontend sends them; the backend stores them.
4. `job_role_id` on `estimation_resources` is nullable — a feature can be estimated without a specific role.
5. The `win_deal()` SP does NOT copy estimation data into the contract — it carries `client` and `total_value` (from the Deal's `estimated_value` or `client_budget`) into the new Contract record.

---

## AI Generation (chg-007)

A Claude-powered draft endpoint produces a per-sheet JSON payload from the Deal context. **AI output is preview-only — it does NOT persist** to `estimation_versions`, `estimation_resources`, or `deal_overheads`. The frontend loads the draft into the Estimation Simulator's editable state; the user reviews/adjusts and clicks Save, which uses the normal `POST /deals/{deal}/estimation-versions` path.

### Endpoint
```
POST /api/deals/{deal}/estimation-versions/ai-draft
```
- Middleware: `auth:sanctum`, `tenant`, `permission:manage_crm`
- Validates the deal has `workload_description` OR at least one `deal_contract_documents` row; otherwise 422.
- Returns 200 with the per-sheet JSON in `data`, 503 on AI failure, 404 on cross-tenant deal lookup.

### Service: `app/Services/EstimationAiService.php`
- Model: Claude `claude-3-5-sonnet-latest` (same provider/key as `ContractAnalysisService` from chg-005).
- Prompt template lives in `resources/prompts/estimation_generation.txt` — editable without redeploy.
- Logs every call (success + failure) to `ai_usage_logs` with `feature='estimation_generation'`.

### Prompt inputs
| Input | Source | Notes |
|---|---|---|
| Deal fields | `deals` row | `workload_description`, `client_budget`, `timeline_months`, `client`, `target_margin`, `expected_close_date` |
| Contract document text | `deal_contract_documents.extracted_text` (chg-005) | Joined for `analysis_status IN ('approved','pending')`. Truncated to ~50k chars. |
| Org roles + rates | `roles` (tenant-scoped) | Tells Claude real role names with real costs |
| Few-shot won deals | `deals.status='won'`, latest 2–3 by `updated_at` | Includes each one's latest `EstimationVersion.resources`. Skipped if tenant has <2 won deals. |

### Output schema (Claude must echo verbatim)
```json
{
  "sheet1_summary": {
    "rough_estimate_hours": 0, "requirement_study_hours": 0,
    "web_development_hours": 0, "environment_setup_hours": 0,
    "total_hours_per_person": 0, "total_days_per_person": 0,
    "total_months_per_person": 0
  },
  "sheet2_features": [
    { "function_id": "F001", "name": "...", "explanation": "...", "category": "Web" }
  ],
  "sheet3_manhours": [
    { "function_id": "F001", "dev_hours": 16 }
  ],
  "sheet4_milestone": {
    "start_month": "YYYY-MM", "total_months": 0,
    "phase_durations": { /* per-phase month counts */ }
  },
  "sheet5_team_stack": [
    { "role": "Backend Developer", "count": 2, "monthly_allocation": [1.0, 1.0, 0.5] }
  ],
  "reasoning": "...", "confidence": "high"
}
```

### Validation rules
- `sheet2_features[*].function_id` and `sheet3_manhours[*].function_id` must match 1:1; mismatched response → 422 with retry-once preamble before failing.
- `sheet5_team_stack[*].monthly_allocation.length` should equal `sheet4_milestone.total_months` (clamped/padded if off-by-one; rejected if >2x off).
- AI never proposes phase multipliers — those stay as Excel formulas in the chg-006 XLSX writer. AI only proposes `dev_hours` per feature in sheet3.

### Why preview-only
Versions in `estimation_versions` are immutable snapshots tied to the chg-006 XLSX export. Auto-saving every AI draft would pollute version history. The user explicitly saves the version they want, which then triggers the existing XLSX generation pipeline.

---

## What is Missing (Not Implemented)

- No separate `estimations` table with its own lifecycle.
- No estimation status: draft → sent → approved → rejected.
- No `estimation_id` FK on contracts.
- No `POST /estimations` endpoint.
- No client-facing estimation document (PDF, shareable link, approval workflow).
- No "Estimation Approved" trigger that creates a Contract — Contract creation is tied to Deal win status only.
- AI prompt opt-out per tenant (`tenants.ai_estimation_enabled`) — proposed in chg-007 risk section, not implemented in v1.
- Semantic few-shot retrieval (embeddings-based "most similar past deal") — current implementation uses recency.
