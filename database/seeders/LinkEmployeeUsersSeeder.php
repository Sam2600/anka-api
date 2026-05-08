<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Creates login accounts (rows in `users`) for existing `employees` rows
 * so those employees can log in and use the My Tasks page.
 *
 * Idempotent: skips employees that already have a linked user, and skips
 * any account whose email is already taken.
 *
 * Run with:
 *     php artisan db:seed --class=LinkEmployeeUsersSeeder
 */
class LinkEmployeeUsersSeeder extends Seeder
{
    private const DEFAULT_PASSWORD = 'Password123!';

    public function run(): void
    {
        $tenantId = DB::table('tenants')->value('id');
        if (! $tenantId) {
            $this->command?->error('No tenant found. Aborting.');

            return;
        }

        // Each entry maps an existing employee (by name) to the user
        // account that should be created for them.
        // app_role determines what the user is allowed to do:
        //   - Admin / Executive / Delivery  → currently treated as manager
        //   - Sales / HR                     → not used for time tracking
        $accounts = [
            [
                'employee_name' => 'Ma Mintzu',
                'first_name' => 'Ma',
                'last_name' => 'Mintzu',
                'email' => 'mintzu@anka.dev',
                'app_role' => 'Executive', // manager-level
            ],
            [
                'employee_name' => 'kg kg',
                'first_name' => 'kg',
                'last_name' => 'kg',
                'email' => 'kgkg@anka.dev',
                'app_role' => 'Delivery', // engineer-level
            ],
        ];

        foreach ($accounts as $a) {
            $employee = DB::table('employees')
                ->where('tenant_id', $tenantId)
                ->where('name', $a['employee_name'])
                ->first();

            if (! $employee) {
                $this->command?->warn("Employee '{$a['employee_name']}' not found — skipping.");

                continue;
            }

            // Already has a linked login? Skip.
            $existingByEmployee = User::withTrashed()->where('employee_id', $employee->id)->first();
            if ($existingByEmployee) {
                $this->command?->info("Login already exists for {$a['employee_name']} ({$existingByEmployee->email}) — skipping.");

                continue;
            }

            // Email already taken by some other user? Skip rather than collide.
            if (User::withTrashed()->where('email', $a['email'])->exists()) {
                $this->command?->warn("Email {$a['email']} already in use — skipping {$a['employee_name']}.");

                continue;
            }

            // The User model casts `password` as `hashed`, so a plain string
            // is hashed automatically on insert.
            User::create([
                'tenant_id' => $tenantId,
                'employee_id' => $employee->id,
                'first_name' => $a['first_name'],
                'last_name' => $a['last_name'],
                'email' => $a['email'],
                'password' => self::DEFAULT_PASSWORD,
                'app_role' => $a['app_role'],
                'system_role' => 'member',
                'is_super_admin' => false,
            ]);

            $this->command?->info(
                "Created login {$a['email']} → employee {$a['employee_name']} "
                ."(role: {$a['app_role']}, password: ".self::DEFAULT_PASSWORD.')'
            );
        }
    }
}
