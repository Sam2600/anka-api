<?php

/**
 * Seeds additional time-tracking entries for the active project,
 * mixing Draft, Pending, and Approved statuses so the Time Tracking
 * page shows a realistic approval workflow and burn-rate data.
 */

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TimeTrackingSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $now = Carbon::now()->toDateTimeString();
            $today = Carbon::now()->format('Y-m-d');
            $yesterday = Carbon::now()->subDay()->format('Y-m-d');
            $twoDaysAgo = Carbon::now()->subDays(2)->format('Y-m-d');
            $threeDaysAgo = Carbon::now()->subDays(3)->format('Y-m-d');
            $lastMonth = Carbon::now()->subMonth()->format('Y-m-d');
            $twoMonthsAgo = Carbon::now()->subMonths(2)->format('Y-m-d');

            DB::table('time_entries')->insert([
                // ── Developer — additional entries ─────────────────────────
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_DEV_ID,
                    'approved_by' => null,
                    'task' => 'Anomaly detection rule engine prototyping',
                    'date' => $today,
                    'hours' => 6.00,
                    'billable' => true,
                    'status' => 'Draft',
                    'approved_at' => null,
                    'notes' => 'Exploring statistical thresholds for alert triggers.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_DEV_ID,
                    'approved_by' => DemoDataMap::PIXEL_ADMIN_USER_ID,
                    'task' => 'Docker Compose local dev environment',
                    'date' => $threeDaysAgo,
                    'hours' => 5.00,
                    'billable' => false,
                    'status' => 'Approved',
                    'approved_at' => $threeDaysAgo.' 18:00:00',
                    'notes' => 'Internal tooling — non-billable.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],

                // ── Designer — additional entries ──────────────────────────
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_DESIGN_ID,
                    'approved_by' => null,
                    'task' => 'High-fidelity dashboard mockups v2',
                    'date' => $today,
                    'hours' => 7.00,
                    'billable' => true,
                    'status' => 'Pending',
                    'approved_at' => null,
                    'notes' => 'Awaiting feedback on colour accessibility.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_DESIGN_ID,
                    'approved_by' => DemoDataMap::PIXEL_ADMIN_USER_ID,
                    'task' => 'Icon set & visual asset exports',
                    'date' => $yesterday,
                    'hours' => 4.00,
                    'billable' => true,
                    'status' => 'Approved',
                    'approved_at' => $yesterday.' 18:00:00',
                    'notes' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],

                // ── PM — additional entries ────────────────────────────────
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_PM_ID,
                    'approved_by' => null,
                    'task' => 'Risk register update & client status report',
                    'date' => $yesterday,
                    'hours' => 3.00,
                    'billable' => true,
                    'status' => 'Pending',
                    'approved_at' => null,
                    'notes' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_PM_ID,
                    'approved_by' => DemoDataMap::PIXEL_ADMIN_USER_ID,
                    'task' => 'Vendor evaluation — edge hardware suppliers',
                    'date' => $threeDaysAgo,
                    'hours' => 4.00,
                    'billable' => true,
                    'status' => 'Approved',
                    'approved_at' => $threeDaysAgo.' 18:00:00',
                    'notes' => 'Compared 3 supplier quotes.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],

                // ── QA — additional entries ────────────────────────────────
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_QA_ID,
                    'approved_by' => null,
                    'task' => 'Load-test scenario design for MQTT throughput',
                    'date' => $today,
                    'hours' => 5.50,
                    'billable' => true,
                    'status' => 'Draft',
                    'approved_at' => null,
                    'notes' => 'Targeting 10 k messages / sec baseline.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_QA_ID,
                    'approved_by' => DemoDataMap::PIXEL_ADMIN_USER_ID,
                    'task' => 'Regression suite for auth middleware',
                    'date' => $twoDaysAgo,
                    'hours' => 6.00,
                    'billable' => true,
                    'status' => 'Approved',
                    'approved_at' => $twoDaysAgo.' 18:00:00',
                    'notes' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],

                // ── Historical entries for P&L (2 months ago) ────────────
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_DEV_ID,
                    'approved_by' => DemoDataMap::PIXEL_ADMIN_USER_ID,
                    'task' => 'IoT gateway prototype architecture',
                    'date' => $twoMonthsAgo,
                    'hours' => 8.00,
                    'billable' => true,
                    'status' => 'Approved',
                    'approved_at' => $twoMonthsAgo.' 18:00:00',
                    'notes' => 'Raspberry Pi edge node PoC.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_PM_ID,
                    'approved_by' => DemoDataMap::PIXEL_ADMIN_USER_ID,
                    'task' => 'Project charter & stakeholder alignment',
                    'date' => $twoMonthsAgo,
                    'hours' => 6.00,
                    'billable' => true,
                    'status' => 'Approved',
                    'approved_at' => $twoMonthsAgo.' 18:00:00',
                    'notes' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],

                // ── Historical entries for P&L (last month) ────────────────
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_DEV_ID,
                    'approved_by' => DemoDataMap::PIXEL_ADMIN_USER_ID,
                    'task' => 'MQTT authentication & TLS layer',
                    'date' => $lastMonth,
                    'hours' => 7.00,
                    'billable' => true,
                    'status' => 'Approved',
                    'approved_at' => $lastMonth.' 18:00:00',
                    'notes' => 'Client-mandated mTLS for all devices.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_APEX_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_QA_ID,
                    'approved_by' => DemoDataMap::PIXEL_ADMIN_USER_ID,
                    'task' => 'Security audit & penetration testing prep',
                    'date' => $lastMonth,
                    'hours' => 5.00,
                    'billable' => true,
                    'status' => 'Approved',
                    'approved_at' => $lastMonth.' 18:00:00',
                    'notes' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],

                // ── Nova Studio time entry (isolation proof) ───────────────
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => DemoDataMap::NOVA_TENANT_ID,
                    'project_id' => DemoDataMap::PROJECT_NOVA1_ID,
                    'employee_id' => DemoDataMap::NOVA_EMP1_ID,
                    'approved_by' => null,
                    'task' => 'Mood board & brand discovery session',
                    'date' => $yesterday,
                    'hours' => 4.00,
                    'billable' => true,
                    'status' => 'Approved',
                    'approved_at' => $yesterday.' 18:00:00',
                    'notes' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        });
    }
}
