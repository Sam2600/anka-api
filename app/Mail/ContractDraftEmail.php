<?php

namespace App\Mail;

use App\Models\Deal;
use App\Models\DealContractDraft;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Customer-facing email for the contract draft.
 *
 *   From      → MAIL_FROM_ADDRESS (global tenant address)
 *   Reply-To  → the sender (the salesperson who clicked Send)
 *   Subject   → "Contract draft for {Deal} — {Provider}"
 *   Body      → resources/views/emails/contract-draft.blade.php
 *   Attached  → the rendered PDF from ContractPdfService::renderDraft
 *
 * Queued via ShouldQueue so the HTTP request returns immediately; the
 * worker handles the actual SMTP roundtrip.
 */
class ContractDraftEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Deal $deal,
        public DealContractDraft $draft,
        public string $pdfPath,
        public string $pdfFilename,
        public ?User $sender = null,
        public ?string $message = null,
    ) {}

    public function envelope(): Envelope
    {
        $providerName = $this->resolveProviderName();
        $dealName = $this->deal->name ?: 'Service Agreement';

        $envelope = new Envelope(
            subject: "Contract draft for {$dealName} — {$providerName}",
        );

        // Reply-To = the salesperson who clicked Send. Customer replies
        // land in their inbox, not the shared from-address.
        if ($this->sender?->email) {
            $envelope = $envelope->replyTo([
                new Address($this->sender->email, $this->sender->name ?? $this->sender->email),
            ]);
        }

        return $envelope;
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contract-draft',
            with: [
                'providerName' => $this->resolveProviderName(),
                'dealName'     => $this->deal->name,
                'clientName'   => $this->deal->client,
                'contactName'  => $this->deal->contact_name,
                'senderName'   => $this->sender?->name,
                'senderEmail'  => $this->sender?->email,
                'monthlyFee'   => $this->deal->final_monthly_fee,
                'currency'     => $this->deal->final_currency,
                'months'       => $this->deal->final_contract_months,
                'personalMessage' => $this->message,
                'draftVersion' => $this->draft->version,
            ],
        );
    }

    /**
     * Provider name shown in the email subject + body. Prefers the
     * tenant's own name (editable in Org → Company); falls back to the
     * global config when tenant data is unavailable.
     *
     * Runs in the queue worker, where the tenant scope isn't bound — so
     * we read via the deal's belongs-to relation, which uses the FK
     * directly and ignores the tenant scope.
     */
    private function resolveProviderName(): string
    {
        return $this->deal->tenant?->name
            ?: config('contract.provider_fallback.name', 'Provider');
    }

    /**
     * Attach the rendered PDF as the contract document.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->pdfPath)
                ->as($this->pdfFilename)
                ->withMime('application/pdf'),
        ];
    }
}
