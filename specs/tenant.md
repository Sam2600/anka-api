# anka-api — Tenant Module Spec

## Architecture

`TenantController` serves two distinct audiences:

1. **Org users** — access their own tenant via `/api/tenant` (behind `auth:sanctum` + `tenant` middleware)
2. **Super admins** — full CRUD over all tenants + user management via `/api/admin/tenants/*` (behind `auth:sanctum` + `super_admin` middleware)

---

## Tenant Model

**Table:** `tenants`

| Field | Type | Notes |
|---|---|---|
| `id` | UUID | PK |
| `name` | string | max 255 |
| `slug` | string | max 100; must be `alpha_dash`; unique across tenants |
| `plan` | string | nullable; `free`, `pro`, `enterprise` (UI only — no billing logic) |
| `is_active` | boolean | inactive tenants: users cannot log in |
| `created_at` | timestamp | |

**Relationships:** `hasMany User`, `hasMany` all business entities via `BelongsToTenant` trait

---

## Multi-Tenancy Flow

1. Every business data request includes `X-Tenant-ID: <uuid>` header.
2. `TenantScope` middleware validates the UUID, checks `is_active = true`, binds to `app('tenant_id')`.
3. `BelongsToTenant` trait adds a global Eloquent scope filtering all queries to `tenant_id`.
4. Super admins bypass this scope entirely — `TenantScope` skips when `$user->isSuperAdmin()` is true.

---

## Org User Routes

### `GET /api/tenant`

Returns the caller's own tenant.

**Response:**
```json
{
    "data": {
        "id": "uuid",
        "name": "Acme Corp",
        "slug": "acme-corp",
        "plan": "pro",
        "is_active": true,
        "created_at": "2026-01-01T00:00:00Z"
    }
}
```

---

### `PUT /api/tenant`

Update own tenant name or slug.

**Validation:**
```
name: sometimes|required|string|max:255
slug: sometimes|required|string|max:100|alpha_dash|unique:tenants,slug,{current_tenant_id}
```

Returns the same shape as `GET /api/tenant`.

---

## Super Admin Routes (`/api/admin/tenants`)

### `GET /api/admin/tenants`

Paginated list of all tenants (50 per page), ordered by `created_at DESC`. Includes `users_count` (excluding soft-deleted users).

**Response:**
```json
{
    "data": [{ "id": "...", "name": "...", "slug": "...", "plan": "...", "is_active": true, "created_at": "...", "users_count": 3 }],
    "meta": { "total": 42, "per_page": 50, "current_page": 1, "last_page": 1 }
}
```

---

### `POST /api/admin/tenants`

Create a new tenant.

**Validation:**
```
name: required|string|max:255
slug: required|string|max:100|alpha_dash|unique:tenants,slug
plan: nullable|string|max:50
is_active: boolean
```

Returns `{ "data": { ...tenant } }` with 201.

---

### `GET /api/admin/tenants/{id}`

Get a single tenant by UUID.

---

### `PUT /api/admin/tenants/{id}`

Update any tenant (name, slug, plan, is_active).

---

### `DELETE /api/admin/tenants/{id}`

**Does not delete** — sets `is_active = false`. Returns `{ "message": "Tenant deactivated" }`.

---

## User Management (`/api/admin/tenants/{tenantId}/users`)

### `GET /api/admin/tenants/{tenantId}/users`

List all non-deleted users for a tenant, ordered by `first_name`.

**Response:**
```json
{
    "data": [{ "id": "uuid", "first_name": "Jane", "last_name": "Smith", "email": "...", "app_role": "Admin", "employee_id": "uuid" }]
}
```

---

### `POST /api/admin/tenants/{tenantId}/users`

Create a new user. This is the only way to add users to a tenant.

**Validation:**
```
first_name: required|string|max:255
last_name: required|string|max:255
email: required|email|unique:users,email
app_role: required|in:Admin,Executive,Sales,Delivery,HR
```

**Side effects (in order):**
1. Creates `User` record with a random 8-character password.
2. Auto-creates a linked `Employee` record (mapping `app_role` → `role_name`):
   - `Admin` / `Executive` → `Head of Organization`
   - `HR` → `HR Manager`
   - `Sales` → `Sales Manager`
   - `Delivery` → `Delivery Lead`
3. Links `user.employee_id` → new employee.
4. Queues `WelcomeUser` mail with the plain-text password.

**Response (201):**
```json
{ "data": { ...user }, "generated_password": "aBcD1234" }
```

⚠️ `generated_password` is also shown in the frontend toast (8 seconds). This is the only time the password is visible.

---

### `PUT /api/admin/tenants/{tenantId}/users/{userId}`

Update user name, email, or role.

If `app_role` changes, the linked employee's `role_name` is also updated to match.

---

### `DELETE /api/admin/tenants/{tenantId}/users/{userId}`

Soft-deletes the user AND soft-deletes the linked employee record.

Returns `{ "message": "User deleted" }`.

---

## AI Usage (Super Admin)

### `GET /api/admin/ai-usage`

Returns aggregate AI usage across all tenants (from `ai_usage_logs` table), grouped by tenant, ordered by total cost descending.

**Response:**
```json
{
    "totals": { "total_calls": 100, "total_input_tokens": 500000, "total_output_tokens": 100000, "total_cost": 0.45 },
    "tenants": [{ "tenant_id": "uuid", "tenant_name": "Acme Corp", "total_calls": 50, "total_input_tokens": 250000, "total_output_tokens": 50000, "total_cost": 0.225 }]
}
```

---

## Known Gaps

- Tenant deletion is not a true delete — only deactivation. No way to permanently remove a tenant via API.
- No plan enforcement logic — `plan` is stored but no feature gates or billing hooks exist.
- `slug` is unique but not currently used for subdomain routing in the frontend.
