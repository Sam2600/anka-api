<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MilestoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'contract_id'         => $this->contract_id,
            'name'                => $this->name,
            'due_date'            => $this->due_date?->toDateString(),
            'amount'              => $this->amount,
            'status'              => $this->status,
            'completed_at'        => $this->completed_at,
            'acceptance_criteria' => $this->acceptance_criteria,
            'accepted_at'         => $this->accepted_at,
            'accepted_by_client'  => $this->accepted_by_client,
            'sequence_number'     => $this->sequence_number !== null ? (int) $this->sequence_number : null,
        ];
    }
}
