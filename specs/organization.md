# anka-api — Organization Module Spec

## Overview

`OrganizationController` handles five entity types: Departments, Roles, Employees, Global Overheads, and Company Settings. All routes are tenant-scoped (`auth:sanctum`, `tenant` middleware).

---

## Department Model

**Table:** `departments`

| Field | Type | Notes |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK, auto-injected by `BelongsToTenant` |
| `name` | string | max 255 |
| `manager` | string | nullable; denormalized manager name |
| `manager_id` | UUID | nullable FK → employees |
| `headcount` | integer | employee count; defaults 0 |

**Relationships:** `belongsTo Employee` (manager), `hasMany Employee`

---

## Role Model

**Table:** `roles`

| Field | Type | Notes |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK, auto-injected |
| `title` | string | max 255 |
| `department` | string | denormalized department name |
| `department_id` | UUID | nullable FK → departments |
| `rate` | decimal | billable hourly rate |

Used in Estimation: `rate × 0.5` = internal cost rate for ghost roles.

---

## Employee Model

**Table:** `employees`

| Field | Type | Notes |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK, auto-injected |
| `name` | string | max 255 |
| `role` | string | role ID (FK as string) |
| `role_name` | string | nullable; denormalized title |
| `department_id` | UUID | nullable FK → departments |
| `job_role_id` | UUID | nullable FK → roles |
| `capacity_role` | enum | `frontend`, `backend`, `pm`, `qa`, `design`; used in capacity pool |
| `monthly_salary` | decimal | |
| `workable_hours` | integer | 1–744 |
| `cost_per_hour` | decimal | **GENERATED ALWAYS** = `monthly_salary / workable_hours`; never set from PHP |
| `status` | enum | `Active`, `On Leave`, `Terminated` |

⚠️ `cost_per_hour` is a PostgreSQL generated column. Never attempt to set it from PHP — Eloquent ignores writes to generated columns. Always reload via `$employee->fresh()` after save to get the computed value.

---

## GlobalOverhead Model

**Table:** `global_overheads`

| Field | Type | Notes |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK, auto-injected |
| `category` | string | max 255 |
| `description` | string | max 500 |
| `monthly_cost` | decimal | |
| `effective_month` | integer | nullable; 1–12 |
| `effective_year` | integer | nullable; min 2000 |

Used in `getFinancialPnL()` on the frontend to compute monthly overhead costs.

---

## CompanySetting Model

**Table:** `company_settings`

| Field | Type | Notes |
|---|---|---|
| `id` | UUID | PK = tenant_id (set explicitly on create) |
| `overhead_percentage` | decimal | 0–100 |
| `buffer_percentage` | decimal | 0–100 |
| `yearly_fixed_cost` | decimal | |
| `employer_tax_percentage` | decimal | 0–100 |
| `benefits_percentage` | decimal | 0–100 |

Only one record per tenant. `upsertSettings` does a find-or-create with the tenant UUID as PK.

---

## API Endpoints

### Departments

| Method | Endpoint | Action |
|---|---|---|
| GET | `/api/departments` | List all; eager-loads `managerEmployee`, counts `employees` |
| POST | `/api/departments` | Create; optional `id` UUID field allowed |
| PUT | `/api/departments/{department}` | Update; partial via `sometimes` |
| DELETE | `/api/departments/{department}` | Hard delete → 204 |

**Create/Update validation:**
```
name: required|string|max:255
manager: nullable|string|max:255
manager_id: nullable|uuid|exists:employees,id
headcount: sometimes|integer|min:0
```

---

### Roles

| Method | Endpoint | Action |
|---|---|---|
| GET | `/api/roles` | List all; ordered by created_at |
| POST | `/api/roles` | Create; optional `id` UUID field |
| PUT | `/api/roles/{role}` | Update |
| DELETE | `/api/roles/{role}` | Hard delete → 204 |

**Create/Update validation:**
```
title: required|string|max:255
department: required|string|max:255
department_id: nullable|uuid|exists:departments,id
rate: required|numeric|min:0
```

---

### Employees

| Method | Endpoint | Action |
|---|---|---|
| GET | `/api/employees` | List all; eager-loads `department` |
| POST | `/api/employees` | Create; reloads via `fresh()` to get computed `cost_per_hour` |
| PUT | `/api/employees/{employee}` | Update; reloads via `fresh()` |
| DELETE | `/api/employees/{employee}` | Hard delete → 204 |

**Create/Update validation:**
```
name: required|string|max:255
role: required|string|max:255
role_name: nullable|string|max:255
department_id: nullable|uuid|exists:departments,id
job_role_id: nullable|uuid|exists:roles,id
capacity_role: nullable|in:frontend,backend,pm,qa,design
monthly_salary: required|numeric|min:0
workable_hours: required|integer|min:1|max:744
status: required|in:Active,On Leave,Terminated
```

Never accept `cost_per_hour` from client — it is DB-computed.

---

### Global Overheads

| Method | Endpoint | Action |
|---|---|---|
| GET | `/api/global-overheads` | List all; ordered by created_at |
| POST | `/api/global-overheads` | Create; optional `id` UUID field |
| PUT | `/api/global-overheads/{globalOverhead}` | Update |
| DELETE | `/api/global-overheads/{globalOverhead}` | Hard delete → 204 |

**Create/Update validation:**
```
category: required|string|max:255
description: required|string|max:500
monthly_cost: required|numeric|min:0
effective_month: nullable|integer|min:1|max:12
effective_year: nullable|integer|min:2000
```

---

### Company Settings

| Method | Endpoint | Action |
|---|---|---|
| GET | `/api/company-settings` | Get settings; 404 if not yet created |
| PUT | `/api/company-settings` | Upsert; creates with tenant UUID as PK on first call |

**Validation (all required):**
```
overhead_percentage: numeric|min:0|max:100
buffer_percentage: numeric|min:0|max:100
yearly_fixed_cost: numeric|min:0
employer_tax_percentage: numeric|min:0|max:100
benefits_percentage: numeric|min:0|max:100
```

---

## Resource Shapes

**DepartmentResource:**
```json
{
    "id": "uuid", "name": "Engineering", "manager": "Jane Smith",
    "manager_id": "uuid", "headcount": 5, "employees_count": 5
}
```

**RoleResource:**
```json
{ "id": "uuid", "title": "Backend Developer", "department": "Engineering", "department_id": "uuid", "rate": 85.00 }
```

**EmployeeResource:**
```json
{
    "id": "uuid", "name": "Alice", "role": "uuid", "role_name": "Backend Developer",
    "department_id": "uuid", "job_role_id": "uuid", "capacity_role": "backend",
    "monthly_salary": 5000, "workable_hours": 160, "cost_per_hour": 31.25,
    "status": "Active"
}
```

**GlobalOverheadResource:**
```json
{ "id": "uuid", "category": "Software", "description": "Hosting costs", "monthly_cost": 500, "effective_month": null, "effective_year": null }
```

**CompanySettingResource:**
```json
{
    "id": "tenant-uuid", "overhead_percentage": 15, "buffer_percentage": 10,
    "yearly_fixed_cost": 120000, "employer_tax_percentage": 12, "benefits_percentage": 8
}
```

---

## Known Gaps

- No bulk import for employees.
- No department soft delete — deletes are hard; employees in the department are left with a dangling `department_id`.
- `headcount` field on Department is not auto-computed — it is set manually on create and can diverge from the actual employee count (though `employees_count` from `withCount` is accurate).
