# RBAC Changes — Old vs Current

Documents the role/permission system changes made across the 6-phase Phase 1–6 RBAC tightening (May 2026).

Date: 2026-05-23

---

## 1. Backend route gating

| Aspect | Old | Current |
|---|---|---|
| Gated routes | 8 (`view_crm`, `manage_crm`, `manage_tenant` only) | ~60 routes across every business module |
| Ungated business routes | `/employees`, `/projects`, `/time-entries/approve`, `/phase-progress-logs`, `/holidays`, `/tenant` PUT, `/exchange-rates`, etc. — any tenant member could hit them via curl | All gated; permissions enforced server-side |
| Reads vs writes | All-or-nothing per route group | Reads use OR (`view_employees|view_crm|view_projects|manage_organization`); writes use the strict key (`manage_employees`, `manage_organization`, etc.) |
| `permission:` middleware syntax | Single key only: `permission:view_crm` | Supports OR: `permission:view_crm|view_employees` |

**Concrete example — `GET /api/employees`:**
- Old: any tenant member → 200 (sidebar hid it, but curl worked)
- Current: requires any of `view_employees|view_crm|view_projects|manage_organization` → 403 otherwise

**Concrete example — `PATCH /api/time-entries/{id}/approve`:**
- Old: anyone with `track_time` could approve their own draft entries — wrong semantic
- Current: requires `approve_time` (new key, Admin-only by default)

## 2. Permission catalog

| Old (12 keys) | Current (14 keys) |
|---|---|
| view_crm, manage_crm | (unchanged) |
| manage_estimation | (unchanged) |
| view_contracts | (unchanged) |
| view_projects, manage_projects | (unchanged) |
| view_schedule_tracking, **track_time** | (unchanged) |
| | **+ approve_time** (new — separates time approve from time logging) |
| | **+ log_progress** (new — separates phase progress writes from time logging) |
| manage_organization, view_employees, manage_employees | (unchanged) |
| view_dashboard, view_reports | (unchanged) |
| manage_tenant | (unchanged) |

**Default role assignments:**
- Old `Delivery`: `[view_schedule_tracking]` — couldn't log progress without it being implicitly allowed by ungated routes
- Current `Delivery`: `[view_schedule_tracking, log_progress, track_time]` — explicit grants; existing tenants get backfilled via migration `2026_05_23_000001`

## 3. Cache invalidation

| Aspect | Old | Current |
|---|---|---|
| `CheckPermission::$cache` | Static per-process cache, keyed by `userId|tenantId|roleName` | Now keyed by `userId|tenantId|roleId|roleName` (id added) |
| After tenant admin edits a role | **Cache never flushed** — long-running queue workers served stale permissions until restart | `TenantAppRoleController::update` calls `flushPermissionCacheForRole()` per affected user |
| Scope | n/a | Surgical: only users assigned to the edited role get evicted; other users' cache untouched |

## 4. Tenant-admin guardrails on role editing

| Lockout vector | Old | Current |
|---|---|---|
| Stripping `Admin` of `all` | ✓ Blocked | ✓ Blocked (unchanged) |
| Stripping `Executive/Sales/Delivery/HR` to `[]` while users assigned | ✗ **Allowed** — every assignee locked out of every page | ✓ Blocked with `422`: "Role 'X' has N user(s) assigned — granting zero permissions would lock them out" |
| Stripping an UNassigned role to `[]` | ✓ Allowed | ✓ Allowed (intentional — restructuring case) |
| Admin removing `manage_tenant` from their OWN role | ✗ **Allowed** — locks themselves out of role administration | ✓ Blocked with `422`: "ask another admin to make this change, or remove yourself from this role first" |
| Admin removing `manage_tenant` from a DIFFERENT role | ✓ Allowed | ✓ Allowed (unchanged) |

## 5. Frontend route gating

| Aspect | Old | Current |
|---|---|---|
| Cookies | `__session` (token), `__role` (super_admin/member) | `__session`, `__role`, **+ `__perms`** (comma-separated permission list) |
| Edge middleware (`middleware.ts`) | Only checked system role (super_admin vs member). App-role permissions invisible at edge. | Reads `__perms`, calls `canAccessRoute()`, redirects denied users via `fallbackPathFor()` |
| URL-jump to unauthorized page | Page mounts → queries fire → client-side `canAccessRoute` redirects (**visible flash**) | Edge redirect before any HTML ships — no flash |
| Sidebar visibility | Client-side `canAccessRoute()` | (unchanged) |
| `<PermissionGuard>` behavior | Disables + tooltip, never hides | (unchanged) |
| Permission refresh after role change | Required logout + login | `/auth/me` cycle (every page load) re-posts permissions to `__perms` cookie |

**Safety net:** if `__perms` is missing (legacy session, expired), middleware falls through to client-side gating rather than failing closed — no surprise logouts during the rollout window.

## 6. Database schema

| Aspect | Old | Current |
|---|---|---|
| `users.app_role` | String column, name-based join to `tenant_app_roles(tenant_id, name)` | (unchanged — kept for API back-compat per "never rename" rule) |
| `users.app_role_id` | n/a | **New nullable UUID FK** to `tenant_app_roles.id` |
| Permission lookup join | `WHERE r.tenant_id = ? AND r.name = ?` (fragile to renames) | `WHERE r.id = ?` (preferred); falls back to name join for orphans |
| Resilience to role rename | Required cascade UPDATE on `users.app_role`. Race condition or partial write → user falls through to empty permission list → locked out | FK survives renames; lookup follows `id`. Cascade still runs for back-compat but no longer load-bearing for auth |
| Orphan user (string `app_role` with no matching role row) | Locked out silently — empty permission list | Falls back to legacy name lookup; backfill migration logs warnings for cleanup |

**Migration applied:** `2026_05_23_000002_add_app_role_id_to_users` — adds column, FK, index; backfills existing rows by name lookup.

## 7. Code cleanup

| Aspect | Old | Current |
|---|---|---|
| `lib/rbac.ts` `Role` type | `export type Role = 'Admin' \| 'Executive' \| 'Sales' \| 'Delivery' \| 'HR' \| string` — the `\| string` made it accept anything, providing no type-safety | Deleted (zero importers — verified via grep) |
| Naming clarity | `Role` in `rbac.ts` (string union) coexisted with `Role` in `types/business.ts` (job-role entity) | One `Role` in the codebase — the org job-role entity. RBAC roles are simply strings. |

## 8. Test coverage

| Aspect | Old | Current |
|---|---|---|
| RBAC tests | 0 | 10 tests / 80 assertions in `tests/Feature/RoutePermissionTest.php` |
| Coverage | n/a | Zero-perm user gets 403/404 on every gated route; super admin bypasses; matching permission passes; OR syntax works; role update invalidates cache; FK survives rename; assigned-role-strip rejected; self-lockout rejected |
| Self-validation | n/a | Cache-flush and FK-rename tests were verified to fail when the underlying mechanism is disabled (real exercise, not just always-green) |

---

## Risk delta — what changed in production behavior

**No behavior change for compliant users.** Existing tenants with the seeded `Admin / Executive / Sales / Delivery / HR` roles see identical access patterns because:
- The route gating uses OR with multiple permissions, so any persona that previously reached a route still reaches it through their existing keys
- The `log_progress` + `track_time` backfill grants `Delivery` what they implicitly had before

**Behavior changes that are intentional improvements:**
- Users without `track_time` can no longer create or destroy time entries via API (they couldn't before either, because no role had `track_time` enabled by default — but the routes were open)
- A non-`approve_time` user can no longer approve a time entry (previously gated only by frontend disable — bypassable with curl)
- Salary history reads require `view_employees` (previously open to any tenant member)
- Initial budgets, exchange rate writes, tenant settings writes now require `manage_tenant`

**Behavior changes that may surprise:**
- If a tenant had hand-crafted a custom role that relied on a now-gated route working without the matching permission, those calls will 403. Mitigation: the error message is explicit (`"Your role (X) does not have permission to perform this action"`) and the catalog is documented for the admin UI.

---

## Deployment notes

**Backend migrations to run:**
```bash
php artisan migrate
# applies:
#   2026_05_23_000001_backfill_delivery_log_progress_permission
#   2026_05_23_000002_add_app_role_id_to_users
```

**Frontend deploy:** standard `npm run build` + deploy. The `__perms` cookie is populated on the next `/auth/me` cycle after any user's first request post-deploy, so existing sessions transition cleanly (Edge gate is opt-in until the cookie exists).

---

## File-by-file summary

### anka-api
- `app/Http/Middleware/CheckPermission.php` — OR syntax in `permission:a|b|c`; FK-preferred lookup; cache key includes `app_role_id`
- `app/Http/Controllers/Api/TenantAppRoleController.php` — empty-perm and self-lockout guards; `flushPermissionCacheForRole()` after updates
- `app/Http/Controllers/Api/TenantController.php` — `resolveAppRoleId()` helper; populates FK on create/update
- `app/Models/User.php` — added `app_role_id` to fillable
- `app/Support/PermissionCatalog.php` — added `approve_time`, `log_progress`
- `app/Support/TenantAppRoleSeeder.php` — Delivery defaults updated
- `routes/api.php` — ~60 routes annotated with `permission:` middleware
- `database/migrations/2026_05_23_000001_backfill_delivery_log_progress_permission.php` — backfill for existing tenants
- `database/migrations/2026_05_23_000002_add_app_role_id_to_users.php` — FK + backfill
- `tests/Feature/RoutePermissionTest.php` — 10 tests / 80 assertions

### anka-frontend
- `app/api/auth/session/route.ts` — writes/clears `__perms` cookie
- `hooks/useAuth.ts` — sends permissions to session route on login + on every `/auth/me` cycle
- `middleware.ts` — Edge gate using `__perms` + `canAccessRoute()`
- `lib/rbac.ts` — removed dead `Role` union
