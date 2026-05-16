<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DealContractDraftResource;
use App\Models\ContractTemplate;
use App\Models\Deal;
use App\Models\DealContractDraft;
use App\Services\ContractDraftService;
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
        ]);

        $template = ContractTemplate::findOrFail($validated['template_id']);

        $draft = $this->service->generateDraft(
            $deal,
            $template,
            $validated['wizard_inputs'] ?? [],
            $request->user(),
        );

        return new DealContractDraftResource($draft->load(['deal', 'template']));
    }

    public function show(DealContractDraft $contractDraft)
    {
        return new DealContractDraftResource($contractDraft->load(['deal', 'template']));
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
