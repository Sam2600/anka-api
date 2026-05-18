<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeUser;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    // ── Org-user routes (behind tenant middleware) ───────────────────────────

    public function show()
    {
        $tenant = Tenant::findOrFail(app('tenant_id'));

        return response()->json(['data' => $this->tenantData($tenant)]);
    }

    public function update(Request $request)
    {
        // Org-admins can change their own company name (it appears on
        // contracts + customer-facing emails). Slug is still super-admin-only
        // because it's part of URLs / external references.
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'signatory_name' => 'sometimes|nullable|string|max:255',
            'signatory_title' => 'sometimes|nullable|string|max:255',
            'tax_rate' => 'sometimes|numeric|min:0|max:1',
            'avg_delivery_lag_months' => 'sometimes|integer|min:0|max:24',
            'avg_payment_days_late' => 'sometimes|integer|min:0|max:365',
        ]);

        $tenant = Tenant::findOrFail(app('tenant_id'));
        $tenant->update($validated);

        return response()->json(['data' => $this->tenantData($tenant)]);
    }

    /**
     * Upload (or replace) the tenant's logo. Rendered at the top of every
     * contract PDF and the customer-facing email. Public disk so the
     * frontend can preview it via a plain URL.
     */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            // 2 MB cap — large enough for a 400×400 PNG; small enough that
            // the queue worker doesn't choke embedding it base64 per PDF.
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $tenant = Tenant::findOrFail(app('tenant_id'));

        // Delete the prior file (if any) so we don't litter the disk.
        if ($tenant->logo_path) {
            Storage::disk('public')->delete($tenant->logo_path);
        }

        // Deterministic filename per tenant — keeps the public URL stable
        // when the operator replaces the logo. Extension comes from the
        // upload so MIME stays correct.
        $ext = strtolower($request->file('logo')->getClientOriginalExtension() ?: 'png');
        $relativePath = "tenant-logos/{$tenant->id}.{$ext}";

        Storage::disk('public')->putFileAs(
            'tenant-logos',
            $request->file('logo'),
            "{$tenant->id}.{$ext}",
        );

        $tenant->update(['logo_path' => $relativePath]);

        AuditService::log('tenant.logo.upload', 'tenant', $tenant->id, 'Logo uploaded');

        return response()->json(['data' => $this->tenantData($tenant->fresh())]);
    }

    /**
     * Remove the tenant's logo. PDF + email fall back to the global
     * config('contract.provider_fallback.logo_path').
     */
    public function deleteLogo()
    {
        $tenant = Tenant::findOrFail(app('tenant_id'));

        if ($tenant->logo_path) {
            Storage::disk('public')->delete($tenant->logo_path);
            $tenant->update(['logo_path' => null]);

            AuditService::log('tenant.logo.delete', 'tenant', $tenant->id, 'Logo removed');
        }

        return response()->json(['data' => $this->tenantData($tenant->fresh())]);
    }

    // ── Super-admin routes (behind super_admin middleware) ───────────────────

    public function index()
    {
        $tenants = Tenant::withCount(['users' => function ($q) {
            $q->whereNull('deleted_at');
        }])->orderBy('created_at', 'desc')->paginate(50);

        return response()->json([
            'data' => $tenants->map(fn ($t) => [
                ...$this->tenantData($t),
                'users_count' => $t->users_count,
            ]),
            'meta' => [
                'total' => $tenants->total(),
                'per_page' => $tenants->perPage(),
                'current_page' => $tenants->currentPage(),
                'last_page' => $tenants->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:100|alpha_dash|unique:tenants,slug',
            'plan' => 'nullable|string|max:50',
            'currency' => 'nullable|string|in:MMK,JPY',
            'tax_rate' => 'nullable|numeric|min:0|max:1',
            'avg_delivery_lag_months' => 'nullable|integer|min:0|max:24',
            'avg_payment_days_late' => 'nullable|integer|min:0|max:365',
            'is_active' => 'boolean',
        ]);

        $tenant = Tenant::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'plan' => $validated['plan'] ?? null,
            'currency' => $validated['currency'] ?? 'MMK',
            'tax_rate' => $validated['tax_rate'] ?? 0.20,
            'avg_delivery_lag_months' => $validated['avg_delivery_lag_months'] ?? 1,
            'avg_payment_days_late' => $validated['avg_payment_days_late'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        AuditService::log('tenant.create', 'tenant', $tenant->id, "Created tenant {$tenant->name}");

        return response()->json(['data' => $this->tenantData($tenant)], 201);
    }

    public function showAdmin(string $id)
    {
        $tenant = Tenant::findOrFail($id);

        return response()->json(['data' => $this->tenantData($tenant)]);
    }

    public function updateAdmin(Request $request, string $id)
    {
        $tenant = Tenant::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|required|string|max:100|alpha_dash|unique:tenants,slug,'.$id,
            'plan' => 'nullable|string|max:50',
            'currency' => 'nullable|string|in:MMK,JPY',
            'tax_rate' => 'sometimes|numeric|min:0|max:1',
            'avg_delivery_lag_months' => 'sometimes|integer|min:0|max:24',
            'avg_payment_days_late' => 'sometimes|integer|min:0|max:365',
            'is_active' => 'boolean',
        ]);

        $tenant->update($validated);

        AuditService::log('tenant.update', 'tenant', $tenant->id, "Updated tenant {$tenant->name}", null, $tenant->id);

        return response()->json(['data' => $this->tenantData($tenant)]);
    }

    public function destroy(string $id)
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update(['is_active' => false]);

        AuditService::log('tenant.deactivate', 'tenant', $tenant->id, "Deactivated tenant {$tenant->name}", null, $tenant->id);

        return response()->json(['message' => 'Tenant deactivated']);
    }

    // ── User management within a tenant ──────────────────────────────────────

    public function listUsers(string $tenantId)
    {
        $tenant = Tenant::findOrFail($tenantId);

        $users = User::where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->orderBy('first_name')
            ->get();

        return response()->json([
            'data' => $users->map(fn ($u) => $this->userData($u)),
        ]);
    }

    public function createUser(Request $request, string $tenantId)
    {
        $tenant = Tenant::findOrFail($tenantId);

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'app_role' => 'required|in:Admin,Executive,Sales,Delivery,HR',
        ]);

        // Generate a secure random 8-character password.
        $plainPassword = Str::random(8);

        // 1. Create auth user
        $user = User::create([
            'tenant_id' => $tenant->id,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => Hash::make($plainPassword),
            'app_role' => $validated['app_role'],
            'system_role' => 'member',
            'is_super_admin' => false,
        ]);

        // 2. Auto-create employee record so the user appears in Organization / capacity pool
        $employee = $this->createEmployeeForUser($user, $tenant->id, $validated['app_role']);

        // 3. Link user → employee
        $user->update(['employee_id' => $employee->id]);

        // 4. Send welcome email to the user's email address
        Mail::to($user->email)->queue(new WelcomeUser($user, $plainPassword));

        AuditService::log('user.create', 'user', $user->id, "Created user {$user->email}", null, $tenant->id);

        return response()->json([
            'data' => $this->userData($user->fresh()),
            'generated_password' => $plainPassword,
        ], 201);
    }

    public function updateUser(Request $request, string $tenantId, string $userId)
    {
        $tenant = Tenant::findOrFail($tenantId);

        $user = User::where('tenant_id', $tenant->id)
            ->where('id', $userId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $validated = $request->validate([
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:users,email,'.$user->id,
            'app_role' => 'sometimes|required|in:Admin,Executive,Sales,Delivery,HR',
        ]);

        $user->update($validated);

        // If role changed, update the linked employee's role_name too.
        if (isset($validated['app_role']) && $user->employee_id) {
            $roleName = match ($validated['app_role']) {
                'Admin', 'Executive' => 'Head of Organization',
                'HR' => 'HR Manager',
                'Sales' => 'Sales Manager',
                'Delivery' => 'Delivery Lead',
                default => $validated['app_role'],
            };
            Employee::where('id', $user->employee_id)->update(['role_name' => $roleName]);
        }

        AuditService::log('user.update', 'user', $user->id, "Updated user {$user->email}", null, $user->tenant_id);

        return response()->json(['data' => $this->userData($user->fresh())]);
    }

    public function deleteUser(string $tenantId, string $userId)
    {
        $tenant = Tenant::findOrFail($tenantId);

        $user = User::where('tenant_id', $tenant->id)
            ->where('id', $userId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        // Soft delete the user.
        $user->delete();

        // Soft delete the linked employee record.
        if ($user->employee_id) {
            Employee::where('id', $user->employee_id)->delete();
        }

        AuditService::log('user.delete', 'user', $user->id, "Deleted user {$user->email}", null, $user->tenant_id);

        return response()->json(['message' => 'User deleted']);
    }

    /**
     * Auto-create an employee record for a newly created tenant user.
     * Maps the user's app_role to a sensible employee role_name.
     */
    private function createEmployeeForUser(User $user, string $tenantId, string $appRole): Employee
    {
        $roleName = match ($appRole) {
            'Admin', 'Executive' => 'Head of Organization',
            'HR' => 'HR Manager',
            'Sales' => 'Sales Manager',
            'Delivery' => 'Delivery Lead',
            default => $appRole,
        };

        return Employee::create([
            'tenant_id' => $tenantId,
            'name' => "{$user->first_name} {$user->last_name}",
            'role_name' => $roleName,
            'status' => 'Active',
            // Spec ①.2 — Basic + Allowance instead of single monthly_salary.
            // Admin-created stub starts at zero; the org admin can fill in
            // the actual figures via the Employee edit form afterwards.
            'basic_salary' => 0,
            'allowance' => 0,
            'workable_hours' => 160,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function tenantData(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'plan' => $tenant->plan,
            'logo_path' => $tenant->logo_path,
            'logo_url' => $tenant->logo_url,
            'signatory_name' => $tenant->signatory_name,
            'signatory_title' => $tenant->signatory_title,
            'currency' => $tenant->currency ?? 'MMK',
            'tax_rate' => (float) ($tenant->tax_rate ?? 0.20),
            'avg_delivery_lag_months' => (int) ($tenant->avg_delivery_lag_months ?? 1),
            'avg_payment_days_late' => (int) ($tenant->avg_payment_days_late ?? 0),
            'is_active' => $tenant->is_active,
            'created_at' => $tenant->created_at,
            'exchange_rates' => $tenant->exchangeRates()
                ->where('to_currency', 'USD')
                ->get(['from_currency', 'rate'])
                ->keyBy('from_currency')
                ->map(fn ($r) => (float) $r->rate),
        ];
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'app_role' => $user->app_role,
            'employee_id' => $user->employee_id,
        ];
    }
}
