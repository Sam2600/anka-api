# ANKA — Full Project Description

## What ANKA Is

ANKA is a **multi-tenant B2B SaaS platform** built for software agencies to run their entire business end-to-end — from chasing leads, to estimating projects, to delivering work, to billing clients, and tracking financial health. One platform, one workflow, instead of juggling a CRM, a spreadsheet for cost estimation, a contract tool, and a time tracker.

"Multi-tenant" means many separate agencies (called **tenants**) all share the same application, but each tenant's data is completely isolated from every other tenant.

---

## The Business Flow (the Core Story)

This is the single most important thing to understand about ANKA. Everything revolves around one pipeline:

```
DEAL  →  (won)  →  CONTRACT  +  PROJECT
                       │             │
                   Milestones    TimeEntries
                   Invoices      (logged → approved → consumed)
                       │
                   Payments → Revenue Recognized
```

1. A **Deal** is a sales opportunity (lead → opportunity → proposal → won/lost).
2. Inside a Deal, the sales team builds an **Estimation** — labor hours, ghost roles (planned team), overheads, target margin.
3. When a Deal is **won**, a single PostgreSQL stored procedure called `win_deal()` atomically creates:
   - A **Contract** (the legal/billing record), and
   - A **Project** (the delivery record).
4. The Contract gets **Milestones** and **Invoices**. Invoices get paid → `revenue_recognized` goes up.
5. The Project consumes hours through **Time Entries**. Time entries get approved → `consumed_hours` goes up.
6. **Financials** and **Forecast** pages roll everything up into P&L and revenue predictions.

> **Critical rule:** Contracts and Projects can **only** be created by `win_deal()`. There is intentionally no `POST /contracts` or `POST /projects` endpoint. This guarantees you cannot have an orphan Contract without a Deal.

---

## Two Repositories

| Repo | Role |
|---|---|
| `anka-api` | Laravel 13 backend — the source of truth |
| `anka-frontend` | Next.js 16 frontend — the user interface |

The frontend **never touches the database directly**. All data flows through the Laravel API.

---

## Backend — `anka-api`

**Stack:** Laravel 13, PHP 8.3+, PostgreSQL (Supabase in production, SQLite in tests), Eloquent ORM, Sanctum auth, Mailgun email, PHPUnit 12.

### Multi-tenancy mechanics
- Every request must include an `X-Tenant-ID` header.
- The `TenantScope` middleware validates the tenant and binds the UUID via `app()->instance('tenant_id', ...)`.
- Models use the `BelongsToTenant` trait, which adds a global Eloquent scope and auto-injects `tenant_id` on creation.
- **Exception:** `User` does *not* use this trait (login must find users globally by email before a tenant is known).
- **Super admins** (`users.is_super_admin = true`) bypass tenant scoping entirely.

### Route groups (three layers)
1. **`/api/auth/*`** — `auth:sanctum` only (login, logout, me, refresh, profile, password).
2. **`/api/admin/*`** — `auth:sanctum + super_admin` (tenant CRUD + user management).
3. **All business routes** — `auth:sanctum + tenant` (deals, contracts, invoices, projects, time entries, organization, etc.).

### Key models
`Deal`, `EstimationResource`, `DealGhostRole`, `DealHardAssignment`, `DealOverhead`, `Contract`, `Milestone`, `Invoice`, `Project`, `TimeEntry`, `Employee`, `Department`, `Role`, `GlobalOverhead`, `CompanySettings`, `Tenant`, `User`, `AiUsageLog`.

All primary models use **UUID PKs**; most use **soft deletes**.

### PostgreSQL-specific features (you cannot replicate these in plain Eloquent)
- **`win_deal(p_deal_id, p_tenant_id)`** — atomic stored procedure that converts a Deal to Contract + Project.
- **Sequences** — produce human-readable IDs: `CON-0001`, `INV-1042`, `PRJ-101`.
- **Generated columns** — written by Postgres, ignored by PHP:
  - `invoices.total = amount + tax`
  - `employees.cost_per_hour = monthly_salary / workable_hours`

### Important transactional logic
- **Invoice payment** (`PATCH /invoices/{id}/pay`) — runs in `DB::transaction()` and increments `contracts.revenue_recognized` in the same transaction.
- **TimeEntry approval** (`PATCH /time-entries/{id}/approve`) — uses `lockForUpdate()` then increments `projects.consumed_hours`.
- **User creation** (super-admin) — auto-creates a linked `Employee` and queues a `WelcomeUser` email.

### Response shape
All responses go through **API Resources** in `app/Http/Resources/`, return JSON, wrap data in `{ "data": ... }`, and use `snake_case` keys.

---

## Frontend — `anka-frontend`

**Stack:** Next.js 16.2.4 (App Router), React 19.2.3, TypeScript 5, Tailwind CSS 4, shadcn/ui (new-york / neutral), Zustand 5 (business data), TanStack Query 5 (server state), React Hook Form 7 + Zod 4, Axios 1.13, Recharts 3, `@hello-pangea/dnd` (Kanban), `@anthropic-ai/sdk` + Google Gemini Flash (AI team builder).

### Pages
| Route | Purpose |
|---|---|
| `/login` | Public login |
| `/dashboard` | Main overview |
| `/crm` | Kanban deal pipeline |
| `/crm/new`, `/crm/[id]`, `/crm/edit/[id]`, `/crm/[id]/staffing` | Deal CRUD + AI staffing |
| `/estimation` | Estimation Engine calculator |
| `/contracts` | Contracts, milestones, invoices (tabbed) |
| `/projects` | Project delivery + burn rate |
| `/time-tracking` | Log + approve hours |
| `/organization` | Departments, roles, employees, overheads |
| `/financial` | P&L |
| `/forecast` | Revenue forecasting |
| `/profile` | User settings |
| `/tenant` | **Super-admin only** — manage tenants |

### Auth flow (Sanctum, but secured properly)
1. Login → `POST /api/auth/login` returns a Bearer token.
2. Token written to **httpOnly `__session` cookie** via Next.js route `/api/auth/session` (so JS can never read it).
3. Token also held **in-memory** in `authStore` (not persisted).
4. On page refresh, `AuthInitializer` calls `GET /api/auth/session` to re-hydrate the token from the cookie.
5. `middleware.ts` reads the `__role` cookie at the Edge to route super-admins to `/tenant` and members to org routes.

### State management (Zustand stores)
| Store | Persisted | Purpose |
|---|---|---|
| `businessStore` | No | **Primary store** — all business data, rehydrated from API |
| `authStore` | No | Bearer token in memory |
| `tenantStore` | Yes (`tenant-storage`) | Active tenant info |
| `uiStore` | Yes (`ui-storage`) | Sidebar collapsed state |

`businessStore` exposes computed selectors: `getCapacityPool()`, `getFinancialPnL()`, `getDealEstimation()`.

Mutations follow an **optimistic-update + rollback** pattern: snapshot → mutate → call API → on error restore snapshot + toast.

### API layers
- **`lib/api.ts`** — business data axios instance. Injects `Authorization: Bearer` + `X-Tenant-ID`. Handles 401 (redirect), 403 (toast), 429 (rate-limit countdown).
- **`lib/axios.ts`** — auth-only axios instance. Handles Sanctum CSRF.
- **`app/api/ai-team-builder/route.ts`** — server-side proxy that calls Google Gemini (keeps the API key off the browser).

### Two-tier RBAC
- **System role:** `super_admin` (tenant management only) vs `member` (all org routes).
- **App role:** `Admin`, `Executive`, `Sales`, `Delivery`, `HR` — checked via `hasPermission()` / `usePermission()` / `<PermissionGuard>`.
- `PermissionGuard` **disables + shows a tooltip** — it never hides features, so users always know a capability exists.

### Field naming bridge
The backend speaks `snake_case`, the frontend speaks `camelCase`. The single bridge is **`lib/dealsMapper.ts`** — never inline a field-name conversion in a component or query hook.

### Validation
Zod schemas live in `lib/schemas/` (one per module), wired into React Hook Form via `zodResolver`. Never inline validation in components.

### Errors
Always use `normalizeError(err)` from `lib/errorHandler.ts` in catch blocks. Returns a typed `NormalizedError` with `code` and `message` (plus `fields` map for 422 form errors).

---

## Cross-cutting Rules (the "do not break" list)

1. **Never rename existing DB columns** — mappers + Resources are tightly coupled to names.
2. **All schema changes go through a Laravel migration** named `YYYY_MM_DD_NNNNNN_description`.
3. **Never touch working features when fixing something else** — scope tightly.
4. **Read the relevant spec file in `specs/` before changing a module.**
5. **Frontend TS types must match backend API Resource `toArray()`** — change Resource, mapper, and type in the same PR.
6. **All fixes must work across both repos** — endpoint change ↔ frontend query update.
7. **No manual `store` routes for Contract or Project** — only `win_deal()` creates them.
8. **Never set `invoices.total` or `employees.cost_per_hour` from PHP** — generated columns ignore writes.
9. **Always use `normalizeError()`** — never access `error.response.data.errors` directly.
10. **Inline validation via `$request->validate()`** — no Form Request classes.

---

## How to Run

### Backend
```bash
composer setup     # first-time setup
composer dev       # API + queue + log stream + Vite, all concurrently
composer test      # PHPUnit
php artisan migrate
php ./vendor/bin/pint   # lint/format
```

### Frontend
```bash
npm run dev        # Next.js dev server on :3000
npm run build
npm run start
npm run lint
```

### Environment
- **API `.env`** — `APP_*`, `DB_*`, `SANCTUM_STATEFUL_DOMAINS`, `MAILGUN_*`, `MAIL_*`.
- **Frontend `.env.local`** — `NEXT_PUBLIC_BACKEND_URL`, `NEXT_PUBLIC_API_URL`, `GEMINI_API_KEY` (server-only).

---

## Where to Read More

| File | What it covers |
|---|---|
| `/var/www/CLAUDE.md` | Master project spec |
| `/var/www/CONVENTIONS.md` | Coding patterns |
| `anka-api/specs/overview.md` | All backend modules, models, routes, tables |
| `anka-api/specs/{crm,estimation,contracts,projects,organization,auth,tenant}.md` | Per-module backend specs |
| `anka-frontend/specs/overview.md` | All pages, state, auth, API layers |
| `anka-frontend/specs/{crm,estimation,contracts,projects,...}.md` | Per-module frontend specs |
