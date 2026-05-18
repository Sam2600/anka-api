<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
