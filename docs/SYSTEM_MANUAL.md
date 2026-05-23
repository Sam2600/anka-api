# ANKA — User Manual

> For end users. The principle to remember on every screen: **AI prepares the materials; you decide.**

---

## 1. AI at a glance **[AI]**

ANKA has four AI helpers built in. They draft, recommend, and explain — but never act for you. Every AI output sits on a page where you can review, edit, or reject it before it becomes part of your business record.

### When you do X, AI does Y, and you should check Z

| You're doing… | What AI does for you **[AI]** | What you should check before accepting |
|---|---|---|
| Pricing a new deal | **AI Team Builder** proposes a team + hours + cost + estimated margin from your scope text | The recommended team uses people you'd actually staff. The proposed hours match the customer's expectation. The margin is honest about overhead. |
| Drafting the contract | **Contract Draft** writes the 10 standard sections in plain contract English | Slot tokens like `{{TODO: data tier}}` are filled. Fees and OT terms match what you agreed verbally. Cancellation + termination match your standard policy. |
| Starting delivery on a won deal | **AI Auto-Assign** proposes who works on which task, when | Holidays + leave are respected. Nobody is double-booked. The team you actually have agrees with the plan. |
| Looking at the agency's health | **AI Forecast Summary** names the bleeding project, the stalled deal, the overloaded person | The diagnoses match what your PMs say privately. Suggested actions are realistic. |
| Stuck on a screen | **ANKA Assistant** explains how the screen works and what to do next | The answer matches your tenant's setup (it shouldn't reference features you don't have). |

**The product principle, stated plainly: AI prepares the materials. You decide.** No AI helper can finalise a contract, approve a time entry, recognise revenue, or sign anything. Every commit step is your click.

---

## 2. The four AI helpers **[AI]**

A dedicated chapter on how to invoke each one, what it gives back, and what to verify.

### 2.1 AI Team Builder **[AI]**

**What it's for**: Turn a workload description and a budget into a concrete team proposal with estimated cost, sell price, and margin. Use it when sales has a fresh deal and you want a first staffing pass without doing the math by hand.

**How to invoke**:
- Open `Estimation` from the sidebar.
- Pick the deal you're estimating from the dropdown.
- Fill the deal's budget, timeline, and workload description if not already populated.
- Click **Build AI Team & Estimate** in the AI Project Planner card.

Alternative entry point: on a rank-C (lead) or rank-B (qualified) deal, the secondary **Rebuild AI Team** button on the same card lets you re-run after you've refined the scope.

**What it gives you back**:
- A table of proposed roles with quantity, months, hours, monthly salary band, and estimated cost.
- A labour-cost basis, a suggested client price (3× loaded cost), and a margin %.
- A short reasoning paragraph explaining the team shape.
- Yellow warnings if the quote exceeds the budget or the project complexity doesn't fit the time window.

**What to check before clicking Accept Roles**:
- Are the proposed roles ones your bench can actually cover? (Especially singletons — if only one person can do that QA role, your delivery exposure is real.)
- Does the suggested price exceed the customer's known budget? If yes, AI usually flags it; trust the warning and renegotiate scope before pitching.
- Does the proposed margin make sense given any side deals or discounts you've offered? AI doesn't know about handshake commitments.

**Common reasons to override**:
- Customer asked specifically for a senior dev — AI may propose a mid + cost optimisation.
- You want to upskill a junior on the project — AI optimises for cost, not training.
- A team member is on personal leave that isn't yet in the holiday table.

When you click **Accept Roles**, the AI panel disappears and the role list gets saved to the deal. The deal is then ready for the Contract Draft step.

---

### 2.2 AI Auto-Assign **[AI]**

**What it's for**: Once a deal is won and a project exists, AI Auto-Assign distributes the project's task rows across the team with concrete `(planned_start, planned_end)` dates per phase, respecting holidays, working days, and individual workable hours.

**How to invoke**:
- Open `Time Tracking` from the sidebar.
- Pick the project from the Master Assign Table selector.
- Click **AI Task Assignment**.
- The preview dialog opens — review the proposed team mix.
- Confirm to write the schedule.

**What it gives you back**:
- A row-by-row plan: which engineer does which function in which phase (Design / Implementation / Testing), starting when and ending when.
- The Master Assign Table populates with status badges (`未着手` / `進行中` / `完了`) and editable date cells.

**What to check before accepting**:
- Are the assignees on the project's team? (AI is constrained to only propose people from `ProjectTeamAssignment`, but verify anyway.)
- Does the proposed timeline overshoot the project end date? If yes, the validator usually rejects, but if it slipped through, you've been warned.
- Do the phase orders within a row make sense (Design before Impl before Test)?

**Common reasons to override**:
- A specific function should go to a specific person for client-relationship reasons.
- You want a junior shadowing a senior on a particular task — AI won't propose that pairing unless you encode it as a separate row.
- A planned holiday isn't in the calendar yet.

After persist, you can drag cells in the Master Assign Table to retune dates manually. The AI plan is the starting point, not the final word.

---

### 2.3 Contract Draft **[AI]**

**What it's for**: Turn an estimated deal into a 10-section contract draft you can iterate on, send to the customer, and (when signed) upload back to flip the deal to won.

**How to invoke**:
- Open the deal from the Pipeline kanban (rank A — negotiation).
- Click **Generate Contract Draft** in the deal detail page (or via the kebab menu).
- The contract draft wizard opens with the relevant template.
- Fill any wizard inputs the template asks for (e.g. cloud platform, data tier, signatory names).
- Confirm to invoke AI.

**What it gives you back**:
- 10 sections rendered as contract prose: Description of Services, Services Provided, Scope of Work, Requirements, Calculation of Fees, Usage Period, Monitoring, Payment Policy (fixed text), Cancellation Fee (fixed text), Termination.
- Any unfilled slot tokens appear as `{{TODO: …}}` so you can spot them before sending.
- A version number (`v1`, `v2`, …) so iterations are tracked.

**What to check before sending to the customer**:
- Every `{{TODO}}` is resolved.
- Fees match what was verbally agreed.
- OT policy section matches your standard (no overtime allowed / customer pays per hour / etc.).
- Customer signatory name + title are filled (you can override per draft).
- Termination notice period matches what you'd accept in this contract.

**Sending**:
- Click **Send to customer** to email the draft. ANKA renders the contract as a PDF and emails it via Mailgun to the customer signatory captured in the wizard. The draft status flips to `sent_to_customer` only after the mail job is enqueued; you do not need to attach the PDF yourself.
- If the mail send fails (Mailgun outage, bad email), retry from the same screen — your draft text is preserved.
- When the signed PDF comes back, upload it via **Mark Signed**. This action atomically (a) stores the PDF, (b) sets the draft to `signed`, (c) runs the win-deal procedure to create the contract, project, and team.

**Common reasons to override AI's text**:
- Specific legal phrasing your finance team requires.
- A custom liability cap or payment-terms tweak.
- A non-standard clause you negotiated.

---

### 2.4 ANKA Assistant **[AI]**

**What it's for**: A help chatbot that knows how ANKA works — how to win a deal, how estimation and team-building connect, how time tracking feeds the P&L, how to use auto-assign. It's an in-context tutor.

**How to invoke**:
- Click the floating chat button (it lives wherever you last dragged it; default bottom-left).
- Type your question.
- The Assistant responds with an answer + cited knowledge sources (ANKA Help, General, etc.).

**What it gives you back**:
- A natural-language answer with one or two relevant knowledge entries cited.
- For procedural questions, a numbered list of steps.

**What to check before acting on its advice**:
- Make sure it's referring to the right tenant — the Assistant doesn't have line-of-sight to your real margin numbers, only to ANKA's general workflows. If it says "your Rakuten margin is X" — distrust it. If it says "to check your project margin, open Dashboard and look at Project Profit Comparison" — trust it.
- The Assistant should not reveal individual employee salaries or specific customer data. If it does, that's a bug — report it.

**Common situations to override**:
- The Assistant suggests a workflow that's faster on your screen than its written guidance. Trust your eyes.
- The Assistant doesn't know about a tenant-custom workflow (custom role permissions, etc.) — escalate to your Admin.

The chat panel can be repositioned anywhere on screen — drag the floating button. Position persists across sessions for your browser.

---

## 3. By role

The Admin/Executive/Sales/Delivery/HR split below is the default; tenants can customise role names and permissions.

### 3.1 Admin

**Purpose**: own the tenant — settings, users, roles, all data.

**Screens in usage order**:
1. `Tenant settings` — currency, signatory, target profit.
2. `Roles & permissions` — define what each role can do (drag permissions into Admin / Executive / Sales / Delivery / HR / custom).
3. `Users` — invite people; assign role + employee link.
4. `Organization` — departments, ranks, roles, skills.
5. `Employees` — roster, salaries, skills, salary history.
6. `Pipeline / Estimation / Contracts / Projects / Time tracking / Financial / Forecast` — full visibility.
7. `AI Usage admin` **[AI]** — see token + cost spend by feature, by month.

Admins can invoke every AI feature.

---

### 3.2 Executive

**Purpose**: read the agency's health and steer the strategy. Not in the day-to-day deal work.

**Screens in usage order**:
1. **Dashboard** — Current Realized Project Profit, Plan-to-Date, Variance, at-risk project count.
2. **Financial** — Monthly P&L (revenue, direct labour, overhead, profit). Use this to verify the Dashboard numbers and to see where the cost is.
3. **Forecast** — Year-end projection. Run **AI Forecast Summary** **[AI]** here.
4. `Pipeline` (view) — see what's coming.
5. `Projects` (view) — drill into specific projects' margin + OT.

Executives invoke **AI Forecast Summary** and **ANKA Assistant**. They typically don't invoke Team Builder or Contract Draft (those are Sales work).

---

### 3.3 Sales

**Purpose**: drive deals from lead to signed contract.

**Screens in usage order**:
1. **Pipeline (Kanban)** — drag deals across ranks C → B → A → S.
2. **New Deal** — capture lead.
3. **Deal Detail / Edit** — fill scope, customer requirements, expected close.
4. **Estimation** — pick the deal, invoke **AI Team Builder** **[AI]**, accept roles.
5. **Contract Draft** **[AI]** — generate sections, edit, send to customer, upload signed PDF.
6. **Forecast** (view) — see pipeline-weighted income.

Sales invokes **AI Team Builder** and **Contract Draft** routinely. The "Win Deal" moment isn't a button — it's the consequence of uploading the signed PDF on the Contract Draft page.

---

### 3.4 Delivery (PM / engineers / QA)

**Purpose**: run the won project — assign tasks, log time, report progress.

**Screens in usage order**:
1. **Projects** — see the project list (only assigned projects appear).
2. **Project Detail** — team, milestones, status.
3. **Time Tracking** — log hours, submit for approval. PMs additionally approve subordinates' entries.
4. **AI Task Assignment** **[AI]** (PMs) — auto-assign the task schedule for a project.
5. **My Schedule** — phase progress logs, daily progress vs used hours.
6. **ANKA Assistant** **[AI]** — for the inevitable "how does this work" moment.

PMs invoke **AI Auto-Assign**. Engineers don't invoke AI directly but consume the schedule the AI proposed.

---

### 3.5 HR

**Purpose**: manage employee data — hiring, salary changes, skills.

**Screens in usage order**:
1. **Employees** — list, add, edit. Set basic salary, allowance, workable hours, capacity role, rank.
2. **Employee Detail** — salary history, skills, current allocations.
3. **Ranks & Skills catalog** — adjust as needed.
4. **Departments** — set headcount, manager.

HR invokes **ANKA Assistant** for help. Other AI helpers are not part of the HR workflow.

---

## 3.6 Operational features (non-AI)

These are the supporting features around the AI flows — email send and data export. They are not AI-driven, but they're how AI outputs leave ANKA.

### 3.6.1 Sending things by email

ANKA sends mail for four moments. All four go through Mailgun and are queued — the UI button flips state, the worker delivers the message a few seconds later.

| You're doing… | Where to click | What goes out | Where it lands |
|---|---|---|---|
| Inviting a new user | Admin → Users → **+ New User** | Welcome email with a generated password + login URL. Auto-sent on save. | The user's inbox. They can change the password after first login. |
| Sending a contract draft to the customer | Contract Draft → **Send to customer** | The drafted contract rendered as PDF and attached. Recipient = the customer signatory captured in the wizard. | The customer's inbox. Status flips to `sent_to_customer`. |
| Sending an estimation to a stakeholder | Estimation → version row → **Send** | The estimation version's XLSX attached. You provide the recipient email in the dialog. | The recipient's inbox. |
| Sending an invoice | Invoices → invoice row → **Send** | The invoice PDF attached. Recipient = the customer billing contact. | The customer's inbox. Status flips to `Pending`. |

**What to verify before clicking Send**:
- Recipient email is the one you intend (typo-check; ANKA cannot recall a wrong send).
- The attachment shows the right version / month / total.
- For Contract Draft: every `{{TODO}}` is resolved before send.

**If Send fails**:
- The screen surfaces an error. The artefact (draft, version, invoice) remains in its previous status — nothing leaks halfway.
- Retry the same button after the underlying issue is fixed (most commonly: malformed recipient email, or a Mailgun outage).

**What ANKA does not send**: marketing campaigns, notifications about other people's deals, anything to addresses you didn't explicitly target. No auto-cc / auto-bcc.

### 3.6.2 Exporting data (CSV / XLSX)

Two pages let you take ANKA data offline.

| You're doing… | Where to click | File you get |
|---|---|---|
| Sharing a month-by-month P&L with finance | Financial → **Export CSV** | `pnl_statement.csv` — one row per month, columns for revenue, direct labour, overhead, profit. UTF-8, comma-separated. |
| Sharing an estimation with a customer or stakeholder | Estimation → version row → **Export XLSX** | `{dealName}_v{n}.xlsx` — 5-sheet workbook (functions, role mapping, phase split, overheads, sales projection). |

Both downloads are client-side for the CSV: nothing is logged to the server and no email is sent. If you want the customer to receive the XLSX, use the **Send** action on the same row instead — that delivers it via Mailgun with the file attached.

---

## 4. Run a deal end to end — the demo walkthrough

Sign in as Admin (full access) for the first run. Each step shows where AI fits in.

1. **Add a deal**. Pipeline → **+ New Deal**. Fill name (e.g. "Mercari payments rebuild"), client, contact, expected close date. Save. The deal lands in column **C (Lead)**.

2. **Capture scope + customer requirements**. Open the deal → **Edit**. Fill `workload_description` (a paragraph of what the customer wants), `client_budget`, `timeline_months`. Fill the four customer requirements:
   - **Customer support obligations** — what the customer must provide (test envs, credentials, named liaison).
   - **Out-of-scope policy** — what's NOT included.
   - **Working hours** — provider availability.
   - **Testing range** — browsers, OS, restore drills.
   Save.

3. **Build the team with AI Team Builder** **[AI]**. Open **Estimation** → select the deal → click **Build AI Team & Estimate**.
   - **What to verify**: the proposed roles match your bench. The estimated margin is in your target range. The warnings (yellow boxes) flag any budget/scope issue.
   - Click **Accept Roles**. The AI panel collapses. The deal is now rank B (Qualified).

4. **Lock final terms**. Back on the deal → **Edit final terms**: monthly fee, contract months, OT policy, team summary. Save. Once `final_confirmed_at` is set, the deal moves to rank A (Negotiation).

5. **Generate the contract with Contract Draft** **[AI]**. Deal kebab menu → **Generate Contract Draft**. Pick a template (cloud_backup / managed_hosting / engineer_dispatch). Fill wizard inputs (signatory names, cloud platform, etc.). Confirm.
   - **What to verify**: every `{{TODO}}` slot is filled. Fees match your verbal agreement. OT policy matches.
   - Edit any section inline as needed.
   - **Mark Sent to customer** when you email it.

6. **Customer signs**. They send back the signed PDF. Open the draft → **Mark Signed**. Upload the PDF.
   - **What happens behind the scenes**: ANKA atomically (a) saves the PDF, (b) marks the draft signed, (c) creates the Contract row, (d) creates the Project row, (e) copies team assignments from the estimation onto the project, (f) flips the deal to rank S (Won) with `won_at=now()`.
   - **What to verify**: a new contract is visible on `/contracts` with the right total value. A new project is visible on `/projects` with the right budget hours.

7. **Auto-assign the schedule with AI Auto-Assign** **[AI]**. Switch to a Delivery user (or stay Admin). Open **Time Tracking** → select the new project → **AI Task Assignment**.
   - The preview dialog shows the proposed (function × phase × assignee × date) plan.
   - **What to verify**: holidays respected, no double-bookings, phases in order (Design → Impl → Test).
   - Confirm to persist. The Master Assign Table populates.

8. **Log time**. Each team member opens **Time Tracking** → logs their hours per row + phase. **Submit**. The PM approves via the inbox. Approval atomically increments `consumed_hours` on the project.

9. **Log phase progress daily**. From the Master Assign Table, click a phase to log daily progress: `progress_hours` (work delivered) and `used_hours` (clock time). When `used > progress` → the row contributes OT.

10. **Accept milestones**. As deliverables complete, **Mark Accepted** on each milestone. This increments `revenue_recognized` on the contract.

11. **Issue + collect invoices**. Each month, issue the monthly invoice (`Draft`). Click **Send** on the invoice row — ANKA emails the customer the invoice PDF via Mailgun and flips the status to `Pending`. When the customer pays, mark **Paid**. The Financial page rolls revenue + cost into the monthly P&L; click **Export CSV** there to share the month-by-month numbers with finance.

12. **Read out the health**:
    - **Dashboard** — Current Realized Project Profit (cash − labour). At-risk badge if margin < target.
    - **Financial** — Monthly P&L line, with red months called out.
    - **Forecast** — full Jan–Dec view of pipeline income vs. company-payroll cost.
    - **AI Forecast Summary** **[AI]** — click **Regenerate Summary** to get named diagnoses:
      - Top: 1-line **headline** naming the bleeding project / stalled deal / overloaded person + a concrete number.
      - **⚠️ Project Alerts**: e.g. "Rakuten warehouse OS · critical · margin — Lifetime margin -6.6%; OT logged 120h Mar-May."
      - **👥 People Alerts**: e.g. "Hayashi Ren · warning · singleton — Sole QA on a 7-month engagement."
      - **📈 Pipeline Alerts**: e.g. "LINE chatbot integration · warning · stalled — 35 days in qualified."
    - **What to verify**: the alerts match what your PMs say off-record. Suggested actions are realistic.

### Reading the margin-risk and budget-burn views

- **Margin %** on Project Profit Comparison = `(budget − extrapolated lifetime cost) / budget × 100`. Negative = bleeding.
- **OT Impact** card = total `Σ (used − progress)` hours from phase logs × average loaded cost rate. Red if non-zero AND the deal's OT policy is `absorbed_by_provider`.
- **Budget pace** = `actualHours / (budgetHours × elapsedFraction)`. Over 1.0 means you're burning faster than planned.
- **Status auto-flip**: a project flips to `Over Budget` when `consumed_hours > budget_hours × 1.10`. The Dashboard's at-risk badge counts these.

---

## 5. Glossary

| Term | Plain meaning |
|---|---|
| **Tenant** | One agency's workspace. Your data, your team, your deals — separated from every other tenant. |
| **Deal** | A potential or actual customer engagement. Moves through ranks C (lead) → B (qualified) → A (negotiation) → S (won). |
| **Estimate** | The proposed team + hours + cost for a deal, before contract. Lives on the deal as ghost roles + hard assignments + estimation resources. |
| **Contract** | The signed agreement that locks total value, milestones, payment terms. Created only when the signed PDF is uploaded. |
| **Project** | The delivery side of a contract. Where time, schedule, and progress live. |
| **Milestone** | A billable checkpoint on a contract. Accepting one recognises revenue. |
| **Invoice** | A bill issued against a contract. Paying it collects cash. |
| **Margin** | Profit ÷ revenue, expressed as %. Positive = healthy. Negative = losing money. |
| **Burn** | The rate hours are being consumed against the budget. >100% pace = over budget. |
| **OT (overtime)** | Hours worked beyond planned. Detected from phase logs where `used_hours > progress_hours`. |
| **Revenue recognized** | Income you've earned (milestone accepted), regardless of whether you've been paid yet. |
| **Cash collected** | Money actually received (invoice paid). |
| **Loaded cost** | Per-hour cost including 15% company overhead. |
| **Sell price** | Per-hour rate quoted to the client (3× loaded cost, for IT staff). |
| **AI Team Builder [AI]** | The AI helper that proposes the team for a deal. |
| **AI Auto-Assign [AI]** | The AI helper that proposes the project's task schedule. |
| **Contract Draft [AI]** | The AI helper that drafts the 10 contract sections. |
| **ANKA Assistant [AI]** | The in-app chatbot. |
| **AI Forecast Summary [AI]** | The named-alerts panel on the Forecast page. |
| **"AI prepares, human decides"** | The product principle. Every AI output requires a human commit before it becomes business-of-record. |

---

## 6. FAQ

**Q. Can I trust the margin estimate?**

The estimated margin shown in AI Team Builder is computed from your tenant's actual employee salaries × hours, plus a 15% overhead, plus an optional buffer. The math is deterministic — it's the team mix that AI proposed. If you accept the team, the margin is accurate to within the salary data you provided. AI cannot estimate handshake discounts, side-letter terms, or future raises.

**Q. What if AI Auto-Assign picks the wrong person?**

You can edit any cell in the Master Assign Table after the AI plan is persisted — drag a date, change the assignee, change the phase order. The AI plan is your starting point, not the final word. The schedule validator will warn if your edits create a conflict (overlap, holiday, over-budget hours).

**Q. Is my project data sent to Claude?**

Yes — for the AI helpers to work, they need context:
- **AI Team Builder** sends your tenant's employee roster (names, capacity roles, salaries, skills) and the deal's scope text, budget, and timeline.
- **AI Auto-Assign** sends the project team + tasks + calendar.
- **Contract Draft** sends the deal's scope, customer requirements, and template ID.
- **ANKA Assistant** sends your question + retrieved knowledge base entries.
- **AI Forecast Summary** sends per-project margin/OT signals, per-deal stage info, capacity utilisation per role.

Anthropic does NOT use API data for model training (per their API policy). No customer-side personal data (end-customer names, end-customer PII, anything outside the agency) is included unless you've put it into your tenant's records. Every Claude call is logged in `ai_usage_logs` with tenant + user + tokens + cost.

**Q. What does the Assistant know about my tenant?**

The Assistant knows:
- Your tenant's general help knowledge base (procedural docs).
- The conversation history within the current chat session (last 10 messages).
- It does NOT have real-time access to your business data — it can't tell you today's Rakuten margin or specific employee salaries. It can tell you which page to open to see those numbers.

**Q. Can someone in another tenant see my AI Forecast or my team's salaries?**

No. Tenant isolation is enforced at two layers: HTTP middleware validates the `X-Tenant-ID` header against your session token, and every database query is automatically scoped to your `tenant_id`. AI prompts are constructed from already-scoped queries. The `ai_usage_logs` table is also tenant-scoped — only your tenant's admin sees your tenant's AI spend.

**Q. Why is the AI Forecast year-end profit so negative?**

Forecast uses *company-wide payroll* as the monthly cost line. If your active pipeline doesn't yet cover all of your team's salary for every month, the forecast will show a loss. That's the point — the AI surfaces the gap so you can either close more deals, reshape the team, or both. The Dashboard's *Realized Project Profit* uses a different denominator (only logged time entries) and typically shows a positive number even when the forecast is red.

**Q. What if Claude is down?**

- **ANKA Assistant** shows a static fallback message listing topics you can ask about.
- **AI Team Builder** falls back to a deterministic demo payload **only when the tenant is running in demo mode** (`ANKA_DEMO_MODE=1` AND no Claude key configured). Production tenants see a clear error message; they do not silently get a fake team.
- **Contract Draft, AI Auto-Assign, AI Forecast, AI Estimation Draft** fail closed with a clear error message — they don't fabricate a draft. The page stays usable for everything except the specific AI button.

**Q. How do I see how much AI is costing me?**

Admin → `/admin/ai-usage`. Tokens and estimated USD cost per feature per month. Per-call detail is in the underlying `ai_usage_logs` table (currently surfaced as totals; per-call detail is roadmap).

**Q. Can I customise the AI prompts?**

Not yet (roadmap). All prompts are hard-coded in the route/service files. Custom contract templates per tenant are also roadmap; today the 3 SES templates are global.

**Q. Is the Assistant translated?**

UI is translated to English, Japanese, and Vietnamese. The Assistant responds in your UI language for procedural questions but may slip into English for technical terms. Korean, Burmese, and Khmer translations are on the roadmap.
