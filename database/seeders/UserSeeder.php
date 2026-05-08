<?php

/**
 * Seeds all login accounts (users) per tenant.
 * Pixel Agency gets an Org Admin + 4 employees.
 * Nova Studio gets an Org Admin + 2 employee accounts.
 * All passwords are bcrypt-hashed "Demo@1234".
 */

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $now = Carbon::now()->toDateTimeString();

            DB::table('users')->insert([
                // ── Pixel Agency ─────────────────────────────────────────
                [
                    'id' => DemoDataMap::PIXEL_ADMIN_USER_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'employee_id' => null,
                    'first_name' => 'Morgan',
                    'last_name' => 'Wright',
                    'email' => 'admin@pixelagency.test',
                    'password' => DemoDataMap::PASSWORD_HASH,
                    'app_role' => 'Admin',
                    'system_role' => 'member',
                    'is_super_admin' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PIXEL_DEV_USER_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_DEV_ID,
                    'first_name' => 'Alex',
                    'last_name' => 'Chen',
                    'email' => 'dev@pixelagency.test',
                    'password' => DemoDataMap::PASSWORD_HASH,
                    'app_role' => 'Delivery',
                    'system_role' => 'member',
                    'is_super_admin' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PIXEL_DESIGNER_USER_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_DESIGN_ID,
                    'first_name' => 'Sarah',
                    'last_name' => 'Lin',
                    'email' => 'designer@pixelagency.test',
                    'password' => DemoDataMap::PASSWORD_HASH,
                    'app_role' => 'Delivery',
                    'system_role' => 'member',
                    'is_super_admin' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PIXEL_PM_USER_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_PM_ID,
                    'first_name' => 'Jordan',
                    'last_name' => 'Miller',
                    'email' => 'pm@pixelagency.test',
                    'password' => DemoDataMap::PASSWORD_HASH,
                    'app_role' => 'Executive',
                    'system_role' => 'member',
                    'is_super_admin' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::PIXEL_QA_USER_ID,
                    'tenant_id' => DemoDataMap::PIXEL_TENANT_ID,
                    'employee_id' => DemoDataMap::PIXEL_EMP_QA_ID,
                    'first_name' => 'Casey',
                    'last_name' => 'Brooks',
                    'email' => 'qa@pixelagency.test',
                    'password' => DemoDataMap::PASSWORD_HASH,
                    'app_role' => 'Delivery',
                    'system_role' => 'member',
                    'is_super_admin' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],

                // ── Nova Studio ──────────────────────────────────────────
                [
                    'id' => DemoDataMap::NOVA_ADMIN_USER_ID,
                    'tenant_id' => DemoDataMap::NOVA_TENANT_ID,
                    'employee_id' => null,
                    'first_name' => 'Drew',
                    'last_name' => 'Hayes',
                    'email' => 'admin@novastudio.test',
                    'password' => DemoDataMap::PASSWORD_HASH,
                    'app_role' => 'Admin',
                    'system_role' => 'member',
                    'is_super_admin' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::NOVA_EMP1_USER_ID,
                    'tenant_id' => DemoDataMap::NOVA_TENANT_ID,
                    'employee_id' => DemoDataMap::NOVA_EMP1_ID,
                    'first_name' => 'Riley',
                    'last_name' => 'Park',
                    'email' => 'riley@novastudio.test',
                    'password' => DemoDataMap::PASSWORD_HASH,
                    'app_role' => 'Delivery',
                    'system_role' => 'member',
                    'is_super_admin' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::NOVA_EMP2_USER_ID,
                    'tenant_id' => DemoDataMap::NOVA_TENANT_ID,
                    'employee_id' => DemoDataMap::NOVA_EMP2_ID,
                    'first_name' => 'Taylor',
                    'last_name' => 'Reed',
                    'email' => 'taylor@novastudio.test',
                    'password' => DemoDataMap::PASSWORD_HASH,
                    'app_role' => 'Delivery',
                    'system_role' => 'member',
                    'is_super_admin' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        });
    }
}
