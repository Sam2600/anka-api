<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\EstimationVersion;
use App\Models\Project;
use Illuminate\Support\Facades\Log;

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

        $absolute = storage_path('app/'.ltrim($version->xlsx_path, '/'));

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
}
