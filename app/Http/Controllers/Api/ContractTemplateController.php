<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContractTemplateResource;
use App\Models\ContractTemplate;
use Illuminate\Http\Request;

/**
 * Read-only access to the contract template library. v1 ships with three
 * global SES variants (cloud_backup / managed_hosting / engineer_dispatch);
 * tenants can't edit yet — admin CRUD UI is deferred to v2.
 */
class ContractTemplateController extends Controller
{
    public function index(Request $request)
    {
        $query = ContractTemplate::query()->where('is_active', true);

        if ($request->filled('umbrella')) {
            $query->where('umbrella', $request->string('umbrella'));
        }

        // Tenant-owned templates listed before global so tenant overrides win
        // when slugs collide in the UI's variant picker.
        $templates = $query
            ->orderByRaw('CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('name')
            ->get();

        return ContractTemplateResource::collection($templates);
    }

    public function show(ContractTemplate $contractTemplate)
    {
        return new ContractTemplateResource($contractTemplate);
    }
}
