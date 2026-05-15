<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DealContractDocumentResource;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\DealContractDocument;
use App\Models\Project;
use App\Services\ContractAnalysisService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Upload + analyse customer-signed contract documents for a deal that is in
 * the `negotiation` (A-rank) stage. When Claude approves a document the deal
 * is auto-transitioned to `won` via the same win_deal() path the manual Win
 * button uses, so the resulting Contract + Project are created atomically.
 */
class DealContractDocumentController extends Controller
{
    private const MAX_BYTES = 25 * 1024 * 1024; // 25 MB
    // xlsx + pptx were dropped because every release of phpoffice/phpspreadsheet
    // has published security advisories and Composer's audit gate blocks
    // installation. Clients should re-export those files as PDF or DOCX.
    private const ALLOWED_EXT = ['pdf', 'docx', 'txt'];

    public function index(Deal $deal)
    {
        $docs = DealContractDocument::where('deal_id', $deal->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return DealContractDocumentResource::collection($docs);
    }

    /**
     * Tenant-wide contract-document list — feeds the /contract-reviews
     * queue screen. Joins deal name + client so the table can render
     * "for which deal?" without an extra round trip per row.
     *
     * Optional filters: status (pending|analyzing|approved|rejected|failed),
     * search (matches against deal name, deal client, original filename —
     * case-insensitive substring).
     *
     * Pagination: standard Laravel paginate; per_page capped at 100.
     */
    public function indexAll(Request $request)
    {
        $query = DealContractDocument::query()
            ->with(['deal:id,name,client,status'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('analysis_status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $needle = '%'.$request->input('search').'%';
            $op = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($q) use ($needle, $op) {
                $q->where('original_filename', $op, $needle)
                    ->orWhereHas('deal', function ($dq) use ($needle, $op) {
                        $dq->where('name', $op, $needle)
                            ->orWhere('client', $op, $needle);
                    });
            });
        }

        $perPage = min((int) ($request->input('per_page', 50)), 100);

        return DealContractDocumentResource::collection($query->paginate($perPage));
    }

    public function show(DealContractDocument $contractDocument)
    {
        return new DealContractDocumentResource($contractDocument);
    }

    public function store(Request $request, Deal $deal, ContractAnalysisService $analyzer)
    {
        abort_if(
            $deal->status !== 'negotiation',
            422,
            'Contract documents can only be uploaded while the deal is in the Negotiation (A) stage.'
        );

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.(self::MAX_BYTES / 1024), // Laravel max: in KB
            ],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        abort_if(
            ! in_array($extension, self::ALLOWED_EXT, true),
            422,
            'Unsupported file type. Allowed: '.implode(', ', self::ALLOWED_EXT).'.'
        );

        $tenantId = app('tenant_id');

        // Re-upload semantics (per the chg-008 plan): keep ONE rolling level of
        // history. Snapshot the previous row's analysis_result into the new row's
        // `previous_analysis` so Claude can compute diff_vs_previous, then delete
        // the prior file + row. If the user wants full audit history later we'd
        // switch this to a "mark-superseded" pattern instead.
        //
        // The "real previous upload" check (`fileExistsOnDisk`) filters out the
        // seeded demo rows — they have fictional storage paths and no actual
        // files, so they shouldn't pollute first-time uploads with a phantom
        // diff against a verdict the user never produced.
        $previousDocs = DealContractDocument::where('deal_id', $deal->id)->get();
        $previousAnalysisForDiff = null;
        foreach ($previousDocs as $prev) {
            $fileExistsOnDisk = $prev->storage_path
                && Storage::disk('local')->exists($prev->storage_path);

            if ($prev->analysis_result && $fileExistsOnDisk && ! $previousAnalysisForDiff) {
                $previousAnalysisForDiff = $prev->analysis_result;
            }
            if ($fileExistsOnDisk) {
                Storage::disk('local')->delete($prev->storage_path);
            }
            $prev->delete();
        }

        $storagePath = sprintf(
            'contract-docs/%s/%s/%s.%s',
            $tenantId,
            $deal->id,
            Str::uuid(),
            $extension,
        );

        Storage::disk('local')->put($storagePath, file_get_contents($file->getRealPath()));

        $document = DealContractDocument::create([
            'tenant_id' => $tenantId,
            'deal_id' => $deal->id,
            'uploaded_by' => $request->user()?->id,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
            'extension' => $extension,
            'size_bytes' => $file->getSize(),
            'storage_path' => $storagePath,
            'analysis_status' => 'pending',
            'previous_analysis' => $previousAnalysisForDiff,
        ]);

        // Pass the deal so the analyzer can verify the uploaded contract is
        // actually for THIS deal (client name / project name / value match).
        // Without this the AI would happily approve a wrong-customer contract
        // and auto-fire win_deal() with mis-routed Contract + Project data.
        $document = $analyzer->analyze($document, $deal);

        // Auto-fire win when Claude (or the keyword fallback) approves the contract.
        if ($document->analysis_status === 'approved' && $deal->status === 'negotiation') {
            $winPayload = $this->autoWinDeal($deal);

            return response()->json([
                'document' => (new DealContractDocumentResource($document))->resolve($request),
                'auto_won' => true,
                'deal' => $winPayload['deal'] ?? null,
                'contract' => $winPayload['contract'] ?? null,
                'project' => $winPayload['project'] ?? null,
            ], 201);
        }

        return response()->json([
            'document' => (new DealContractDocumentResource($document))->resolve($request),
            'auto_won' => false,
        ], 201);
    }

    /**
     * Re-run AI analysis against an existing uploaded file. Used when the
     * first analysis hit the keyword fallback (Claude unreachable / proxy
     * timeout) — this lets the salesperson recover with one click instead
     * of having to delete + re-upload the file.
     *
     * If the re-analysis succeeds and is approved AND the deal is still in
     * negotiation, the existing auto-fire path runs (same as initial upload).
     */
    public function reanalyze(Request $request, DealContractDocument $contractDocument, ContractAnalysisService $analyzer)
    {
        // The analyser needs the actual file on disk to extract text.
        // Reject early if it's missing rather than letting the service
        // return a "failed" verdict and confusing the user.
        if (! $contractDocument->storage_path
            || ! Storage::disk('local')->exists($contractDocument->storage_path)) {
            abort(422, 'The original file is no longer available. Please upload again.');
        }

        $deal = $contractDocument->deal;

        $contractDocument = $analyzer->analyze($contractDocument, $deal);

        // Mirror the post-upload auto-win behaviour: if the new verdict
        // approves AND the deal is still in negotiation, fire win_deal().
        if ($contractDocument->analysis_status === 'approved'
            && $deal && $deal->status === 'negotiation') {
            $winPayload = $this->autoWinDeal($deal);

            return response()->json([
                'document' => (new DealContractDocumentResource($contractDocument->load('deal')))->resolve($request),
                'auto_won' => true,
                'deal' => $winPayload['deal'] ?? null,
                'contract' => $winPayload['contract'] ?? null,
                'project' => $winPayload['project'] ?? null,
            ]);
        }

        return response()->json([
            'document' => (new DealContractDocumentResource($contractDocument->load('deal')))->resolve($request),
            'auto_won' => false,
        ]);
    }

    public function destroy(DealContractDocument $contractDocument)
    {
        if ($contractDocument->storage_path && Storage::disk('local')->exists($contractDocument->storage_path)) {
            Storage::disk('local')->delete($contractDocument->storage_path);
        }

        $contractDocument->delete();

        return response()->noContent();
    }

    /**
     * Re-uses the same code path as DealController@win — calling win_deal()
     * on Postgres and the PHP fallback otherwise. Kept inline (not a shared
     * trait) because the existing controller's method is request-driven and
     * returns a Resource; here we just need the side effects + the linked
     * Contract/Project payload for the response.
     */
    private function autoWinDeal(Deal $deal): array
    {
        if (DB::getDriverName() === 'pgsql') {
            try {
                DB::select('SELECT win_deal(?, ?)', [$deal->id, app('tenant_id')]);
            } catch (QueryException $e) {
                $message = $e->getPrevious()?->getMessage() ?? $e->getMessage();
                abort(422, 'Failed to auto-win deal: '.$message);
            }
        } else {
            DB::transaction(function () use ($deal) {
                $existingContract = Contract::where('deal_id', $deal->id)->first();
                if (! $existingContract) {
                    $lastNumber = (int) (Contract::withoutGlobalScope('tenant')->max(
                        DB::raw('CAST(SUBSTR(contract_number, 5) AS INTEGER)')
                    ) ?? 0);
                    $nextNumber = 'CON-'.str_pad((string) ($lastNumber + 1), 4, '0', STR_PAD_LEFT);

                    $contract = Contract::create([
                        'id' => Str::orderedUuid(),
                        'tenant_id' => $deal->tenant_id,
                        'deal_id' => $deal->id,
                        'contract_number' => $nextNumber,
                        'client' => $deal->client ?? '',
                        'total_value' => $deal->client_budget ?? $deal->estimated_value ?? 0,
                        'status' => 'Signed',
                        'signed_at' => now(),
                        'start_date' => now()->toDateString(),
                    ]);

                    $lastPrj = (int) (Project::withoutGlobalScope('tenant')->max(
                        DB::raw('CAST(SUBSTR(project_number, 5) AS INTEGER)')
                    ) ?? 100);
                    $nextPrj = 'PRJ-'.str_pad((string) ($lastPrj + 1), 3, '0', STR_PAD_LEFT);

                    Project::create([
                        'id' => Str::orderedUuid(),
                        'tenant_id' => $deal->tenant_id,
                        'contract_id' => $contract->id,
                        'project_number' => $nextPrj,
                        'name' => $deal->name ?? '',
                        'client' => $deal->client ?? '',
                        'budget_hours' => $deal->workload_hours ?? 0,
                        'consumed_hours' => 0,
                        'status' => 'Not Started',
                        'start_date' => now()->toDateString(),
                    ]);
                }

                $deal->update([
                    'status' => 'won',
                    'win_probability' => 100,
                    'won_at' => now(),
                    'win_reason' => $deal->win_reason ?? 'Contract document approved by AI analysis.',
                ]);
            });
        }

        $contract = Contract::where('deal_id', $deal->id)->first();
        $project = $contract ? Project::where('contract_id', $contract->id)->first() : null;

        return [
            'deal' => (new \App\Http\Resources\DealResource($deal->fresh()->load([
                'ghost_roles', 'hard_assignments', 'estimation_resources', 'deal_overheads',
            ])))->resolve(request()),
            'contract' => $contract
                ? (new \App\Http\Resources\ContractResource($contract))->resolve(request())
                : null,
            'project' => $project
                ? (new \App\Http\Resources\ProjectResource($project))->resolve(request())
                : null,
        ];
    }
}
