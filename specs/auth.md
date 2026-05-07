# anka-api — Auth Module Spec

## Architecture

Laravel Sanctum, stateless token-based. No sessions — every authenticated request carries a Bearer token in `Authorization` header. Passwords hashed bcrypt cost 12 (cost 4 in tests).

---

## Middleware Groups

| Group | Middleware | Routes |
|---|---|---|
| Login | `throttle:5,1` (brute-force protection) | `POST /api/auth/login` only |
| Auth-only | `auth:sanctum`, `throttle:60,1` | All other `/api/auth/*` routes |
| Super admin | `auth:sanctum`, `super_admin`, `throttle:60,1` | `/api/admin/*` |
| Tenant-scoped | `auth:sanctum`, `tenant`, `throttle:60,1` | All business data routes |

⚠️ Auth routes do NOT include the `tenant` middleware. `/auth/me` is the call the frontend uses to **obtain** the tenant ID — requiring `X-Tenant-ID` on that route would be a chicken-and-egg problem.

---

## User Model

| Field | Type | Notes |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants; nullable for super admins |
| `first_name` | string | max 255 |
| `last_name` | string | max 255 |
| `email` | string | unique |
| `password` | string | bcrypt hashed |
| `app_role` | enum | `Admin`, `Executive`, `Sales`, `Delivery`, `HR` |
| `system_role` | string | `member` or `super_admin` |
| `is_super_admin` | boolean | gates `/admin/*` middleware |
| `employee_id` | UUID | FK → employees; auto-linked on user creation |
| `deleted_at` | timestamp | soft delete |

⚠️ `User` intentionally does NOT use the `BelongsToTenant` trait — login queries must search globally by email before a tenant is known.

---

## API Endpoints

### `POST /api/auth/login`

**Rate limited:** 5 requests/minute per IP.

**Request body:**
```json
{ "email": "required|email", "password": "required" }
```

**Success response (200):**
```json
{
    "user": { ...AuthUserResource },
    "token": "plain_text_token"
}
```

**Failure response (401):**
```json
{ "message": "Invalid credentials" }
```

---

### `GET /api/auth/me`

Returns the authenticated user with tenant relation.

**Response:** `AuthUserResource` shape:
```json
{
    "data": {
        "id": "uuid",
        "first_name": "Jane",
        "last_name": "Smith",
        "email": "jane@example.com",
        "app_role": "Admin",
        "system_role": "member",
        "is_super_admin": false,
        "tenant": {
            "id": "uuid",
            "name": "Acme Corp",
            "slug": "acme-corp"
        }
    }
}
```

---

### `DELETE /api/auth/logout`

Deletes the current Sanctum token. Returns `{ "message": "Logged out" }`.

---

### `POST /api/auth/refresh`

Revokes the current token and issues a new one. Used by the axios refresh interceptor.

**Response:**
```json
{ "token": "new_plain_text_token" }
```

---

### `PUT /api/auth/profile`

Update the authenticated user's own name/email.

**Request body (all `sometimes`):**
```json
{
    "first_name": "string|max:255",
    "last_name": "string|max:255",
    "email": "email|unique:users,email,{userId}"
}
```

Returns updated `AuthUserResource`.

---

### `POST /api/auth/password`

Change the authenticated user's password.

**Request body:**
```json
{
    "current_password": "required|string",
    "new_password": "required|string|min:8|confirmed",
    "new_password_confirmation": "required|string"
}
```

**422 response** (wrong current password):
```json
{
    "message": "Current password is incorrect.",
    "errors": { "current_password": ["Current password is incorrect."] }
}
```

---

## App Roles

| Role | Key Permissions |
|---|---|
| `Admin` | All permissions |
| `Executive` | `view_dashboard`, `view_reports`, `manage_tenant`, `view_projects`, `view_crm` |
| `Sales` | `view_crm`, `manage_crm`, `manage_estimation`, `view_contracts` |
| `Delivery` | `view_projects`, `manage_projects`, `track_time` |
| `HR` | `manage_organization`, `view_employees`, `manage_employees` |

Role is stored in `users.app_role`. Full permission matrix is in `lib/rbac.ts` (frontend).

---

## Super Admin

`users.is_super_admin = true` grants access to `/api/admin/*` routes via the `super_admin` middleware. Super admins bypass tenant scoping entirely — the `TenantScope` middleware skips when `$user->isSuperAdmin()` is true. They manage tenants and users but cannot access org business data.

---

## Known Gaps

- No password reset / forgot-password flow — password can only be changed via `POST /auth/password` while authenticated.
- No email verification on signup — users are created by super admins and receive a WelcomeUser mail with a generated password.
- No "remember me" / long-lived token support — token persists until explicit logout or revocation.
