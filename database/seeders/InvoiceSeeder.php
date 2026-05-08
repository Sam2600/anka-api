<?php

/**
 * Seeds invoices linked to contracts and milestones.
 *
 * Pixel Agency:
 *   - Apex Manufacturing: 1 paid invoice, 1 pending invoice
 *   - Meridian Health: 1 draft invoice
 *
 * Note: invoices.total is a PostgreSQL GENERATED column (amount + tax).
 * We never set it from PHP.
 */

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InvoiceSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $now = Carbon::now()->toDateTimeString();

            // ── Milestones first (needed for invoice FKs) ───────────────
            DB::table('milestones')->insert([
                [
                    'id' => DemoDataMap::MILESTONE_APEX_1_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'contract_id' => DemoDataMap::CONTRACT_APEX_ID,
                    'name' => 'Phase 1: Discovery & Architecture',
                    'due_date' => Carbon::now()->subMonth()->format('Y-m-d'),
                    'amount' => 35000.00,
                    'status' => 'Completed',
                    'completed_at' => Carbon::now()->subMonth()->toDateTimeString(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::MILESTONE_APEX_2_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'contract_id' => DemoDataMap::CONTRACT_APEX_ID,
                    'name' => 'Phase 2: MVP Core Platform',
                    'due_date' => Carbon::now()->addMonths(2)->format('Y-m-d'),
                    'amount' => 70000.00,
                    'status' => 'In Progress',
                    'completed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::MILESTONE_MERIDIAN_1_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'contract_id' => DemoDataMap::CONTRACT_MERIDIAN_ID,
                    'name' => 'Kickoff & Architecture Design',
                    'due_date' => Carbon::now()->addMonth()->format('Y-m-d'),
                    'amount' => 23000.00,
                    'status' => 'Pending',
                    'completed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            // ── Invoices ────────────────────────────────────────────────
            DB::table('invoices')->insert([
                // Apex — Paid (linked to completed milestone)
                [
                    'id' => DemoDataMap::INVOICE_APEX_PAID_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'contract_id' => DemoDataMap::CONTRACT_APEX_ID,
                    'milestone_id' => DemoDataMap::MILESTONE_APEX_1_ID,
                    'invoice_number' => 'INV-1042',
                    'issue_date' => Carbon::now()->subMonth()->format('Y-m-d'),
                    'due_date' => Carbon::now()->subWeek()->format('Y-m-d'),
                    'amount' => 35000.00,
                    'tax' => 2800.00,
                    'status' => 'Paid',
                    'paid_at' => Carbon::now()->subWeeks(2)->toDateTimeString(),
                    'notes' => 'Phase 1 completion — paid via wire transfer.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                // Apex — Pending (interim billing)
                [
                    'id' => DemoDataMap::INVOICE_APEX_PENDING_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'contract_id' => DemoDataMap::CONTRACT_APEX_ID,
                    'milestone_id' => null,
                    'invoice_number' => 'INV-1043',
                    'issue_date' => Carbon::now()->subDays(5)->format('Y-m-d'),
                    'due_date' => Carbon::now()->addWeeks(3)->format('Y-m-d'),
                    'amount' => 25000.00,
                    'tax' => 2000.00,
                    'status' => 'Pending',
                    'paid_at' => null,
                    'notes' => 'Interim billing — Phase 2 partial progress.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                // Meridian — Draft
                [
                    'id' => DemoDataMap::INVOICE_MERIDIAN_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'contract_id' => DemoDataMap::CONTRACT_MERIDIAN_ID,
                    'milestone_id' => DemoDataMap::MILESTONE_MERIDIAN_1_ID,
                    'invoice_number' => 'INV-1044',
                    'issue_date' => Carbon::now()->format('Y-m-d'),
                    'due_date' => Carbon::now()->addMonth()->format('Y-m-d'),
                    'amount' => 23000.00,
                    'tax' => 1840.00,
                    'status' => 'Draft',
                    'paid_at' => null,
                    'notes' => 'Pro-forma invoice awaiting contract signature.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        });
    }
}
