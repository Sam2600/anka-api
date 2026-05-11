<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'deal_id'              => $this->deal_id,
            'contract_number'      => $this->contract_number,
            'client'               => $this->client,
            'total_value'          => $this->total_value,
            'revenue_recognized'   => $this->revenue_recognized,
            'cash_collected'       => (float) ($this->cash_collected ?? 0),
            'status'               => $this->status,
            'start_date'           => $this->start_date?->toDateString(),
            'end_date'             => $this->end_date?->toDateString(),
            'signed_at'            => $this->signed_at,
            'payment_terms_days'   => (int) ($this->payment_terms_days ?? 30),
            'po_number'            => $this->po_number,
            'billing_contact_name' => $this->billing_contact_name,
            'billing_email'        => $this->billing_email,
            'currency'             => $this->currency,
            'tax_jurisdiction'     => $this->tax_jurisdiction,
            'notes'                => $this->notes,
            'created_at'           => $this->created_at,
        ];
    }
}
