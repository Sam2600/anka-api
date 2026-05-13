<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealContractDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'deal_id' => $this->deal_id,
            'uploaded_by' => $this->uploaded_by,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'extension' => $this->extension,
            'size_bytes' => $this->size_bytes,
            'analysis_status' => $this->analysis_status,
            'analysis_result' => $this->analysis_result,
            // Surfaced top-level for the UI score gauge and quick filtering.
            // Same values are also accessible inside analysis_result; these
            // two columns just save the frontend from unpacking the JSONB.
            'overall_score' => $this->overall_score,
            'detected_payment_pattern' => $this->detected_payment_pattern,
            // Snapshot of the prior verdict (when this is a re-upload).
            // Claude consumes it to produce diff_vs_previous; the UI can also
            // render a "previous vs current" comparison if/when we add one.
            'previous_analysis' => $this->previous_analysis,
            'analyzed_at' => $this->analyzed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
