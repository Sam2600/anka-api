<?php

/**
 * Seeds projects linked to contracts, plus project-team assignments.
 *
 * Pixel Agency:
 *   1. Apex Manufacturing IoT Platform — Active (linked to completed contract)
 *   2. Hartwell Retail Brand Refresh — Completed
 *   3. Sunrise Fintech Mobile App MVP — Not Started
 *
 * Nova Studio:
 *   1. Artisan Coffee Portfolio Site — Not Started
 */

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $now = Carbon::now()->toDateTimeString();

            DB::table('projects')->insert([
                [
                    'id' => DemoDataMap::PROJECT_APEX_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'contract_id' => DemoDataMap::CONTRACT_APEX_ID,
                    'project_number' => 'PRJ-101',
                    'name' => 'IoT Platform',
                    'client' => 'Apex Manufacturing',
                    'budget_hours' => 1440.00,
                    'consumed_hours' => 320.00,
                    'status' => 'On Track',
                    'start_date' => Carbon::now()->subMonths(2)->format('Y-m-d'),
                    'end_date' => Carbon::now()->addMonths(4)->format('Y-m-d'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PROJECT_HARTWELL_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'contract_id' => DemoDataMap::CONTRACT_HARTWELL_ID,
                    'project_number' => 'PRJ-102',
                    'name' => 'Brand Refresh',
                    'client' => 'Hartwell Retail Co',
                    'budget_hours' => 320.00,
                    'consumed_hours' => 320.00,
                    'status' => 'Completed',
                    'start_date' => Carbon::now()->subMonths(4)->format('Y-m-d'),
                    'end_date' => Carbon::now()->subMonths(2)->format('Y-m-d'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PROJECT_SUNRISE_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'contract_id' => DemoDataMap::CONTRACT_SUNRISE_ID,
                    'project_number' => 'PRJ-103',
                    'name' => 'Mobile App MVP',
                    'client' => 'Sunrise Fintech',
                    'budget_hours' => 720.00,
                    'consumed_hours' => 0.00,
                    'status' => 'Not Started',
                    'start_date' => null,
                    'end_date' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PROJECT_NOVA1_ID,
                    'tenant_id' => DemoDataMap::NOVA_TENANT_ID,
                    'contract_id' => DemoDataMap::CONTRACT_NOVA1_ID,
                    'project_number' => 'PRJ-104',
                    'name' => 'Portfolio Site',
                    'client' => 'Artisan Coffee Roasters',
                    'budget_hours' => 120.00,
                    'consumed_hours' => 0.00,
                    'status' => 'Not Started',
                    'start_date' => null,
                    'end_date' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            // ── Project Team Assignments (Apex — active project) ────────
            // Simulating the "auto-assign" that happens when win_deal() runs.
            DB::table('project_team_assignments')->insert([
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_DEV_ID,
                    'allocated_hours' => 640.00,
                    'assignment_source' => 'deal_transfer',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_DESIGN_ID,
                    'allocated_hours' => 320.00,
                    'assignment_source' => 'deal_transfer',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_PM_ID,
                    'allocated_hours' => 480.00,
                    'assignment_source' => 'deal_transfer',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_QA_ID,
                    'allocated_hours' => 240.00,
                    'assignment_source' => 'deal_transfer',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        });
    }
}
