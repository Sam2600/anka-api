<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $defaults = [
            'frontend' => ['name' => 'Frontend Developer', 'code' => 'frontend'],
            'backend' => ['name' => 'Backend Developer',  'code' => 'backend'],
            'pm' => ['name' => 'Project Manager',   'code' => 'pm'],
            'qa' => ['name' => 'QA Engineer',       'code' => 'qa'],
            'design' => ['name' => 'Designer',          'code' => 'design'],
        ];

        $tenants = DB::table('tenants')->pluck('id');
        foreach ($tenants as $tenantId) {
            foreach ($defaults as $code => $data) {
                $exists = DB::table('capacity_roles')
                    ->where('tenant_id', $tenantId)
                    ->where('code', $code)
                    ->exists();

                if (! $exists) {
                    DB::table('capacity_roles')->insert([
                        'id' => DB::raw('gen_random_uuid()'),
                        'tenant_id' => $tenantId,
                        'name' => $data['name'],
                        'code' => $data['code'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $roles = DB::table('capacity_roles')
                ->where('tenant_id', $tenantId)
                ->pluck('id', 'code');

            $employees = DB::table('employees')
                ->where('tenant_id', $tenantId)
                ->whereNotNull('capacity_role')
                ->where('capacity_role', '!=', '')
                ->get(['id', 'capacity_role']);

            foreach ($employees as $emp) {
                $roleId = $roles[$emp->capacity_role] ?? null;
                if ($roleId) {
                    DB::table('employees')
                        ->where('id', $emp->id)
                        ->update(['capacity_role_id' => $roleId]);
                }
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::table('employees')->update(['capacity_role_id' => null]);
    }
};
