<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckPermission;
use App\Models\TenantAppRole;
use App\Models\TenantAppRolePermission;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TenantAppRoleController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = app('tenant_id');

        $roles = TenantAppRole::with('permissions')
            ->orderBy('is_system', 'desc')
            ->orderBy('name')
            ->get()
            ->map(fn ($r) => $this->shape($r));

        return response()->json(['data' => $roles]);
    }

    public function catalog()
    {
        return response()->json(['data' => PermissionCatalog::all()]);
    }

    public function store(Request $request)
    {
        $tenantId = app('tenant_id');

        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:64',
                Rule::unique('tenant_app_roles', 'name')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'description'    => ['nullable', 'string', 'max:255'],
            'permissions'    => ['array'],
            'permissions.*'  => ['string', Rule::in(PermissionCatalog::keys())],
        ]);

        $role = DB::transaction(function () use ($validated) {
            $role = TenantAppRole::create([
                'name'        => $validated['name'],
                'description' => $validated['description'] ?? null,
                'is_system'   => false,
            ]);
            foreach (($validated['permissions'] ?? []) as $key) {
                TenantAppRolePermission::create([
                    'role_id'        => $role->id,
                    'permission_key' => $key,
                ]);
            }
            return $role->load('permissions');
        });

        return response()->json(['data' => $this->shape($role)], 201);
    }

    public function update(Request $request, string $roleId)
    {
        $tenantId = app('tenant_id');
        $role = TenantAppRole::with('permissions')->findOrFail($roleId);

        $rules = [
            'description'    => ['nullable', 'string', 'max:255'],
            'permissions'    => ['array'],
            'permissions.*'  => ['string', Rule::in(array_merge(['all'], PermissionCatalog::keys()))],
        ];

        // System roles cannot be renamed — keeps the (tenant_id, name)
        // lookup stable for the seeded defaults.
        if (! $role->is_system) {
            $rules['name'] = ['sometimes', 'required', 'string', 'max:64',
                Rule::unique('tenant_app_roles', 'name')
                    ->ignore($role->id)
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ];
        }

        $validated = $request->validate($rules);

        // Forbid removing 'all' from Admin — that would lock out every Admin.
        if ($role->is_system && $role->name === 'Admin' && array_key_exists('permissions', $validated)) {
            if (! in_array('all', $validated['permissions'], true)) {
                throw ValidationException::withMessages([
                    'permissions' => ['The Admin role must keep the "all" permission.'],
                ]);
            }
        }

        // Forbid stripping a role with assigned users to zero permissions —
        // that locks every assignee out of every page. Tenant admins must
        // either reassign the users first or grant at least one key.
        if (array_key_exists('permissions', $validated) && count($validated['permissions']) === 0) {
            $assignedCount = User::where('tenant_id', $role->tenant_id)
                ->where('app_role', $role->name)
                ->count();
            if ($assignedCount > 0) {
                throw ValidationException::withMessages([
                    'permissions' => ["Role '{$role->name}' has {$assignedCount} user(s) assigned — granting zero permissions would lock them out. Reassign them first or grant at least one permission."],
                ]);
            }
        }

        // Forbid the editor from removing manage_tenant from their OWN role.
        // Without manage_tenant they can no longer reach the /tenant/app-roles
        // endpoint to undo the change — they would lock themselves out of the
        // recovery path. Super admins bypass (they edit via /admin and aren't
        // subject to app-role permissions in the first place).
        $editor = $request->user();
        if (
            $editor
            && ! ($editor->is_super_admin ?? false)
            && (string) $editor->tenant_id === (string) $role->tenant_id
            && $editor->app_role === $role->name
            && array_key_exists('permissions', $validated)
            && ! in_array('all', $validated['permissions'], true)
            && ! in_array('manage_tenant', $validated['permissions'], true)
        ) {
            throw ValidationException::withMessages([
                'permissions' => ['You cannot remove "manage_tenant" from your own role — doing so would lock you out of role administration. Ask another admin to make this change, or remove yourself from this role first.'],
            ]);
        }

        DB::transaction(function () use ($role, $validated) {
            $previousName = $role->name;

            if (array_key_exists('name', $validated) && $validated['name'] !== $previousName) {
                $role->name = $validated['name'];
                // Cascade rename to users that referenced the old name.
                User::where('tenant_id', $role->tenant_id)
                    ->where('app_role', $previousName)
                    ->update(['app_role' => $validated['name']]);
            }
            if (array_key_exists('description', $validated)) {
                $role->description = $validated['description'];
            }
            $role->save();

            if (array_key_exists('permissions', $validated)) {
                TenantAppRolePermission::where('role_id', $role->id)->delete();
                foreach ($validated['permissions'] as $key) {
                    TenantAppRolePermission::create([
                        'role_id'        => $role->id,
                        'permission_key' => $key,
                    ]);
                }
            }
        });

        // Bust the per-process permission cache for every user assigned to this
        // role (under whatever name it now carries, post-rename). Without this,
        // long-running queue workers keep returning the old permission list
        // until the worker process restarts.
        $this->flushPermissionCacheForRole($role->tenant_id, $role->name);

        return response()->json(['data' => $this->shape($role->fresh()->load('permissions'))]);
    }

    public function destroy(string $roleId)
    {
        $role = TenantAppRole::findOrFail($roleId);

        if ($role->is_system) {
            return response()->json([
                'message' => 'System roles cannot be deleted. You can edit their permissions instead.',
            ], 422);
        }

        $assignedCount = User::where('tenant_id', $role->tenant_id)
            ->where('app_role', $role->name)
            ->count();

        if ($assignedCount > 0) {
            return response()->json([
                'message' => "Cannot delete role '{$role->name}' — {$assignedCount} user(s) still assigned. Reassign them first.",
            ], 422);
        }

        $role->delete();
        return response()->json(['message' => 'Role deleted.']);
    }

    /**
     * Drop cached permission lists for every user holding $roleName in $tenantId.
     * Static cache is keyed by `{userId}|{tenantId}|{roleName}`, so we flush
     * one entry per user. Idempotent if the cache key is missing.
     */
    private function flushPermissionCacheForRole(string $tenantId, string $roleName): void
    {
        User::where('tenant_id', $tenantId)
            ->where('app_role', $roleName)
            ->pluck('id')
            ->each(fn ($id) => CheckPermission::flushCache((string) $id));
    }

    private function shape(TenantAppRole $role): array
    {
        return [
            'id'          => $role->id,
            'name'        => $role->name,
            'description' => $role->description,
            'is_system'   => (bool) $role->is_system,
            'permissions' => $role->permissions->pluck('permission_key')->values()->all(),
            'created_at'  => $role->created_at?->toIso8601String(),
            'updated_at'  => $role->updated_at?->toIso8601String(),
        ];
    }
}
