<?php

namespace App\Services;

use App\Models\ContractTemplate;
use App\Models\Deal;
use App\Models\DealContractDraft;
use App\Models\Tenant;
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
 * Resilience: 90s timeout, retry on ConnectionException only — don't
 * retry response-timeouts because Claude is working, let it finish.
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

            // No rank flip here — the deal must already be at A (negotiation)
            // by the time this runs. B → A is owned by the Estimation handoff
            // (DealController::update auto-flips when final_confirmed_at is
            // written and all REQUIRED_ESTIMATION_FIELDS are complete).

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

        if ($deal->status !== 'negotiation') {
            throw ValidationException::withMessages([
                'deal.status' => [
                    'Contract drafting requires the deal to be at rank A (negotiation). Current rank: '.$deal->rank
                    .'. Complete the Estimation handoff (sets final_confirmed_at + final_* fields) to advance the deal to A.',
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
You are an expert contract drafting assistant for an IT services agency operating
in the Japan/APAC market that signs SES-style (System Engineering Service)
agreements with corporate customers.

Your task is to produce specific, legally precise contract sections based on:
- The TEMPLATE TYPE and service context
- The DEAL CONTEXT (fees, timeline, team, OT policy, support hours)
- The CUSTOMER REQUIREMENT DESCRIPTION (free-form project brief)
- The CUSTOMER REQUIREMENTS (structured nego-time obligations — binding inputs)
- The WIZARD ANSWERS (operator-provided specifics per section)

OUTPUT RULES (absolute):
1. Return ONLY a strict JSON object — no markdown fences, no prose outside the
   object. Keys are section.key strings; values are the rendered section content
   as plain strings.
2. For any specific you cannot confidently fill from the provided inputs, emit
   the literal token "{{TODO: <what is needed>}}" at that point. Never invent
   dollar amounts, thresholds, version numbers, proper names, or dates that are
   not stated in the inputs.
3. Formal, precise contract English. Use "shall" for binding obligations, "will"
   for expected behaviour, "may" for permissions. Attribute every obligation to
   a named party — no passive-voice ambiguity about who is responsible.
4. Do NOT include the section title in your output — the renderer prepends it.
5. Never expose internal field names (snake_case keys), placeholder syntax, or
   system identifiers in human-readable text. The customer reads this directly.
6. Slot tokens of the form {{slot_name}} in fixed_text examples are filled by
   the renderer later — do not emit them in AI-written sections unless the
   section prompt explicitly instructs you to (ai_with_slots sections only).
7. Keep each section tight: sufficient legal precision, zero filler. Each clause
   must state a concrete obligation, entitlement, or constraint.

OUTPUT FORMAT RULES (by output_format value in the section spec):
- paragraph: continuous prose. For definitions, opening descriptions, narrative
  obligations.
- bulleted_simple: a numbered or bulleted list. Each item is one self-contained
  obligation or precondition.
- bulleted_pair: exactly TWO labelled sections. Label them "Provider:" and
  "User:" on their own lines, each followed by "- " bullet items. Each list
  must contain at least three items. Separate the two blocks with a blank line.
- table: a markdown table with a header row and a | --- | separator row.
  At minimum 2 columns. Populate from wizard answers and deal context; mark
  unknown cells as {{TODO: what is needed}}.

CUSTOMER REQUIREMENTS RULE:
The prompt includes a CUSTOMER REQUIREMENTS block capturing support obligations,
out-of-scope policy, working hours, and testing range — gathered during
negotiation. These are BINDING INPUTS. If a field is populated, its content
must appear verbatim or paraphrased into the relevant section (scope_of_work,
requirements, monitoring). Do not silently ignore a populated field.

JAPAN/APAC CONTEXT:
Payment terms default to 30 days unless the wizard specifies otherwise. Dispute
resolution defaults to the courts of the provider's registered location unless
a governing law is specified. Maintain formal, deferential tone in
customer-facing clauses — avoid adversarial or aggressive language.
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

        $otContext = $this->renderOtContext($deal);

        $providerName = Tenant::find($deal->tenant_id)?->name ?? 'Provider';
        $currency = $deal->final_currency ?? 'USD';

        $dealContext = implode("\n", [
            "Provider (agency): {$providerName}",
            'Customer company: '.($deal->client ?? '(unknown)'),
            'Customer contact name: '.($deal->contact_name ?? '(not specified)'),
            'Project / deal name: '.($deal->name ?? '(unnamed)'),
            'Timeline: '.($deal->timeline_months ?? 0).' months',
            'Contract length: '.($deal->final_contract_months ?? 0).' months',
            'Team composition: '.($deal->final_team_summary ?? '(team not summarised)'),
            'Monthly service fee: '.$currency.' '.number_format((float) ($deal->final_monthly_fee ?? 0), 2),
            'One-time installation / onboarding fee: '.(
                $deal->final_installation_fee !== null
                    ? $currency.' '.number_format((float) $deal->final_installation_fee, 2)
                    : '(none)'
            ),
            'OT / overage policy: '.$otContext,
            'Support hours per month: '.($deal->final_support_hours_per_month ?? self::DEFAULT_SUPPORT_HOURS),
            'Invoicing currency: '.$currency,
        ]);

        // Build label lookup: question key → human-readable label.
        $labelMap = $this->buildWizardLabelMap($template);
        $wizardLines = empty($wizardInputs)
            ? '(no wizard answers provided)'
            : collect($wizardInputs)
                ->map(fn ($v, $k) => '- '.($labelMap[$k] ?? $k).': '.(is_scalar($v) ? (string) $v : json_encode($v)))
                ->implode("\n");

        $sectionSpecs = collect($aiSections)->map(function ($section) use ($labelMap) {
            $questionKeys = collect($section['wizard_questions'] ?? [])->pluck('key');
            $questions = $questionKeys->isEmpty()
                ? ''
                : "\n   Wizard answers relevant to this section: "
                    .$questionKeys->map(fn ($k) => ($labelMap[$k] ?? $k))->implode(', ');

            return sprintf(
                "- key: %s\n   title: %s\n   output_format: %s\n   prompt: %s%s",
                $section['key'],
                $section['title'] ?? '',
                $section['output_format'] ?? 'paragraph',
                $section['ai_prompt'] ?? '(no prompt provided)',
                $questions,
            );
        })->implode("\n\n");

        // Customer requirements gathered progressively during negotiation.
        // Populated fields are BINDING — Claude must incorporate them.
        // Empty fields are skipped rather than surfaced as noise.
        $reqLines = [];
        foreach ([
            'Customer support obligations' => $deal->customer_support_obligations,
            'Out-of-scope policy' => $deal->out_of_scope_policy,
            'Working hours' => $deal->working_hours,
            'Testing range' => $deal->testing_range,
        ] as $label => $value) {
            $reqLines[] = $value
                ? "- {$label}: {$value}"
                : "- {$label}: (not captured — omit this clause if not applicable)";
        }
        $requirementsBlock = implode("\n", $reqLines);

        $variantContext = $this->templateVariantContext($template);

        return <<<TXT
TEMPLATE: {$template->name}
SERVICE TYPE: {$variantContext}

DEAL CONTEXT:
{$dealContext}

CUSTOMER REQUIREMENT DESCRIPTION:
"""
{$requirementDescription}
"""

CUSTOMER REQUIREMENTS (captured at negotiation — binding inputs):
{$requirementsBlock}

WIZARD ANSWERS:
{$wizardLines}

SECTIONS TO PRODUCE (return one JSON key per section listed below):

{$sectionSpecs}

Return ONLY the JSON object. No commentary before or after.
TXT;
    }

    /** Maps every wizard question key in the template to its human-readable label. */
    private function buildWizardLabelMap(ContractTemplate $template): array
    {
        $map = [];
        foreach ($template->sections ?? [] as $section) {
            foreach ($section['wizard_questions'] ?? [] as $q) {
                if (! empty($q['key']) && ! empty($q['label'])) {
                    $map[$q['key']] = $q['label'];
                }
            }
        }
        return $map;
    }

    /** Describes the commercial and risk context of each template variant. */
    private function templateVariantContext(ContractTemplate $template): string
    {
        return match ($template->slug) {
            'cloud_backup' =>
                'Cloud Backup Service — Provider remotely manages a cloud-based backup solution '
                . "for the customer's on-premises or cloud servers. Commercial scope: one-time "
                . 'installation and setup; recurring monthly fee covering cloud storage and '
                . 'monitoring; defined support hours. Key risk areas: data sovereignty, retention '
                . 'policy, restore-time SLA, and access controls.',
            'managed_hosting' =>
                'Managed Hosting / Cloud Operations — Provider operates and monitors the '
                . "customer's cloud infrastructure 24/7 against an agreed SLA. Commercial scope: "
                . 'onboarding fee; monthly retainer for SLA-backed service; cloud-platform costs '
                . 'passed through at cost. Key risk areas: SLA definitions, incident-response '
                . 'times, change-management authority, and liability caps.',
            'engineer_dispatch' =>
                'Engineer Dispatch (SES) — Provider assigns specialist engineers to the '
                . "customer's project at a fixed monthly capacity. Commercial scope: monthly "
                . 'retainer per engineer; overtime / out-of-scope billing per OT policy. '
                . 'Key risk areas: IP ownership of work product, scope creep, substitute '
                . 'engineers, and confidentiality of customer systems.',
            default =>
                'SES-umbrella service agreement — use DEAL CONTEXT and REQUIREMENT DESCRIPTION '
                . 'to infer the service type and obligations.',
        };
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
     * for SQLite tests.
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
