<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealContractDraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'deal_id' => $this->deal_id,
            'template_id' => $this->template_id,
            'template_version_at_generation' => $this->template_version_at_generation,
            'status' => $this->status,
            'version' => $this->version,
            'wizard_inputs' => $this->wizard_inputs,
            // ai_outputs is the raw Claude response — heavy and rarely needed
            // outside debugging. Hide from list payloads; expose on show.
            'ai_outputs' => $this->when(
                $request->routeIs('contract-drafts.show') || $request->boolean('include_ai_outputs'),
                $this->ai_outputs,
            ),
            'sections' => $this->sections,
            'todo_count' => $this->countTodos(),
            'generated_pdf_path' => $this->generated_pdf_path,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'sent_to_email' => $this->sent_to_email,
            'signed_at' => $this->signed_at?->toIso8601String(),
            'signed_pdf_path' => $this->signed_pdf_path,
            'generated_by_user_id' => $this->generated_by_user_id,
            'finalized_by_user_id' => $this->finalized_by_user_id,
            // Per-draft overrides for the Provider signatory block on the
            // PDF. Null/empty → renderer falls back to tenant.signatory_*.
            'signatory_name_override' => $this->signatory_name_override,
            'signatory_title_override' => $this->signatory_title_override,
            // Customer-side signer captured at draft time. Distinct from
            // deal.contact_* (day-to-day liaison). Null/empty → PDF prints
            // blank '____' lines for the customer to hand-fill.
            'customer_signatory_name' => $this->customer_signatory_name,
            'customer_signatory_title' => $this->customer_signatory_title,
            'customer_signed_date' => $this->customer_signed_date?->toDateString(),
            // Lightweight deal summary when eager-loaded — lets the wizard
            // avoid a separate fetch for breadcrumb / context display.
            'deal' => $this->whenLoaded('deal', fn () => $this->deal ? [
                'id' => $this->deal->id,
                'name' => $this->deal->name,
                'client' => $this->deal->client,
                'status' => $this->deal->status,
                'rank' => $this->deal->rank,
            ] : null),
            'template' => $this->whenLoaded('template', fn () => $this->template ? [
                'id' => $this->template->id,
                'name' => $this->template->name,
                'slug' => $this->template->slug,
                'umbrella' => $this->template->umbrella,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Count remaining {{TODO: …}} markers across all rendered sections.
     * Surfaced top-level so the UI can show "3 unresolved" without iterating.
     */
    private function countTodos(): int
    {
        $count = 0;
        foreach ($this->sections ?? [] as $section) {
            $rendered = $section['rendered'] ?? '';
            $count += substr_count($rendered, '{{TODO');
        }
        return $count;
    }
}
