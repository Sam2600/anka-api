<?php

namespace App\Services;

use App\Models\AiUsageLog;
use App\Models\DealContractDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Extracts text from an uploaded contract document and asks Claude to grade
 * it against the agency's canonical field checklist (24 fields, 3 severity
 * tiers). The verdict is a rich JSON document the UI renders as a per-field
 * card with evidence quotes, severity chips, suggested fixes, dispute risks
 * and (on re-upload) a diff against the previous attempt.
 *
 * When all critical + required fields pass the document is marked `approved`
 * and the controller auto-fires win_deal() to move the deal to S/won.
 */
class ContractAnalysisService
{
    private const CLAUDE_MODEL_DEFAULT = 'claude-3-5-sonnet-latest';
    private const CLAUDE_BASE_URL_DEFAULT = 'https://api.anthropic.com';

    /**
     * Score weights per severity tier — used by the prompt and by the
     * overall_score safety-net calculation if Claude forgets the field.
     */
    private const SEVERITY_WEIGHTS = [
        'critical'   => 3,
        'required'   => 2,
        'recommended' => 1,
    ];

    /**
     * Canonical field checklist. The single source of truth for what an
     * acceptable contract must contain; one entry per field with a short
     * label (shown in the UI), severity tier (drives whether absence blocks
     * approval), and a presence criterion that Claude uses to decide
     * present / partial / missing.
     *
     * 6 critical · 15 required · 5 recommended = 26 entries (incl. payment
     * schedule which checks any valid pattern is present).
     */
    public const FIELD_DEFINITIONS = [
        // ── Critical (missing → automatic fail) ─────────────────────────────
        [
            'field' => 'provider_signature',
            'label' => 'Provider signature',
            'severity' => 'critical',
            'criterion' => 'Provider signatory name, title, and date are filled in. NOT acceptable: placeholder text like "Date" or blank fields.',
        ],
        [
            'field' => 'customer_signature',
            'label' => 'Customer signature',
            'severity' => 'critical',
            'criterion' => 'Customer signatory name, title, ID (NRC / passport), and date are all filled. Blank or placeholder fields = missing.',
        ],
        [
            'field' => 'provider_scope_of_work',
            'label' => 'Provider scope of work',
            'severity' => 'critical',
            'criterion' => 'A clear statement of what the agency (provider) will deliver, broken down into specific tasks or responsibilities.',
        ],
        [
            'field' => 'customer_scope_of_work',
            'label' => 'Customer responsibilities',
            'severity' => 'critical',
            'criterion' => 'What the customer must provide for the engagement to succeed (test devices, environments, internet access, accounts, etc.). Pure prerequisites in a separate "Requirements" section also count.',
        ],
        [
            'field' => 'out_of_scope_clause',
            'label' => 'Out-of-scope + additional charges',
            'severity' => 'critical',
            'criterion' => 'Explicit statement that work outside the agreed scope is billable separately, or that the agency does not support out-of-scope requests.',
        ],
        [
            'field' => 'working_hours_timezone',
            'label' => 'Working hours + timezone',
            'severity' => 'critical',
            'criterion' => 'Stated support / working hours AND a timezone (or implicit country reference acceptable for fully local engagements). Hours alone without timezone = partial.',
        ],

        // ── Required (missing → fail with reasoning) ────────────────────────
        [
            'field' => 'provider_identity',
            'label' => 'Provider legal name + address',
            'severity' => 'required',
            'criterion' => 'Provider legal entity name and registered address.',
        ],
        [
            'field' => 'provider_signatory',
            'label' => 'Provider authorised signatory',
            'severity' => 'required',
            'criterion' => 'Named representative + their title (Managing Director / Director / etc.).',
        ],
        [
            'field' => 'customer_identity',
            'label' => 'Customer legal name + address',
            'severity' => 'required',
            'criterion' => 'Customer legal entity name and address.',
        ],
        [
            'field' => 'customer_signatory',
            'label' => 'Customer authorised signatory',
            'severity' => 'required',
            'criterion' => 'Named representative + their title.',
        ],
        [
            'field' => 'agreement_date',
            'label' => 'Agreement date',
            'severity' => 'required',
            'criterion' => 'Date the agreement was executed. Placeholder text like "Date" or unfilled = missing.',
        ],
        [
            'field' => 'description_of_services',
            'label' => 'Description of services',
            'severity' => 'required',
            'criterion' => 'High-level description of what the agency does / sells. Usually a "DESCRIPTION OF SERVICES" or similar section.',
        ],
        [
            'field' => 'services_provided',
            'label' => 'Services provided for this engagement',
            'severity' => 'required',
            'criterion' => 'Specific services / configuration / deliverables for this contract (not the agency\'s generic catalogue).',
        ],
        [
            'field' => 'customer_prerequisites',
            'label' => 'Customer prerequisites / requirements',
            'severity' => 'required',
            'criterion' => 'Technical prerequisites the customer must meet (e.g. internet bandwidth, server access, account permissions).',
        ],
        [
            'field' => 'pricing_structure',
            'label' => 'Pricing structure',
            'severity' => 'required',
            'criterion' => 'How fees are calculated (flat / tiered / per-unit). Even without specific numbers, the *structure* must be defined.',
        ],
        [
            'field' => 'payment_terms',
            'label' => 'Payment terms',
            'severity' => 'required',
            'criterion' => 'Invoice timing and payment due window (e.g. "invoiced at month-end, payable within 7 days of invoice date").',
        ],
        [
            'field' => 'tax_responsibility',
            'label' => 'Tax & bank-charge responsibility',
            'severity' => 'required',
            'criterion' => 'Which party bears applicable taxes and bank charges.',
        ],
        [
            'field' => 'effective_date',
            'label' => 'Effective / commencement date',
            'severity' => 'required',
            'criterion' => 'When the agreement / billing meter starts. Placeholder text = missing.',
        ],
        [
            'field' => 'term_duration',
            'label' => 'Term / duration',
            'severity' => 'required',
            'criterion' => 'How long the contract runs (e.g. "6 months", "until project completion", "perpetual until terminated").',
        ],
        [
            'field' => 'termination_procedure',
            'label' => 'Termination procedure',
            'severity' => 'required',
            'criterion' => 'What happens when the contract ends — data return, data deletion, evidence-of-deletion, transition assistance.',
        ],
        [
            'field' => 'payment_schedule',
            'label' => 'Payment schedule',
            'severity' => 'required',
            'criterion' => 'Some clear, valid billing pattern present: monthly_recurring OR milestone_based (per-deliverable) OR per_phase (per SOW phase) OR one_time (single upfront payment).',
        ],

        // ── Recommended (missing → amber flag, doesn't block) ────────────────
        [
            'field' => 'testing_range',
            'label' => 'Testing range (dev contracts)',
            'severity' => 'recommended',
            'criterion' => 'For software dev contracts: which browsers / mobile versions / environments will be tested against. Mark as not_applicable for non-dev contracts (managed services etc.).',
        ],
        [
            'field' => 'trial_period',
            'label' => 'Trial period',
            'severity' => 'recommended',
            'criterion' => 'A free or discounted trial window (if any). Absent is fine if the contract is straight commercial.',
        ],
        [
            'field' => 'early_termination_fee',
            'label' => 'Cancellation / early-termination fee',
            'severity' => 'recommended',
            'criterion' => 'For recurring contracts: penalty if the customer breaks the term early. Not applicable to fixed-bid projects.',
        ],
        [
            'field' => 'acceptance_criteria',
            'label' => 'Acceptance criteria per deliverable',
            'severity' => 'recommended',
            'criterion' => 'For milestone-based contracts: what counts as "accepted" for each deliverable. Not applicable to managed services.',
        ],
        [
            'field' => 'phase_signoff',
            'label' => 'Per-phase sign-off',
            'severity' => 'recommended',
            'criterion' => 'For SOW contracts: explicit sign-off / go-no-go before each subsequent phase starts.',
        ],
    ];

    public function analyze(DealContractDocument $document): DealContractDocument
    {
        $document->update(['analysis_status' => 'analyzing']);

        try {
            $text = $this->extractText($document);
        } catch (Throwable $e) {
            Log::warning('ContractAnalysis: text extraction failed', [
                'document_id' => $document->id,
                'extension' => $document->extension,
                'error' => $e->getMessage(),
            ]);

            $document->update([
                'analysis_status' => 'failed',
                'analysis_result' => [
                    'error' => 'Could not extract text from this document: '.$e->getMessage(),
                    'suggestion' => 'Re-export as PDF or DOCX and try again.',
                ],
                'analyzed_at' => now(),
            ]);

            return $document->fresh();
        }

        if (trim($text) === '') {
            $document->update([
                'analysis_status' => 'failed',
                'analysis_result' => [
                    'error' => 'Document contained no extractable text.',
                    'suggestion' => 'The file may be image-only or password-protected.',
                ],
                'analyzed_at' => now(),
            ]);

            return $document->fresh();
        }

        $apiKey = config('services.anthropic.api_key') ?? env('ANTHROPIC_API_KEY');

        if (! $apiKey) {
            $verdict = $this->keywordFallback($text, $document->previous_analysis);
        } else {
            try {
                $verdict = $this->callClaude($apiKey, $text, $document);
            } catch (Throwable $e) {
                Log::error('ContractAnalysis: Claude call failed, using keyword fallback', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage(),
                ]);

                $verdict = $this->keywordFallback($text, $document->previous_analysis);
                $verdict['note'] = 'Claude API error — used keyword fallback.';
            }
        }

        $approved = $this->isApproved($verdict);

        $document->update([
            'analysis_status' => $approved ? 'approved' : 'rejected',
            'analysis_result' => $verdict,
            'overall_score' => isset($verdict['overall_score']) ? (int) $verdict['overall_score'] : null,
            'detected_payment_pattern' => $verdict['detected_payment_pattern'] ?? null,
            'analyzed_at' => now(),
        ]);

        return $document->fresh();
    }

    /**
     * Approval rule: no critical or required field may be missing.
     * Claude can advise via `approved` in the verdict, but we enforce
     * here in PHP so a confused model can't accidentally flip a deal to S.
     */
    private function isApproved(array $verdict): bool
    {
        $grades = $verdict['field_grades'] ?? [];
        foreach ($grades as $grade) {
            $severity = $grade['severity'] ?? null;
            $status = $grade['status'] ?? null;
            if (in_array($severity, ['critical', 'required'], true) && $status === 'missing') {
                return false;
            }
        }
        // Critical failures explicitly reported — also bail out.
        if (! empty($verdict['critical_failures'])) {
            return false;
        }

        return (bool) ($verdict['approved'] ?? false);
    }

    private function extractText(DealContractDocument $document): string
    {
        $absPath = Storage::disk('local')->path($document->storage_path);

        if (! is_file($absPath)) {
            throw new \RuntimeException('Uploaded file is missing from storage.');
        }

        // xlsx + pptx are intentionally unsupported — see DealContractDocumentController::ALLOWED_EXT.
        return match ($document->extension) {
            'txt' => $this->extractTxt($absPath),
            'pdf' => $this->extractPdf($absPath),
            'docx' => $this->extractDocx($absPath),
            default => throw new \RuntimeException("Unsupported extension: {$document->extension}. Allowed: pdf, docx, txt."),
        };
    }

    private function extractTxt(string $path): string
    {
        $contents = file_get_contents($path);

        return $contents === false ? '' : $contents;
    }

    private function extractPdf(string $path): string
    {
        if (! class_exists(\Smalot\PdfParser\Parser::class)) {
            throw new \RuntimeException('PDF parser not installed. Run: composer require smalot/pdfparser');
        }

        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($path);

        return $pdf->getText();
    }

    private function extractDocx(string $path): string
    {
        if (! class_exists(\PhpOffice\PhpWord\IOFactory::class)) {
            throw new \RuntimeException('phpoffice/phpword not installed.');
        }

        $reader = \PhpOffice\PhpWord\IOFactory::createReader('Word2007');
        $doc = $reader->load($path);

        $out = [];
        foreach ($doc->getSections() as $section) {
            $this->flattenPhpWordElement($section, $out);
        }

        return implode("\n", $out);
    }

    private function flattenPhpWordElement($element, array &$out): void
    {
        if (method_exists($element, 'getText') && is_string($element->getText())) {
            $out[] = $element->getText();
        }

        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $child) {
                $this->flattenPhpWordElement($child, $out);
            }
        }
    }

    /**
     * Build the prompt, call Anthropic, parse the JSON verdict.
     * Returns the verdict (already shape-validated) ready to persist.
     */
    private function callClaude(string $apiKey, string $text, DealContractDocument $document): array
    {
        // 60k chars ≈ ~15k input tokens. Leaves comfortable headroom under
        // Claude 3.5 Sonnet's 200k context window for the prompt + response.
        $textForPrompt = mb_substr($text, 0, 60_000);

        $checklist = $this->renderChecklistForPrompt();
        $previous = $document->previous_analysis
            ? $this->renderPreviousVerdictForPrompt($document->previous_analysis)
            : 'N/A — this is the first upload for this deal.';

        $system = $this->buildSystemPrompt();
        $user = $this->buildUserPrompt($checklist, $textForPrompt, $previous);

        $baseUrl = config('services.anthropic.base_url') ?: self::CLAUDE_BASE_URL_DEFAULT;
        $model = config('services.anthropic.model') ?: self::CLAUDE_MODEL_DEFAULT;

        $response = Http::timeout(90)
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

        // Surface non-2xx responses with the proxy's error body so the log
        // shows "401 invalid x-api-key" instead of "malformed JSON". The
        // catch in analyze() still falls back to keyword grading.
        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'Anthropic API returned HTTP %d: %s',
                $response->status(),
                substr($response->body(), 0, 300),
            ));
        }

        $body = $response->json();

        if (isset($body['usage'])) {
            $this->logUsage($document, $body['usage']);
        }

        $raw = $body['content'][0]['text'] ?? '';
        $raw = trim($raw);
        // Strip markdown code fences if the model wrapped its JSON.
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/', '', $raw);
        }

        $verdict = json_decode($raw, true);
        if (! is_array($verdict) || ! isset($verdict['field_grades'])) {
            throw new \RuntimeException('Claude returned malformed JSON: '.substr($raw, 0, 200));
        }

        return $this->normaliseVerdict($verdict, source: 'claude');
    }

    /**
     * Keyword-based fallback when ANTHROPIC_API_KEY is missing or the API
     * errors. Returns the SAME verdict shape Claude returns so the rest of
     * the pipeline (UI, approval rule, diff) doesn't branch on source.
     */
    private function keywordFallback(string $text, ?array $previousAnalysis): array
    {
        $haystack = strtolower($text);

        // Coarse keyword sniff per field — used only when Claude is unavailable.
        // Mirrors the labels well enough that the UI is still informative.
        $keywordMap = [
            'provider_signature'      => ['provider', 'signature', 'signed'],
            'customer_signature'      => ['user', 'customer', 'signature', 'nrc'],
            'provider_scope_of_work'  => ['scope of work', 'scope', 'deliverables', 'responsibilities'],
            'customer_scope_of_work'  => ['customer must', 'user shall', 'customer responsibilities'],
            'out_of_scope_clause'     => ['out of scope', 'additional charge', 'do not support'],
            'working_hours_timezone'  => ['working hours', 'support hours', 'mst', 'utc', 'timezone'],
            'provider_identity'       => ['ltd', 'corporation', 'pte', 'company'],
            'provider_signatory'      => ['managing director', 'director', 'represented by'],
            'customer_identity'       => ['user', 'customer', 'co.,ltd', 'co.', 'company'],
            'customer_signatory'      => ['director', 'manager', 'represented by'],
            'agreement_date'          => ['entered into', 'this agreement is made on', 'dated'],
            'description_of_services' => ['description of services', 'services provided'],
            'services_provided'       => ['services provided', 'scope of services'],
            'customer_prerequisites'  => ['requirements', 'prerequisites', 'must be available'],
            'pricing_structure'       => ['fee', 'charge', 'price', 'pricing', 'calculation'],
            'payment_terms'           => ['payment', 'payable within', 'invoice', 'net 30', 'net 7'],
            'tax_responsibility'      => ['tax', 'taxes', 'bank charges'],
            'effective_date'          => ['effective date', 'commencement', 'start date'],
            'term_duration'           => ['term', 'duration', 'usage period', 'months'],
            'termination_procedure'   => ['termination', 'terminate', 'data deletion'],
            'payment_schedule'        => ['monthly', 'milestone', 'phase', 'one-time', 'one time'],
            'testing_range'           => ['browser', 'mobile', 'testing', 'compatibility'],
            'trial_period'            => ['trial', 'free trial', 'evaluation'],
            'early_termination_fee'   => ['early termination', 'cancellation fee', 'remain months'],
            'acceptance_criteria'     => ['acceptance', 'accepted by client', 'sign-off'],
            'phase_signoff'           => ['phase', 'go-no-go', 'go / no-go'],
        ];

        $grades = [];
        $criticalFailures = [];
        $weightedSum = 0;
        $weightTotal = 0;

        foreach (self::FIELD_DEFINITIONS as $def) {
            $field = $def['field'];
            $severity = $def['severity'];
            $needles = $keywordMap[$field] ?? [];

            $hit = false;
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($haystack, $needle)) {
                    $hit = true;
                    break;
                }
            }

            $status = $hit ? 'present' : 'missing';
            $score = $hit ? 80 : 0;

            $grades[] = [
                'field' => $field,
                'label' => $def['label'],
                'status' => $status,
                'severity' => $severity,
                'score' => $score,
                'evidence' => null,
                'evidence_location' => null,
                'reasoning' => $hit
                    ? 'Keyword match detected in the document (fallback heuristic — Claude not available).'
                    : 'No keyword match for this field in the fallback dictionary.',
                'suggested_fix' => $hit ? null : 'Add this clause to the contract before re-uploading.',
            ];

            if ($severity === 'critical' && ! $hit) {
                $criticalFailures[] = $field;
            }

            $weight = self::SEVERITY_WEIGHTS[$severity];
            $weightedSum += $score * $weight;
            $weightTotal += 100 * $weight;
        }

        $overallScore = $weightTotal > 0 ? (int) round(($weightedSum / $weightTotal) * 100) : 0;
        $approved = empty($criticalFailures) && $this->countMissingRequired($grades) === 0;

        $verdict = [
            'approved' => $approved,
            'overall_score' => $overallScore,
            'detected_payment_pattern' => $this->detectPaymentPatternHeuristic($haystack),
            'executive_summary' => $approved
                ? 'All critical and required fields appear present (keyword fallback — no Claude available).'
                : 'Fallback grading: '.count($criticalFailures).' critical issue(s) and '.$this->countMissingRequired($grades).' required field(s) missing.',
            'field_grades' => $grades,
            'critical_failures' => $criticalFailures,
            'dispute_risks' => [],
            'diff_vs_previous' => $this->computeDiff($grades, $previousAnalysis, $overallScore),
            'model' => 'keyword-fallback',
        ];

        return $verdict;
    }

    private function countMissingRequired(array $grades): int
    {
        return count(array_filter(
            $grades,
            fn ($g) => ($g['severity'] ?? null) === 'required' && ($g['status'] ?? null) === 'missing'
        ));
    }

    private function detectPaymentPatternHeuristic(string $haystack): string
    {
        if (str_contains($haystack, 'monthly') && (str_contains($haystack, 'fee') || str_contains($haystack, 'charge'))) {
            return 'monthly_recurring';
        }
        if (str_contains($haystack, 'milestone')) {
            return 'milestone_based';
        }
        if (str_contains($haystack, 'phase')) {
            return 'per_phase';
        }
        if (str_contains($haystack, 'one-time') || str_contains($haystack, 'one time')) {
            return 'one_time';
        }

        return 'unknown';
    }

    /**
     * Compute diff_vs_previous from the current grades and the previous
     * verdict (if any). Used by the fallback path; Claude does its own
     * (richer) diff in the LLM call.
     */
    private function computeDiff(array $currentGrades, ?array $previousAnalysis, int $currentScore): ?array
    {
        if (! $previousAnalysis || empty($previousAnalysis['field_grades'])) {
            return null;
        }

        $prev = [];
        foreach ($previousAnalysis['field_grades'] as $g) {
            $prev[$g['field'] ?? ''] = $g;
        }

        $improvements = [];
        $regressions = [];
        $stillMissing = [];

        foreach ($currentGrades as $g) {
            $field = $g['field'];
            $prevGrade = $prev[$field] ?? null;
            $wasMissing = $prevGrade && ($prevGrade['status'] ?? null) === 'missing';
            $isMissing = ($g['status'] ?? null) === 'missing';

            if ($wasMissing && ! $isMissing) {
                $improvements[] = $g['label'].' is now present.';
            } elseif (! $wasMissing && $isMissing) {
                $regressions[] = $g['label'].' is now missing (was present previously).';
            } elseif ($wasMissing && $isMissing) {
                $stillMissing[] = $field;
            }
        }

        return [
            'improvements' => $improvements,
            'regressions' => $regressions,
            'still_missing' => $stillMissing,
            'previous_score' => (int) ($previousAnalysis['overall_score'] ?? 0),
            'score_delta' => $currentScore - (int) ($previousAnalysis['overall_score'] ?? 0),
        ];
    }

    /**
     * Defensive normalisation of Claude's response — fill in defaults for
     * missing keys, coerce types, ensure every field definition has a grade
     * (so the UI never has a hole when Claude forgets a field).
     */
    private function normaliseVerdict(array $verdict, string $source): array
    {
        $gradesByField = [];
        foreach (($verdict['field_grades'] ?? []) as $g) {
            if (isset($g['field'])) {
                $gradesByField[$g['field']] = $g;
            }
        }

        $normalisedGrades = [];
        $criticalFailures = (array) ($verdict['critical_failures'] ?? []);

        foreach (self::FIELD_DEFINITIONS as $def) {
            $field = $def['field'];
            $g = $gradesByField[$field] ?? [
                'field' => $field,
                'status' => 'missing',
                'score' => 0,
                'reasoning' => 'Claude did not return a grade for this field.',
            ];

            $g['field'] = $field;
            $g['label'] = $def['label']; // Force canonical label.
            $g['severity'] = $def['severity']; // Force canonical severity.
            $g['status'] = in_array($g['status'] ?? null, ['present', 'partial', 'missing', 'not_applicable'], true)
                ? $g['status']
                : 'missing';
            $g['score'] = isset($g['score']) ? max(0, min(100, (int) $g['score'])) : 0;
            $g['evidence'] = $g['evidence'] ?? null;
            $g['evidence_location'] = $g['evidence_location'] ?? null;
            $g['reasoning'] = $g['reasoning'] ?? null;
            $g['suggested_fix'] = $g['suggested_fix'] ?? null;

            $normalisedGrades[] = $g;

            // If a critical field is missing and Claude forgot to list it
            // under critical_failures, add it here so isApproved() catches it.
            if ($def['severity'] === 'critical' && $g['status'] === 'missing'
                && ! in_array($field, $criticalFailures, true)) {
                $criticalFailures[] = $field;
            }
        }

        // Overall_score: trust Claude if provided, else compute it.
        $overallScore = isset($verdict['overall_score'])
            ? max(0, min(100, (int) $verdict['overall_score']))
            : $this->computeOverallScore($normalisedGrades);

        $modelLabel = $source === 'claude'
            ? (config('services.anthropic.model') ?: self::CLAUDE_MODEL_DEFAULT)
            : 'keyword-fallback';

        return [
            'approved' => (bool) ($verdict['approved'] ?? false),
            'overall_score' => $overallScore,
            'detected_payment_pattern' => $verdict['detected_payment_pattern'] ?? 'unknown',
            'executive_summary' => $verdict['executive_summary'] ?? '',
            'field_grades' => $normalisedGrades,
            'critical_failures' => array_values(array_unique($criticalFailures)),
            'dispute_risks' => (array) ($verdict['dispute_risks'] ?? []),
            'diff_vs_previous' => $verdict['diff_vs_previous'] ?? null,
            'model' => $modelLabel,
        ];
    }

    private function computeOverallScore(array $grades): int
    {
        $weightedSum = 0;
        $weightTotal = 0;

        foreach ($grades as $g) {
            $weight = self::SEVERITY_WEIGHTS[$g['severity']] ?? 1;
            $weightedSum += (int) $g['score'] * $weight;
            $weightTotal += 100 * $weight;
        }

        return $weightTotal > 0 ? (int) round(($weightedSum / $weightTotal) * 100) : 0;
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a contract review assistant for Anka, an agency-management platform used by an IT services agency. You review customer-signed contracts and decide whether each is complete enough to move the associated deal from Negotiation to Won.

CRITICAL RULES:
1. Return ONLY valid JSON in the exact schema specified by the user. No prose, no markdown fences.
2. When you quote evidence, quote the document VERBATIM (max 200 chars). NEVER invent quotes.
3. Be conservative on critical fields — when in doubt, mark `missing` or `partial`.
4. Be liberal on recommended fields — they're informational; absence is acceptable.
5. Placeholder text like "Date" or "from Date to Date" left unfilled = `missing`, NOT `present`. Always flag this in `dispute_risks`.
6. If a field truly doesn't apply (e.g. testing_range on a managed-service contract), set status to `not_applicable` with a brief reasoning.
7. The agency operates a single contract template — different billing patterns (monthly_recurring · milestone_based · per_phase · one_time) are all valid. Detect which pattern this contract uses and report it in `detected_payment_pattern`.
PROMPT;
    }

    private function buildUserPrompt(string $checklist, string $documentText, string $previous): string
    {
        return <<<PROMPT
Grade the following customer-signed contract against the agency's 26-field checklist.

═══════ FIELD CHECKLIST ═══════
{$checklist}

═══════ SEVERITY RULES ═══════
- critical    → status `missing` BLOCKS approval. Add the field key to `critical_failures`.
- required    → status `missing` BLOCKS approval (but does not add to critical_failures).
- recommended → status `missing` is acceptable; flagged amber in the UI only.

═══════ STATUS VALUES ═══════
- present       → clearly stated, complete, unambiguous.
- partial       → stated but missing detail (e.g. hours given but no timezone).
- missing       → absent or left as placeholder text.
- not_applicable → field doesn't apply to this contract type (rare; explain in reasoning).

═══════ SCORING ═══════
- score per field: 0 if missing · 40-70 if partial · 80-100 if present · null acceptable for not_applicable
- overall_score: 0-100, weighted by severity (critical=3, required=2, recommended=1). Compute and report.

═══════ PREVIOUS VERDICT (for diff) ═══════
{$previous}

═══════ CONTRACT TEXT ═══════
{$documentText}

═══════ RETURN SHAPE ═══════
Return ONLY this JSON (no markdown):

{
  "approved": boolean,
  "overall_score": integer 0-100,
  "detected_payment_pattern": "monthly_recurring" | "milestone_based" | "per_phase" | "one_time" | "unknown",
  "executive_summary": "1-2 sentences for the salesperson explaining the overall verdict.",
  "field_grades": [
    {
      "field": "field_key_from_checklist",
      "status": "present" | "partial" | "missing" | "not_applicable",
      "score": integer 0-100,
      "evidence": "verbatim quote (max 200 chars) or null",
      "evidence_location": "section number or page reference, or null",
      "reasoning": "1 sentence explaining the grade.",
      "suggested_fix": "actionable instruction the salesperson can send to the customer, or null if status=present"
    }
    // ... one entry per field in the checklist
  ],
  "critical_failures": ["array of field keys that have severity=critical AND status=missing"],
  "dispute_risks": [
    {
      "concern": "1 sentence describing a clause that could cause friction.",
      "severity": "high" | "medium" | "low",
      "clause_quote": "verbatim quote from the doc (max 200 chars) or null",
      "suggested_remediation": "actionable suggestion"
    }
  ],
  "diff_vs_previous": null OR {
    "improvements": ["short bullets describing what improved vs the previous verdict"],
    "regressions": ["what got worse"],
    "still_missing": ["field keys still missing across both attempts"],
    "previous_score": integer,
    "score_delta": integer (current - previous)
  }
}

Return only the JSON. No additional text.
PROMPT;
    }

    private function renderChecklistForPrompt(): string
    {
        $lines = [];
        foreach (self::FIELD_DEFINITIONS as $def) {
            $lines[] = sprintf(
                '- %s [%s] %s — %s',
                $def['field'],
                $def['severity'],
                $def['label'],
                $def['criterion'],
            );
        }

        return implode("\n", $lines);
    }

    private function renderPreviousVerdictForPrompt(array $previous): string
    {
        // Send just enough for Claude to compute the diff — no need to ship
        // every grade's evidence quote back. Score + per-field status is
        // enough to identify what changed.
        $summary = $previous['executive_summary'] ?? '(no summary recorded)';
        $score = $previous['overall_score'] ?? 'n/a';
        $pattern = $previous['detected_payment_pattern'] ?? 'unknown';

        $statuses = [];
        foreach (($previous['field_grades'] ?? []) as $g) {
            $statuses[] = sprintf('  %s → %s (score %s)',
                $g['field'] ?? '?',
                $g['status'] ?? '?',
                $g['score'] ?? '?',
            );
        }

        return "Previous overall_score: {$score}\n"
            . "Previous detected_payment_pattern: {$pattern}\n"
            . "Previous summary: {$summary}\n"
            . "Previous field statuses:\n"
            . implode("\n", $statuses);
    }

    private function logUsage(DealContractDocument $document, array $usage): void
    {
        try {
            AiUsageLog::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->uploaded_by,
                'feature' => 'contract_analysis',
                'model' => config('services.anthropic.model') ?: self::CLAUDE_MODEL_DEFAULT,
                'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
                'estimated_cost_usd' => $this->estimateCost(
                    (int) ($usage['input_tokens'] ?? 0),
                    (int) ($usage['output_tokens'] ?? 0),
                ),
            ]);
        } catch (Throwable $e) {
            Log::warning('ContractAnalysis: failed to log AI usage', ['error' => $e->getMessage()]);
        }
    }

    private function estimateCost(int $inputTokens, int $outputTokens): float
    {
        // Claude 3.5 Sonnet public pricing: $3 / 1M input, $15 / 1M output.
        return round(($inputTokens / 1_000_000) * 3 + ($outputTokens / 1_000_000) * 15, 6);
    }
}
