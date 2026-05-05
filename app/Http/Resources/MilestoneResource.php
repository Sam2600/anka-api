<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MilestoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'contract_id'  => $this->contract_id,
            'name'         => $this->name,
            'due_date'     => $this->due_date?->toDateString(),
            'amount'       => $this->amount,
            'status'       => $this->status,
            'completed_at' => $this->completed_at,
        ];
    }
}
