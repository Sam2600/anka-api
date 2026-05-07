<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\EstimationVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            ]);

        return response()->json(['data' => $versions]);
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

        // Sync estimation resources to the deal
        DB::table('estimation_resources')->where('deal_id', $deal->id)->delete();
        foreach ($request->input('resources', []) as $res) {
            DB::table('estimation_resources')->insert([
                'id' => Str::orderedUuid(),
                'tenant_id' => $tenantId,
                'deal_id' => $deal->id,
                'role_id' => $res['roleId'] ?? $res['role_id'] ?? null,
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

        return response()->json([
            'data' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'target_margin' => $version->target_margin,
                'notes' => $version->notes,
                'created_at' => $version->created_at?->toISOString(),
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
            ],
        ]);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $version = EstimationVersion::findOrFail($id);

        // Restore estimation resources from the version snapshot
        $tenantId = app('tenant_id');
        DB::table('estimation_resources')->where('deal_id', $version->deal_id)->delete();
        foreach ($version->resources as $res) {
            DB::table('estimation_resources')->insert([
                'id' => Str::orderedUuid(),
                'tenant_id' => $tenantId,
                'deal_id' => $version->deal_id,
                'role_id' => $res['role_id'] ?? $res['roleId'] ?? null,
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
