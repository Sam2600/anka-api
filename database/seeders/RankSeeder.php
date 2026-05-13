<?php

namespace Database\Seeders;

use App\Models\Rank;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class RankSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['code' => 'Junior', 'name' => 'Junior',                'level' => 10],
            ['code' => 'Mid',    'name' => 'Mid-Level',             'level' => 20],
            ['code' => 'Senior', 'name' => 'Senior',                'level' => 30],
            ['code' => 'Lead',   'name' => 'Lead / Tech Lead',      'level' => 40],
        ];

        Tenant::query()->get()->each(function (Tenant $tenant) use ($defaults) {
            foreach ($defaults as $rank) {
                Rank::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $rank['code']],
                    ['name' => $rank['name'], 'level' => $rank['level']]
                );
            }
        });
    }
}
