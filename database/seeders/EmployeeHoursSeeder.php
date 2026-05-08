<?php

/**
 * Seeds the organisational backbone for both tenants:
 *   - Departments
 *   - Job Roles
 *   - Capacity Roles
 *   - Employees (with monthly workable hours)
 *   - Company Settings
 *
 * This seeder must run before UserSeeder so that employee_id foreign keys
 * can be wired correctly.
 */

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmployeeHoursSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $now = Carbon::now()->toDateTimeString();

            // ── Pixel Agency Departments ──────────────────────────────────
            DB::table('departments')->insert([
                [
                    'id' => DemoDataMap::PIXEL_DEPT_ENG_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'name' => 'Engineering',
                    'manager' => 'Alex Chen',
                    'headcount' => 2,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PIXEL_DEPT_DESIGN_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'name' => 'Design',
                    'manager' => 'Sarah Lin',
                    'headcount' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PIXEL_DEPT_PM_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'name' => 'Project Management',
                    'manager' => 'Jordan Miller',
                    'headcount' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PIXEL_DEPT_QA_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'name' => 'Quality Assurance',
                    'manager' => 'Casey Brooks',
                    'headcount' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            // ── Pixel Agency Job Roles ────────────────────────────────────
            DB::table('roles')->insert([
                [
                    'id' => DemoDataMap::PIXEL_ROLE_DEV_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'department_id' => DemoDataMap::PIXEL_DEPT_ENG_ID,
                    'title' => 'Senior Full-Stack Developer',
                    'department' => 'Engineering',
                    'rate' => 95.00,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PIXEL_ROLE_DESIGN_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'department_id' => DemoDataMap::PIXEL_DEPT_DESIGN_ID,
                    'title' => 'UI/UX Designer',
                    'department' => 'Design',
                    'rate' => 80.00,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PIXEL_ROLE_PM_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'department_id' => DemoDataMap::PIXEL_DEPT_PM_ID,
                    'title' => 'Project Manager',
                    'department' => 'Project Management',
                    'rate' => 85.00,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PIXEL_ROLE_QA_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'department_id' => DemoDataMap::PIXEL_DEPT_QA_ID,
                    'title' => 'QA Engineer',
                    'department' => 'Quality Assurance',
                    'rate' => 65.00,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            // ── Pixel Agency Capacity Roles ───────────────────────────────
            DB::table('capacity_roles')->insert([
                [
                    'id' => DemoDataMap::PIXEL_CAP_BACKEND_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'name' => 'Backend Developer',
                    'code' => 'backend',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PIXEL_CAP_FRONTEND_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'name' => 'Frontend Developer',
                    'code' => 'frontend',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PIXEL_CAP_PM_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'name' => 'Project Manager',
                    'code' => 'pm',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PIXEL_CAP_QA_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'name' => 'QA Engineer',
                    'code' => 'qa',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PIXEL_CAP_DESIGN_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'name' => 'Designer',
                    'code' => 'design',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            // ── Pixel Agency Employees (monthly hours = 160) ──────────────
            DB::table('employees')->insert([
                [
                    'id' => DemoDataMap::PIXEL_EMP_DEV_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'department_id' => DemoDataMap::PIXEL_DEPT_ENG_ID,
                    'job_role_id' => DemoDataMap::PIXEL_ROLE_DEV_ID,
                    'name' => 'Alex Chen',
                    'role' => 'Senior Full-Stack Developer',
                    'role_name' => 'Senior Full-Stack Developer',
                    'capacity_role' => 'backend',
                    'capacity_role_id' => DemoDataMap::PIXEL_CAP_BACKEND_ID,
                    'monthly_salary' => 7200.00,
                    'workable_hours' => 160,
                    'status' => 'Active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PIXEL_EMP_DESIGN_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'department_id' => DemoDataMap::PIXEL_DEPT_DESIGN_ID,
                    'job_role_id' => DemoDataMap::PIXEL_ROLE_DESIGN_ID,
                    'name' => 'Sarah Lin',
                    'role' => 'UI/UX Designer',
                    'role_name' => 'UI/UX Designer',
                    'capacity_role' => 'design',
                    'capacity_role_id' => DemoDataMap::PIXEL_CAP_DESIGN_ID,
                    'monthly_salary' => 6000.00,
                    'workable_hours' => 160,
                    'status' => 'Active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PIXEL_EMP_PM_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'department_id' => DemoDataMap::PIXEL_DEPT_PM_ID,
                    'job_role_id' => DemoDataMap::PIXEL_ROLE_PM_ID,
                    'name' => 'Jordan Miller',
                    'role' => 'Project Manager',
                    'role_name' => 'Project Manager',
                    'capacity_role' => 'pm',
                    'capacity_role_id' => DemoDataMap::PIXEL_CAP_PM_ID,
                    'monthly_salary' => 6800.00,
                    'workable_hours' => 160,
                    'status' => 'Active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PIXEL_EMP_QA_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'department_id' => DemoDataMap::PIXEL_DEPT_QA_ID,
                    'job_role_id' => DemoDataMap::PIXEL_ROLE_QA_ID,
                    'name' => 'Casey Brooks',
                    'role' => 'QA Engineer',
                    'role_name' => 'QA Engineer',
                    'capacity_role' => 'qa',
                    'capacity_role_id' => DemoDataMap::PIXEL_CAP_QA_ID,
                    'monthly_salary' => 5200.00,
                    'workable_hours' => 160,
                    'status' => 'Active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            // ── Nova Studio Employees ─────────────────────────────────────
            DB::table('employees')->insert([
                [
                    'id' => DemoDataMap::NOVA_EMP1_ID,
                    'tenant_id' => DemoDataMap::NOVA_TENANT_ID,
                    'department_id' => null,
                    'job_role_id' => null,
                    'name' => 'Riley Park',
                    'role' => 'Creative Director',
                    'role_name' => 'Creative Director',
                    'capacity_role' => 'design',
                    'monthly_salary' => 5500.00,
                    'workable_hours' => 160,
                    'status' => 'Active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::NOVA_EMP2_ID,
                    'tenant_id' => DemoDataMap::NOVA_TENANT_ID,
                    'department_id' => null,
                    'job_role_id' => null,
                    'name' => 'Taylor Reed',
                    'role' => 'Frontend Developer',
                    'role_name' => 'Frontend Developer',
                    'capacity_role' => 'frontend',
                    'monthly_salary' => 4800.00,
                    'workable_hours' => 160,
                    'status' => 'Active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            // ── Company Settings ──────────────────────────────────────────
            DB::table('company_settings')->insert([
                [
                    'id' => DemoDataMap::PIXEL_SETTINGS_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'overhead_percentage' => 20.00,
                    'buffer_percentage' => 10.00,
                    'yearly_fixed_cost' => 120000.00,
                    'employer_tax_percentage' => 8.00,
                    'benefits_percentage' => 12.00,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::NOVA_SETTINGS_ID,
                    'tenant_id' => DemoDataMap::NOVA_TENANT_ID,
                    'overhead_percentage' => 18.00,
                    'buffer_percentage' => 8.00,
                    'yearly_fixed_cost' => 60000.00,
                    'employer_tax_percentage' => 7.00,
                    'benefits_percentage' => 10.00,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        });
    }
}
