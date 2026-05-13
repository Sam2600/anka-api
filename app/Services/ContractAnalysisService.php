<?php

namespace App\Services;

use App\Models\AiUsageLog;
use App\Models\DealContractDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Extracts text from an uploaded contract document and asks Claude whether
 * the document contains the agency's required fields. Sets the document's
 * analysis_status to approved / rejected / failed and returns the verdict.
 *
 * The required-fields list is intentionally a placeholder for now — the user
 * will replace it with the agency's actual checklist once finalised.
 */
class ContractAnalysisService
{
    public const REQUIRED_FIELDS_PLACEHOLDER = [
        'client_name',
        'contract_value',
        'payment_terms',
        'effective_date',
        'signatures',
        'scope_of_work',
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
            $verdict = $this->keywordFallback($text);
            $document->update([
                'analysis_status' => $verdict['approved'] ? 'approved' : 'rejected',
                'analysis_result' => $verdict + ['model' => 'keyword-fallback'],
                'analyzed_at' => now(),
            ]);

            return $document->fresh();
        }

        try {
            $verdict = $this->callClaude($apiKey, $text, $document);
        } catch (Throwable $e) {
            Log::error('ContractAnalysis: Claude call failed, using keyword fallback', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            $verdict = $this->keywordFallback($text) + [
                'model' => 'keyword-fallback',
                'note' => 'Claude API error — used keyword fallback.',
            ];
        }

        $document->update([
            'analysis_status' => ($verdict['approved'] ?? false) ? 'approved' : 'rejected',
            'analysis_result' => $verdict,
            'analyzed_at' => now(),
        ]);

        return $document->fresh();
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

    private function callClaude(string $apiKey, string $text, DealContractDocument $document): array
    {
        // Trim very long documents so we don't blow the context window or
        // spend tokens on boilerplate. 60k chars ≈ ~15k tokens, generous
        // headroom under the model's input limit.
        $textForPrompt = mb_substr($text, 0, 60_000);

        $required = implode(', ', self::REQUIRED_FIELDS_PLACEHOLDER);

        $system = 'You are a contract review assistant. Decide whether the provided contract '
            . 'document contains all required fields. Respond ONLY with valid JSON of the form '
            . '{"approved": boolean, "missing_fields": [string], "reasoning": string}. No markdown.';

        $user = <<<PROMPT
Required fields (placeholder — replace later with the agency's real checklist):
{$required}

Task:
1. Determine whether each required field is present (any mention or equivalent counts).
2. Set "approved" to true ONLY if every required field is present.
3. List any missing fields in "missing_fields".
4. Provide a 1-2 sentence "reasoning" explaining the decision.

Contract document text:
---
{$textForPrompt}
---
PROMPT;

        $response = Http::timeout(60)
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-3-5-sonnet-latest',
                'max_tokens' => 1024,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $user],
                ],
            ]);

        $body = $response->json();

        if (isset($body['usage'])) {
            $this->logUsage($document, $body['usage']);
        }

        $raw = $body['content'][0]['text'] ?? '';
        $raw = trim($raw);
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/', '', $raw);
        }

        $verdict = json_decode($raw, true);
        if (! is_array($verdict) || ! isset($verdict['approved'])) {
            throw new \RuntimeException('Claude returned malformed JSON: '.substr($raw, 0, 200));
        }

        $verdict['model'] = 'claude-3-5-sonnet-latest';
        $verdict['required_fields'] = self::REQUIRED_FIELDS_PLACEHOLDER;

        return $verdict;
    }

    private function keywordFallback(string $text): array
    {
        $haystack = strtolower($text);
        $missing = [];
        $keywordMap = [
            'client_name' => ['client', 'customer', 'party'],
            'contract_value' => ['total value', 'contract value', 'amount', 'price', 'fee'],
            'payment_terms' => ['payment terms', 'net 30', 'due', 'invoice'],
            'effective_date' => ['effective date', 'commencement', 'start date'],
            'signatures' => ['signature', 'signed by', 'authorised signatory', 'authorized signatory'],
            'scope_of_work' => ['scope of work', 'deliverables', 'services'],
        ];

        foreach ($keywordMap as $field => $needles) {
            $present = false;
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    $present = true;
                    break;
                }
            }
            if (! $present) {
                $missing[] = $field;
            }
        }

        return [
            'approved' => empty($missing),
            'missing_fields' => $missing,
            'reasoning' => empty($missing)
                ? 'All required field keywords were detected (keyword fallback).'
                : 'Missing keywords for: '.implode(', ', $missing).' (keyword fallback).',
            'required_fields' => self::REQUIRED_FIELDS_PLACEHOLDER,
        ];
    }

    private function logUsage(DealContractDocument $document, array $usage): void
    {
        try {
            AiUsageLog::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->uploaded_by,
                'feature' => 'contract_analysis',
                'model' => 'claude-3-5-sonnet-latest',
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
