<?php

namespace App\Services;

use App\Models\DealContractDraft;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Asks Claude two questions about a customer-returned signed contract:
 *   1. Is this the same contract we originally sent? (compare against
 *      the cached v{N}.pdf the system rendered + emailed)
 *   2. Does it contain a customer signature block?
 *
 * Returns a structured verdict so the SignedUpload step in the wizard
 * can gate the "Mark as signed → S" action behind a passing check. The
 * salesperson can override with an explicit checkbox when verification
 * fails (common for scanned/photo'd PDFs the text extractor can't read).
 *
 * This service NEVER throws — every failure path returns a verdict with
 * `match: false, signature: false` and a human-readable note. The
 * frontend treats those as failures, the salesperson reviews the PDF
 * manually, and uses the override.
 */
class SignedContractVerifier
{
    private const CLAUDE_MODEL_DEFAULT = 'claude-3-5-sonnet-latest';
    private const CLAUDE_BASE_URL_DEFAULT = 'https://api.anthropic.com';

    /**
     * @return array{match:bool, signature:bool, notes:string}
     */
    public function verify(DealContractDraft $draft, UploadedFile $signedPdf): array
    {
        $originalText = $this->extractText($this->originalPdfAbsolutePath($draft));
        $signedText = $this->extractText($signedPdf->getRealPath());

        if ($originalText === null || $signedText === null) {
            return [
                'match' => false,
                'signature' => false,
                'notes' => 'Could not extract text from one or both PDFs '
                    .'(likely a scanned image rather than a text PDF). '
                    .'Review the file manually and use the override.',
            ];
        }

        try {
            return $this->callClaude($originalText, $signedText);
        } catch (Throwable $e) {
            Log::warning('SignedContractVerifier: Claude call failed', [
                'exception' => $e->getMessage(),
                'draft_id' => $draft->id,
            ]);
            return [
                'match' => false,
                'signature' => false,
                'notes' => 'AI verification unavailable. Review the PDF manually and use the override.',
            ];
        }
    }

    private function originalPdfAbsolutePath(DealContractDraft $draft): string
    {
        return Storage::disk('local')->path("contract-drafts/{$draft->id}/v{$draft->version}.pdf");
    }

    /**
     * Extract text from a PDF via smalot/pdfparser. Returns null when the
     * extraction fails (corrupted PDF, image-only scan, etc.). Truncates
     * to a sane upper bound so we don't blow the Claude token budget on
     * a 200-page customer-side reformat.
     */
    private function extractText(string $absolutePath): ?string
    {
        if (! is_file($absolutePath)) {
            return null;
        }
        try {
            $doc = (new PdfParser())->parseFile($absolutePath);
            $text = trim($doc->getText());
            if ($text === '') {
                return null;
            }
            // Cap at ~20k chars to keep the prompt cheap and fast. Real
            // SES contracts run 2–5k chars; 20k handles even hand-edited
            // re-formats without truncating signatures.
            return mb_substr($text, 0, 20000);
        } catch (Throwable $e) {
            Log::warning('PDF text extraction failed', [
                'path' => $absolutePath,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @return array{match:bool, signature:bool, notes:string}
     */
    private function callClaude(string $originalText, string $signedText): array
    {
        $apiKey = config('services.anthropic.api_key');
        if (! $apiKey) {
            return [
                'match' => false,
                'signature' => false,
                'notes' => 'AI verification not configured (no Anthropic API key). Use override.',
            ];
        }

        $model = config('services.anthropic.model', self::CLAUDE_MODEL_DEFAULT);
        $baseUrl = config('services.anthropic.base_url', self::CLAUDE_BASE_URL_DEFAULT);

        $payload = [
            'model' => $model,
            'max_tokens' => 400,
            'system' => $this->systemPrompt(),
            'messages' => [
                ['role' => 'user', 'content' => $this->userPrompt($originalText, $signedText)],
            ],
        ];

        $response = Http::timeout(45)
            ->retry(1, 1000, function ($exception) {
                return $exception instanceof ConnectionException;
            })
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
            ->post(rtrim($baseUrl, '/').'/v1/messages', $payload);

        if (! $response->successful()) {
            Log::warning('SignedContractVerifier: Claude returned non-200', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 400),
            ]);
            return [
                'match' => false,
                'signature' => false,
                'notes' => 'AI verification request failed ('.$response->status().'). Review manually + use override.',
            ];
        }

        $body = $response->json();
        $raw = $body['content'][0]['text'] ?? '';
        return $this->parseVerdict($raw);
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
You are verifying a customer-returned signed contract against the original
PDF text the system sent. Your job is to answer two yes/no questions and
output STRICT JSON only — no prose, no markdown fences.

Check 1 — MATCH: is the SIGNED text the same contract as the ORIGINAL?
- Compare the party names (Provider company name + Customer company name).
- Compare the financial terms (monthly fee, contract months).
- Compare the section titles in order (Description of Services, Services
  Provided, Scope of Work, Requirements, Fees, Usage Period, Monitoring,
  Payment Policy, Cancellation Fee).
- Minor whitespace / line-wrap / re-formatting differences are fine.
- If the SIGNED is clearly a different document (wrong customer, wrong
  scope, missing whole sections), MATCH is false.

Check 2 — SIGNATURE: does the SIGNED text contain a customer signature
block?
- Look for the customer name appearing near a label like "Signature",
  "Signed", "Authorized signatory", "Signed by", or a printed name on a
  signature line, accompanied by a date.
- A hand-written signature image won't appear in extracted text — but
  the printed name + date typically do, even on photo'd PDFs that OCRed
  during text extraction.
- If there's no clear signature block on the customer side, SIGNATURE
  is false.

Output exactly this JSON:
{"match": true|false, "signature": true|false, "notes": "one short sentence summarising what you found or didn't find"}

No other keys. No prose outside the JSON. No markdown fences.
TXT;
    }

    private function userPrompt(string $original, string $signed): string
    {
        return "=== ORIGINAL (what we sent) ===\n"
            .$original
            ."\n\n=== SIGNED (what customer returned) ===\n"
            .$signed;
    }

    /**
     * @return array{match:bool, signature:bool, notes:string}
     */
    private function parseVerdict(string $raw): array
    {
        $raw = trim($raw);
        // Strip markdown fences defensively even though the prompt forbids them.
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
            $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        }

        $parsed = json_decode($raw, true);
        if (! is_array($parsed)) {
            Log::warning('SignedContractVerifier: Claude returned non-JSON', [
                'raw' => mb_substr($raw, 0, 300),
            ]);
            return [
                'match' => false,
                'signature' => false,
                'notes' => 'AI returned an unparseable response. Review manually + use override.',
            ];
        }

        return [
            'match' => (bool) ($parsed['match'] ?? false),
            'signature' => (bool) ($parsed['signature'] ?? false),
            'notes' => is_string($parsed['notes'] ?? null) && $parsed['notes'] !== ''
                ? $parsed['notes']
                : 'No additional notes from the verifier.',
        ];
    }
}
