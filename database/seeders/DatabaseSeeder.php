<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Super admin — belongs to no tenant.
        User::firstOrCreate(
            ['email' => 'owner@anka.app'],
            [
                'tenant_id' => null,
                'first_name' => 'Anka',
                'last_name' => 'Owner',
                'password' => 'password',
                'system_role' => 'owner',
                'is_super_admin' => true,
                'app_role' => 'Admin',
            ]
        );

// Demo tenant + org admin user + full business data.
        // Uses the same hard-coded tenant ID as DemoTenantSeeder
        // so the admin user can access all seeded data.
        $tenantId = 'aa24b68f-9de2-4621-b404-fb3edd318ee6';

        DB::table('tenants')->insertOrIgnore([
            'id' => $tenantId,
            'name' => 'ANKA Agency',
            'slug' => 'anka-agency',
            'plan' => 'pro',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = 'aa24b68f-9de2-4621-b404-fb3edd318ef0';

        DB::table('employees')->insertOrIgnore([
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

User::firstOrCreate(
            ['email' => 'admin@anka.dev'],
            [
                'tenant_id' => $tenantId,
                'employee_id' => $employeeId,
                'first_name' => 'Admin',
                'last_name' => 'User',
                'password' => 'password',
                'system_role' => 'member',
                'is_super_admin' => false,
                'app_role' => 'Admin',
            ]
        );

        User::firstOrCreate(
            ['email' => 'jane@anka.dev'],
            [
                'tenant_id' => $tenantId,
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'password' => 'password',
                'system_role' => 'member',
                'is_super_admin' => false,
                'app_role' => 'Member',
            ]
        );

        $this->call(DemoTenantSeeder::class);
    }
}
