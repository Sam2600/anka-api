# anka-api — Backend Architecture Overview

## Modules

| Module | Controller | Purpose |
|---|---|---|
| Auth | `AuthController` | Login, logout, me, profile, password, token refresh |
| CRM | `DealController` | Deal pipeline + estimation data |
| Contracts & Billing | `ContractController`, `InvoiceController`, `MilestoneController` | Contract lifecycle, invoices, milestones |
| Projects & Delivery | `ProjectController`, `TimeEntryController` | Project tracking, time logging, approval |
| Organization | `OrganizationController` | Departments, roles, employees, overheads, company settings |
| Tenant Admin | `TenantController` | Tenant CRUD (super-admin only) + user management |
| AI Usage | `AiUsageController` | Logs AI API calls per tenant |

---

## Models and Key Fields

### `Deal`
| Field | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `tenant_id` | UUID FK | tenants |
| `name` | varchar(255) | |
| `client` | varchar(255) | |
| `contact_name` | varchar(255) | |
| `contact_email` | varchar(255) | |
| `contact_phone` | varchar(50) | |
| `estimated_value` | decimal(14,2) | |
| `win_probability` | smallint 0–100 | |
| `status` | enum | lead, inquiry, opportunity, proposal, contract, won, lost |
| `expected_close_date` | date | |
| `lead_source` | enum | inbound, referral, cold_outreach, social, event, partner, other |
| `client_budget` | decimal(14,2) | |
| `timeline_months` | integer | |
| `workload_hours` | decimal(10,2) | |
| `workload_description` | text | |
| `target_margin` | decimal(5,2) | |
| `base_labor_cost` | decimal(14,2) | |
| `overhead_cost` | decimal(14,2) | |
| `buffer_cost` | decimal(14,2) | |
| `total_estimated_cost` | decimal(14,2) | |
| `estimated_gross_profit` | decimal(14,2) | |
| `won_at` | timestamp | set by win_deal() SP |
| `lost_at` | timestamp | set by lose() action |
| `win_reason` | varchar(500) | |
| `loss_reason` | varchar(500) | |
| Soft delete | `deleted_at` | |

### `EstimationResource`
| Field | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `tenant_id` | UUID FK | |
| `deal_id` | UUID FK → deals | CASCADE delete |
| `job_role_id` | UUID FK → roles | nullable, SET NULL |
| `role_id` | text | alternate string identifier |
| `feature_name` | varchar(255) | |
| `hours` | decimal(10,2) | |

### `DealGhostRole`
| Field | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `tenant_id` | UUID FK | |
| `deal_id` | UUID FK → deals | CASCADE delete |
| `role_type` | enum | frontend, backend, pm, qa, design |
| `quantity` | integer | |
| `months` | integer | |
| `avg_monthly_salary` | decimal(14,2) | |

### `DealHardAssignment`
| Field | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `tenant_id` | UUID FK | |
| `deal_id` | UUID FK → deals | CASCADE delete |
| `employee_id` | UUID FK → employees | CASCADE delete |
| `allocated_hours` | decimal(10,2) | |
| Unique | | `(deal_id, employee_id)` |

### `DealOverhead`
| Field | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `tenant_id` | UUID FK | |
| `deal_id` | UUID FK → deals | CASCADE delete |
| `name` | varchar(255) | |
| `cost` | decimal(14,2) | |

### `Contract`
| Field | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `tenant_id` | UUID FK | |
| `deal_id` | UUID FK → deals | SET NULL on deal delete |
| `contract_number` | varchar(255) UNIQUE | auto: CON-0001 via sequence |
| `client` | varchar(255) | |
| `total_value` | decimal(14,2) | |
| `revenue_recognized` | decimal(14,2) | incremented on invoice payment |
| `status` | enum | Draft, Active, Completed, Cancelled |
| `start_date` | date | |
| `end_date` | date | |
| `notes` | text | |
| Soft delete | `deleted_at` | |

### `Milestone`
| Field | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `tenant_id` | UUID FK | |
| `contract_id` | UUID FK → contracts | |
| `name` | varchar(255) | |
| `due_date` | date | |
| `amount` | decimal(14,2) | |
| `status` | enum | Pending, In Progress, Completed |
| `completed_at` | timestamp | |

### `Invoice`
| Field | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `tenant_id` | UUID FK | |
| `contract_id` | UUID FK → contracts | RESTRICT delete |
| `milestone_id` | UUID FK → milestones | SET NULL |
| `invoice_number` | varchar(255) UNIQUE | auto: INV-1042 via sequence |
| `issue_date` | date | |
| `due_date` | date | nullable |
| `amount` | decimal(14,2) | |
| `tax` | decimal(14,2) | |
| `total` | decimal(14,2) **GENERATED** | amount + tax — never set from PHP |
| `status` | enum | Draft, Pending, Paid, Overdue, Cancelled |
| `paid_at` | timestamp | |
| `notes` | text | |
| Soft delete | `deleted_at` | |

### `Project`
| Field | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `tenant_id` | UUID FK | |
| `contract_id` | UUID FK → contracts | UNIQUE — one project per contract |
| `project_number` | varchar(255) UNIQUE | auto: PRJ-101 via sequence |
| `name` | varchar(255) | |
| `client` | varchar(255) | |
| `budget_hours` | decimal(10,2) | |
| `consumed_hours` | decimal(10,2) | incremented on time entry approval |
| `status` | enum | Not Started, On Track, At Risk, Over Budget, Completed |
| `start_date` | date | |
| `end_date` | date | |
| Soft delete | `deleted_at` | |

### `TimeEntry`
| Field | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `tenant_id` | UUID FK | |
| `project_id` | UUID FK → projects | RESTRICT delete |
| `employee_id` | UUID FK → employees | RESTRICT delete |
| `approved_by` | UUID FK → users | SET NULL |
| `task` | varchar(500) | |
| `date` | date | |
| `hours` | decimal(6,2) | min 0.5, max 24 |
| `billable` | boolean | default true |
| `status` | enum | Draft, Pending, Approved, Rejected |
| `notes` | text | |
| `approved_at` | timestamp | |

### `Employee`
| Field | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `tenant_id` | UUID FK | |
| `department_id` | UUID FK → departments | |
| `job_role_id` | UUID FK → roles | |
| `name` | varchar(255) | |
| `role` | text | FK UUID stored as text (legacy) |
| `role_name` | varchar(255) | denormalized display name |
| `capacity_role` | enum | frontend, backend, pm, qa, design, etc. |
| `monthly_salary` | decimal(14,2) | |
| `workable_hours` | integer | hours per month |
| `cost_per_hour` | decimal(10,4) **GENERATED** | monthly_salary / workable_hours |
| `status` | enum | Active, On Leave, Terminated |
| Soft delete | `deleted_at` | |

---

## Model Relationships

```
Tenant
  └── hasMany: User, Deal, Contract, Project, Employee, Invoice, TimeEntry, ...

Deal
  ├── hasOne:  Contract  (via deal_id)
  ├── hasMany: EstimationResource  (CASCADE)
  ├── hasMany: DealGhostRole       (CASCADE)
  ├── hasMany: DealHardAssignment  (CASCADE)
  └── hasMany: DealOverhead        (CASCADE)

Contract
  ├── belongsTo: Deal
  ├── hasOne:    Project    (UNIQUE FK)
  ├── hasMany:   Milestone
  └── hasMany:   Invoice

Project
  ├── belongsTo: Contract
  └── hasMany:   TimeEntry

Invoice
  ├── belongsTo: Contract
  └── belongsTo: Milestone (nullable)

Milestone
  ├── belongsTo: Contract
  └── hasMany:   Invoice

TimeEntry
  ├── belongsTo: Project
  ├── belongsTo: Employee
  └── belongsTo: User (approved_by)

Employee
  ├── belongsTo: Department
  ├── belongsTo: Role (job_role_id)
  ├── hasOne:    User
  ├── hasMany:   TimeEntry
  └── hasMany:   DealHardAssignment
```

---

## API Route Groups

### Group 1 — Auth (no tenant required)
`middleware: auth:sanctum, throttle:60,1`
```
POST   /api/auth/login         (throttle: 5/min)
DELETE /api/auth/logout
GET    /api/auth/me
PUT    /api/auth/profile
POST   /api/auth/password
POST   /api/auth/refresh
```

### Group 2 — Super Admin
`middleware: auth:sanctum, super_admin, throttle:60,1`, prefix: `/api/admin`
```
GET    /admin/tenants
POST   /admin/tenants
GET    /admin/tenants/{id}
PUT    /admin/tenants/{id}
DELETE /admin/tenants/{id}
GET    /admin/tenants/{tenantId}/users
POST   /admin/tenants/{tenantId}/users
PUT    /admin/tenants/{tenantId}/users/{userId}
DELETE /admin/tenants/{tenantId}/users/{userId}
GET    /admin/ai-usage
```

### Group 3 — Business Data (tenant-scoped)
`middleware: auth:sanctum, tenant, throttle:60,1`
```
# Deals
GET    /api/deals
POST   /api/deals
GET    /api/deals/{deal}
PUT    /api/deals/{deal}
DELETE /api/deals/{deal}
PATCH  /api/deals/{deal}/stage
POST   /api/deals/{deal}/win
POST   /api/deals/{deal}/lose
GET    /api/deals/{deal}/contract

# Contracts
GET    /api/contracts
GET    /api/contracts/{contract}
PATCH  /api/contracts/{contract}
DELETE /api/contracts/{contract}
GET    /api/contracts/{contract}/project

# Invoices
GET    /api/invoices
POST   /api/invoices
GET    /api/invoices/{invoice}
DELETE /api/invoices/{invoice}
PATCH  /api/invoices/{invoice}/pay

# Projects
GET    /api/projects
GET    /api/projects/{project}
PATCH  /api/projects/{project}
DELETE /api/projects/{project}

# Time Entries
GET    /api/time-entries
POST   /api/time-entries
GET    /api/time-entries/{time_entry}
DELETE /api/time-entries/{time_entry}
PATCH  /api/time-entries/{time_entry}/approve

# Milestones
GET    /api/milestones
POST   /api/milestones
GET    /api/milestones/{milestone}
PUT    /api/milestones/{milestone}
DELETE /api/milestones/{milestone}

# Organization
GET/POST/PUT/DELETE  /api/departments/{department}
GET/POST/PUT/DELETE  /api/roles/{role}
GET/POST/PUT/DELETE  /api/employees/{employee}
GET/POST/PUT/DELETE  /api/global-overheads/{globalOverhead}
GET/PUT              /api/company-settings
GET/PUT              /api/tenant

# AI
POST   /api/ai-usage
```

---

## Database Tables

| Table | Purpose |
|---|---|
| `tenants` | Multi-tenant root — each org is a tenant |
| `users` | Login accounts; optionally linked to an employee |
| `personal_access_tokens` | Sanctum tokens |
| `departments` | Org departments |
| `roles` | Billable job roles with rates |
| `employees` | Org team members |
| `global_overheads` | Monthly fixed costs (with optional period) |
| `company_settings` | Per-tenant config (overhead %, buffer %, etc.) |
| `deals` | CRM deals (also holds estimation data) |
| `estimation_resources` | Line-item scope rows per deal |
| `deal_ghost_roles` | Estimated team composition per deal |
| `deal_hard_assignments` | Actual employee allocations per deal |
| `deal_overheads` | Deal-specific overhead items |
| `contracts` | Auto-created by win_deal() |
| `milestones` | Billing milestones per contract |
| `invoices` | Invoices per contract (optionally per milestone) |
| `projects` | Auto-created by win_deal(), 1-to-1 with contract |
| `time_entries` | Hours logged per project |
| `ai_usage_logs` | Tracks AI API calls per tenant |
| `jobs` | Laravel queue jobs table (no job classes yet) |
| `failed_jobs` | Failed queue jobs |

---

## Queues, Jobs, Events

- **`jobs` and `failed_jobs` tables** exist from migrations but **no Job classes** exist in `app/Jobs/`.
- **`WelcomeUser` mail** (`app/Mail/WelcomeUser.php`) is queued when a user is created via super-admin endpoint. This is the only async operation.
- No Events, Listeners, or Observers implemented.

---

## Third-Party Integrations

| Integration | Library | Used For |
|---|---|---|
| Email | `symfony/mailgun-mailer` | Welcome email on user creation |
| Auth | `laravel/sanctum` | Stateless API token auth |
| Supabase | via standard PostgreSQL driver | Production database host |
| Google Gemini | (frontend only) | AI team composition suggestions |
| Anthropic SDK | (frontend only) | AI features |

---

## PostgreSQL-Specific Features

| Feature | Details |
|---|---|
| `win_deal(p_deal_id, p_tenant_id)` | Stored procedure — atomically creates Contract + Project |
| `contract_number_seq` | Generates CON-0001, CON-0002, … |
| `invoice_number_seq` | Generates INV-1042, INV-1043, … (starts at 1042) |
| `project_number_seq` | Generates PRJ-101, PRJ-102, … (starts at 101) |
| `invoices.total` | GENERATED ALWAYS AS (amount + tax) STORED |
| `employees.cost_per_hour` | GENERATED ALWAYS AS (monthly_salary / workable_hours) STORED |
