<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Http\Resources\InvoiceResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'notes'          => 'nullable|string|max:2000',
            // total is GENERATED ALWAYS — excluded from validation
        ]);

        $invoice = Invoice::create($validated);
        return new InvoiceResource($invoice->fresh());
    }

    public function pay(Invoice $invoice)
    {
        DB::transaction(function () use ($invoice) {
            $invoice->update(['status' => 'Paid', 'paid_at' => now()]);
            DB::table('contracts')
                ->where('id', $invoice->contract_id)
                ->increment('revenue_recognized', $invoice->total ?? 0);
        });

        return new InvoiceResource($invoice->fresh());
    }

    public function destroy(Invoice $invoice)
    {
        $invoice->delete();
        return response()->noContent();
    }
}
