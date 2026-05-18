<?php

namespace App\Mail;

use App\Models\Deal;
use App\Models\EstimationVersion;
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
 * Customer-facing email for the approved Estimate Doc (spec ④.G).
 *
 * Mirrors ContractDraftEmail's per-tenant From + Reply-To pattern so
 * estimate-from-Yangon-Works and estimate-from-Mandalay-Studio land
 * in the customer's inbox as their respective brands. The XLSX is
 * attached as the estimate document; the body is a short cover note
 * with an optional personal message from the salesperson.
 *
 *   From      → per-tenant: "{tenant.name} <{tenant.slug}@{from-address-domain}>"
 *   Reply-To  → the sender (salesperson who clicked Send)
 *   Subject   → "Estimate for {Deal} — {Provider}"
 *   Body      → resources/views/emails/estimate-approved.blade.php
 *   Attached  → the saved XLSX from EstimationXlsxService
 */
class EstimateApprovedEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Deal $deal,
        public EstimationVersion $version,
        public string $xlsxPath,
        public string $xlsxFilename,
        public ?User $sender = null,
        public ?string $message = null,
    ) {}

    public function envelope(): Envelope
    {
        $providerName = $this->resolveProviderName();
        $dealName = $this->deal->name ?: 'Service Estimate';

        $envelope = new Envelope(
            from: $this->resolveFromAddress($providerName),
            subject: "Estimate for {$dealName} — {$providerName}",
        );

        if ($this->sender?->email) {
            $envelope = $envelope->replyTo([
                new Address($this->sender->email, $this->sender->name ?? $this->sender->email),
            ]);
        }

        return $envelope;
    }

    private function resolveFromAddress(string $providerName): Address
    {
        $configured = config('mail.from.address') ?: 'noreply@example.com';
        $domain = str_contains($configured, '@')
            ? substr($configured, strrpos($configured, '@') + 1)
            : 'example.com';
        $tenant = $this->deal->tenant;
        $localPart = $this->safeLocalPart($tenant?->slug ?: ($tenant?->id ?? 'sender'));

        return new Address("{$localPart}@{$domain}", $providerName);
    }

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
            view: 'emails.estimate-approved',
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
                'versionNumber' => $this->version->version_number,
            ],
        );
    }

    private function resolveProviderName(): string
    {
        return $this->deal->tenant?->name
            ?: config('contract.provider_fallback.name', 'Provider');
    }

    /**
     * Attach the saved XLSX as the estimate document.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->xlsxPath)
                ->as($this->xlsxFilename)
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }
}
