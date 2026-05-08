<?php

/**
 * Seeds core "task" time entries for the active project (Apex Manufacturing).
 *
 * Note: ANKA does not have a separate tasks table; task descriptions are
 * stored inline on time_entries.task. This seeder establishes the primary
 * tasks that the TimeTrackingSeeder will build upon.
 */

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaskSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $now = Carbon::now()->toDateTimeString();
            $today = Carbon::now()->format('Y-m-d');
            $yesterday = Carbon::now()->subDay()->format('Y-m-d');
            $twoDaysAgo = Carbon::now()->subDays(2)->format('Y-m-d');

            DB::table('time_entries')->insert([
                // Developer tasks
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_DEV_ID,
                    'approved_by' => DemoDataMap::PIXEL_ADMIN_USER_ID,
                    'task' => 'Sensor ingestion API & MQTT broker setup',
                    'date' => $twoDaysAgo,
                    'hours' => 8.00,
                    'billable' => true,
                    'status' => 'Approved',
                    'approved_at' => $twoDaysAgo.' 18:00:00',
                    'notes' => 'Initial broker config and authentication layer.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_DEV_ID,
                    'approved_by' => DemoDataMap::PIXEL_ADMIN_USER_ID,
                    'task' => 'TimescaleDB schema & data retention policies',
                    'date' => $yesterday,
                    'hours' => 7.50,
                    'billable' => true,
                    'status' => 'Approved',
                    'approved_at' => $yesterday.' 18:00:00',
                    'notes' => 'Hypertables partitioning for 6-month retention.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                // Designer tasks
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_DESIGN_ID,
                    'approved_by' => DemoDataMap::PIXEL_ADMIN_USER_ID,
                    'task' => 'Dashboard wireframes & component library',
                    'date' => $twoDaysAgo,
                    'hours' => 6.00,
                    'billable' => true,
                    'status' => 'Approved',
                    'approved_at' => $twoDaysAgo.' 18:00:00',
                    'notes' => 'Figma design system v1 delivered.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                // PM tasks
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_PM_ID,
                    'approved_by' => DemoDataMap::PIXEL_ADMIN_USER_ID,
                    'task' => 'Sprint 1 planning & stakeholder kickoff',
                    'date' => $twoDaysAgo,
                    'hours' => 4.00,
                    'billable' => true,
                    'status' => 'Approved',
                    'approved_at' => $twoDaysAgo.' 18:00:00',
                    'notes' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                // QA tasks
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_QA_ID,
                    'approved_by' => DemoDataMap::PIXEL_ADMIN_USER_ID,
                    'task' => 'Test plan & CI pipeline scaffolding',
                    'date' => $yesterday,
                    'hours' => 5.00,
                    'billable' => true,
                    'status' => 'Approved',
                    'approved_at' => $yesterday.' 18:00:00',
                    'notes' => 'Playwright + GitHub Actions configured.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        });
    }
}
