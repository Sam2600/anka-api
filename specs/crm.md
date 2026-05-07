# anka-api — CRM Module Spec

## Models

### `Deal` — primary CRM record
File: `app/Models/Deal.php`

**All fields:**
```
id                    UUID PK
tenant_id             UUID FK → tenants
name                  varchar(255)  required
client                varchar(255)  required
contact_name          varchar(255)  required
contact_email         varchar(255)  required, valid email
contact_phone         varchar(50)   required
estimated_value       decimal(14,2) nullable
win_probability       smallint      0–100, default 50
status                enum          lead | inquiry | opportunity | proposal | contract | won | lost
                                    DEFAULT: inquiry
expected_close_date   date          nullable
lead_source           enum          inbound | referral | cold_outreach | social | event | partner | other
client_budget         decimal(14,2) nullable
timeline_months       integer       min:1, nullable
workload_hours        decimal(10,2) nullable
workload_description  text          nullable
target_margin         decimal(5,2)  nullable
base_labor_cost       decimal(14,2) nullable
overhead_cost         decimal(14,2) nullable
buffer_cost           decimal(14,2) nullable
total_estimated_cost  decimal(14,2) nullable
estimated_gross_profit decimal(14,2) nullable
won_at                timestamp     set by win_deal() SP
lost_at               timestamp     set by lose() action
win_reason            varchar(500)  nullable
loss_reason           varchar(500)  nullable
created_at, updated_at, deleted_at
```

**Relationships:**
```
hasOne:  Contract      (FK: contracts.deal_id)
hasMany: EstimationResource  (CASCADE delete)
hasMany: DealGhostRole       (CASCADE delete)
hasMany: DealHardAssignment  (CASCADE delete)
hasMany: DealOverhead        (CASCADE delete)
```

### Supporting child models (all scoped to `deal_id`)

**`EstimationResource`** — scope/feature rows with hour estimates
```
deal_id, job_role_id (FK → roles, SET NULL), role_id (text), feature_name, hours
```

**`DealGhostRole`** — estimated team headcount
```
deal_id, role_type (frontend|backend|pm|qa|design), quantity, months, avg_monthly_salary
```

**`DealHardAssignment`** — actual employee allocations
```
deal_id, employee_id (FK → employees), allocated_hours
UNIQUE: (deal_id, employee_id)
```

**`DealOverhead`** — deal-specific cost items
```
deal_id, name, cost
```

---

## Deal Status Values and Transitions

```
lead → inquiry → opportunity → proposal → contract → won
                                                   ↘ lost
```
Any active status can also transition to `lost`.

**Default probability per stage (enforced by `updateStage`):**
| Status | Probability |
|---|---|
| lead | 10% |
| inquiry | 20% |
| opportunity | 40% |
| proposal | 60% |
| contract | 80% |
| won | 100% |
| lost | 0% |

**Terminal statuses:** `won` and `lost` cannot be re-opened. Both `win()` and `lose()` check:
```php
abort_if(in_array($deal->status, ['won', 'lost']), 409, 'This deal is already closed.');
```

---

## Business Rules

- `name`, `client`, `contact_name`, `contact_email`, `contact_phone` are **required** on create.
- Child records (ghost_roles, estimation_resources, hard_assignments, deal_overheads) are **replace-all**: the entire set is deleted and re-inserted on every update. Never PATCH individual child rows.
- `won_at` and `lost_at` are set by the server — never accepted from request payload.
- `win_reason` is optional on win; `loss_reason` is required on lose.
- Soft-deleted deals are excluded from all queries via global BelongsToTenant scope.

---

## API Endpoints

### `GET /api/deals`
- Filter: `status`, `search` (ILIKE on name and client)
- Pagination: `per_page` (default 100, max 500)
- Eager-loads: ghost_roles, hard_assignments, estimation_resources, deal_overheads
- Response: paginated `DealResource` collection

### `POST /api/deals`
- Required: `name`, `client`, `contact_name`, `contact_email`, `contact_phone`
- Optional: all other Deal fields + arrays for child models
- Child models created in same transaction via `replaceDealChildren()`
- Response: `DealResource` with relations

### `GET /api/deals/{deal}`
- Response: `DealResource` with all relations eager-loaded

### `PUT /api/deals/{deal}`
- Same validation as store (all `sometimes`)
- Child arrays replace existing rows atomically
- Response: `DealResource` with relations

### `DELETE /api/deals/{deal}`
- Soft delete
- Response: 204

### `PATCH /api/deals/{deal}/stage`
- Required: `status` (enum), `win_probability` (int 0–100)
- Response: `DealResource`

### `POST /api/deals/{deal}/win`
- Guard: 409 if status already won or lost
- Optional: `win_reason` (varchar 500)
- Calls: `DB::select('SELECT win_deal(?, ?)', [$deal->id, app('tenant_id')])`
- Side effects: creates Contract + Project atomically, sets `won_at = now()`
- Response: **flat JSON** (no data wrapper): `{ deal, contract, project }`

### `POST /api/deals/{deal}/lose`
- Guard: 409 if status already won or lost
- Required: `loss_reason` (varchar 500)
- Sets: `status='lost'`, `lost_at=now()`, `win_probability=0`
- Response: `DealResource`

### `GET /api/deals/{deal}/contract`
- Returns linked contract (or `{ data: null }` if none)
- Response: `ContractResource` or `{ data: null }`

---

## `DealResource` Response Shape

```json
{
  "id": "uuid",
  "name": "string",
  "client": "string",
  "contact_name": "string",
  "contact_email": "string",
  "contact_phone": "string",
  "estimated_value": 0.0,
  "win_probability": 50,
  "status": "inquiry",
  "expected_close_date": "YYYY-MM-DD",
  "lead_source": "inbound",
  "client_budget": 0.0,
  "timeline_months": 3,
  "workload_hours": 0.0,
  "workload_description": "string",
  "target_margin": 30.0,
  "base_labor_cost": 0.0,
  "overhead_cost": 0.0,
  "buffer_cost": 0.0,
  "total_estimated_cost": 0.0,
  "estimated_gross_profit": 0.0,
  "win_reason": null,
  "loss_reason": null,
  "ghost_roles": [
    { "id": "uuid", "role_type": "backend", "quantity": 2, "months": 3, "avg_monthly_salary": 5000 }
  ],
  "hard_assignments": [
    { "employee_id": "uuid", "allocated_hours": 160 }
  ],
  "estimation_resources": [
    { "id": "uuid", "feature_name": "Login", "role_id": "uuid", "hours": 40 }
  ],
  "deal_overheads": [
    { "id": "uuid", "name": "Cloud hosting", "cost": 500 }
  ]
}
```

---

## Relationships to Other Modules

| Link | Status | How |
|---|---|---|
| Deal → Contract | ✅ EXISTS | `contracts.deal_id` FK; created by `win_deal()` SP |
| Deal → Project | ✅ EXISTS (indirect) | via Contract → Project chain |
| Deal → Estimation | ✅ EXISTS (embedded) | `estimation_resources`, `deal_ghost_roles`, `deal_overheads` on the Deal itself |
| Deal → Estimation (separate entity) | ⚠️ NOT IMPLEMENTED | No separate `estimations` table; estimation is embedded in Deal |

---

## Current State

- All CRUD and win/lose actions are **fully implemented**.
- `contact_name`, `contact_email`, `contact_phone` added in migration `2026_05_06_000001_add_crm_fields_to_deals_table.php`.
- `workload_description` field exists in model and resource but is not shown in the current deal detail page.
