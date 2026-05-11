<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FlipOverdueInvoices extends Command
{
    protected $signature   = 'invoices:flip-overdue';
    protected $description = 'Flip Pending or Partially Paid invoices past their due_date to Overdue.';

    public function handle(): int
    {
        // Partial-paid invoices that go past due also count as Overdue from an
        // AR-aging standpoint — the remaining balance is what's late. We don't
        // wipe paid_amount; the status field is what AR dashboards key off.
        $flipped = DB::table('invoices')
            ->whereIn('status', ['Pending', 'Partially Paid'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->update(['status' => 'Overdue', 'updated_at' => now()]);

        $this->info("Flipped {$flipped} invoices to Overdue.");
        return self::SUCCESS;
    }
}
