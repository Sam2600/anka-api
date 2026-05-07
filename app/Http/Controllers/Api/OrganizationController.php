<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Http\Resources\RoleResource;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\GlobalOverheadResource;
use App\Http\Resources\CompanySettingResource;
use App\Models\Department;
use App\Models\Role;
use App\Models\Employee;
use App\Models\GlobalOverhead;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    // ── Departments ───────────────────────────────────────────────────────────

    public function indexDepartments()
    {
        return DepartmentResource::collection(
            Department::with('managerEmployee')->withCount('employees')->orderBy('created_at')->get()
        );
    }

    public function storeDepartment(Request $request)
    {
        $tenantId = app('tenant_id');
        $request->validate([
            'id'         => 'sometimes|uuid',
            'name'       => [
                'required', 'string', 'max:255',
                Rule::unique('departments', 'name')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'manager'    => 'nullable|string|max:255',
            'manager_id' => 'nullable|uuid|exists:employees,id',
            'headcount'  => 'sometimes|integer|min:0',
        ]);

        $dept = new Department($request->only(['name', 'manager', 'manager_id', 'headcount']));
        if ($request->filled('id')) {
            $dept->id = $request->input('id');
        }
        $dept->save();

        return new DepartmentResource($dept->loadCount('employees')->load('managerEmployee'));
    }

    public function updateDepartment(Request $request, Department $department)
    {
        $tenantId = app('tenant_id');
        $request->validate([
            'name'       => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('departments', 'name')
                    ->ignore($department->id)
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'manager'    => 'sometimes|nullable|string|max:255',
            'manager_id' => 'sometimes|nullable|uuid|exists:employees,id',
            'headcount'  => 'sometimes|integer|min:0',
        ]);

        $department->update($request->only(['name', 'manager', 'manager_id', 'headcount']));

        return new DepartmentResource($department->loadCount('employees')->load('managerEmployee'));
    }

    public function destroyDepartment(Department $department)
    {
        $department->delete();

        return response()->noContent();
    }

    // ── Roles ─────────────────────────────────────────────────────────────────

    public function indexRoles()
    {
        return RoleResource::collection(Role::orderBy('created_at')->get());
    }

    public function storeRole(Request $request)
    {
        $tenantId = app('tenant_id');
        $request->validate([
            'id'            => 'sometimes|uuid',
            'title'         => [
                'required', 'string', 'max:255',
                Rule::unique('roles', 'title')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'department'    => 'required|string|max:255',
            'department_id' => 'nullable|uuid|exists:departments,id',
            'rate'          => 'required|numeric|min:0',
        ]);

        $role = new Role($request->only(['title', 'department', 'department_id', 'rate']));
        if ($request->filled('id')) {
            $role->id = $request->input('id');
        }
        $role->save();

        return new RoleResource($role);
    }

    public function updateRole(Request $request, Role $role)
    {
        $tenantId = app('tenant_id');
        $request->validate([
            'title'         => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('roles', 'title')
                    ->ignore($role->id)
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'department'    => 'sometimes|required|string|max:255',
            'department_id' => 'sometimes|nullable|uuid|exists:departments,id',
            'rate'          => 'sometimes|required|numeric|min:0',
        ]);

        $role->update($request->only(['title', 'department', 'department_id', 'rate']));

        return new RoleResource($role);
    }

    public function destroyRole(Role $role)
    {
        $role->delete();

        return response()->noContent();
    }

    // ── Employees ─────────────────────────────────────────────────────────────

    public function indexEmployees()
    {
        return EmployeeResource::collection(
            Employee::with(['department', 'user'])->orderBy('created_at')->get()
        );
    }

    public function storeEmployee(Request $request)
    {
        $tenantId = app('tenant_id');
        $request->validate([
            'id'             => 'sometimes|uuid',
            'name'           => [
                'required', 'string', 'max:255',
                Rule::unique('employees', 'name')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'role'           => 'required|string|max:255',
            'role_name'      => 'nullable|string|max:255',
            'department_id'  => 'nullable|uuid|exists:departments,id',
            'job_role_id'    => 'nullable|uuid|exists:roles,id',
            'capacity_role'  => 'nullable|in:frontend,backend,pm,qa,design',
            'monthly_salary' => 'required|numeric|min:0',
            'workable_hours' => 'required|integer|min:1|max:744',
            'status'         => 'required|in:Active,On Leave,Terminated',
            // Login credentials for the auto-created user account.
            // Currently required so every employee can log in to /my-tasks.
            'email'          => 'required|email|max:255|unique:users,email',
            'password'       => 'required|string|min:6|max:255',
            // cost_per_hour is GENERATED ALWAYS — never accept from client
        ]);

        $employee = DB::transaction(function () use ($request) {
            $employee = new Employee($request->only([
                'name', 'role', 'role_name', 'department_id', 'job_role_id',
                'capacity_role', 'monthly_salary', 'workable_hours', 'status',
            ]));
            if ($request->filled('id')) {
                $employee->id = $request->input('id');
            }
            $employee->save();

            // Split "First Last" into first_name + last_name; if a single
            // word, last_name falls back to that same word so neither column is empty.
            $parts     = preg_split('/\s+/', trim($request->input('name')), 2);
            $firstName = $parts[0] ?? $request->input('name');
            $lastName  = $parts[1] ?? $parts[0] ?? '';

            // password is auto-hashed by the User model's `hashed` cast.
            User::create([
                'tenant_id'      => app('tenant_id'),
                'employee_id'    => $employee->id,
                'first_name'     => $firstName,
                'last_name'      => $lastName,
                'email'          => $request->input('email'),
                'password'       => $request->input('password'),
                'app_role'       => 'Delivery', // minimal-permission executor
                'system_role'    => 'member',
                'is_super_admin' => false,
            ]);

            return $employee;
        });

        // Reload to get the DB-computed cost_per_hour and eager-loaded department
        return new EmployeeResource($employee->fresh()->load(['department', 'user']));
    }

    public function updateEmployee(Request $request, Employee $employee)
    {
        $tenantId = app('tenant_id');
        $linkedUser = $employee->user; // may be null for legacy rows

        $request->validate([
            'name'           => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('employees', 'name')
                    ->ignore($employee->id)
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'role'           => 'sometimes|required|string|max:255',
            'role_name'      => 'sometimes|nullable|string|max:255',
            'department_id'  => 'sometimes|nullable|uuid|exists:departments,id',
            'job_role_id'    => 'sometimes|nullable|uuid|exists:roles,id',
            'capacity_role'  => 'sometimes|nullable|in:frontend,backend,pm,qa,design',
            'monthly_salary' => 'sometimes|required|numeric|min:0',
            'workable_hours' => 'sometimes|required|integer|min:1|max:744',
            'status'         => 'sometimes|required|in:Active,On Leave,Terminated',
            // Login credentials — both optional on update. Email is checked for
            // uniqueness ignoring the current linked user so saving the same
            // email back doesn't bounce. Password rule only fires when present.
            'email'          => [
                'sometimes', 'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore(optional($linkedUser)->id),
            ],
            'password'       => 'sometimes|nullable|string|min:6|max:255',
            // cost_per_hour is GENERATED ALWAYS — never accept from client
        ]);

        DB::transaction(function () use ($request, $employee, $linkedUser) {
            $employee->update($request->only([
                'name', 'role', 'role_name', 'department_id', 'job_role_id',
                'capacity_role', 'monthly_salary', 'workable_hours', 'status',
            ]));

            $hasEmail    = $request->filled('email');
            $newPwd      = $request->input('password');
            $hasPassword = is_string($newPwd) && trim($newPwd) !== '';

            // Mirror the (split) name onto user fields whenever we touch the user.
            $name      = $request->filled('name') ? $request->input('name') : $employee->name;
            $parts     = preg_split('/\s+/', trim((string) $name), 2);
            $firstName = $parts[0] ?? (string) $name;
            $lastName  = $parts[1] ?? ($parts[0] ?? '');

            if ($linkedUser) {
                // Existing linked user — patch only what changed.
                $userPatch = [
                    'first_name' => $firstName,
                    'last_name'  => $lastName,
                ];
                if ($hasEmail && $request->input('email') !== $linkedUser->email) {
                    $userPatch['email'] = $request->input('email');
                }
                if ($hasPassword) {
                    $userPatch['password'] = $newPwd; // hashed via User cast
                }
                $linkedUser->update($userPatch);
            } elseif ($hasEmail && $hasPassword) {
                // No linked user yet (legacy row created before auto-user-creation).
                // If the manager has supplied both credentials on Edit, create
                // the login now so this employee can finally sign in.
                User::create([
                    'tenant_id'      => app('tenant_id'),
                    'employee_id'    => $employee->id,
                    'first_name'     => $firstName,
                    'last_name'      => $lastName,
                    'email'          => $request->input('email'),
                    'password'       => $newPwd,
                    'app_role'       => 'Delivery',
                    'system_role'    => 'member',
                    'is_super_admin' => false,
                ]);
            }
            // If linkedUser is null AND credentials are missing, do nothing —
            // the manager just edited a legacy employee without onboarding them.
        });

        return new EmployeeResource($employee->fresh()->load(['department', 'user']));
    }

    public function destroyEmployee(Employee $employee)
    {
        $employee->delete();

        return response()->noContent();
    }

    // ── Global Overheads ──────────────────────────────────────────────────────

    public function indexOverheads()
    {
        return GlobalOverheadResource::collection(GlobalOverhead::orderBy('created_at')->get());
    }

    public function storeOverhead(Request $request)
    {
        $tenantId = app('tenant_id');
        // Two overheads with the same `category` are only a duplicate when they
        // also share the same effective period (both null = "always", or same
        // month+year). That way a tenant can have a "Software Licenses" entry
        // for the always-on cost AND a one-off "Software Licenses" boost for
        // 2026-09 without colliding.
        $month = $request->input('effective_month');
        $year  = $request->input('effective_year');

        $request->validate([
            'id'              => 'sometimes|uuid',
            'category'        => [
                'required', 'string', 'max:255',
                Rule::unique('global_overheads', 'category')->where(function ($q) use ($tenantId, $month, $year) {
                    $q->where('tenant_id', $tenantId)->whereNull('deleted_at');
                    $month === null ? $q->whereNull('effective_month') : $q->where('effective_month', $month);
                    $year  === null ? $q->whereNull('effective_year')  : $q->where('effective_year',  $year);
                }),
            ],
            'description'     => 'required|string|max:500',
            'monthly_cost'    => 'required|numeric|min:0',
            'effective_month' => 'nullable|integer|min:1|max:12',
            'effective_year'  => 'nullable|integer|min:2000',
        ]);

        $overhead = new GlobalOverhead(
            $request->only(['category', 'description', 'monthly_cost', 'effective_month', 'effective_year'])
        );
        if ($request->filled('id')) {
            $overhead->id = $request->input('id');
        }
        $overhead->save();

        return new GlobalOverheadResource($overhead);
    }

    public function updateOverhead(Request $request, GlobalOverhead $globalOverhead)
    {
        $tenantId = app('tenant_id');
        // Use the *incoming* effective period if provided, otherwise fall back
        // to the row's current period. This way you can't update one of two
        // existing overheads to silently collide with the other on category+period.
        $month = $request->has('effective_month') ? $request->input('effective_month') : $globalOverhead->effective_month;
        $year  = $request->has('effective_year')  ? $request->input('effective_year')  : $globalOverhead->effective_year;

        $request->validate([
            'category'        => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('global_overheads', 'category')
                    ->ignore($globalOverhead->id)
                    ->where(function ($q) use ($tenantId, $month, $year) {
                        $q->where('tenant_id', $tenantId)->whereNull('deleted_at');
                        $month === null ? $q->whereNull('effective_month') : $q->where('effective_month', $month);
                        $year  === null ? $q->whereNull('effective_year')  : $q->where('effective_year',  $year);
                    }),
            ],
            'description'     => 'sometimes|required|string|max:500',
            'monthly_cost'    => 'sometimes|required|numeric|min:0',
            'effective_month' => 'sometimes|nullable|integer|min:1|max:12',
            'effective_year'  => 'sometimes|nullable|integer|min:2000',
        ]);

        $globalOverhead->update(
            $request->only(['category', 'description', 'monthly_cost', 'effective_month', 'effective_year'])
        );

        return new GlobalOverheadResource($globalOverhead);
    }

    public function destroyOverhead(GlobalOverhead $globalOverhead)
    {
        $globalOverhead->delete();

        return response()->noContent();
    }

    // ── Company Settings ──────────────────────────────────────────────────────

    public function getSettings()
    {
        $settings = CompanySetting::first();

        if (!$settings) {
            return response()->json(null, Response::HTTP_NOT_FOUND);
        }

        return new CompanySettingResource($settings);
    }

    public function upsertSettings(Request $request)
    {
        $validated = $request->validate([
            'overhead_percentage'     => 'required|numeric|min:0|max:100',
            'buffer_percentage'       => 'required|numeric|min:0|max:100',
            'yearly_fixed_cost'       => 'required|numeric|min:0',
            'employer_tax_percentage' => 'required|numeric|min:0|max:100',
            'benefits_percentage'     => 'required|numeric|min:0|max:100',
        ]);

        $tenantId = app('tenant_id');
        $settings = CompanySetting::first();

        if ($settings) {
            $settings->update($validated);
        } else {
            $settings = CompanySetting::create(array_merge(['id' => $tenantId], $validated));
        }

        return new CompanySettingResource($settings);
    }
}
