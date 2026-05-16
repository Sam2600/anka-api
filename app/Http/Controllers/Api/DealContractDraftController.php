<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DealContractDraftResource;
use App\Models\ContractTemplate;
use App\Models\Deal;
use App\Models\DealContractDraft;
use App\Services\ContractDraftService;
use App\Services\ContractPdfService;
use App\Services\SignedContractVerifier;
use Illuminate\Http\Request;

/**
 * REST surface for AI-generated contract drafts.
 *
 * Lifecycle endpoints, each gated by middleware in routes/api.php:
 *   GET    /deals/{deal}/contract-drafts                  (view_crm)
 *   POST   /deals/{deal}/contract-drafts                  (manage_crm)  — generate + fire B→A
 *   GET    /contract-drafts/{draft}                       (view_crm)
 *   PATCH  /contract-drafts/{draft}/sections/{key}        (manage_crm)  — edit one section
 *   POST   /contract-drafts/{draft}/regenerate-section    (manage_crm)
 *   POST   /contract-drafts/{draft}/finalise              (manage_crm)
 *   POST   /contract-drafts/{draft}/send                  (send_contract_draft)
 *   POST   /contract-drafts/{draft}/mark-signed           (manage_crm)  — fire A→S
 */
class DealContractDraftController extends Controller
{
    public function __construct(private readonly ContractDraftService $service) {}

    public function index(Deal $deal)
    {
        $drafts = DealContractDraft::with(['template'])
            ->where('deal_id', $deal->id)
            ->orderByDesc('version')
            ->get();

        return DealContractDraftResource::collection($drafts);
    }

    public function store(Request $request, Deal $deal)
    {
        $validated = $request->validate([
            'template_id' => ['required', 'string', 'exists:contract_templates,id'],
            'wizard_inputs' => ['sometimes', 'array'],
            // Per-draft override of the Provider signatory on the PDF.
            // Null/missing → fall back to tenant.signatory_*. Empty string
            // is treated as "explicitly leave blank for this draft".
            'signatory_name_override' => ['sometimes', 'nullable', 'string', 'max:255'],
            'signatory_title_override' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Customer-side signer captured at draft time. The deal's
            // contact_* fields are the day-to-day liaison (often a sales
            // rep / procurement contact) and are not the authorised signer.
            // All three optional — blank values render '____' on the PDF.
            'customer_signatory_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_signatory_title' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $template = ContractTemplate::findOrFail($validated['template_id']);

        $draft = $this->service->generateDraft(
            $deal,
            $template,
            $validated['wizard_inputs'] ?? [],
            $request->user(),
            $validated['signatory_name_override'] ?? null,
            $validated['signatory_title_override'] ?? null,
            $validated['customer_signatory_name'] ?? null,
            $validated['customer_signatory_title'] ?? null,
        );

        return new DealContractDraftResource($draft->load(['deal', 'template']));
    }

    public function show(DealContractDraft $contractDraft)
    {
        return new DealContractDraftResource($contractDraft->load(['deal', 'template']));
    }

    /**
     * Stream the rendered PDF inline so the wizard can preview the actual
     * customer-facing document (logo, layout, signature block) before
     * sending. Reuses the per-draft+version cache — edits/regenerates
     * already invalidate it via ContractPdfService::clearCache, so the
     * preview always reflects the current sections.
     */
    public function previewPdf(DealContractDraft $contractDraft, ContractPdfService $pdfService)
    {
        $absolutePath = $pdfService->renderDraft($contractDraft);

        if (! is_file($absolutePath)) {
            return response()->json(['message' => 'Could not render the preview PDF.'], 500);
        }

        $slug = \Illuminate\Support\Str::slug($contractDraft->deal?->name ?? 'contract');
        $filename = "{$slug}-v{$contractDraft->version}.pdf";

        return response()->file($absolutePath, [
            'Content-Type' => 'application/pdf',
            // 'inline' = render in browser; the iframe in the wizard reads it
            // straight from the blob URL the frontend builds after fetch.
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }

    public function updateSection(Request $request, DealContractDraft $contractDraft, string $sectionKey)
    {
        $validated = $request->validate([
            'content' => ['required', 'string'],
        ]);

        $draft = $this->service->updateSectionContent(
            $contractDraft,
            $sectionKey,
            $validated['content'],
        );

        return new DealContractDraftResource($draft->load(['deal', 'template']));
    }

    public function regenerateSection(Request $request, DealContractDraft $contractDraft)
    {
        $validated = $request->validate([
            'section_key' => ['required', 'string'],
            'wizard_inputs' => ['sometimes', 'array'],
        ]);

        $draft = $this->service->regenerateSection(
            $contractDraft,
            $validated['section_key'],
            $validated['wizard_inputs'] ?? [],
        );

        return new DealContractDraftResource($draft->load(['deal', 'template']));
    }

    public function finalise(Request $request, DealContractDraft $contractDraft)
    {
        $draft = $this->service->finaliseDraft($contractDraft, $request->user());

        return new DealContractDraftResource($draft->load(['deal', 'template']));
    }

    public function send(Request $request, DealContractDraft $contractDraft)
    {
        $validated = $request->validate([
            'to_email' => ['required', 'email', 'max:255'],
            'message' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $draft = $this->service->markSent(
            $contractDraft,
            $validated['to_email'],
            $request->user(),
            $validated['message'] ?? null,
        );

        return new DealContractDraftResource($draft->load(['deal', 'template']));
    }

    /**
     * Run an AI-backed verification of the customer's returned signed PDF
     * against the original we sent. Returns a verdict the wizard uses to
     * gate the actual mark-signed action. This endpoint does NOT mutate
     * the draft or store the uploaded file — it's a read-only check.
     */
    public function verifySigned(Request $request, DealContractDraft $contractDraft, SignedContractVerifier $verifier)
    {
        $request->validate([
            'signed_pdf' => ['required', 'file', 'mimes:pdf', 'max:25600'],
        ]);

        $verdict = $verifier->verify($contractDraft, $request->file('signed_pdf'));

        return response()->json(['data' => $verdict]);
    }

    public function markSigned(Request $request, DealContractDraft $contractDraft)
    {
        $request->validate([
            'signed_pdf' => ['required', 'file', 'mimes:pdf', 'max:25600'],
        ]);

        $result = $this->service->markSigned(
            $contractDraft,
            $request->file('signed_pdf'),
            $request->user(),
        );

        // Response shape: { document, auto_won, contract } so the frontend
        // can branch cleanly on auto_won and route to the new contract.
        return response()->json([
            'document' => (new DealContractDraftResource($result['document']->load(['deal', 'template'])))->resolve($request),
            'auto_won' => $result['auto_won'],
            'contract' => $result['contract']
                ? ['id' => $result['contract']->id]
                : null,
        ]);
    }

    public function destroy(DealContractDraft $contractDraft)
    {
        // Only soft-delete drafts that haven't been signed. A signed draft
        // is part of the contract audit trail.
        abort_if(
            $contractDraft->isSigned(),
            422,
            'A signed contract draft cannot be deleted.',
        );

        $contractDraft->delete();

        return response()->noContent();
    }
}
