<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GlobalOverheadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'category'        => $this->category,
            'description'     => $this->description,
            'monthly_cost'    => $this->monthly_cost,
            'effective_month' => $this->effective_month,
            'effective_year'  => $this->effective_year,
        ];
    }
}
