<?php

/**
 * Seeds contracts for the Pixel Agency won deal (Apex Manufacturing)
 * and the negotiation deal (Meridian Health).
 *
 * Contract numbers use the PostgreSQL sequence defaults when running
 * on Postgres; for SQLite / manual seeding we supply explicit numbers.
 */

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContractSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $now = Carbon::now()->toDateTimeString();

            DB::table('contracts')->insert([
                // ── Apex Manufacturing — Completed ────────────────────────
                [
                    'id' => DemoDataMap::CONTRACT_APEX_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'deal_id' => DemoDataMap::DEAL_APEX_ID,
                    'contract_number' => 'CON-0001',
                    'client' => 'Apex Manufacturing',
                    'total_value' => 175000.00,
                    'revenue_recognized' => 105000.00,
                    'status' => 'Completed',
                    'start_date' => Carbon::now()->subMonths(2)->format('Y-m-d'),
                    'end_date' => Carbon::now()->addMonths(4)->format('Y-m-d'),
                    'notes' => 'Fixed-price contract with milestone-based billing. IoT platform delivered on-premise with 12-month support.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                // ── Meridian Health — Draft (negotiation stage) ───────────
                [
                    'id' => DemoDataMap::CONTRACT_MERIDIAN_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'deal_id' => DemoDataMap::DEAL_MERIDIAN_ID,
                    'contract_number' => 'CON-0002',
                    'client' => 'Meridian Health',
                    'total_value' => 115000.00,
                    'revenue_recognized' => 0.00,
                    'status' => 'Draft',
                    'start_date' => Carbon::now()->addWeeks(2)->format('Y-m-d'),
                    'end_date' => Carbon::now()->addMonths(5)->addWeeks(2)->format('Y-m-d'),
                    'notes' => 'Awaiting final legal review and HIPAA BAA signature. Kick-off pending.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                // ── Hartwell Retail — Completed (standalone contract) ─────
                [
                    'id' => DemoDataMap::CONTRACT_HARTWELL_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'deal_id' => null,
                    'contract_number' => 'CON-0003',
                    'client' => 'Hartwell Retail Co',
                    'total_value' => 42000.00,
                    'revenue_recognized' => 42000.00,
                    'status' => 'Completed',
                    'start_date' => Carbon::now()->subMonths(4)->format('Y-m-d'),
                    'end_date' => Carbon::now()->subMonths(2)->format('Y-m-d'),
                    'notes' => 'Brand refresh delivered on time and on budget.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                // ── Sunrise Fintech — Draft (standalone contract) ─────────
                [
                    'id' => DemoDataMap::CONTRACT_SUNRISE_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'deal_id' => null,
                    'contract_number' => 'CON-0004',
                    'client' => 'Sunrise Fintech',
                    'total_value' => 90000.00,
                    'revenue_recognized' => 0.00,
                    'status' => 'Draft',
                    'start_date' => null,
                    'end_date' => null,
                    'notes' => 'Placeholder contract for upcoming mobile MVP engagement.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                // ── Nova Studio — Draft ───────────────────────────────────
                [
                    'id' => DemoDataMap::CONTRACT_NOVA1_ID,
                    'tenant_id' => DemoDataMap::NOVA_TENANT_ID,
                    'deal_id' => null,
                    'contract_number' => 'CON-0005',
                    'client' => 'Artisan Coffee Roasters',
                    'total_value' => 11000.00,
                    'revenue_recognized' => 0.00,
                    'status' => 'Draft',
                    'start_date' => null,
                    'end_date' => null,
                    'notes' => 'Portfolio site contract pending client approval.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        });
    }
}
