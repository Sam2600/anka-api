<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DealContractDocument;
use App\Models\EstimationVersion;
use App\Services\EstimationAiService;
use App\Services\EstimationXlsxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     * AI-powered estimation draft for a deal. Reads-only: does NOT persist a
     * version. Returns a per-sheet JSON the frontend loads into the
     * Estimation Simulator's editable state; the user then reviews and uses
     * the normal POST estimation-versions to save.
     */
    public function aiDraft(Request $request, Deal $deal): JsonResponse
    {
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
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'AI service unavailable, please try again later.',
                'detail' => app()->environment('production') ? null : $e->getMessage(),
            ], 503);
        }

        return response()->json(['data' => $draft]);
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
