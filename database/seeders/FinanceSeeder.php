<?php

/**
 * Seeds global overheads with effective_month / effective_year so that
 * the client-side P&L calculator (store.getFinancialPnL) has realistic
 * fixed-cost data for the last 3 months.
 *
 * Note: getFinancialPnL currently applies the same monthly overhead total
 * to every month that has revenue or labour data, but we seed period-aware
 * rows for future-proofing.
 */

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $now = Carbon::now()->toDateTimeString();
            $currentMonth = (int) Carbon::now()->format('n');
            $currentYear = (int) Carbon::now()->format('Y');

            // Pixel Agency overheads (seeded for current month so P&L sees them)
            DB::table('global_overheads')->insert([
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'category' => 'Office Rent',
                    'description' => 'Downtown coworking private suite',
                    'monthly_cost' => 3500.00,
                    'effective_month' => null,
                    'effective_year' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'category' => 'Software Licenses',
                    'description' => 'Figma, GitHub, AWS, Slack, Linear',
                    'monthly_cost' => 2200.00,
                    'effective_month' => null,
                    'effective_year' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'category' => 'Accounting & Legal',
                    'description' => 'Bookkeeping, payroll, and legal retainer',
                    'monthly_cost' => 1500.00,
                    'effective_month' => null,
                    'effective_year' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'category' => 'Marketing & Events',
                    'description' => 'Conference tickets, ads, and swag',
                    'monthly_cost' => 800.00,
                    'effective_month' => null,
                    'effective_year' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            // Nova Studio overheads (minimal)
            DB::table('global_overheads')->insert([
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::NOVA_TENANT_ID,
                    'category' => 'Studio Rent',
                    'description' => 'Shared creative studio space',
                    'monthly_cost' => 1200.00,
                    'effective_month' => null,
                    'effective_year' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::NOVA_TENANT_ID,
                    'category' => 'Software',
                    'description' => 'Adobe CC, Notion, Dropbox',
                    'monthly_cost' => 400.00,
                    'effective_month' => null,
                    'effective_year' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        });
    }
}
