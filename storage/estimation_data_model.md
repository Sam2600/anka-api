# /estimation — Current Saved Data

Snapshot of the data the `/estimation` page reads from and writes to. Generated from `database/database.sqlite` at 2026-05-16. UUIDs are abbreviated to the first 13 chars (`019e2249-1496…`) for readability — full IDs are in the DB.

| Table | Rows |
|---|---:|
| `tenants` | 3 |
| `deals` | 20 |
| `estimation_resources` | 78 |
| `deal_overheads` | 38 |
| `deal_ghost_roles` | 80 |
| `estimation_versions` | 19 |
| `deal_contract_documents` | 3 |
| `roles` | 24 |
| `employees` | 27 |
| `company_settings` | 3 |

---

## Tenants

| Tenant ID | Name |
|---|---|
| `019e2249-0c52…` | Yangon Digital Works |
| `019e2249-1d99…` | Mandalay Studio Co |
| `019e2249-30ba…` | Tokyo Product Lab |

---

## Deals (20)

The "Target Deal" dropdown on /estimation lists these. `pct` = `win_probability`. Currency context comes from the tenant's `company_settings`.

| Deal ID | Name | Client | Status | Lifecycle | pct | Client Budget | Months | Margin |
|---|---|---|---|---|---:|---:|---:|---:|
| `019e27d8-171c…` | BCMM Profile Web | Brycen Myanmar | qualified | active | 50 | 5,000,000 | 3 | 30 |
| `019e2249-1c80…` | Cross-Border Remittance Risk Dashboard | Mingalar Money Transfer | negotiation | active | 75 | 242,000,000 | 4 | 30 |
| `019e2249-15b3…` | Hospital Queue and Appointment System | Shwe Taw Hospital Group | won | active | 100 | 128,000,000 | 5 | 32 |
| `019e2249-1d15…` | Insurance Claims Mobile Back Office | Tharaphu Insurance | qualified | active | 35 | 156,000,000 | 4 | 30 |
| `019e2249-1d51…` | Legacy ERP Rescue Assessment | Irrawaddy Distribution | qualified | dropped | 0 | 48,000,000 | 4 | 30 |
| `019e2249-1496…` | Merchant Wallet Reconciliation Portal | AyarPay Services | won | active | 100 | 186,000,000 | 5 | 32 |
| `019e2776-6aff…` | Smoke Test — Backup Service | Acme Corp | negotiation | active | 80 | 60,000 | 12 | _(null)_ |
| `019e2249-1cdc…` | Tea Exporter B2B Ordering Portal | Golden Leaf Export | qualified | active | 55 | 86,000,000 | 4 | 30 |
| `019e2249-3071…` | Food Delivery Campaign Microsite | Taste Mandalay | qualified | dropped | 0 | 26,000,000 | 4 | 30 |
| `019e2249-2887…` | Hotel Group Booking Microsites | Bagan Heritage Hotels | won | active | 100 | 62,000,000 | 5 | 32 |
| `019e2249-2791…` | Omnichannel Retail Commerce Relaunch | Royal Jade Retail | won | active | 100 | 98,000,000 | 5 | 32 |
| `019e2249-301c…` | Retail Loyalty Data Mart | Mandalay Mart | negotiation | active | 70 | 118,000,000 | 4 | 30 |
| `019e2249-2fdc…` | Tour Operator CRM and Quotation Tool | Upper Myanmar Journeys | qualified | active | 40 | 54,000,000 | 4 | 30 |
| `019e2249-2971…` | Wholesale Inventory Mobile App | Zay Cho Market Cooperative | qualified | active | 60 | 72,000,000 | 4 | 30 |
| `019e2249-3de8…` | Event Ticketing Landing System | Tokyo Culture Week | qualified | dropped | 0 | 5,200,000 | 4 | 30 |
| `019e2249-3d83…` | Fintech Compliance Evidence Vault | Shinjuku Payments | negotiation | active | 80 | 28,600,000 | 4 | 30 |
| `019e2249-3dbe…` | HR Onboarding Workflow Tool | Meguro People Ops | qualified | active | 35 | 9,400,000 | 4 | 30 |
| `019e2249-3d5a…` | Multilingual Partner Portal | Nihon Travel Partners | qualified | active | 50 | 13,200,000 | 4 | 30 |
| `019e2249-34d3…` | SaaS Customer Success Analytics | Kanda Cloud Systems | won | active | 100 | 24,500,000 | 5 | 32 |
| `019e2249-35e3…` | Warehouse Picking Optimization MVP | Sumida Logistics | won | active | 100 | 16,800,000 | 5 | 32 |

### Deals with Mark Contract Ready submitted (`final_*` populated)

Only **1 deal** in the DB has the contract-ready handoff fields written:

| Field | Value |
|---|---|
| Deal ID | `019e2776-6aff-7343-a35a-077c01478ab4` |
| Name | Smoke Test — Backup Service |
| Client | Acme Corp |
| Status | negotiation (B → A fired) |
| `final_monthly_fee` | 5,000 |
| `final_installation_fee` | 1,500 |
| `final_contract_months` | 12 |
| `final_support_hours_per_month` | 12 |
| `final_team_summary` | "1 cloud engineer + on-call rotation" |
| `final_currency` | USD |
| `final_confirmed_at` | 2026-05-13 17:08:52 |
| `suggested_template_variant` | cloud_backup |
| `final_ot_policy` | "Support hours capped at 12/month; overage charged at $80/hr." |

---

## `estimation_resources` (78 rows) — feature scope per deal

Rows the user sees in the Project Scope table. `role` is resolved via `job_role_id → roles.title`.

| Deal | Feature | Role | Hours |
|---|---|---|---:|
| **BCMM Profile Web** | _(no rows — versioned only via Save Version v1)_ | | |
| Cross-Border Remittance Risk Dashboard | Discovery and delivery planning | Project Manager | 55 |
| Cross-Border Remittance Risk Dashboard | Integration and data services | Backend Engineer | 220 |
| Cross-Border Remittance Risk Dashboard | Portal UI and workflow screens | Frontend Engineer | 180 |
| Cross-Border Remittance Risk Dashboard | Prototype and usability review | Product Designer | 70 |
| Event Ticketing Landing System | Discovery and delivery planning | Project Manager | 55 |
| Event Ticketing Landing System | Integration and data services | Backend Engineer | 220 |
| Event Ticketing Landing System | Portal UI and workflow screens | Frontend Engineer | 180 |
| Event Ticketing Landing System | Prototype and usability review | Product Designer | 70 |
| Fintech Compliance Evidence Vault | Discovery and delivery planning | Project Manager | 55 |
| Fintech Compliance Evidence Vault | Integration and data services | Backend Engineer | 220 |
| Fintech Compliance Evidence Vault | Portal UI and workflow screens | Frontend Engineer | 180 |
| Fintech Compliance Evidence Vault | Prototype and usability review | Product Designer | 70 |
| Food Delivery Campaign Microsite | Discovery and delivery planning | Project Manager | 55 |
| Food Delivery Campaign Microsite | Integration and data services | Backend Engineer | 220 |
| Food Delivery Campaign Microsite | Portal UI and workflow screens | Frontend Engineer | 180 |
| Food Delivery Campaign Microsite | Prototype and usability review | Product Designer | 70 |
| HR Onboarding Workflow Tool | Discovery and delivery planning | Project Manager | 55 |
| HR Onboarding Workflow Tool | Integration and data services | Backend Engineer | 220 |
| HR Onboarding Workflow Tool | Portal UI and workflow screens | Frontend Engineer | 180 |
| HR Onboarding Workflow Tool | Prototype and usability review | Product Designer | 70 |
| Hospital Queue and Appointment System | Discovery, planning, client governance | Project Manager | 90 |
| Hospital Queue and Appointment System | UX flows and design system | Product Designer | 120 |
| Hospital Queue and Appointment System | Responsive dashboard and client portal | Frontend Engineer | 300 |
| Hospital Queue and Appointment System | API, data model, integrations, auth | Backend Engineer | 430 |
| Hospital Queue and Appointment System | Regression testing and UAT support | QA Engineer | 130 |
| Hotel Group Booking Microsites | Discovery, planning, client governance | Project Manager | 90 |
| Hotel Group Booking Microsites | UX flows and design system | Product Designer | 120 |
| Hotel Group Booking Microsites | Responsive dashboard and client portal | Frontend Engineer | 300 |
| Hotel Group Booking Microsites | API, data model, integrations, auth | Backend Engineer | 430 |
| Hotel Group Booking Microsites | Regression testing and UAT support | QA Engineer | 130 |
| Insurance Claims Mobile Back Office | Discovery and delivery planning | Project Manager | 55 |
| Insurance Claims Mobile Back Office | Integration and data services | Backend Engineer | 220 |
| Insurance Claims Mobile Back Office | Portal UI and workflow screens | Frontend Engineer | 180 |
| Insurance Claims Mobile Back Office | Prototype and usability review | Product Designer | 70 |
| Legacy ERP Rescue Assessment | Discovery and delivery planning | Project Manager | 55 |
| Legacy ERP Rescue Assessment | Integration and data services | Backend Engineer | 220 |
| Legacy ERP Rescue Assessment | Portal UI and workflow screens | Frontend Engineer | 180 |
| Legacy ERP Rescue Assessment | Prototype and usability review | Product Designer | 70 |
| Merchant Wallet Reconciliation Portal | Discovery, planning, client governance | Project Manager | 90 |
| Merchant Wallet Reconciliation Portal | UX flows and design system | Product Designer | 120 |
| Merchant Wallet Reconciliation Portal | Responsive dashboard and client portal | Frontend Engineer | 300 |
| Merchant Wallet Reconciliation Portal | API, data model, integrations, auth | Backend Engineer | 430 |
| Merchant Wallet Reconciliation Portal | Regression testing and UAT support | QA Engineer | 130 |
| Multilingual Partner Portal | Discovery and delivery planning | Project Manager | 55 |
| Multilingual Partner Portal | Integration and data services | Backend Engineer | 220 |
| Multilingual Partner Portal | Portal UI and workflow screens | Frontend Engineer | 180 |
| Multilingual Partner Portal | Prototype and usability review | Product Designer | 70 |
| Omnichannel Retail Commerce Relaunch | Discovery, planning, client governance | Project Manager | 90 |
| Omnichannel Retail Commerce Relaunch | UX flows and design system | Product Designer | 120 |
| Omnichannel Retail Commerce Relaunch | Responsive dashboard and client portal | Frontend Engineer | 300 |
| Omnichannel Retail Commerce Relaunch | API, data model, integrations, auth | Backend Engineer | 430 |
| Omnichannel Retail Commerce Relaunch | Regression testing and UAT support | QA Engineer | 130 |
| Retail Loyalty Data Mart | Discovery and delivery planning | Project Manager | 55 |
| Retail Loyalty Data Mart | Integration and data services | Backend Engineer | 220 |
| Retail Loyalty Data Mart | Portal UI and workflow screens | Frontend Engineer | 180 |
| Retail Loyalty Data Mart | Prototype and usability review | Product Designer | 70 |
| SaaS Customer Success Analytics | Discovery, planning, client governance | Project Manager | 90 |
| SaaS Customer Success Analytics | UX flows and design system | Product Designer | 120 |
| SaaS Customer Success Analytics | Responsive dashboard and client portal | Frontend Engineer | 300 |
| SaaS Customer Success Analytics | API, data model, integrations, auth | Backend Engineer | 430 |
| SaaS Customer Success Analytics | Regression testing and UAT support | QA Engineer | 130 |
| Tea Exporter B2B Ordering Portal | Discovery and delivery planning | Project Manager | 55 |
| Tea Exporter B2B Ordering Portal | Integration and data services | Backend Engineer | 220 |
| Tea Exporter B2B Ordering Portal | Portal UI and workflow screens | Frontend Engineer | 180 |
| Tea Exporter B2B Ordering Portal | Prototype and usability review | Product Designer | 70 |
| Tour Operator CRM and Quotation Tool | Discovery and delivery planning | Project Manager | 55 |
| Tour Operator CRM and Quotation Tool | Integration and data services | Backend Engineer | 220 |
| Tour Operator CRM and Quotation Tool | Portal UI and workflow screens | Frontend Engineer | 180 |
| Tour Operator CRM and Quotation Tool | Prototype and usability review | Product Designer | 70 |
| Warehouse Picking Optimization MVP | Discovery, planning, client governance | Project Manager | 90 |
| Warehouse Picking Optimization MVP | UX flows and design system | Product Designer | 120 |
| Warehouse Picking Optimization MVP | Responsive dashboard and client portal | Frontend Engineer | 300 |
| Warehouse Picking Optimization MVP | API, data model, integrations, auth | Backend Engineer | 430 |
| Warehouse Picking Optimization MVP | Regression testing and UAT support | QA Engineer | 130 |
| Wholesale Inventory Mobile App | Discovery and delivery planning | Project Manager | 55 |
| Wholesale Inventory Mobile App | Integration and data services | Backend Engineer | 220 |
| Wholesale Inventory Mobile App | Portal UI and workflow screens | Frontend Engineer | 180 |
| Wholesale Inventory Mobile App | Prototype and usability review | Product Designer | 70 |

> Note: Smoke Test — Backup Service has no `estimation_resources` rows (it was created via the Mark Contract Ready smoke test, not via the estimator).

---

## `deal_overheads` (38 rows)

Project-overhead lines shown in the Overheads table. Currency follows the deal's tenant.

| Deal | Overhead | Cost |
|---|---|---:|
| BCMM Profile Web | Mail Server Service | 100,000 |
| BCMM Profile Web | Testing Environment Hosting | 150,000 |
| Cross-Border Remittance Risk Dashboard | Solution workshop | 2,420,000 |
| Cross-Border Remittance Risk Dashboard | Prototype tooling | 1,936,000 |
| Event Ticketing Landing System | Solution workshop | 52,000 |
| Event Ticketing Landing System | Prototype tooling | 41,600 |
| Fintech Compliance Evidence Vault | Solution workshop | 286,000 |
| Fintech Compliance Evidence Vault | Prototype tooling | 228,800 |
| Food Delivery Campaign Microsite | Solution workshop | 260,000 |
| Food Delivery Campaign Microsite | Prototype tooling | 208,000 |
| HR Onboarding Workflow Tool | Solution workshop | 94,000 |
| HR Onboarding Workflow Tool | Prototype tooling | 75,200 |
| Hospital Queue and Appointment System | Cloud staging environment | 2,304,000 |
| Hospital Queue and Appointment System | Client workshop and training materials | 1,536,000 |
| Hotel Group Booking Microsites | Cloud staging environment | 1,116,000 |
| Hotel Group Booking Microsites | Client workshop and training materials | 744,000 |
| Insurance Claims Mobile Back Office | Solution workshop | 1,560,000 |
| Insurance Claims Mobile Back Office | Prototype tooling | 1,248,000 |
| Legacy ERP Rescue Assessment | Solution workshop | 480,000 |
| Legacy ERP Rescue Assessment | Prototype tooling | 384,000 |
| Merchant Wallet Reconciliation Portal | Cloud staging environment | 3,348,000 |
| Merchant Wallet Reconciliation Portal | Client workshop and training materials | 2,232,000 |
| Multilingual Partner Portal | Solution workshop | 132,000 |
| Multilingual Partner Portal | Prototype tooling | 105,600 |
| Omnichannel Retail Commerce Relaunch | Cloud staging environment | 1,764,000 |
| Omnichannel Retail Commerce Relaunch | Client workshop and training materials | 1,176,000 |
| Retail Loyalty Data Mart | Solution workshop | 1,180,000 |
| Retail Loyalty Data Mart | Prototype tooling | 944,000 |
| SaaS Customer Success Analytics | Cloud staging environment | 441,000 |
| SaaS Customer Success Analytics | Client workshop and training materials | 294,000 |
| Tea Exporter B2B Ordering Portal | Solution workshop | 860,000 |
| Tea Exporter B2B Ordering Portal | Prototype tooling | 688,000 |
| Tour Operator CRM and Quotation Tool | Solution workshop | 540,000 |
| Tour Operator CRM and Quotation Tool | Prototype tooling | 432,000 |
| Warehouse Picking Optimization MVP | Cloud staging environment | 302,400 |
| Warehouse Picking Optimization MVP | Client workshop and training materials | 201,600 |
| Wholesale Inventory Mobile App | Solution workshop | 720,000 |
| Wholesale Inventory Mobile App | Prototype tooling | 576,000 |

---

## `deal_ghost_roles` (80 rows) — Project Roles card

Read-only on /estimation; pre-fills `final_team_summary` when **Mark Contract Ready** opens. Currency follows the deal's tenant.

| Deal | Role Type | Qty | Months | Avg Salary | Min – Max |
|---|---|---:|---:|---:|---|
| BCMM Profile Web | backend | 1 | 3 | 2,000,000 | 1,900,000 – 2,100,000 |
| BCMM Profile Web | pm | 1 | 3 | 1,600,000 | 1,450,000 – 1,750,000 |
| Cross-Border Remittance Risk Dashboard | backend | 1 | 100 | 2,350,000 | 1,900,000 – 2,800,000 |
| Cross-Border Remittance Risk Dashboard | design | 1 | 100 | 1,650,000 | 1,650,000 – 1,650,000 |
| Cross-Border Remittance Risk Dashboard | frontend | 1 | 100 | 1,850,000 | 1,850,000 – 1,850,000 |
| Cross-Border Remittance Risk Dashboard | pm | 1 | 100 | 1,925,000 | 1,450,000 – 2,400,000 |
| Event Ticketing Landing System | backend | 1 | 100 | 760,000 | 640,000 – 880,000 |
| Event Ticketing Landing System | design | 1 | 100 | 610,000 | 610,000 – 610,000 |
| Event Ticketing Landing System | frontend | 1 | 100 | 630,000 | 630,000 – 630,000 |
| Event Ticketing Landing System | pm | 1 | 100 | 640,000 | 560,000 – 720,000 |
| Fintech Compliance Evidence Vault | backend | 1 | 100 | 760,000 | 640,000 – 880,000 |
| Fintech Compliance Evidence Vault | design | 1 | 100 | 610,000 | 610,000 – 610,000 |
| Fintech Compliance Evidence Vault | frontend | 1 | 100 | 630,000 | 630,000 – 630,000 |
| Fintech Compliance Evidence Vault | pm | 1 | 100 | 640,000 | 560,000 – 720,000 |
| Food Delivery Campaign Microsite | backend | 1 | 100 | 1,925,000 | 1,550,000 – 2,300,000 |
| Food Delivery Campaign Microsite | design | 1 | 100 | 1,500,000 | 1,500,000 – 1,500,000 |
| Food Delivery Campaign Microsite | frontend | 1 | 100 | 1,620,000 | 1,620,000 – 1,620,000 |
| Food Delivery Campaign Microsite | pm | 1 | 100 | 1,600,000 | 1,300,000 – 1,900,000 |
| HR Onboarding Workflow Tool | backend | 1 | 100 | 760,000 | 640,000 – 880,000 |
| HR Onboarding Workflow Tool | design | 1 | 100 | 610,000 | 610,000 – 610,000 |
| HR Onboarding Workflow Tool | frontend | 1 | 100 | 630,000 | 630,000 – 630,000 |
| HR Onboarding Workflow Tool | pm | 1 | 100 | 640,000 | 560,000 – 720,000 |
| Hospital Queue and Appointment System | backend | 2 | 100 | 2,350,000 | 1,900,000 – 2,800,000 |
| Hospital Queue and Appointment System | design | 1 | 100 | 1,650,000 | 1,650,000 – 1,650,000 |
| Hospital Queue and Appointment System | frontend | 1 | 100 | 1,850,000 | 1,850,000 – 1,850,000 |
| Hospital Queue and Appointment System | pm | 1 | 100 | 1,925,000 | 1,450,000 – 2,400,000 |
| Hospital Queue and Appointment System | qa | 1 | 100 | 1,200,000 | 1,200,000 – 1,200,000 |
| Hotel Group Booking Microsites | backend | 2 | 100 | 1,925,000 | 1,550,000 – 2,300,000 |
| Hotel Group Booking Microsites | design | 1 | 100 | 1,500,000 | 1,500,000 – 1,500,000 |
| Hotel Group Booking Microsites | frontend | 1 | 100 | 1,620,000 | 1,620,000 – 1,620,000 |
| Hotel Group Booking Microsites | pm | 1 | 100 | 1,600,000 | 1,300,000 – 1,900,000 |
| Hotel Group Booking Microsites | qa | 1 | 100 | 1,050,000 | 1,050,000 – 1,050,000 |
| Insurance Claims Mobile Back Office | backend | 1 | 100 | 2,350,000 | 1,900,000 – 2,800,000 |
| Insurance Claims Mobile Back Office | design | 1 | 100 | 1,650,000 | 1,650,000 – 1,650,000 |
| Insurance Claims Mobile Back Office | frontend | 1 | 100 | 1,850,000 | 1,850,000 – 1,850,000 |
| Insurance Claims Mobile Back Office | pm | 1 | 100 | 1,925,000 | 1,450,000 – 2,400,000 |
| Legacy ERP Rescue Assessment | backend | 1 | 100 | 2,350,000 | 1,900,000 – 2,800,000 |
| Legacy ERP Rescue Assessment | design | 1 | 100 | 1,650,000 | 1,650,000 – 1,650,000 |
| Legacy ERP Rescue Assessment | frontend | 1 | 100 | 1,850,000 | 1,850,000 – 1,850,000 |
| Legacy ERP Rescue Assessment | pm | 1 | 100 | 1,925,000 | 1,450,000 – 2,400,000 |
| Merchant Wallet Reconciliation Portal | backend | 2 | 100 | 2,350,000 | 1,900,000 – 2,800,000 |
| Merchant Wallet Reconciliation Portal | design | 1 | 100 | 1,650,000 | 1,650,000 – 1,650,000 |
| Merchant Wallet Reconciliation Portal | frontend | 1 | 100 | 1,850,000 | 1,850,000 – 1,850,000 |
| Merchant Wallet Reconciliation Portal | pm | 1 | 100 | 1,925,000 | 1,450,000 – 2,400,000 |
| Merchant Wallet Reconciliation Portal | qa | 1 | 100 | 1,200,000 | 1,200,000 – 1,200,000 |
| Multilingual Partner Portal | backend | 1 | 100 | 760,000 | 640,000 – 880,000 |
| Multilingual Partner Portal | design | 1 | 100 | 610,000 | 610,000 – 610,000 |
| Multilingual Partner Portal | frontend | 1 | 100 | 630,000 | 630,000 – 630,000 |
| Multilingual Partner Portal | pm | 1 | 100 | 640,000 | 560,000 – 720,000 |
| Omnichannel Retail Commerce Relaunch | backend | 2 | 100 | 1,925,000 | 1,550,000 – 2,300,000 |
| Omnichannel Retail Commerce Relaunch | design | 1 | 100 | 1,500,000 | 1,500,000 – 1,500,000 |
| Omnichannel Retail Commerce Relaunch | frontend | 1 | 100 | 1,620,000 | 1,620,000 – 1,620,000 |
| Omnichannel Retail Commerce Relaunch | pm | 1 | 100 | 1,600,000 | 1,300,000 – 1,900,000 |
| Omnichannel Retail Commerce Relaunch | qa | 1 | 100 | 1,050,000 | 1,050,000 – 1,050,000 |
| Retail Loyalty Data Mart | backend | 1 | 100 | 1,925,000 | 1,550,000 – 2,300,000 |
| Retail Loyalty Data Mart | design | 1 | 100 | 1,500,000 | 1,500,000 – 1,500,000 |
| Retail Loyalty Data Mart | frontend | 1 | 100 | 1,620,000 | 1,620,000 – 1,620,000 |
| Retail Loyalty Data Mart | pm | 1 | 100 | 1,600,000 | 1,300,000 – 1,900,000 |
| SaaS Customer Success Analytics | backend | 2 | 100 | 760,000 | 640,000 – 880,000 |
| SaaS Customer Success Analytics | design | 1 | 100 | 610,000 | 610,000 – 610,000 |
| SaaS Customer Success Analytics | frontend | 1 | 100 | 630,000 | 630,000 – 630,000 |
| SaaS Customer Success Analytics | pm | 1 | 100 | 640,000 | 560,000 – 720,000 |
| SaaS Customer Success Analytics | qa | 1 | 100 | 470,000 | 470,000 – 470,000 |
| Tea Exporter B2B Ordering Portal | backend | 1 | 100 | 2,350,000 | 1,900,000 – 2,800,000 |
| Tea Exporter B2B Ordering Portal | design | 1 | 100 | 1,650,000 | 1,650,000 – 1,650,000 |
| Tea Exporter B2B Ordering Portal | frontend | 1 | 100 | 1,850,000 | 1,850,000 – 1,850,000 |
| Tea Exporter B2B Ordering Portal | pm | 1 | 100 | 1,925,000 | 1,450,000 – 2,400,000 |
| Tour Operator CRM and Quotation Tool | backend | 1 | 100 | 1,925,000 | 1,550,000 – 2,300,000 |
| Tour Operator CRM and Quotation Tool | design | 1 | 100 | 1,500,000 | 1,500,000 – 1,500,000 |
| Tour Operator CRM and Quotation Tool | frontend | 1 | 100 | 1,620,000 | 1,620,000 – 1,620,000 |
| Tour Operator CRM and Quotation Tool | pm | 1 | 100 | 1,600,000 | 1,300,000 – 1,900,000 |
| Warehouse Picking Optimization MVP | backend | 2 | 100 | 760,000 | 640,000 – 880,000 |
| Warehouse Picking Optimization MVP | design | 1 | 100 | 610,000 | 610,000 – 610,000 |
| Warehouse Picking Optimization MVP | frontend | 1 | 100 | 630,000 | 630,000 – 630,000 |
| Warehouse Picking Optimization MVP | pm | 1 | 100 | 640,000 | 560,000 – 720,000 |
| Warehouse Picking Optimization MVP | qa | 1 | 100 | 470,000 | 470,000 – 470,000 |
| Wholesale Inventory Mobile App | backend | 1 | 100 | 1,925,000 | 1,550,000 – 2,300,000 |
| Wholesale Inventory Mobile App | design | 1 | 100 | 1,500,000 | 1,500,000 – 1,500,000 |
| Wholesale Inventory Mobile App | frontend | 1 | 100 | 1,620,000 | 1,620,000 – 1,620,000 |
| Wholesale Inventory Mobile App | pm | 1 | 100 | 1,600,000 | 1,300,000 – 1,900,000 |

> The 100-month durations on most rows look like seed defaults — they are not realistic delivery windows.

---

## `estimation_versions` (19 rows) — Save Version snapshots

One row per Save Version click. `res_count` and `ovh_count` are JSON array lengths in the snapshot (independent of the deal's current `estimation_resources` rows).

| Deal | v | Margin | Notes | Resources | Overheads | Created |
|---|---:|---:|---|---:|---:|---|
| Hospital Queue and Appointment System | 1 | 32 | Initial customer-ready estimate used for demo scenario. | 5 | 2 | 2025-12-26 |
| Hotel Group Booking Microsites | 1 | 32 | Initial customer-ready estimate used for demo scenario. | 5 | 2 | 2026-01-04 |
| Warehouse Picking Optimization MVP | 1 | 32 | Initial customer-ready estimate used for demo scenario. | 5 | 2 | 2026-01-08 |
| Omnichannel Retail Commerce Relaunch | 1 | 32 | Initial customer-ready estimate used for demo scenario. | 5 | 2 | 2026-01-22 |
| SaaS Customer Success Analytics | 1 | 32 | Initial customer-ready estimate used for demo scenario. | 5 | 2 | 2026-01-31 |
| Merchant Wallet Reconciliation Portal | 1 | 32 | Initial customer-ready estimate used for demo scenario. | 5 | 2 | 2026-02-04 |
| Event Ticketing Landing System | 1 | 30 | Initial customer-ready estimate used for demo scenario. | 4 | 2 | 2026-03-24 |
| Legacy ERP Rescue Assessment | 1 | 30 | Initial customer-ready estimate used for demo scenario. | 4 | 2 | 2026-03-29 |
| Food Delivery Campaign Microsite | 1 | 30 | Initial customer-ready estimate used for demo scenario. | 4 | 2 | 2026-04-02 |
| **BCMM Profile Web** | **1** | **30** | **Estimate calculated.** | **16** | **2** | **2026-05-16 03:36:10** |
| Tea Exporter B2B Ordering Portal | 1 | 30 | Initial customer-ready estimate used for demo scenario. | 4 | 2 | 2026-05-21 |
| Retail Loyalty Data Mart | 1 | 30 | Initial customer-ready estimate used for demo scenario. | 4 | 2 | 2026-05-27 |
| Cross-Border Remittance Risk Dashboard | 1 | 30 | Initial customer-ready estimate used for demo scenario. | 4 | 2 | 2026-06-01 |
| Multilingual Partner Portal | 1 | 30 | Initial customer-ready estimate used for demo scenario. | 4 | 2 | 2026-06-04 |
| Wholesale Inventory Mobile App | 1 | 30 | Initial customer-ready estimate used for demo scenario. | 4 | 2 | 2026-06-06 |
| Fintech Compliance Evidence Vault | 1 | 30 | Initial customer-ready estimate used for demo scenario. | 4 | 2 | 2026-06-14 |
| Tour Operator CRM and Quotation Tool | 1 | 30 | Initial customer-ready estimate used for demo scenario. | 4 | 2 | 2026-06-18 |
| HR Onboarding Workflow Tool | 1 | 30 | Initial customer-ready estimate used for demo scenario. | 4 | 2 | 2026-07-01 |
| Insurance Claims Mobile Back Office | 1 | 30 | Initial customer-ready estimate used for demo scenario. | 4 | 2 | 2026-07-08 |

> The bolded BCMM Profile Web row is the only version that wasn't part of the demo seed — it has 16 resources because it includes the AI sentinel rows (`_sheet1_summary`, `_sheet5_team_stack`) plus 14 real scope rows from a Generate-with-AI run.

---

## `deal_contract_documents` (3 rows)

These are the only documents available to the AI Generate prompt. **All three are `rejected`**, so they are NOT included in the prompt context (the controller filters to `analysis_status IN ('approved','pending')`).

| Deal | Filename | Ext | Size | Status | Analyzed At |
|---|---|---|---:|---|---|
| Retail Loyalty Data Mart | mandalay-mart-draft-contract.pdf | pdf | 412,300 | rejected | 2026-05-11 17:01:22 |
| Fintech Compliance Evidence Vault | shinjuku-payments-draft-contract.pdf | pdf | 412,300 | rejected | 2026-05-11 17:01:25 |
| Cross-Border Remittance Risk Dashboard | MYANMAR YAZAKI THILAWA agreement_software_FileSever_20250305.pdf | pdf | 1,351,426 | rejected | 2026-05-14 06:55:18 |

---

## Reference: `roles` (24 rows)

Per-tenant role catalog. `rate` is the billable rate; estimator falls back to `rate × cost_to_bill_ratio` when no employees match.

### Yangon Digital Works (`019e2249-0c52…`)
| Title | Rate |
|---|---:|
| Account Director | 95,000 |
| Backend Engineer | 76,000 |
| Finance Manager | 52,000 |
| Frontend Engineer | 68,000 |
| Product Designer | 62,000 |
| Project Manager | 70,000 |
| QA Engineer | 45,000 |
| Solution Architect | 90,000 |

### Mandalay Studio Co (`019e2249-1d99…`)
| Title | Rate |
|---|---:|
| Account Director | 82,000 |
| Backend Engineer | 64,000 |
| Finance Manager | 48,000 |
| Frontend Engineer | 60,000 |
| Product Designer | 56,000 |
| Project Manager | 62,000 |
| QA Engineer | 42,000 |
| Solution Architect | 78,000 |

### Tokyo Product Lab (`019e2249-30ba…`)
| Title | Rate |
|---|---:|
| Account Director | 14,000 |
| Backend Engineer | 12,500 |
| Finance Manager | 9,000 |
| Frontend Engineer | 11,500 |
| Product Designer | 10,800 |
| Project Manager | 11,800 |
| QA Engineer | 8,200 |
| Solution Architect | 15,000 |

---

## Reference: `employees` (27 rows)

Per-tenant employees with `cost_per_hour` (= `monthly_salary / workable_hours`). The estimator takes the **median** `cost_per_hour` across active employees in the matching role.

### Yangon Digital Works
| Name | Role | Monthly Salary | Hours/mo | Cost/hr |
|---|---|---:|---:|---:|
| Aung Kyaw Min | Solution Architect | 2,800,000 | 160 | 17,500.00 |
| Mya Thandar | Account Director | 2,400,000 | 160 | 15,000.00 |
| Htet Wai Yan | Backend Engineer | 2,100,000 | 160 | 13,125.00 |
| Nyein Chan Ko | Backend Engineer | 1,900,000 | 160 | 11,875.00 |
| Su Hnin Wai | Frontend Engineer | 1,850,000 | 160 | 11,562.50 |
| Ei Mon Khaing | Project Manager | 1,750,000 | 160 | 10,937.50 |
| May Zin Htun | Product Designer | 1,650,000 | 152 | 10,855.26 |
| Thet Htar Oo | Finance Manager | 1,450,000 | 152 | 9,539.47 |
| Ko Pyae Sone | QA Engineer | 1,200,000 | 160 | 7,500.00 |

### Mandalay Studio Co
| Name | Role | Monthly Salary | Hours/mo | Cost/hr |
|---|---|---:|---:|---:|
| Soe Myint Naing | Solution Architect | 2,300,000 | 160 | 14,375.00 |
| Win Thiri | Account Director | 1,900,000 | 160 | 11,875.00 |
| Paing Sithu | Backend Engineer | 1,700,000 | 160 | 10,625.00 |
| Thinzar Lwin | Frontend Engineer | 1,620,000 | 160 | 10,125.00 |
| Nandar Win | Project Manager | 1,600,000 | 160 | 10,000.00 |
| Ye Htet Aung | Backend Engineer | 1,550,000 | 160 | 9,687.50 |
| Phyu Phyu Kyaw | Product Designer | 1,500,000 | 152 | 9,868.42 |
| Hnin Yu Mon | Finance Manager | 1,300,000 | 152 | 8,552.63 |
| Kaung Htet | QA Engineer | 1,050,000 | 160 | 6,562.50 |

### Tokyo Product Lab
| Name | Role | Monthly Salary | Hours/mo | Cost/hr |
|---|---|---:|---:|---:|
| Daichi Tanaka | Solution Architect | 880,000 | 160 | 5,500.00 |
| Haruka Sato | Account Director | 720,000 | 160 | 4,500.00 |
| Kenji Mori | Backend Engineer | 690,000 | 160 | 4,312.50 |
| Yui Watanabe | Project Manager | 660,000 | 160 | 4,125.00 |
| Yuto Kobayashi | Backend Engineer | 640,000 | 160 | 4,000.00 |
| Aiko Nakamura | Frontend Engineer | 630,000 | 160 | 3,937.50 |
| Mika Ito | Product Designer | 610,000 | 152 | 4,013.16 |
| Naoko Suzuki | Finance Manager | 560,000 | 152 | 3,684.21 |
| Riku Yamamoto | QA Engineer | 470,000 | 160 | 2,937.50 |

---

## Reference: `company_settings` (3 rows — one per tenant)

Drives the cost roll-up: company overhead %, risk buffer %, and cost-to-bill / fallback rates.

| Tenant | Overhead % | Buffer % | Cost:Bill | Default Capacity (h/mo) | Fallback /hr |
|---|---:|---:|---:|---:|---:|
| Yangon Digital Works | 22 | 9 | 0.42 | 160 | 32,000 |
| Mandalay Studio Co | 20 | 10 | 0.44 | 160 | 28,000 |
| Tokyo Product Lab | 24 | 8 | 0.48 | 160 | 6,500 |
