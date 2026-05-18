<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Backfills login accounts for every active employee that lacks a linked User
 * row. Idempotent — running again only creates the missing ones, never
 * overwrites a password.
 *
 * Email format: lowercase-name-without-spaces @ {tenant's existing email
 * domain inferred from an already-seeded user}. Falls back to a slug-derived
 * domain when the tenant has no user yet.
 *
 * Password: demo1234 for every account. Intended for the demo / staging
 * environment only — do not run in production without rotating passwords.
 */
class EmployeeUsersSeeder extends Seeder
{
    private const DEFAULT_PASSWORD = 'demo1234';

    public function run(): void
    {
        $domainByTenant = [];

        Employee::with('tenant')
            ->whereDoesntHave('user')
            ->where('status', 'Active')
            ->get()
            ->each(function (Employee $employee) use (&$domainByTenant) {
                $tenantId = $employee->tenant_id;

                if (! isset($domainByTenant[$tenantId])) {
                    $domainByTenant[$tenantId] = $this->resolveDomain($employee);
                }
                $domain = $domainByTenant[$tenantId];

                $local = $this->localPart($employee->name);
                $email = $this->ensureUniqueEmail("{$local}@{$domain}", $tenantId);

                [$first, $last] = $this->splitName($employee->name);

                $user = User::create([
                    'tenant_id'      => $tenantId,
                    'employee_id'    => $employee->id,
                    'first_name'     => $first,
                    'last_name'      => $last,
                    'email'          => $email,
                    'password'       => Hash::make(self::DEFAULT_PASSWORD),
                    'app_role'       => $this->appRoleFor($employee),
                    'system_role'    => 'user',
                    'is_super_admin' => false,
                ]);

                $this->command?->line("  ✓ {$user->email}  →  {$employee->name}");
            });
    }

    /**
     * Look up an existing email domain on this tenant so new accounts share it
     * (yangonworks.demo, mandalaystudio.demo, tokyolab.demo, etc.). Falls back
     * to a synthesised domain when the tenant has no users yet.
     */
    private function resolveDomain(Employee $employee): string
    {
        $existing = User::where('tenant_id', $employee->tenant_id)
            ->whereNotNull('email')
            ->orderBy('created_at')
            ->first();

        if ($existing && str_contains($existing->email, '@')) {
            return substr($existing->email, strpos($existing->email, '@') + 1);
        }

        $slug = optional($employee->tenant)->slug ?? 'tenant';
        $clean = preg_replace('/[^a-z0-9]/i', '', $slug) ?: 'tenant';

        return strtolower($clean).'.demo';
    }

    private function localPart(string $name): string
    {
        $stripped = preg_replace('/[^a-z0-9]/i', '', $name) ?? '';

        return strtolower($stripped) ?: 'user'.uniqid();
    }

    /**
     * Names like "Htet Wai Yan" → first="Htet", last="Wai Yan". Solo names
     * (rare in this data) become first only, last empty.
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [$name];
        $first = $parts[0] ?? $name;
        $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';

        return [$first, $last];
    }

    /**
     * Pick a sensible app_role. Mostly Delivery — the spec allows
     * Admin/Executive/Sales/Delivery/HR; engineers, designers, QA, architects
     * all map to Delivery. Finance roles map to HR (closest non-Admin fit).
     */
    private function appRoleFor(Employee $employee): string
    {
        $role = strtolower((string) ($employee->role_name ?: $employee->role));

        if (str_contains($role, 'finance') || str_contains($role, 'hr')) {
            return 'HR';
        }

        return 'Delivery';
    }

    /**
     * If the natural email collides (e.g. two employees named "Aung Min" in
     * the same tenant), append a numeric suffix so the unique index on email
     * doesn't bite.
     */
    private function ensureUniqueEmail(string $email, string $tenantId): string
    {
        if (! User::where('email', $email)->exists()) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $n = 2;
        while (User::where('email', "{$local}{$n}@{$domain}")->exists()) {
            $n++;
        }

        return "{$local}{$n}@{$domain}";
    }
}
