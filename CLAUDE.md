# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Development (runs API server, queue, log streaming, and Vite concurrently)
composer dev

# First-time setup
composer setup

# Run all tests
composer test

# Run a single test file
php artisan test tests/Feature/ExampleTest.php

# Run tests matching a filter
php artisan test --filter=SomeTestName

# Lint / fix code style
php ./vendor/bin/pint

# Frontend build
npm run build
npm run dev
```

## Architecture

**ANKA** is a multi-tenant SaaS API backend (Laravel 13, PHP 8.3+) serving a Next.js frontend. PostgreSQL (Supabase) is used in production; SQLite is used for tests.

### Multi-Tenancy

Every request must include an `X-Tenant-ID` header. The `TenantScope` middleware (`tenant` alias) validates this header, checks the tenant is active, and binds the UUID to the container via `app()->instance('tenant_id', ...)`. All primary models use the `BelongsToTenant` trait, which:
- Adds a global Eloquent scope filtering all queries to the current `tenant_id`
- Auto-injects `tenant_id` on model creation

**Exception:** `User` intentionally does NOT use `BelongsToTenant` — login queries must search globally by email before a tenant is known.

This prevents cross-tenant data leakage. Never bypass this scope unless explicitly needed.

### Route Middleware Layers

Routes are split into three middleware groups in `routes/api.php`:
1. **Auth-only** (`auth:sanctum`) — `/auth/*` routes (login, logout, me, refresh, profile). These do not require `X-Tenant-ID` because `/auth/me` is used by the frontend to *obtain* the tenant ID.
2. **Super admin** (`auth:sanctum`, `super_admin`) — `/admin/*` routes. Global access, no tenant scope. Requires `is_super_admin = true` on the User.
3. **Tenant-scoped** (`auth:sanctum`, `tenant`) — all business data routes. Requires a valid `X-Tenant-ID`.

### Super Admin

Super admins (`users.is_super_admin = true`) have global access and bypass tenant scoping. They manage tenants and users via `/api/admin/*`. The `TenantScope` middleware skips scoping entirely when `$user->isSuperAdmin()` is true.

### Request / Response

- All API routes live in `routes/api.php` under `/api` prefix
- Controllers are in `app/Http/Controllers/Api/`
- JSON responses are shaped by Laravel API Resources in `app/Http/Resources/` — responses wrap data in a `data` key, all keys in `snake_case`
- All errors return JSON (never HTML) — configured in `bootstrap/app.php`
- CORS is restricted to `localhost:3000` and `localhost:3001`

### Key Business Logic

**Deal → Contract → Project flow:** `POST /deals/{deal}/win` invokes the PostgreSQL stored procedure `win_deal(p_deal_id, p_tenant_id)`, which atomically converts a Deal into a Contract and Project. Do not replicate this logic in PHP — it lives in the database. Because of this, `ContractController` and `ProjectController` have no `store` route.

**Invoice payments (`PATCH /invoices/{invoice}/pay`):** Runs inside `DB::transaction()` and simultaneously increments `contracts.revenue_recognized`.

**TimeEntry approval (`PATCH /time-entries/{time_entry}/approve`):** Uses `lockForUpdate()` (pessimistic locking) inside a transaction before incrementing `projects.consumed_hours`.

**User creation (`POST /admin/tenants/{id}/users`):** Auto-creates a linked `Employee` record (mapping `app_role` → `role_name`) and queues a `WelcomeUser` mail with the generated password. Deleting a user also soft-deletes its linked employee.

### PostgreSQL-Specific Features

- **Readable IDs:** Contracts, Invoices, and Projects get human-readable IDs (e.g. `CON-0001`, `INV-1042`) via PostgreSQL sequences created in migration `2026_05_04_000003_create_sequences.php`.
- **Generated columns:**
  - `employees.cost_per_hour` — auto-calculated (`monthly_salary / workable_hours`)
  - `invoices.total` — auto-calculated (`amount + tax`)
  - Do not attempt to set these from PHP — Eloquent ignores writes to generated columns.

### Models

| Model | Notable relationships / fields |
|-------|-------------------------------|
| `Deal` | `ghost_roles`, `hard_assignments`, `estimation_resources`, `deal_overheads` relationships |
| `Contract` | Tracks `total_value` vs `revenue_recognized`; created only via `win_deal()` |
| `Invoice` | `total` is a PostgreSQL generated column — do not set it in PHP |
| `Project` | Tracks `consumed_hours`; created only via `win_deal()` |
| `TimeEntry` | Has `status` (default `Draft`) and approval workflow |
| `Employee` | `cost_per_hour` is a PostgreSQL generated column |

All models use UUID primary keys. `User`, `Deal`, `Contract`, `Invoice`, `Project`, `TimeEntry`, and `Employee` use soft deletes.

### Authentication

Laravel Sanctum (stateless token-based). Passwords hashed at bcrypt cost 12 (cost 4 in tests). Valid `app_role` values: `Admin`, `Executive`, `Sales`, `Delivery`, `HR`.

### Testing

PHPUnit 12. Test environment uses in-memory SQLite, array cache, and sync queue (configured in `phpunit.xml`). Tests live in `tests/Feature/` and `tests/Unit/`. Note: PostgreSQL-specific features (generated columns, stored procedures, sequences) are not available in the SQLite test environment — tests touching those areas require special handling or mocking.
