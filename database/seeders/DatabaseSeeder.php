<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Super admin — belongs to no tenant.
        User::create([
            'tenant_id' => null,
            'first_name' => 'Anka',
            'last_name' => 'Owner',
            'email' => 'owner@anka.app',
            'password' => 'password',
            'system_role' => 'owner',
            'is_super_admin' => true,
            'app_role' => 'Admin',
        ]);

        // Demo tenant + org admin user.
        // Must match the hard-coded tenant ID in DemoTenantSeeder so all seeded
        // demo data (deals, contracts, employees, etc.) lives in the same tenant.
        $tenantId = 'aa24b68f-9de2-4621-b404-fb3edd318ee6';

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'ANKA Agency',
            'slug' => 'anka-agency',
            'plan' => 'pro',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = Str::uuid()->toString();

        DB::table('employees')->insert([
            'id' => $employeeId,
            'tenant_id' => $tenantId,
            'name' => 'Admin User',
            'role_name' => 'Head of Organization',
            'status' => 'Active',
            'monthly_salary' => 0,
            'workable_hours' => 160,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        User::create([
            'tenant_id' => $tenantId,
            'employee_id' => $employeeId,
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin@anka.dev',
            'password' => 'password',
            'system_role' => 'member',
            'is_super_admin' => false,
            'app_role' => 'Admin',
        ]);

        // Regular team member user (no employee link)
        User::create([
            'tenant_id' => $tenantId,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@anka.dev',
            'password' => 'password',
            'system_role' => 'member',
            'is_super_admin' => false,
            'app_role' => 'Member',
        ]);

        // Seed all demo organization, deals, contracts, time entries, etc.
        $this->call(DemoTenantSeeder::class);
    }
}
