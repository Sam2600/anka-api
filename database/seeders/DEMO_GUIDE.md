# ANKA Demo Guide

> Complete step-by-step script for demonstrating the three AI features and navigating the full platform with seeded demo data.

---

## Setup

### Run the seeder

```bash
cd /var/www/anka-api
php artisan db:seed --class=DemoSeeder
```

### Reset and re-seed cleanly

```bash
cd /var/www/anka-api
php artisan migrate:fresh
php artisan db:seed --class=DemoSeeder
```

### Demo accounts

| Role | Email | Password |
|---|---|---|
| Super Admin (Owner) | `owner@anka.test` | `Demo@1234` |
| Pixel Agency — Org Admin | `admin@pixelagency.test` | `Demo@1234` |
| Pixel Agency — Developer | `dev@pixelagency.test` | `Demo@1234` |
| Pixel Agency — Designer | `designer@pixelagency.test` | `Demo@1234` |
| Pixel Agency — PM | `pm@pixelagency.test` | `Demo@1234` |
| Pixel Agency — QA | `qa@pixelagency.test` | `Demo@1234` |
| Nova Studio — Org Admin | `admin@novastudio.test` | `Demo@1234` |

**Presenter login:** `admin@pixelagency.test` / `Demo@1234`

---

## Demo Flow (3 AI Features)

### Feature 1: Team Building AI

**Goal:** Show AI suggesting an optimal team composition based on deal scope.

**Steps:**
1. Log in as `admin@pixelagency.test`
2. Navigate to **CRM → New Deal** (`/crm/new`)
3. Fill in the Deal Info tab quickly (or just proceed):
   - Deal Name: `BluePeak Logistics — Dashboard Redesign`
   - Client: `BluePeak Logistics`
4. Switch to the **Estimation** tab
5. Scroll to the **AI Team Builder** section
6. Click **"Upload workload doc"** (or paste the workload description):
   > *"Redesign legacy logistics dashboard with real-time map tracking, route optimisation views, and driver-scheduling widgets. Tech: Next.js, Mapbox, D3."*
7. Click **Generate**
8. **What the client sees:**
   - AI suggests a team composition (e.g., 2 Frontend, 1 Backend, 1 Designer, 1 PM)
   - Each role shows quantity, months, and estimated salary range
   - Live cost/margin preview updates instantly

**Talking points:**
- *"Instead of guessing team size, the AI reads the project brief and matches it against our current capacity pool."*
- *"We can accept all suggestions, swap individual roles, or adjust months before locking the estimate."*
- *"The margin simulator updates in real time so sales knows exactly what profit we're signing up for."*

---

### Feature 2: Auto Assign (Time Tracking)

**Goal:** Show that winning a deal automatically creates a project and pre-assigns hours to the team.

**Pre-requisite:** The seeded data already contains a **Won** deal (`Apex Manufacturing — IoT Platform`) with hard assignments. If you want to show the live transition:

**Option A — Live trigger (recommended):**
1. From the CRM board, open the **Meridian Health — Patient Portal** deal (status = `Contract`)
2. Click **Win Deal**
3. Confirm in the dialog
4. Navigate to **Time Tracking** (`/time-tracking`)
5. **What the client sees:**
   - New project `Patient Portal` appears in the project dropdown
   - Team members from the deal's hard assignments are pre-allocated hours
   - Time entries can now be logged against those assignments

**Option B — Show already-seeded result:**
1. Navigate to **Time Tracking** (`/time-tracking`)
2. **What the client sees:**
   - Project: `IoT Platform` (Apex Manufacturing)
   - Four team members already have allocated hours from the deal transfer
   - Mix of Approved, Pending, and Draft entries showing realistic workflow
   - Consumed hours (≈ 320 h) vs. budget hours (1 440 h) visible

**Talking points:**
- *"When a deal is won, the system instantly creates a contract and project — no manual data re-entry."*
- *"The same team we estimated in the deal is automatically assigned to the project with their allocated hours."*
- *"Employees see their assignments in Time Tracking and can start logging hours immediately."*
- *"Approvals update consumed hours in real time, so project managers always know burn rate."*

---

### Feature 3: System Chatbot

**Goal:** Show an AI assistant that answers questions about how Anka works.

**Steps:**
1. From any dashboard page, open the **Chatbot** widget (bottom-right corner or sidebar)
2. Type one of the suggested questions below
3. **What the client sees:**
   - Natural-language response based on platform documentation
   - Context-aware answers referencing actual Anka workflows

**Suggested demo questions:**

1. **"How do I win a deal and what happens next?"**
   - *Expected response covers:* deal status change → `win_deal()` stored procedure → contract + project auto-creation → team assignment transfer.

2. **"How does time tracking affect project budgets?"**
   - *Expected response covers:* time entry approval → `consumed_hours` increment → burn-rate visibility → P&L impact via direct-labor calculation.

3. **"What is the difference between soft-booked and hard-booked hours?"**
   - *Expected response covers:* ghost roles (probability-weighted soft booking) vs. deal hard assignments (committed allocated hours) vs. project team assignments (delivery execution).

**Talking points:**
- *"New team members don't need to read a manual — they can ask the chatbot in plain English."*
- *"The bot understands our actual business logic, not just generic FAQ text."*
- *"It reduces onboarding time and support tickets for the admin team."*

---

## Navigation Checklist

Use this checklist to quickly verify every screen before the demo.

| Page | URL | What Should Be Visible |
|---|---|---|
| **Login** | `/login` | Login form; demo credentials ready |
| **Dashboard** | `/dashboard` | KPI cards (deals, revenue, capacity pool); no empty states |
| **CRM Board** | `/crm` | 6 Pixel deals across 7 columns (Lead, Inquiry, Opportunity, Proposal, Contract, Won, Lost); deal cards with budget & probability |
| **CRM New** | `/crm/new` | Deal form with Deal Info + Estimation tabs; AI Team Builder section |
| **Deal Detail** | `/crm/{id}` | Deal overview, ghost roles, hard assignments, linked contract/project cards, financial summary sidebar |
| **Estimation** | `/estimation` | Estimation simulator with scope table, overhead table, live margin cards |
| **Contracts** | `/contracts` | Apex Manufacturing (Completed) + Meridian Health (Draft); milestone tabs; invoice lists |
| **Projects** | `/projects` | 3 Pixel projects (IoT Platform On Track, Brand Refresh Completed, Mobile App MVP Not Started) |
| **Time Tracking** | `/time-tracking` | Time entry table with 4 employees, mixed statuses (Approved / Pending / Draft), billable vs. non-billable |
| **Organization** | `/organization` | 4 departments, 4 job roles, 4 employees with capacity roles and monthly hours |
| **Financial** | `/financial` | Monthly P&L table with revenue (paid invoices), direct labour (approved time entries), overhead, gross profit, net margin |
| **Forecast** | `/forecast` | Scenario simulator with baseline from last month's real P&L data |
| **Tenant Admin** | `/tenant` | (Super-admin only) Pixel Agency + Nova Studio listed |

### Multi-tenancy spot-check
- Log in as `admin@pixelagency.test` → verify 6 deals, 4 employees, 3 projects
- Log in as `admin@novastudio.test` → verify 2 deals, 2 employees, 1 project
- Confirm **no cross-tenant data leakage**

---

## What NOT to Click

Avoid these areas during the presentation to prevent broken or incomplete UX:

| Area | Why to Avoid |
|---|---|
| **"Create Contract" button inside Estimation page** | Not implemented; the real path is **Win Deal** on the deal detail page |
| **Reject action on time entries** | UI has no dedicated reject button; would require manual API call |
| **Un-approve an approved time entry** | One-way status transition in the UI; no un-approve button |
| **Delete a won deal** | Would orphan the linked contract/project and break the workflow chain |
| **Editing `consumed_hours` or `total` directly** | These are system-maintained fields (generated columns or application-managed) |
| **"Version" dropdown in Estimation** | UI-only mock; does not persist or load different estimation versions |

---

## Quick Recovery (If Something Goes Wrong)

| Problem | Fix |
|---|---|
| Data looks wrong or incomplete | `php artisan migrate:fresh && php artisan db:seed --class=DemoSeeder` |
| Can't log in | Verify password is `Demo@1234` (bcrypt hash is seeded) |
| Financial page shows "No financial data" | Ensure at least one invoice has `status = Paid` and one time entry has `status = Approved` |
| Kanban board empty | Verify deals were seeded with `tenant_id` matching the logged-in user's tenant |
| Project missing team assignments | Re-run `php artisan db:seed --class=ProjectSeeder` or full `DemoSeeder` |

---

## Seeder Dependency Order

```
DemoSeeder
├── OwnerSeeder
├── TenantSeeder
├── EmployeeHoursSeeder       (departments → roles → capacity_roles → employees → company_settings)
├── UserSeeder                (links users to employees; depends on EmployeeHoursSeeder)
├── CrmDealSeeder             (deals + ghost_roles + hard_assignments + estimation_resources + deal_overheads)
├── ContractSeeder            (depends on CrmDealSeeder for deal_id)
├── ProjectSeeder             (depends on ContractSeeder for contract_id)
├── TaskSeeder                (depends on ProjectSeeder for project_id + UserSeeder for approved_by)
├── TimeTrackingSeeder        (depends on ProjectSeeder for project_id)
├── InvoiceSeeder             (depends on ContractSeeder for contract_id)
└── FinanceSeeder             (global_overheads per tenant)
```

All sub-seeders reference pre-assigned UUIDs from `DemoDataMap.php` so foreign keys remain consistent across the entire demo dataset.
