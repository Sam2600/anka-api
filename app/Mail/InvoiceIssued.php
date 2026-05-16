<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceIssued extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        $number = $this->invoice->invoice_number ?? substr($this->invoice->id, 0, 8);
        $client = $this->invoice->contract?->client ?? 'Customer';

        return new Envelope(
            subject: "Invoice {$number} from {$this->invoice->contract?->tenant?->name} — {$client}",
        );
    }

    public function content(): Content
    {
        $contract = $this->invoice->contract;
        $tenant   = $contract?->tenant;
        $currency = $contract?->currency ?? $tenant?->currency ?? 'MMK';
        $total    = (float) ($this->invoice->total ?? ($this->invoice->amount + $this->invoice->tax));

        return new Content(
            view: 'emails.invoice-issued',
            with: [
                'invoiceNumber'  => $this->invoice->invoice_number ?? substr($this->invoice->id, 0, 8),
                'contractNumber' => $contract?->contract_number,
                'agencyName'     => $tenant?->name ?? 'Anka',
                'clientName'     => $contract?->client ?? 'Customer',
                'amount'         => number_format($this->invoice->amount, 2),
                'tax'            => number_format($this->invoice->tax, 2),
                'total'          => number_format($total, 2),
                'currency'       => $currency,
                'issueDate'      => $this->invoice->issue_date?->toDateString(),
                'dueDate'        => $this->invoice->due_date?->toDateString(),
                'poNumber'       => $contract?->po_number,
                'notes'          => $this->invoice->notes,
            ],
        );
    }
}
