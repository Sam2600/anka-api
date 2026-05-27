<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'contract_id'         => $this->contract_id,
            'milestone_id'        => $this->milestone_id,
            'invoice_number'      => $this->invoice_number,
            'issue_date'          => $this->issue_date?->toDateString(),
            'due_date'            => $this->due_date?->toDateString(),
            'amount'              => $this->amount,
            'tax'                 => $this->tax,
            'paid_amount'         => (float) ($this->paid_amount ?? 0),
            // total is a PostgreSQL GENERATED column — always read from DB, never set
            'total'               => $this->total,
            'status'              => $this->status,
            'paid_at'             => $this->paid_at,
            'issued_at'           => $this->issued_at,
            'sent_to_email'       => $this->sent_to_email,
            'reminder_sent_count' => (int) ($this->reminder_sent_count ?? 0),
            'notes'               => $this->notes,
            // Invoice template fields (added with the new Invoice menu).
            // line_items is the JSON snapshot locked at save time; null on
            // legacy invoices (the export builder falls back to a live
            // build from the linked deal's estimation).
            'memo'                  => $this->memo,
            'billing_period_label'  => $this->billing_period_label,
            'line_items'            => $this->line_items,
        ];
    }
}
