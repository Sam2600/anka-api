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

## What is Missing (Not Implemented)

- No separate `estimations` table with its own lifecycle.
- No estimation status: draft → sent → approved → rejected.
- No `estimation_id` FK on contracts.
- No `POST /estimations` endpoint.
- No client-facing estimation document (PDF, shareable link, approval workflow).
- No "Estimation Approved" trigger that creates a Contract — Contract creation is tied to Deal win status only.
