<?php

/**
 * Master demo seeder that orchestrates all sub-seeders in the correct
 * dependency order to build a complete, cross-referenced demo dataset.
 *
 * Primary org   : Pixel Agency
 * Secondary org : Nova Studio
 */

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            OwnerSeeder::class,
            TenantSeeder::class,
            EmployeeHoursSeeder::class,   // departments → roles → capacity_roles → employees → company_settings
            UserSeeder::class,            // links login accounts to employees
            CrmDealSeeder::class,         // deals + ghost_roles + hard_assignments + estimation_resources + deal_overheads
            ContractSeeder::class,        // contracts
            ProjectSeeder::class,         // projects + project_team_assignments
            TaskSeeder::class,            // core time-entry "tasks" for the active project
            TimeTrackingSeeder::class,    // additional time entries
            InvoiceSeeder::class,         // invoices linked to contracts
            FinanceSeeder::class,         // global overheads with period data for P&L
        ]);
    }
}
