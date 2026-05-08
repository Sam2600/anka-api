<?php

/**
 * Seeds the super-admin (Owner) account that bypasses TenantScope
 * and manages all tenants from the /tenant screen.
 */

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OwnerSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $now = Carbon::now()->toDateTimeString();

            DB::table('users')->insert([
                'id' => DemoDataMap::OWNER_ID,
                'tenant_id' => null,
                'employee_id' => null,
                'first_name' => 'Anka',
                'last_name' => 'Owner',
                'email' => DemoDataMap::OWNER_EMAIL,
                'password' => DemoDataMap::PASSWORD_HASH,
                'app_role' => 'Admin',
                'system_role' => 'owner',
                'is_super_admin' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }
}
