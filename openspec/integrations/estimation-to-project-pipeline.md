# Integration contract: Estimation menu → Project Pipeline menu

**Owner of this doc:** Project Pipeline (③ Nego + ⑤ Contract)
**Audience:** Estimation menu (④) developer
**Status:** v1 — backend Phase B fields are live as of 2026-05-15
**Paired changes:** [anka-api chg-011](../changes/chg-011-project-pipeline-and-contract-drafting.yaml), [anka-frontend chg-009](../../../anka-frontend/openspec/changes/chg-009-project-pipeline-rewrite.yaml)

---

## What this is for

The manager's "System Flow — ANKA" spec splits the deal lifecycle into eight numbered processes. Estimation (④) feeds Project Pipeline (③ + ⑤). When your menu finishes calculating an estimate and the customer confirms it, you must populate specific fields on the `deals` table. Project Pipeline reads those fields when AI-drafting the customer contract.

If those fields are missing, my menu cannot draft a contract — the `[Generate Contract Draft]` button stays disabled with a "waiting on estimation" message. So this is hard dependency on your side.

---

## Required fields you must populate

Write these to the `deals` table when the customer **confirms** the estimate (not when calc starts — only when the customer says "yes, go to contract").

| Column | Type | Required | Description |
|---|---|---|---|
| `final_monthly_fee` | `decimal(14,2)` | ✓ | Monthly recurring fee in tenant currency |
| `final_installation_fee` | `decimal(14,2)` | nullable | One-time setup charge (goes into contract §5a) |
| `final_contract_months` | `int` | ✓ | Total contract length (goes into contract §6) |
| `final_ot_policy` | `text` | nullable | Free-form description of overage/OT pricing (e.g. "Support hours capped at 12/mo; over-hours charged at $80/hr") |
| `final_support_hours_per_month` | `int` | nullable | Monthly support cap (defaults to 12 in contract template if null) |
| `final_team_summary` | `text` | ✓ | Short prose of team structure delivered (e.g. "2 backend engineers + 1 QA, 4 mo. duration") |
| `final_currency` | `char(3)` | ✓ | ISO 4217 code (USD / MMK / JPY / SGD / etc.) |
| `final_confirmed_at` | `timestamp` | ✓ | Moment the customer accepted the estimate. **This is the trigger** — until this is set, my [Generate Contract] button is disabled. |
| `suggested_template_variant` | `varchar(64)` | nullable | Hint at which SES template variant fits. Allowed values listed below. |

### `suggested_template_variant` allowed values

Set this if your menu can infer the project type. If unset, my menu defaults to "Cloud Backup" and the salesperson can switch.

| Value | When to use |
|---|---|
| `cloud_backup` | Backup-as-a-service, storage management, data retention |
| `managed_hosting` | 24/7 server monitoring, cloud operations, SLA-based |
| `engineer_dispatch` | Traditional SES — N engineers assigned for X hours/month |

More variants will be added in v2. If you're unsure, leave null and the salesperson picks.

---

## How to write the fields

Use the existing `PATCH /api/deals/{deal_id}` endpoint. Send only the fields you're updating:

```http
PATCH /api/deals/abc-123-uuid
Content-Type: application/json
X-Tenant-ID: <tenant_uuid>
Authorization: Bearer <token>

{
  "final_monthly_fee": 5000.00,
  "final_installation_fee": 1500.00,
  "final_contract_months": 12,
  "final_ot_policy": "Support hours capped at 12/mo; over-hours at $80/hr",
  "final_support_hours_per_month": 12,
  "final_team_summary": "2 backend engineers + 1 QA, 4 month duration",
  "final_currency": "USD",
  "final_confirmed_at": "2026-05-14T10:00:00Z",
  "suggested_template_variant": "managed_hosting"
}
```

Status will be `200 OK` with the updated `DealResource` JSON.

### What about rank transitions?

You also need to flip the deal's `status` from `lead` (rank C) → `qualified` (rank B) when calc begins, since "B is during the estimation calculation process" per the spec.

Use the same PATCH:

```json
{ "status": "qualified" }
```

Don't try to set `status` to `negotiation` or `won` — those are my menu's triggers (contract drafted / contract signed). My middleware will 422 if you send them.

### Validation rules my menu enforces

Backend Phase B-breaking (later this sprint) will add a stricter rank state machine that rejects bad transitions with a 422. Specifically:
- `status` field: `lead → qualified` (yours), `qualified → negotiation` (mine), `negotiation → won` (mine). No skipping, no backward, no manual override.
- Once `status` is `negotiation` or `won`, all `final_*` fields are **locked** and writes return 422. So populate them **before** customer-confirms.

---

## When to do each write — the lifecycle

```
Customer lead arrives                            (someone else's menu, not yours)
  ↓
Deal created, status='lead', rank=C
  ↓
Your menu opens the estimation for this deal
  ↓
PATCH { status: 'qualified' }                    ← you flip rank C→B
  ↓
You calculate, iterate with customer, etc.
  ↓
Customer says "yes, go to contract"
  ↓
PATCH { final_*, final_confirmed_at, suggested_template_variant }
  ↓
[Generate Contract Draft] button now enabled in my menu
  ↓
Salesperson opens my wizard, generates contract
  ↓
(my menu sets status='negotiation', rank C→B→A)
  ↓
Customer signs contract
  ↓
(my menu sets status='won', rank A→S, fires win_deal())
```

Your menu's involvement ends at "customer confirms estimate". Anything after that is my menu's job.

---

## Edge cases

**Customer changes their mind during estimation.** Fine — deal stays at `status='qualified'`. You can update `final_*` fields as many times as needed. They are *not* locked at rank B. Lock only kicks in at A (negotiation).

**Customer asks for re-estimation after estimate was confirmed.** Two scenarios:
- If my menu hasn't generated a contract yet → the deal is still rank B, fields are still writable. Just PATCH new values.
- If my menu has already generated a contract → deal is at rank A, fields are locked. The salesperson must drop the deal and start a new one. You'll get a 422 on the PATCH attempt.

**Customer drops out during estimation.** Either side can call `POST /deals/{id}/drop` (endpoint shipping in Phase B-breaking). Sets `lifecycle_status='dropped'`, `dropped_at_stage='qualified'`. You can do this from your UI; we won't fight you for ownership.

**Field validation.**
- `final_monthly_fee` must be ≥ 0
- `final_contract_months` must be ≥ 1
- `final_currency` must be a known ISO code (we don't enforce against a whitelist at the DB level — your menu's UI should pick from a dropdown)
- `final_confirmed_at` must be ≤ `now()` — no future-dated confirmations

---

## What you should NOT do

1. **Don't write to the deal's `status` field except `lead → qualified`.** Other transitions are mine.
2. **Don't bypass `PATCH /deals/{id}`** with raw SQL writes — the field-locking guard runs in the controller, not at the DB level. Direct writes will skip the lock.
3. **Don't populate `final_*` fields before the customer has actually confirmed.** Doing so will enable the [Generate Contract] button prematurely. Set `final_confirmed_at` exactly when the customer says yes.
4. **Don't try to manage `lifecycle_status` or `dropped_at_stage` yourself when manipulating estimate state.** Use the `POST /deals/{id}/drop` endpoint when the deal exits.

---

## Quick sanity check queries

After your menu writes the fields, you can verify on the deal detail page that:

```sql
SELECT
  id, name, status, lifecycle_status,
  final_monthly_fee, final_contract_months, final_currency,
  final_confirmed_at, suggested_template_variant
FROM deals
WHERE id = '<your_deal_uuid>';
```

Required-field check (this is what my service-layer guard runs):

```sql
SELECT id, name,
  (final_monthly_fee IS NOT NULL
   AND final_contract_months IS NOT NULL
   AND final_team_summary IS NOT NULL
   AND final_currency IS NOT NULL
   AND final_confirmed_at IS NOT NULL) AS contract_eligible
FROM deals
WHERE status = 'qualified' AND lifecycle_status = 'active';
```

Or in tinker:

```php
$deal = \App\Models\Deal::find('<uuid>');
$deal->isContractEligible();        // true / false
$deal->missingEstimationFields();   // ['final_monthly_fee', ...] or []
$deal->rank;                         // 'C' | 'B' | 'A' | 'S' | 'Dropped'
$deal->isLocked();                   // true if rank ∈ {A, S}
```

---

## What I commit to on my side

- I won't read or write any `final_*` field until you've set `final_confirmed_at`.
- I won't change `status` while a deal is at rank B — that's your phase.
- I'll surface a clear "waiting on estimation" message in my UI when fields are missing, not a generic error.
- If I add more fields to this contract (new template variants, new wizard inputs), I'll update this doc and ping you before shipping.

---

## Open questions for you

These came up while writing this doc — answer at your convenience, they don't block the integration:

1. **Where does your menu store the team structure detail** (resource list, hours per role, etc.) that feeds `final_team_summary`? If it's already a structured table, my contract drafting AI could read it directly instead of relying on the prose summary — happy to coordinate.
2. **Currency conversion** — if the deal's `client_budget` is in MMK but you set `final_currency='USD'`, that's a tenant-level decision. Do you do FX in your menu, or just pass through whatever the salesperson typed?
3. **Re-estimation flow** — when a customer asks for changes mid-estimation, do you want my menu to wipe the previous `final_*` snapshot, or keep history? My current assumption is no history (latest wins). Tell me if you need history kept.

---

## Contact

Project Pipeline owner: **claude.max@brycenmyanmar.com.mm**
Slack / ping me anytime if a field semantics is unclear or you need a new one added.
