<?php

/**
 * Seeds the two tenant organisations:
 *   - Pixel Agency  (primary demo tenant)
 *   - Nova Studio   (secondary tenant for multi-tenancy proof)
 */

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $now = Carbon::now()->toDateTimeString();

            DB::table('tenants')->insert([
                [
                    'id' => DemoDataMap::PIXEL_TENANT_ID,
                    'name' => 'Pixel Agency',
                    'slug' => 'pixel-agency',
                    'plan' => 'pro',
                    'is_active' => true,
                    'currency' => 'USD',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => DemoDataMap::NOVA_TENANT_ID,
                    'name' => 'Nova Studio',
                    'slug' => 'nova-studio',
                    'plan' => 'starter',
                    'is_active' => true,
                    'currency' => 'USD',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        });
    }
}
