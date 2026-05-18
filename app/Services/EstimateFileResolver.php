<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\EstimationVersion;
use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EstimateFileResolver
{
    public function latestForProject(Project $project): ?string
    {
        $contract = Contract::withoutGlobalScopes()
            ->where('id', $project->contract_id)
            ->first();

        if (! $contract || ! $contract->deal_id) {
            return null;
        }

        $version = EstimationVersion::withoutGlobalScopes()
            ->where('deal_id', $contract->deal_id)
            ->whereNotNull('xlsx_path')
            ->orderByDesc('version_number')
            ->orderByDesc('created_at')
            ->first();

        if (! $version || ! $version->xlsx_path) {
            return null;
        }

        // EstimationXlsxService writes via Storage::disk('local'), whose
        // Laravel-11 root is storage/app/private — NOT storage/app. Resolve
        // through the same disk so the path always matches the writer.
        $absolute = Storage::disk('local')->path($version->xlsx_path);

        if (! file_exists($absolute)) {
            Log::warning('EstimateFileResolver: xlsx_path row exists but file missing on disk', [
                'project_id'      => $project->id,
                'deal_id'         => $contract->deal_id,
                'version_number'  => $version->version_number,
                'xlsx_path'       => $version->xlsx_path,
                'resolved_path'   => $absolute,
            ]);

            return null;
        }

        return $absolute;
    }

    /**
     * Per-tenant Estimate.xlsx fallback used when a project has no
     * `estimation_versions.xlsx_path`. Each tenant gets its own copy under
     * `storage/app/tenants/{tenant_id}/estimate-fallback.xlsx`, preventing the
     * cross-tenant data leak that the legacy `public/storage/Estimate.xlsx`
     * fallback caused (every tenant reading the same file regardless of who
     * uploaded it).
     *
     * Returns null when the tenant has no fallback file; callers must surface
     * a 422 instead of silently sharing data.
     */
    public function tenantFallbackPath(string $tenantId): ?string
    {
        $path = Storage::disk('local')->path('tenants/'.$tenantId.'/estimate-fallback.xlsx');

        return file_exists($path) ? $path : null;
    }
}
