<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiUsageLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'feature'             => $this->feature,
            'model'               => $this->model,
            'input_tokens'        => $this->input_tokens,
            'output_tokens'       => $this->output_tokens,
            'estimated_cost_usd'  => $this->estimated_cost_usd,
            'created_at'          => $this->created_at?->toIso8601String(),
        ];
    }
}
