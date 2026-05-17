<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\EstimateApprovedEmail;
use App\Models\Deal;
use App\Models\DealContractDocument;
use App\Models\EstimationVersion;
use App\Services\EstimationAiService;
use App\Services\EstimationXlsxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class EstimationVersionController extends Controller
{
    public function index(Request $request, Deal $deal): JsonResponse
    {
        $versions = EstimationVersion::where('deal_id', $deal->id)
            ->orderBy('version_number', 'desc')
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'target_margin' => $v->target_margin,
                'resource_count' => count($v->resources ?? []),
                'overhead_count' => count($v->overheads ?? []),
                'notes' => $v->notes,
                'context_notes' => $v->context_notes,
                'has_context_notes' => ! empty(trim((string) $v->context_notes)),
                'created_at' => $v->created_at?->toISOString(),
                'xlsx_path' => $v->xlsx_path,
                'xlsx_available' => $this->xlsxAvailable($v),
            ]);

        return response()->json(['data' => $versions]);
    }

    /**
     * True iff the version has a stored XLSX whose file exists. Re-checks
     * filesystem on every read because storage cleanups or migrations
     * outside this code path could orphan the recorded path.
     */
    private function xlsxAvailable(EstimationVersion $v): bool
    {
        return ! empty($v->xlsx_path) && Storage::disk('local')->exists($v->xlsx_path);
    }

    /**
     * Sentinel rows (e.g. {_sheet1_summary: {...}}, {_sheet5_team_stack: [...]})
     * ride along inside the resources JSONB for the XLSX writer. They are
     * NOT real feature rows and must be filtered out before any sync into
     * the relational estimation_resources table.
     */
    private function isSentinelRow(mixed $row): bool
    {
        if (! is_array($row)) {
            return false;
        }
        foreach (array_keys($row) as $k) {
            if (is_string($k) && str_starts_with($k, '_')) {
                return true;
            }
        }

        return false;
    }

    public function store(Request $request, Deal $deal): JsonResponse
    {
        $request->validate([
            'resources' => 'required|array',
            'overheads' => 'required|array',
            'target_margin' => 'required|numeric|min:0|max:100',
            'notes' => 'nullable|string|max:500',
            // Customer meeting minutes / chat snippet that produced this
            // version. Captured per-version (frozen with snapshot) so future
            // reviewers can see what conversation drove each estimate.
            'context_notes' => 'nullable|string|max:20000',
        ]);

        $tenantId = app('tenant_id');

        $nextNumber = EstimationVersion::where('deal_id', $deal->id)->max('version_number') + 1;

        $version = EstimationVersion::create([
            'tenant_id' => $tenantId,
            'deal_id' => $deal->id,
            'version_number' => $nextNumber,
            'resources' => $request->input('resources', []),
            'overheads' => $request->input('overheads', []),
            'target_margin' => $request->input('target_margin'),
            'notes' => $request->input('notes'),
            'context_notes' => $request->input('context_notes'),
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);

        // Also update the deal's current estimation
        $deal->update([
            'target_margin' => $request->input('target_margin'),
        ]);

        // Sync estimation resources to the deal. Underscore-prefixed sentinel
        // rows (AI metadata like _sheet1_summary, _sheet5_team_stack) ride
        // along in the JSONB column but must not pollute estimation_resources.
        DB::table('estimation_resources')->where('deal_id', $deal->id)->delete();
        foreach ($request->input('resources', []) as $res) {
            if ($this->isSentinelRow($res)) {
                continue;
            }
            DB::table('estimation_resources')->insert([
                'id' => Str::orderedUuid(),
                'tenant_id' => $tenantId,
                'deal_id' => $deal->id,
                'job_role_id' => $res['roleId'] ?? $res['role_id'] ?? $res['jobRoleId'] ?? null,
                'role_id' => $res['roleId'] ?? $res['role_id'] ?? null,
                'employee_id' => $res['employeeId'] ?? $res['employee_id'] ?? null,
                'feature_name' => $res['featureName'] ?? $res['feature_name'] ?? '',
                'hours' => $res['hours'] ?? 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Sync deal overheads
        DB::table('deal_overheads')->where('deal_id', $deal->id)->delete();
        foreach ($request->input('overheads', []) as $oh) {
            DB::table('deal_overheads')->insert([
                'id' => Str::orderedUuid(),
                'tenant_id' => $tenantId,
                'deal_id' => $deal->id,
                'name' => $oh['name'] ?? '',
                'cost' => $oh['cost'] ?? 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // C → B auto-advance: same trigger as DealController::update, applied
        // here because Save Version writes estimation_resources via raw DB
        // queries instead of going through the deal update path. Refresh
        // first so the relation counts see what we just inserted.
        $deal->refresh();
        $deal->maybePromoteToQualified();

        // Generate the XLSX export for this version. Failures are logged but
        // never block the save — the user can still see the version in
        // history and a later download will lazy-regenerate.
        try {
            app(EstimationXlsxService::class)->generateAndStore($version->fresh());
        } catch (Throwable $e) {
            Log::warning('EstimationVersion: XLSX generation failed on save', [
                'version_id' => $version->id,
                'error' => $e->getMessage(),
            ]);
        }

        $version = $version->fresh();

        return response()->json([
            'data' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'target_margin' => $version->target_margin,
                'notes' => $version->notes,
                'context_notes' => $version->context_notes,
                'created_at' => $version->created_at?->toISOString(),
                'xlsx_path' => $version->xlsx_path,
                'xlsx_available' => $this->xlsxAvailable($version),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $version = EstimationVersion::findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $version->id,
                'deal_id' => $version->deal_id,
                'version_number' => $version->version_number,
                'resources' => $version->resources,
                'overheads' => $version->overheads,
                'target_margin' => $version->target_margin,
                'notes' => $version->notes,
                'context_notes' => $version->context_notes,
                'created_at' => $version->created_at?->toISOString(),
                'xlsx_path' => $version->xlsx_path,
                'xlsx_available' => $this->xlsxAvailable($version),
            ],
        ]);
    }

    /**
     * Stream the saved estimation XLSX for this version. Lazy-generates if
     * xlsx_path is null OR the file is missing on disk (e.g. partial
     * post-win migration). Cross-tenant access surfaces as 404 via the
     * BelongsToTenant scope.
     */
    public function downloadXlsx(Request $request, string $id): StreamedResponse
    {
        $version = EstimationVersion::findOrFail($id);

        if (! $this->xlsxAvailable($version)) {
            try {
                app(EstimationXlsxService::class)->generateAndStore($version);
                $version = $version->fresh();
            } catch (Throwable $e) {
                Log::error('EstimationVersion: lazy XLSX regeneration failed', [
                    'version_id' => $version->id,
                    'error' => $e->getMessage(),
                ]);
                abort(503, 'Could not generate the estimation XLSX. Please try again.');
            }
        }

        return Storage::disk('local')->download(
            $version->xlsx_path,
            basename($version->xlsx_path),
        );
    }

    /**
     * Spec ④.G — email the saved estimate XLSX to the customer. Lazy-
     * generates the XLSX if it's missing (e.g. version was created
     * before xlsx_path was a thing). Queues via Mailgun using
     * EstimateApprovedEmail; mirrors the contract-draft send flow.
     *
     * Records the send on the version (`sent_at`, `sent_to_email`) so
     * the UI can show "Sent on X to Y" and the user can re-send by
     * calling again (overwrites the timestamp).
     *
     * Body shape: { to_email: string, message?: string }
     */
    public function sendXlsx(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'to_email' => 'required|email|max:255',
            'message'  => 'sometimes|nullable|string|max:2000',
        ]);

        $version = EstimationVersion::findOrFail($id);
        $deal = Deal::findOrFail($version->deal_id);

        if (! $this->xlsxAvailable($version)) {
            try {
                app(EstimationXlsxService::class)->generateAndStore($version);
                $version = $version->fresh();
            } catch (Throwable $e) {
                Log::error('EstimationVersion: lazy XLSX regeneration failed during send', [
                    'version_id' => $version->id,
                    'error' => $e->getMessage(),
                ]);
                abort(503, 'Could not generate the estimation XLSX. Please try again.');
            }
        }

        $absolutePath = Storage::disk('local')->path($version->xlsx_path);
        $filename = $this->buildAttachmentFilename($deal, $version);
        $toEmail = (string) $request->input('to_email');
        $message = $request->filled('message') ? (string) $request->input('message') : null;

        try {
            Mail::to($toEmail)->queue(new EstimateApprovedEmail(
                deal: $deal,
                version: $version,
                xlsxPath: $absolutePath,
                xlsxFilename: $filename,
                sender: $request->user(),
                message: $message,
            ));
        } catch (Throwable $e) {
            Log::error('EstimationVersion: queue email failed', [
                'version_id' => $version->id,
                'to' => $toEmail,
                'error' => $e->getMessage(),
            ]);
            abort(503, 'Could not queue the estimate email. Please try again.');
        }

        $version->forceFill([
            'sent_at' => now(),
            'sent_to_email' => $toEmail,
        ])->save();

        return response()->json([
            'data' => [
                'id' => $version->id,
                'sent_at' => $version->sent_at?->toIso8601String(),
                'sent_to_email' => $version->sent_to_email,
                'version_number' => $version->version_number,
            ],
        ]);
    }

    /**
     * Build a customer-friendly XLSX filename — readable, no UUIDs.
     */
    private function buildAttachmentFilename(Deal $deal, EstimationVersion $version): string
    {
        $dealSlug = Str::slug($deal->name ?: 'estimate', '_') ?: 'estimate';
        return sprintf('%s_estimate_v%d.xlsx', $dealSlug, $version->version_number);
    }

    /**
     * AI-powered estimation draft for a deal. Reads-only: does NOT persist a
     * version. Returns a per-sheet JSON the frontend loads into the
     * Estimation Simulator's editable state; the user then reviews and uses
     * the normal POST estimation-versions to save.
     */
    public function aiDraft(Request $request, Deal $deal): JsonResponse
    {
        // Lift any inherited timeout (e.g. php artisan serve default 60s, php-fpm
        // request_terminate_timeout). The Anthropic proxy can take up to ~180s
        // for the structured draft and we don't want PHP to kill the process
        // mid-flight. set_time_limit(0) = unlimited.
        @set_time_limit(0);
        @ignore_user_abort(true);
        // Bump memory for this request only. The CLI default of 128M can be
        // exhausted by the prompt builder when ORG_ROLES + few-shot deals +
        // contract docs all stack up, causing a fatal error that drops the
        // connection silently (no Laravel log, browser sees "cancelled").
        @ini_set('memory_limit', '512M');

        $startedAt = microtime(true);
        Log::info('EstimationVersion: AI draft request received', [
            'deal_id' => $deal->id,
        ]);

        // Require at least one of the inputs the AI needs to generate something
        // sensible. workload_description is the cheap signal; a contract doc
        // is the rich one. Without either, the draft would be pure guesswork.
        $hasDescription = ! empty(trim((string) ($deal->workload_description ?? '')));
        $hasContractDoc = DealContractDocument::where('deal_id', $deal->id)
            ->whereIn('analysis_status', ['approved', 'pending'])
            ->exists();

        if (! $hasDescription && ! $hasContractDoc) {
            return response()->json([
                'message' => 'Deal needs a workload description or attached contract document before AI can generate an estimation.',
            ], 422);
        }

        try {
            $draft = app(EstimationAiService::class)->generateDraft($deal);
        } catch (Throwable $e) {
            Log::error('EstimationVersion: AI draft generation failed', [
                'deal_id' => $deal->id,
                'elapsed_s' => round(microtime(true) - $startedAt, 1),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'AI service unavailable, please try again later.',
                'detail' => app()->environment('production') ? null : $e->getMessage(),
            ], 503);
        }

        Log::info('EstimationVersion: AI draft generated', [
            'deal_id' => $deal->id,
            'elapsed_s' => round(microtime(true) - $startedAt, 1),
        ]);

        return response()->json(['data' => $draft]);
    }

    /**
     * Suggest a structured diff (add / remove / modify) of scope rows and
     * overheads from customer meeting notes. Reads-only — does NOT persist.
     * The frontend renders the diff in a review panel and applies the
     * accepted changes via the normal Save Version path with context_notes
     * set on the new EstimationVersion row.
     */
    public function aiDelta(Request $request, Deal $deal): JsonResponse
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        @ini_set('memory_limit', '512M');

        $request->validate([
            'context_notes' => 'required|string|min:5|max:20000',
            'current_resources' => 'nullable|array',
            'current_overheads' => 'nullable|array',
        ]);

        $startedAt = microtime(true);
        Log::info('EstimationVersion: AI delta request received', [
            'deal_id' => $deal->id,
            'notes_chars' => mb_strlen($request->input('context_notes', '')),
            'resources_count' => count($request->input('current_resources', [])),
            'overheads_count' => count($request->input('current_overheads', [])),
        ]);

        try {
            $delta = app(EstimationAiService::class)->generateDelta(
                $deal,
                (string) $request->input('context_notes'),
                $request->input('current_resources', []),
                $request->input('current_overheads', []),
            );
        } catch (Throwable $e) {
            Log::error('EstimationVersion: AI delta generation failed', [
                'deal_id' => $deal->id,
                'elapsed_s' => round(microtime(true) - $startedAt, 1),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'AI service unavailable, please try again later.',
                'detail' => app()->environment('production') ? null : $e->getMessage(),
            ], 503);
        }

        Log::info('EstimationVersion: AI delta generated', [
            'deal_id' => $deal->id,
            'elapsed_s' => round(microtime(true) - $startedAt, 1),
        ]);

        return response()->json(['data' => $delta]);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $version = EstimationVersion::findOrFail($id);

        // Restore estimation resources from the version snapshot. Skip
        // sentinel rows (AI metadata) — same rule as store().
        $tenantId = app('tenant_id');
        DB::table('estimation_resources')->where('deal_id', $version->deal_id)->delete();
        foreach ($version->resources as $res) {
            if ($this->isSentinelRow($res)) {
                continue;
            }
            DB::table('estimation_resources')->insert([
                'id' => Str::orderedUuid(),
                'tenant_id' => $tenantId,
                'deal_id' => $version->deal_id,
                'job_role_id' => $res['role_id'] ?? $res['roleId'] ?? $res['job_role_id'] ?? null,
                'role_id' => $res['role_id'] ?? $res['roleId'] ?? null,
                'employee_id' => $res['employee_id'] ?? $res['employeeId'] ?? null,
                'feature_name' => $res['feature_name'] ?? $res['featureName'] ?? '',
                'hours' => $res['hours'] ?? 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Restore overheads
        DB::table('deal_overheads')->where('deal_id', $version->deal_id)->delete();
        foreach ($version->overheads as $oh) {
            DB::table('deal_overheads')->insert([
                'id' => Str::orderedUuid(),
                'tenant_id' => $tenantId,
                'deal_id' => $version->deal_id,
                'name' => $oh['name'] ?? '',
                'cost' => $oh['cost'] ?? 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Update deal target margin
        Deal::where('id', $version->deal_id)->update([
            'target_margin' => $version->target_margin,
        ]);

        return response()->json([
            'data' => [
                'version_number' => $version->version_number,
                'restored' => true,
            ],
        ]);
    }
}
