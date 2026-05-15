<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RankResource;
use App\Models\Rank;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tenant-managed seniority ranks (Junior / Mid / Senior / Lead by default,
 * extensible per tenant). Used by the AI Team Builder to make seniority
 * decisions deterministic instead of keyword-matching free-text titles.
 *
 * Soft-delete: deleting a rank does not orphan employees — the FK is
 * ON DELETE SET NULL on hard-delete, and a soft-deleted rank still keeps
 * its rank_id intact on employees until force-deleted. Listings hide
 * soft-deleted rows automatically via the SoftDeletes trait.
 */
class RankController extends Controller
{
    public function index()
    {
        return RankResource::collection(
            Rank::orderBy('level')->orderBy('name')->get()
        );
    }

    public function store(Request $request)
    {
        $tenantId = app('tenant_id');

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => [
                'required', 'string', 'max:50',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('ranks', 'code')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'level' => 'required|integer|min:0|max:100',
        ]);

        $rank = Rank::create($data);

        return new RankResource($rank);
    }

    public function update(Request $request, Rank $rank)
    {
        $tenantId = app('tenant_id');

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'code' => [
                'sometimes', 'required', 'string', 'max:50',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('ranks', 'code')
                    ->ignore($rank->id)
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'level' => 'sometimes|required|integer|min:0|max:100',
        ]);

        $rank->update($data);

        return new RankResource($rank->fresh());
    }

    public function destroy(Rank $rank)
    {
        // Soft-delete only — employees keep rank_id pointing at the soft-
        // deleted rank so the data isn't lost. A subsequent restore() brings
        // them back automatically. Force-delete is intentionally not exposed
        // because the FK would null out every employee's rank_id at once.
        $rank->delete();

        return response()->noContent();
    }
}
