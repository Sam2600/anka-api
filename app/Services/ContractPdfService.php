<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealContractDraft;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Renders a DealContractDraft into a PDF using the Yazaki-modelled SES layout.
 *
 * Output: storage/app/contract-drafts/{draft_id}/v{version}.pdf
 *
 * The renderer respects each section's output_format:
 *   - paragraph       → block text (preserved newlines)
 *   - bulleted_simple → <ul>; splits on lines starting with -/*•
 *   - bulleted_pair   → 2-column Provider | User table; splits on "Provider:" / "User:" headings
 *   - table           → AI returns a markdown-style table; rendered as <table>
 *
 * Re-sends reuse the cached PDF; new versions overwrite by path.
 */
class ContractPdfService
{
    /**
     * Render the draft and return the absolute filesystem path of the PDF.
     */
    public function renderDraft(DealContractDraft $draft): string
    {
        $relativePath = $this->relativePathFor($draft);
        $absolutePath = Storage::disk('local')->path($relativePath);

        // Re-use cached file if it already exists for this draft+version.
        // Frees the user to re-send without paying the render cost twice.
        if (Storage::disk('local')->exists($relativePath)) {
            return $absolutePath;
        }

        $deal = $draft->deal()->first();
        if (! $deal) {
            // Defensive — eager-load missed; force it. Without a deal we
            // cannot render the parties block, which is a hard requirement.
            $deal = Deal::findOrFail($draft->deal_id);
        }

        $pdf = Pdf::loadView('pdf.contract-draft', [
            'draft' => $draft,
            'deal' => $deal,
            'provider' => config('contract.provider'),
            'logoDataUri' => $this->logoDataUri(),
            'sections' => $this->sectionsForRender($draft),
            'generatedAt' => now()->toDayDateTimeString(),
        ])->setPaper('a4');

        // Ensure parent dir exists; Storage::put creates it implicitly.
        Storage::disk('local')->put($relativePath, $pdf->output());

        return $absolutePath;
    }

    /**
     * Logical storage path under the local disk (storage/app/...).
     */
    public function relativePathFor(DealContractDraft $draft): string
    {
        $root = config('contract.pdf_storage_path', 'contract-drafts');
        return "{$root}/{$draft->id}/v{$draft->version}.pdf";
    }

    /**
     * The customer-facing filename when attached to email. Embeds the deal
     * name (slugified) so the customer's inbox isn't full of "v1.pdf"s.
     */
    public function suggestedFilename(DealContractDraft $draft, Deal $deal): string
    {
        $slug = \Illuminate\Support\Str::slug($deal->name ?: 'contract');
        return "{$slug}-v{$draft->version}.pdf";
    }

    /**
     * Inline the logo as a base64 data URI. Dompdf's `chroot` doesn't help
     * with files under storage/, so we embed directly. Falls back to null
     * when the file is missing — Blade view conditionally renders the img.
     */
    private function logoDataUri(): ?string
    {
        $path = config('contract.provider.logo_path');
        if (! $path || ! is_string($path) || ! is_file($path)) {
            return null;
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            default => 'image/png',
        };

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /**
     * Pre-process each rendered section into a small struct the Blade
     * template can render without inline logic. Keeps the Blade flat.
     *
     * @return array<int, array{key:string,title:string,output_format:string,html:string}>
     */
    private function sectionsForRender(DealContractDraft $draft): array
    {
        $out = [];
        foreach ($draft->sections ?? [] as $section) {
            $out[] = [
                'key' => $section['key'] ?? '',
                'title' => strtoupper($section['title'] ?? $section['key'] ?? ''),
                'output_format' => $section['output_format'] ?? 'paragraph',
                'html' => $this->renderSectionHtml(
                    $section['rendered'] ?? '',
                    $section['output_format'] ?? 'paragraph',
                ),
            ];
        }
        return $out;
    }

    /**
     * Convert a section's rendered text to HTML based on its output_format.
     */
    private function renderSectionHtml(string $text, string $format): string
    {
        $text = trim($text);
        if ($text === '') {
            return '<p class="muted">(no content)</p>';
        }

        return match ($format) {
            'bulleted_simple' => $this->renderBulletedSimple($text),
            'bulleted_pair'   => $this->renderBulletedPair($text),
            'table'           => $this->renderTable($text),
            default           => $this->renderParagraph($text),
        };
    }

    /**
     * Plain paragraph: split on blank lines, wrap each block in <p>,
     * convert single newlines to <br/> within a block.
     */
    private function renderParagraph(string $text): string
    {
        $blocks = preg_split('/\n\s*\n/', $text) ?: [$text];
        $html = '';
        foreach ($blocks as $block) {
            $block = htmlspecialchars(trim($block), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $block = nl2br($block, false);
            $html .= "<p>{$block}</p>";
        }
        return $html;
    }

    /**
     * Bulleted list: each line starting with -, *, or • becomes <li>;
     * intermediate plain lines fold into the prior <li>.
     */
    private function renderBulletedSimple(string $text): string
    {
        $lines = preg_split('/\r?\n/', $text) ?: [];
        $items = [];
        $current = null;
        foreach ($lines as $line) {
            $trim = ltrim($line);
            if (preg_match('/^[-*•]\s+(.*)$/u', $trim, $m)) {
                if ($current !== null) $items[] = $current;
                $current = $m[1];
            } elseif ($current !== null && $trim !== '') {
                $current .= ' '.$trim;
            } elseif ($trim !== '' && $current === null) {
                // Stray prose before the first bullet — treat as paragraph.
                $items[] = '__P__'.$trim;
            }
        }
        if ($current !== null) $items[] = $current;

        $html = '';
        $inList = false;
        foreach ($items as $item) {
            if (str_starts_with($item, '__P__')) {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                $html .= '<p>'.htmlspecialchars(substr($item, 5), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>';
            } else {
                if (! $inList) { $html .= '<ul>'; $inList = true; }
                $html .= '<li>'.htmlspecialchars($item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</li>';
            }
        }
        if ($inList) $html .= '</ul>';
        return $html ?: $this->renderParagraph($text);
    }

    /**
     * Bulleted pair: AI output is two lists, headed "Provider:" and "User:".
     * Render side-by-side. Falls back to bulleted_simple if no headings.
     */
    private function renderBulletedPair(string $text): string
    {
        $providerLines = [];
        $userLines = [];
        $bucket = null;

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $trim = trim($line);
            if (preg_match('/^provider[\s:]*$/i', $trim) || preg_match('/^provider\s*[:\-]/i', $trim)) {
                $bucket = 'provider';
                continue;
            }
            if (preg_match('/^user[\s:]*$/i', $trim) || preg_match('/^user\s*[:\-]/i', $trim)) {
                $bucket = 'user';
                continue;
            }
            if (preg_match('/^[-*•]\s+(.*)$/u', $trim, $m) && $bucket) {
                if ($bucket === 'provider') $providerLines[] = $m[1];
                else $userLines[] = $m[1];
            }
        }

        if (empty($providerLines) && empty($userLines)) {
            return $this->renderBulletedSimple($text);
        }

        $renderColumn = function (array $lines): string {
            if (empty($lines)) return '<p class="muted">—</p>';
            $html = '<ul>';
            foreach ($lines as $l) {
                $html .= '<li>'.htmlspecialchars($l, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</li>';
            }
            return $html.'</ul>';
        };

        return '<table class="pair-table"><thead><tr><th>Provider</th><th>User</th></tr></thead><tbody><tr><td>'
            . $renderColumn($providerLines)
            . '</td><td>'
            . $renderColumn($userLines)
            . '</td></tr></tbody></table>';
    }

    /**
     * Table: AI emits a markdown-style table. Parse pipe-separated rows
     * (first non-separator row = header). Falls back to <pre> if no `|`.
     */
    private function renderTable(string $text): string
    {
        if (! str_contains($text, '|')) {
            return '<pre class="raw">'.htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre>';
        }

        $rows = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^[\|\s\-:]+$/', $line)) continue; // header sep
            $cells = array_map('trim', explode('|', trim($line, '|')));
            $rows[] = $cells;
        }

        if (empty($rows)) {
            return '<pre class="raw">'.htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre>';
        }

        $header = array_shift($rows);
        $html = '<table class="data-table"><thead><tr>';
        foreach ($header as $h) {
            $html .= '<th>'.htmlspecialchars($h, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>'.htmlspecialchars($cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</td>';
            }
            $html .= '</tr>';
        }
        return $html.'</tbody></table>';
    }
}
