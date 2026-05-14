<?php

namespace App\Services;

use App\Models\ContractTemplate;
use App\Models\Deal;
use App\Models\DealContractDraft;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Generates AI-written contract drafts for a deal, then drives the
 * lifecycle: draft → sent → signed.
 *
 * The Claude prompt is built from the chosen ContractTemplate's `sections`
 * config + the wizard inputs (Path C) + the deal record. Claude returns
 * a structured JSON object keyed by each `ai_written` section's key.
 * `mergeSections()` combines those AI outputs with fixed sections and
 * slot-fills (deal/wizard values dropped into `{{slot}}` tokens). Any
 * gaps the AI couldn't fill confidently are left as `{{TODO: …}}` tokens
 * the salesperson resolves in wizard step 2.
 *
 * Resilience mirrors ContractAnalysisService: 90s timeout, retry on
 * ConnectionException only (don't retry response-timeouts — Claude is
 * working, let it finish).
 */
class ContractDraftService
{
    private const CLAUDE_MODEL_DEFAULT = 'claude-3-5-sonnet-latest';
    private const CLAUDE_BASE_URL_DEFAULT = 'https://api.anthropic.com';

    /** Default monthly support cap when the deal/wizard doesn't specify. */
    private const DEFAULT_SUPPORT_HOURS = 12;

    /**
     * Validate eligibility + run AI generation + persist the draft.
     * Fires the B→A rank transition on first successful generation.
     *
     * @param  array<string,mixed>  $wizardInputs Path C answers keyed by question key
     * @throws ValidationException 422 when the deal isn't contract-eligible
     * @throws \RuntimeException   when Claude is unreachable AND no fallback applies
     */
    public function generateDraft(
        Deal $deal,
        ContractTemplate $template,
        array $wizardInputs,
        ?User $generatedBy = null,
    ): DealContractDraft {
        $this->assertEligible($deal);
        $this->assertTemplateUsable($template);

        $aiOutputs = $this->callClaude($deal, $template, $wizardInputs);
        $merged = $this->mergeSections($template, $aiOutputs, $deal, $wizardInputs);

        return DB::transaction(function () use ($deal, $template, $aiOutputs, $merged, $wizardInputs, $generatedBy) {
            // Newer drafts supersede older ones — preserve history for audit.
            DealContractDraft::where('deal_id', $deal->id)
                ->where('status', DealContractDraft::STATUS_DRAFT)
                ->update(['status' => DealContractDraft::STATUS_SUPERSEDED]);

            $existingVersions = DealContractDraft::where('deal_id', $deal->id)->max('version') ?? 0;

            $draft = DealContractDraft::create([
                'tenant_id' => $deal->tenant_id,
                'deal_id' => $deal->id,
                'template_id' => $template->id,
                'template_version_at_generation' => $template->version,
                'status' => DealContractDraft::STATUS_DRAFT,
                'version' => $existingVersions + 1,
                'wizard_inputs' => $wizardInputs,
                'ai_outputs' => $aiOutputs,
                'sections' => $merged,
                'generated_by_user_id' => $generatedBy?->id,
            ]);

            // B → A: first draft generated. Honour the forward-only state
            // machine — refuse silently if the deal isn't transitionable.
            // Subsequent regenerations won't re-fire (already at A).
            if ($deal->canTransitionTo('negotiation')) {
                $deal->update(['status' => 'negotiation', 'win_probability' => Deal::RANK_PROBABILITY['A']]);
            }

            return $draft;
        });
    }

    /**
     * Replace one section's AI output with a fresh generation. Useful when
     * the user updates a wizard question and wants only that section
     * regenerated, not the whole draft.
     */
    public function regenerateSection(
        DealContractDraft $draft,
        string $sectionKey,
        array $newWizardInputs,
    ): DealContractDraft {
        if (! $draft->isEditable()) {
            throw ValidationException::withMessages([
                'status' => ['Only drafts in "draft" status can be regenerated. Current: '.$draft->status],
            ]);
        }

        $template = $draft->template;
        $deal = $draft->deal;

        if (! $template || ! $deal) {
            throw new \RuntimeException('Draft has no template or deal — corrupt row.');
        }

        $sectionConfig = collect($template->sections)
            ->firstWhere('key', $sectionKey);

        if (! $sectionConfig) {
            throw ValidationException::withMessages([
                'section_key' => ["Unknown section '{$sectionKey}' for template {$template->slug}."],
            ]);
        }

        // Only AI-written sections support regeneration; fixed/slot-only
        // sections have nothing for Claude to produce.
        if (! in_array($sectionConfig['type'] ?? '', ['ai_written', 'ai_with_slots'], true)) {
            throw ValidationException::withMessages([
                'section_key' => ["Section '{$sectionKey}' is not AI-written; no regeneration possible."],
            ]);
        }

        $mergedWizardInputs = array_merge($draft->wizard_inputs ?? [], $newWizardInputs);
        $allAi = $this->callClaude($deal, $template, $mergedWizardInputs, onlySectionKey: $sectionKey);

        $aiOutputs = array_merge($draft->ai_outputs ?? [], $allAi);
        $merged = $this->mergeSections($template, $aiOutputs, $deal, $mergedWizardInputs);

        $draft->update([
            'wizard_inputs' => $mergedWizardInputs,
            'ai_outputs' => $aiOutputs,
            'sections' => $merged,
        ]);

        return $draft->fresh();
    }

    /**
     * Apply user edits to a single section's rendered content. The wizard
     * step 2 calls this when the operator hand-edits AI output.
     */
    public function updateSectionContent(
        DealContractDraft $draft,
        string $sectionKey,
        string $newContent,
    ): DealContractDraft {
        if (! $draft->isEditable()) {
            throw ValidationException::withMessages([
                'status' => ['Only drafts in "draft" status can be edited.'],
            ]);
        }

        $sections = $draft->sections ?? [];
        $found = false;
        foreach ($sections as &$section) {
            if (($section['key'] ?? null) === $sectionKey) {
                $section['rendered'] = $newContent;
                $section['user_edited'] = true;
                $found = true;
                break;
            }
        }
        unset($section);

        if (! $found) {
            throw ValidationException::withMessages([
                'section_key' => ["Unknown section '{$sectionKey}' in this draft."],
            ]);
        }

        $draft->update(['sections' => $sections]);

        return $draft->fresh();
    }

    /**
     * Finalise the draft: mark ready-to-send. v1 just flips a flag; v2
     * will trigger DOCX rendering and persist the binary path.
     */
    public function finaliseDraft(DealContractDraft $draft, ?User $finalizedBy = null): DealContractDraft
    {
        if (! $draft->isEditable()) {
            throw ValidationException::withMessages([
                'status' => ['Draft is already '.$draft->status.'; cannot re-finalise.'],
            ]);
        }

        $draft->update([
            'finalized_by_user_id' => $finalizedBy?->id,
        ]);

        return $draft->fresh();
    }

    /**
     * Flip status to sent_to_customer. The actual email send is delegated
     * to ContractEmailService — this method only mutates the draft.
     */
    public function markSent(DealContractDraft $draft, string $email): DealContractDraft
    {
        if ($draft->isSigned() || $draft->status === DealContractDraft::STATUS_SUPERSEDED) {
            throw ValidationException::withMessages([
                'status' => ['Cannot send a draft that is '.$draft->status.'.'],
            ]);
        }

        $draft->update([
            'status' => DealContractDraft::STATUS_SENT,
            'sent_at' => now(),
            'sent_to_email' => $email,
        ]);

        return $draft->fresh();
    }

    /**
     * Counter-signed PDF uploaded. Persist the file, flip the draft to
     * signed, and fire A → S via the existing win_deal() stored procedure
     * (with PHP fallback for SQLite tests).
     *
     * @return array{document: DealContractDraft, auto_won: bool, contract: ?\App\Models\Contract}
     */
    public function markSigned(
        DealContractDraft $draft,
        UploadedFile $signedPdf,
        ?User $signedBy = null,
    ): array {
        if (! $draft->isSent()) {
            throw ValidationException::withMessages([
                'status' => ['Only drafts sent to the customer can be marked signed. Current: '.$draft->status],
            ]);
        }

        $tenantId = $draft->tenant_id;
        $storedPath = $signedPdf->storeAs(
            "contract-drafts/{$tenantId}",
            "{$draft->id}_signed.pdf",
            'local',
        );

        return DB::transaction(function () use ($draft, $storedPath, $signedBy) {
            $draft->update([
                'status' => DealContractDraft::STATUS_SIGNED,
                'signed_at' => now(),
                'signed_pdf_path' => $storedPath,
                'finalized_by_user_id' => $signedBy?->id ?? $draft->finalized_by_user_id,
            ]);

            $deal = $draft->deal;
            $autoWon = false;
            $contract = null;

            if ($deal && $deal->canTransitionTo('won')) {
                $this->fireWinDeal($deal);
                $autoWon = true;
                $contract = \App\Models\Contract::where('deal_id', $deal->id)->first();
            }

            return [
                'document' => $draft->fresh(),
                'auto_won' => $autoWon,
                'contract' => $contract,
            ];
        });
    }

    // ─── Internals ────────────────────────────────────────────────────────

    /**
     * Hard precondition checks. Returns nothing on success; throws 422 with
     * field-level errors on failure so the frontend can highlight what's
     * missing without a free-text dance.
     */
    private function assertEligible(Deal $deal): void
    {
        if ($deal->isDropped()) {
            throw ValidationException::withMessages([
                'deal' => ['This deal has been dropped — create a new deal to draft a contract.'],
            ]);
        }

        if ($deal->status !== 'qualified') {
            throw ValidationException::withMessages([
                'deal.status' => [
                    'Contract drafting requires the deal to be at rank B (qualified). Current rank: '.$deal->rank,
                ],
            ]);
        }

        $missing = $deal->missingEstimationFields();
        if (! empty($missing)) {
            throw ValidationException::withMessages([
                'estimation' => [
                    'Estimation handoff is incomplete. Missing: '.implode(', ', $missing)
                    . '. The Estimation menu must populate these before drafting.',
                ],
            ]);
        }
    }

    private function assertTemplateUsable(ContractTemplate $template): void
    {
        if (! $template->is_active) {
            throw ValidationException::withMessages([
                'template' => ['Template "'.$template->slug.'" is inactive.'],
            ]);
        }
    }

    /**
     * Build the prompt, call Claude, parse the JSON. Returns AI outputs keyed
     * by section.key. Falls back to a stub-output map when ANTHROPIC_API_KEY
     * is missing so the wizard remains usable in dev environments without a
     * live Claude proxy — every section becomes a {{TODO: …}} marker for the
     * operator to fill in step 2.
     *
     * @param  string|null  $onlySectionKey  If set, restrict the prompt + parsing to one section.
     * @return array<string,string>
     */
    private function callClaude(
        Deal $deal,
        ContractTemplate $template,
        array $wizardInputs,
        ?string $onlySectionKey = null,
    ): array {
        $apiKey = config('services.anthropic.api_key') ?? env('ANTHROPIC_API_KEY');
        $aiSections = collect($template->sections)
            ->filter(fn ($s) => in_array($s['type'] ?? '', ['ai_written', 'ai_with_slots'], true))
            ->when($onlySectionKey, fn ($c) => $c->where('key', $onlySectionKey))
            ->values()
            ->all();

        if (empty($aiSections)) {
            return [];
        }

        if (! $apiKey) {
            // Dev fallback: stub each AI section with a TODO marker so the
            // wizard renders something the operator can replace.
            Log::warning('ContractDraftService: ANTHROPIC_API_KEY missing, returning TODO stubs');
            return collect($aiSections)
                ->mapWithKeys(fn ($s) => [$s['key'] => '{{TODO: Claude unavailable in this environment — fill manually}}'])
                ->all();
        }

        $system = $this->buildSystemPrompt();
        $user = $this->buildUserPrompt($deal, $template, $wizardInputs, $aiSections);

        $baseUrl = config('services.anthropic.base_url') ?: self::CLAUDE_BASE_URL_DEFAULT;
        $model = config('services.anthropic.model') ?: self::CLAUDE_MODEL_DEFAULT;

        try {
            $response = Http::timeout(90)
                ->retry(2, 1000, fn (Throwable $e) => $e instanceof ConnectionException, throw: false)
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post($baseUrl.'/v1/messages', [
                    'model' => $model,
                    'max_tokens' => 4096,
                    'system' => $system,
                    'messages' => [
                        ['role' => 'user', 'content' => $user],
                    ],
                ]);
        } catch (Throwable $e) {
            Log::error('ContractDraftService: Claude call failed', ['error' => $e->getMessage()]);
            // Same fallback as missing key — TODO stubs so the wizard still works.
            return collect($aiSections)
                ->mapWithKeys(fn ($s) => [$s['key'] => '{{TODO: AI generation failed — '.$this->shortErrorMessage($e).'}}'])
                ->all();
        }

        if (! $response->successful()) {
            Log::error('ContractDraftService: Anthropic API non-2xx', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 300),
            ]);
            return collect($aiSections)
                ->mapWithKeys(fn ($s) => [$s['key'] => '{{TODO: AI returned HTTP '.$response->status().' — fill manually}}'])
                ->all();
        }

        $body = $response->json();
        $raw = trim($body['content'][0]['text'] ?? '');
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/', '', $raw);
        }

        $parsed = json_decode($raw, true);
        if (! is_array($parsed)) {
            Log::error('ContractDraftService: Claude returned non-JSON', ['raw' => substr($raw, 0, 300)]);
            return collect($aiSections)
                ->mapWithKeys(fn ($s) => [$s['key'] => '{{TODO: AI returned malformed output — fill manually}}'])
                ->all();
        }

        // Backfill any section Claude skipped with a TODO marker so the
        // merger never silently drops a section.
        $output = [];
        foreach ($aiSections as $section) {
            $key = $section['key'];
            $value = $parsed[$key] ?? null;
            $output[$key] = is_string($value) && $value !== ''
                ? $value
                : '{{TODO: AI did not produce content for this section — fill manually}}';
        }

        return $output;
    }

    /**
     * Build a human-readable OT/overage clause from the structured nego
     * fields, falling back to Estimation's freeform `final_ot_policy`
     * notes when the structured model isn't set. Empty → flag for TODO.
     */
    private function renderOtContext(Deal $deal): string
    {
        $currency = $deal->final_currency ?? 'USD';
        $model = $deal->ot_policy_model;
        $rate = $deal->ot_rate_per_hour;
        $capped = $deal->ot_included_hours_per_month;
        $notes = $deal->ot_notes;
        $estimationNotes = $deal->final_ot_policy;

        $structured = match ($model) {
            'customer_pays_per_hour' => $rate !== null
                ? sprintf('Customer pays for all overtime at %s %s/hour.', $currency, number_format((float) $rate, 2))
                : 'Customer pays for all overtime (rate not specified — flag as TODO).',
            'capped_then_customer_pays' => sprintf(
                'First %s hours/month of overtime are included; beyond that, customer pays at %s.',
                $capped ?? '(TODO: hours)',
                $rate !== null ? "{$currency} ".number_format((float) $rate, 2).'/hour' : '(TODO: rate)',
            ),
            'absorbed_by_provider' => 'Provider absorbs all overtime cost — no overage billing to customer.',
            'no_overtime_allowed' => 'No overtime work permitted under this contract.',
            default => '',
        };

        $parts = array_filter([
            $structured,
            $notes ? "Notes from nego: {$notes}" : null,
            $estimationNotes ? "Estimation notes: {$estimationNotes}" : null,
        ]);

        return empty($parts)
            ? '(not specified — flag as TODO)'
            : implode(' ', $parts);
    }

    private function buildSystemPrompt(): string
    {
        return <<<'TXT'
You are a contract drafting assistant for an agency that signs SES-style
service agreements with corporate customers. You will produce English-
language contract sections based on a template definition, a customer's
project requirements (the deal's Requirement Description), and
operator-provided answers from a structured wizard.

Critical rules:
1. Output strict JSON. The top-level object's keys are the section keys
   the user prompt lists; each value is a STRING containing the section's
   full rendered content. No prose outside the JSON object.
2. For any specific detail you cannot confidently fill from the inputs,
   emit the literal string "{{TODO: <what you need>}}" inline. Do not
   invent specifics (version numbers, exact thresholds, names, dates).
3. Use formal contract English. Prefer active voice. Use numbered or
   lettered lists where the template's output_format says bulleted_*.
   For bulleted_pair sections, format as two clearly-labelled lists
   (e.g., "Provider:\n  - …\n\nUser:\n  - …").
4. Do NOT include the section title in your output — the renderer adds it.
5. Never include snake_case field keys, internal variable names, or
   technical jargon in human-facing text. The customer reads this.
6. Slot tokens of the form {{some_name}} that already appear in the
   prompt's fixed_text examples are filled by the renderer later — do
   not duplicate them in your output unless explicitly requested.
7. Keep each section concise — sufficient legal detail, no padding. A
   reviewer should be able to skim a section and grasp the obligation.
TXT;
    }

    private function buildUserPrompt(
        Deal $deal,
        ContractTemplate $template,
        array $wizardInputs,
        array $aiSections,
    ): string {
        $requirementDescription = $deal->workload_description
            ? $deal->workload_description
            : '(no Requirement Description provided)';

        // Render the OT/overage section from the structured nego-time
        // fields. The freeform final_ot_policy (Estimation's notes layer)
        // is appended when present. ⑦ Profit Calculate reads the same
        // structured fields to decide whether to subtract OT from profit.
        $otContext = $this->renderOtContext($deal);

        $dealContext = sprintf(
            "Customer: %s\nProject name: %s\nClient budget: %s %s\nTimeline: %d months\nContract length: %d months\nTeam: %s\nMonthly fee: %s %s\nInstallation fee: %s\nOT/overage policy: %s\nSupport hours/month: %d\nCurrency: %s",
            $deal->client ?? '(unknown)',
            $deal->name ?? '(unnamed)',
            $deal->final_currency ?? 'USD',
            number_format((float) ($deal->client_budget ?? 0), 2),
            $deal->timeline_months ?? 0,
            $deal->final_contract_months ?? 0,
            $deal->final_team_summary ?? '(team not summarised)',
            $deal->final_currency ?? 'USD',
            number_format((float) ($deal->final_monthly_fee ?? 0), 2),
            $deal->final_installation_fee !== null
                ? $deal->final_currency.' '.number_format((float) $deal->final_installation_fee, 2)
                : '(none)',
            $otContext,
            $deal->final_support_hours_per_month ?? self::DEFAULT_SUPPORT_HOURS,
            $deal->final_currency ?? 'USD',
        );

        $wizardLines = empty($wizardInputs)
            ? '(no wizard answers provided)'
            : collect($wizardInputs)
                ->map(fn ($v, $k) => "- {$k}: ".(is_scalar($v) ? (string) $v : json_encode($v)))
                ->implode("\n");

        $sectionSpecs = collect($aiSections)->map(function ($section) {
            $questions = empty($section['wizard_questions'] ?? [])
                ? ''
                : "\n   Operator answers for this section: see WIZARD ANSWERS above; relevant keys: "
                    . collect($section['wizard_questions'])->pluck('key')->implode(', ');

            return sprintf(
                "- key: %s\n   title: %s\n   output_format: %s\n   prompt: %s%s",
                $section['key'],
                $section['title'] ?? '',
                $section['output_format'] ?? 'paragraph',
                $section['ai_prompt'] ?? '(no prompt provided)',
                $questions,
            );
        })->implode("\n\n");

        return <<<TXT
TEMPLATE: {$template->name} (umbrella: {$template->umbrella})

DEAL CONTEXT:
{$dealContext}

CUSTOMER REQUIREMENT DESCRIPTION:
"""
{$requirementDescription}
"""

WIZARD ANSWERS:
{$wizardLines}

SECTIONS TO PRODUCE (return one JSON key per section.key below):

{$sectionSpecs}

Return ONLY a JSON object. Example shape (with your actual section keys):
{
  "description_of_services": "Provider will deliver …",
  "scope_of_work": "Provider:\\n- Initial: …\\n- Monthly: …\\n\\nUser:\\n- Initial: …",
  ...
}
TXT;
    }

    /**
     * Build the final per-section merged payload. Each output row has:
     *   key, title, type, output_format, rendered, has_todo
     *
     * 'rendered' is what the renderer / UI shows. For fixed sections it's
     * the fixed_text with slot fills. For ai_written it's the AI output.
     * For ai_with_slots, the AI output passes through the slot filler too.
     *
     * @param  array<string,string>  $aiOutputs
     * @return array<int,array<string,mixed>>
     */
    private function mergeSections(
        ContractTemplate $template,
        array $aiOutputs,
        Deal $deal,
        array $wizardInputs,
    ): array {
        $merged = [];
        foreach ($template->sections as $section) {
            $type = $section['type'] ?? 'fixed';
            $key = $section['key'];

            $rendered = match ($type) {
                'fixed' => $this->fillSlots($section['fixed_text'] ?? '', $deal, $wizardInputs),
                'slot_only' => $this->fillSlots($section['fixed_text'] ?? '', $deal, $wizardInputs),
                'ai_written' => $aiOutputs[$key] ?? '{{TODO: missing AI output}}',
                'ai_with_slots' => $this->fillSlots($aiOutputs[$key] ?? '', $deal, $wizardInputs),
                default => '{{TODO: unknown section type}}',
            };

            $merged[] = [
                'key' => $key,
                'title' => $section['title'] ?? '',
                'type' => $type,
                'output_format' => $section['output_format'] ?? 'paragraph',
                'rendered' => $rendered,
                'has_todo' => str_contains($rendered, '{{TODO'),
                'user_edited' => false,
            ];
        }

        return $merged;
    }

    /**
     * Replace `{{key}}` tokens in text with values from the deal record
     * + wizard inputs. Unknown tokens are left as-is so they surface as
     * TODOs to the operator (they look out of place and are flagged by
     * has_todo when prefixed with TODO:).
     *
     * Special handling for `{{trial_period_clause}}` because it expands
     * conditionally based on the trial_months wizard answer.
     */
    private function fillSlots(string $text, Deal $deal, array $wizardInputs): string
    {
        $slots = $this->resolveSlots($deal, $wizardInputs);

        // Custom slot: trial period clause.
        $trialMonths = (int) ($wizardInputs['trial_months'] ?? 0);
        $slots['trial_period_clause'] = $trialMonths > 0
            ? "User can use {$trialMonths} month(s) as a free trial of the service starting from the Commencement Date."
            : 'No trial period applies.';

        return preg_replace_callback(
            '/\{\{([a-z_]+)\}\}/i',
            function ($matches) use ($slots) {
                $key = $matches[1];
                $value = $slots[$key] ?? null;
                if ($value === null || $value === '') {
                    return '{{TODO: '.$key.'}}';
                }
                return (string) $value;
            },
            $text,
        ) ?? $text;
    }

    /** @return array<string,scalar|null> */
    private function resolveSlots(Deal $deal, array $wizardInputs): array
    {
        $currency = $deal->final_currency ?? 'USD';
        $fmtMoney = function ($value) use ($currency) {
            if ($value === null) return null;
            return $currency.' '.number_format((float) $value, 2);
        };

        return array_merge([
            'final_monthly_fee' => $fmtMoney($deal->final_monthly_fee),
            'final_installation_fee' => $fmtMoney($deal->final_installation_fee),
            'final_contract_months' => $deal->final_contract_months,
            'final_ot_policy' => $deal->final_ot_policy,
            'final_support_hours_per_month' => $deal->final_support_hours_per_month ?? self::DEFAULT_SUPPORT_HOURS,
            'final_team_summary' => $deal->final_team_summary,
            'customer_name' => $deal->client,
            'project_name' => $deal->name,
            'payment_terms_days' => 7,
            'actual_start_date' => $wizardInputs['commencement_date'] ?? null,
            'actual_end_date' => $this->computeEndDate($wizardInputs['commencement_date'] ?? null, $deal->final_contract_months),
        ], array_filter($wizardInputs, fn ($v) => is_scalar($v) || $v === null));
    }

    private function computeEndDate(?string $start, ?int $months): ?string
    {
        if (! $start || ! $months) return null;
        try {
            return \Carbon\Carbon::parse($start)->addMonths($months)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function shortErrorMessage(Throwable $e): string
    {
        return Str::limit(str_replace(["\n", "\r"], ' ', $e->getMessage()), 80);
    }

    /**
     * Invoke the win_deal() Postgres stored procedure, or the PHP fallback
     * for SQLite tests. Mirrors DealContractDocumentController::autoWinDeal.
     */
    private function fireWinDeal(Deal $deal): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT win_deal(?, ?)', [$deal->id, $deal->tenant_id]);
            return;
        }

        // SQLite test fallback — minimal version that flips the deal to won
        // and creates a Contract + Project so downstream lookups don't 404.
        DB::transaction(function () use ($deal) {
            $existingContract = \App\Models\Contract::where('deal_id', $deal->id)->first();
            if (! $existingContract) {
                $lastNumber = (int) (\App\Models\Contract::withoutGlobalScope('tenant')->max(
                    DB::raw('CAST(SUBSTR(contract_number, 5) AS INTEGER)')
                ) ?? 0);
                $nextNumber = 'CON-'.str_pad((string) ($lastNumber + 1), 4, '0', STR_PAD_LEFT);

                \App\Models\Contract::create([
                    'id' => Str::orderedUuid(),
                    'tenant_id' => $deal->tenant_id,
                    'deal_id' => $deal->id,
                    'contract_number' => $nextNumber,
                    'client' => $deal->client ?? '',
                    'total_value' => $deal->final_monthly_fee && $deal->final_contract_months
                        ? (float) $deal->final_monthly_fee * (int) $deal->final_contract_months
                        : (float) ($deal->client_budget ?? 0),
                    'status' => 'Draft',
                    'start_date' => now()->toDateString(),
                ]);
            }

            $deal->update([
                'status' => 'won',
                'win_probability' => Deal::RANK_PROBABILITY['S'],
                'won_at' => now(),
            ]);
        });
    }

}
