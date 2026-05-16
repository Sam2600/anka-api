<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\InvoiceIssued;
use App\Models\Invoice;
use App\Http\Resources\InvoiceResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::query();

        if ($request->filled('contract_id')) {
            $query->where('contract_id', $request->contract_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = min((int) ($request->per_page ?? 50), 200);
        return InvoiceResource::collection($query->orderBy('issue_date', 'desc')->paginate($perPage));
    }

    public function show(Invoice $invoice)
    {
        return new InvoiceResource($invoice);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'contract_id'    => 'required|uuid|exists:contracts,id',
            'milestone_id'   => 'nullable|uuid|exists:milestones,id',
            'invoice_number' => 'nullable|string|max:100',
            'issue_date'     => 'required|date',
            'due_date'       => 'nullable|date|after_or_equal:issue_date',
            'amount'         => 'required|numeric|min:0',
            'tax'            => 'nullable|numeric|min:0',
            'status'         => 'nullable|in:Draft,Pending',
            'notes'          => 'nullable|string|max:2000',
            // total is GENERATED ALWAYS — excluded from validation
        ]);

        // PostgreSQL fills invoice_number from a sequence default (INV-0001, …).
        // On SQLite / other drivers there's no default, so we generate one in
        // PHP. This keeps dev environments working without touching the prod
        // sequence-based numbering scheme.
        if (empty($validated['invoice_number']) && DB::connection()->getDriverName() !== 'pgsql') {
            $next = Invoice::withTrashed()->withoutGlobalScopes()->count() + 1;
            $validated['invoice_number'] = 'INV-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        }

        $invoice = Invoice::create($validated);
        return new InvoiceResource($invoice->fresh());
    }

    public function update(Request $request, Invoice $invoice)
    {
        // Only Draft / Pending invoices can be structurally edited. Once Paid (full
        // or partial), only `notes` is safe to change — the rest is locked to keep
        // payment math + audit trail consistent with what was sent to the client.
        if (in_array($invoice->status, ['Paid', 'Cancelled'], true)) {
            $request->validate([
                'notes' => 'sometimes|nullable|string|max:2000',
            ]);
            $invoice->update($request->only(['notes']));
            return new InvoiceResource($invoice->fresh());
        }

        $validated = $request->validate([
            'milestone_id' => 'sometimes|nullable|uuid|exists:milestones,id',
            'issue_date'   => 'sometimes|required|date',
            'due_date'     => 'sometimes|nullable|date',
            'amount'       => 'sometimes|required|numeric|min:0',
            'tax'          => 'sometimes|nullable|numeric|min:0',
            'notes'        => 'sometimes|nullable|string|max:2000',
        ]);

        $invoice->update($validated);
        return new InvoiceResource($invoice->fresh());
    }

    /**
     * Record a payment against an invoice. The `amount` field is optional:
     * if omitted, the invoice is treated as fully paid (covers the legacy
     * "Mark as Paid" UI). When `amount` is supplied, it is applied as a
     * partial payment and the invoice's status is recomputed based on the
     * cumulative paid_amount vs total.
     */
    public function pay(Request $request, Invoice $invoice)
    {
        $request->validate([
            'amount' => 'sometimes|nullable|numeric|min:0.01',
        ]);

        $total           = (float) ($invoice->total ?? ($invoice->amount + $invoice->tax));
        $alreadyPaid     = (float) ($invoice->paid_amount ?? 0);
        $remaining       = max(0, $total - $alreadyPaid);
        $requestedAmount = $request->filled('amount') ? (float) $request->amount : $remaining;

        if ($remaining <= 0) {
            return response()->json(['message' => 'Invoice is already fully paid.'], 409);
        }

        // Cap at remaining so a client can't accidentally pay more than what's owed.
        $appliedAmount = min($requestedAmount, $remaining);

        DB::transaction(function () use ($invoice, $appliedAmount, $alreadyPaid, $total) {
            $newPaid   = $alreadyPaid + $appliedAmount;
            $newStatus = $newPaid >= $total ? 'Paid' : 'Partially Paid';
            $paidAt    = $newStatus === 'Paid' ? now() : $invoice->paid_at;

            $invoice->update([
                'paid_amount' => $newPaid,
                'status'      => $newStatus,
                'paid_at'     => $paidAt,
            ]);

            // Cash basis: track cumulative payments received.
            // Revenue recognition (accrual) lives on milestone Accept, not here.
            DB::table('contracts')
                ->where('id', $invoice->contract_id)
                ->increment('cash_collected', $appliedAmount);
        });

        return new InvoiceResource($invoice->fresh());
    }

    /**
     * Email the invoice to the contract's billing email (or an override address).
     * Marks `issued_at` if not already set, increments `reminder_sent_count` if it
     * was. The first call is the "issue" event; subsequent calls are reminders.
     */
    public function send(Request $request, Invoice $invoice)
    {
        $validated = $request->validate([
            'to' => 'sometimes|nullable|email|max:255',
        ]);

        $invoice->load('contract');
        $to = $validated['to'] ?? $invoice->sent_to_email ?? $invoice->contract?->billing_email;

        if (! $to) {
            throw new HttpException(422, 'No recipient email. Set a billing email on the contract or pass `to` in the request.');
        }

        Mail::to($to)->queue(new InvoiceIssued($invoice));

        $isFirstSend = $invoice->issued_at === null;
        $invoice->update([
            'issued_at'           => $invoice->issued_at ?? now(),
            'sent_to_email'       => $to,
            'reminder_sent_count' => $isFirstSend ? 0 : ($invoice->reminder_sent_count + 1),
            // Status promotion: Draft invoices become Pending the moment they're issued.
            'status'              => $invoice->status === 'Draft' ? 'Pending' : $invoice->status,
        ]);

        return new InvoiceResource($invoice->fresh());
    }

    public function destroy(Invoice $invoice)
    {
        $invoice->delete();
        return response()->noContent();
    }
}
