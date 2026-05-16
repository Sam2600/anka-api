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
 *   From      → per-tenant: "{tenant.name} <{tenant.slug}@{from-address-domain}>"
 *               Domain is locked to whatever MAIL_FROM_ADDRESS uses (on the
 *               Mailgun sandbox this MUST be the sandbox domain); local-part
 *               and display name vary per tenant so each tenant's emails read
 *               as their own brand in the customer's inbox.
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
            from: $this->resolveFromAddress($providerName),
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

    /**
     * Build the per-tenant From address. The domain comes from the global
     * MAIL_FROM_ADDRESS env (locked to the Mailgun sandbox while we're on
     * sandbox; later, locked to the verified domain). The local-part is
     * the tenant slug, sanitised to be RFC-safe. The display name is the
     * tenant's editable Company name.
     */
    private function resolveFromAddress(string $providerName): Address
    {
        $configured = config('mail.from.address') ?: 'noreply@example.com';
        $domain = str_contains($configured, '@')
            ? substr($configured, strrpos($configured, '@') + 1)
            : 'example.com';

        $tenant = $this->deal->tenant;
        $localPart = $this->safeLocalPart(
            $tenant?->slug ?: ($tenant?->id ?? 'sender'),
        );

        return new Address("{$localPart}@{$domain}", $providerName);
    }

    /**
     * Sanitize an arbitrary string into a valid email local-part:
     *   - lowercase ASCII alphanumerics, hyphens, underscores, dots
     *   - collapse runs of disallowed chars to a single dash
     *   - trim leading/trailing dashes; fall back to "sender" when empty
     */
    private function safeLocalPart(string $raw): string
    {
        $lower = strtolower($raw);
        $cleaned = preg_replace('/[^a-z0-9._-]+/', '-', $lower) ?? '';
        $trimmed = trim($cleaned, '-.');
        return $trimmed !== '' ? $trimmed : 'sender';
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
