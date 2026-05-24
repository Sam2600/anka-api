<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Http\Resources\RoleResource;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\GlobalOverheadResource;
use App\Http\Resources\CompanySettingResource;
use App\Http\Resources\InitialBudgetResource;
use App\Http\Resources\EmployeeSalaryHistoryResource;
use App\Http\Resources\SkillResource;
use App\Http\Resources\CapacityRoleResource;
use App\Models\Department;
use App\Models\Role;
use App\Models\Employee;
use App\Models\EmployeeSalaryHistory;
use App\Models\GlobalOverhead;
use App\Models\CompanySetting;
use App\Models\InitialBudget;
use App\Models\Skill;
use App\Models\CapacityRole;
use App\Models\EmployeeSkill;
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
            'manager'               => 'nullable|string|max:255',
            'manager_id'            => 'nullable|uuid|exists:employees,id',
            'headcount'             => 'sometimes|integer|min:0',
            'is_delivery_eligible'  => 'sometimes|boolean',
        ]);

        $dept = new Department($request->only(['name', 'manager', 'manager_id', 'headcount', 'is_delivery_eligible']));
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
            'manager'               => 'sometimes|nullable|string|max:255',
            'manager_id'            => 'sometimes|nullable|uuid|exists:employees,id',
            'headcount'             => 'sometimes|integer|min:0',
            'is_delivery_eligible'  => 'sometimes|boolean',
        ]);

        $department->update($request->only(['name', 'manager', 'manager_id', 'headcount', 'is_delivery_eligible']));

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
            Employee::with(['department', 'user', 'capacityRole', 'rank', 'skills'])->orderBy('created_at')->get()
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
            'capacity_role'  => 'nullable|string|max:50',
            'capacity_role_id' => 'nullable|uuid|exists:capacity_roles,id',
            'rank_id'        => [
                'nullable', 'uuid',
                Rule::exists('ranks', 'id')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            // Spec ①.2 — salary captured as Basic + Allowance. monthly_salary
            // is derived (basic + allowance) and not accepted from clients.
            'basic_salary'   => 'required|numeric|min:0',
            'allowance'      => 'sometimes|numeric|min:0',
            'workable_hours' => 'required|integer|min:1|max:744',
            'status'         => 'required|in:Active,On Leave,Terminated',
            'email'          => 'required|email|max:255|unique:users,email',
            'password'       => 'required|string|min:6|max:255',
            'skills'         => 'nullable|array',
            'skills.*.skill_id' => 'required_with:skills|uuid|exists:skills,id',
            'skills.*.proficiency' => 'required_with:skills|in:beginner,intermediate,expert',
        ]);

        $employee = DB::transaction(function () use ($request, $tenantId) {
            $employee = new Employee($request->only([
                'name', 'role', 'role_name', 'department_id', 'job_role_id',
                'capacity_role', 'capacity_role_id', 'rank_id',
                'basic_salary', 'allowance', 'workable_hours', 'status',
            ]));
            // allowance is optional in the request — default to 0 when omitted
            // so the model save hook computes monthly_salary correctly.
            if (! $request->has('allowance')) {
                $employee->allowance = 0;
            }
            if ($request->filled('id')) {
                $employee->id = $request->input('id');
            }
            $employee->save();

            if ($request->has('skills')) {
                $this->syncEmployeeSkills($employee, $request->input('skills', []));
            }

            $parts     = preg_split('/\s+/', trim($request->input('name')), 2);
            $firstName = $parts[0] ?? $request->input('name');
            $lastName  = $parts[1] ?? $parts[0] ?? '';

            User::create([
                'tenant_id'      => $tenantId,
                'employee_id'    => $employee->id,
                'first_name'     => $firstName,
                'last_name'      => $lastName,
                'email'          => $request->input('email'),
                'password'       => $request->input('password'),
                'app_role'       => 'Delivery',
                'system_role'    => 'member',
                'is_super_admin' => false,
            ]);

            return $employee;
        });

        return new EmployeeResource(
            $employee->fresh()->load(['department', 'user', 'capacityRole', 'rank', 'skills'])
        );
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
            'capacity_role'  => 'sometimes|nullable|string|max:50',
            'capacity_role_id' => 'sometimes|nullable|uuid|exists:capacity_roles,id',
            'rank_id'        => [
                'sometimes', 'nullable', 'uuid',
                Rule::exists('ranks', 'id')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            // Spec ①.2 — salary edited as Basic + Allowance.
            'basic_salary'   => 'sometimes|required|numeric|min:0',
            'allowance'      => 'sometimes|numeric|min:0',
            'workable_hours' => 'sometimes|required|integer|min:1|max:744',
            'status'         => 'sometimes|required|in:Active,On Leave,Terminated',
            'email'          => [
                'sometimes', 'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore(optional($linkedUser)->id),
            ],
            'password'       => 'sometimes|nullable|string|min:6|max:255',
            'skills'         => 'nullable|array',
            'skills.*.skill_id' => 'required_with:skills|uuid|exists:skills,id',
            'skills.*.proficiency' => 'required_with:skills|in:beginner,intermediate,expert',
        ]);

        DB::transaction(function () use ($request, $employee, $linkedUser) {
            $employee->update($request->only([
                'name', 'role', 'role_name', 'department_id', 'job_role_id',
                'capacity_role', 'capacity_role_id', 'rank_id',
                'basic_salary', 'allowance', 'workable_hours', 'status',
            ]));

            if ($request->has('skills')) {
                $this->syncEmployeeSkills($employee, $request->input('skills', []));
            }

            $hasEmail    = $request->filled('email');
            $newPwd      = $request->input('password');
            $hasPassword = is_string($newPwd) && trim($newPwd) !== '';

            $name      = $request->filled('name') ? $request->input('name') : $employee->name;
            $parts     = preg_split('/\s+/', trim((string) $name), 2);
            $firstName = $parts[0] ?? (string) $name;
            $lastName  = $parts[1] ?? ($parts[0] ?? '');

            if ($linkedUser) {
                $userPatch = [
                    'first_name' => $firstName,
                    'last_name'  => $lastName,
                ];
                if ($hasEmail && $request->input('email') !== $linkedUser->email) {
                    $userPatch['email'] = $request->input('email');
                }
                if ($hasPassword) {
                    $userPatch['password'] = $newPwd;
                }
                $linkedUser->update($userPatch);
            } elseif ($hasEmail && $hasPassword) {
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
        });

        return new EmployeeResource(
            $employee->fresh()->load(['department', 'user', 'capacityRole', 'rank', 'skills'])
        );
    }

    public function destroyEmployee(Employee $employee)
    {
        $employee->delete();

        return response()->noContent();
    }

    /**
     * Replace the employee's skill set with $skills, where each item is
     * ['skill_id' => UUID, 'proficiency' => string].
     *
     * Called by storeEmployee and updateEmployee. Goes through the
     * EmployeeSkill model (rather than $employee->skills()->sync(...)) because
     * the pivot table requires a UUID `id` and a NOT NULL `tenant_id` which
     * Laravel's default Pivot does not populate — sync() would crash on a
     * NOT NULL constraint. EmployeeSkill's HasUuids + BelongsToTenant traits
     * fill both correctly on create.
     *
     * @param  array<int, array{skill_id: string, proficiency: string}>  $skills
     */
    private function syncEmployeeSkills(Employee $employee, array $skills): void
    {
        EmployeeSkill::where('employee_id', $employee->id)->delete();

        foreach ($skills as $item) {
            EmployeeSkill::create([
                'employee_id' => $employee->id,
                'skill_id'    => $item['skill_id'],
                'proficiency' => $item['proficiency'],
            ]);
        }
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
            'overhead_percentage'             => 'required|numeric|min:0|max:100',
            'buffer_percentage'               => 'required|numeric|min:0|max:100',
            'yearly_fixed_cost'               => 'required|numeric|min:0',
            'annual_initial_budget'           => 'sometimes|numeric|min:0',
            'employer_tax_percentage'         => 'required|numeric|min:0|max:100',
            'benefits_percentage'             => 'required|numeric|min:0|max:100',
            'cost_to_bill_ratio'              => 'sometimes|numeric|min:0|max:1',
            'default_monthly_capacity_hours'  => 'sometimes|integer|min:1|max:744',
            'fallback_hourly_cost'            => 'sometimes|numeric|min:0',
        ]);

        if (! array_key_exists('annual_initial_budget', $validated)) {
            $validated['annual_initial_budget'] = 1_000_000_000;
        }

        $tenantId = app('tenant_id');
        $settings = CompanySetting::first();

        if ($settings) {
            $settings->update($validated);
        } else {
            $settings = CompanySetting::create(array_merge(['id' => $tenantId], $validated));
        }

        return new CompanySettingResource($settings);
    }

    // ── Initial Budgets (year-scoped target profit, process ①.3) ─────────────
    //
    // Replaces the legacy `company_settings.annual_initial_budget` singleton
    // (still readable for backward compat during the soft cutover). One row
    // per (tenant, fiscal_year); the Forecast page (process ⑧) fetches the
    // year matching the displayed months.

    public function indexInitialBudgets()
    {
        return InitialBudgetResource::collection(
            InitialBudget::orderBy('fiscal_year', 'desc')->get()
        );
    }

    /**
     * Upsert by fiscal_year — the natural key is (tenant_id, fiscal_year),
     * so we route on the year rather than the row id. Lets the frontend
     * say "set the 2027 budget to X" without first looking up the row.
     */
    public function upsertInitialBudget(Request $request, int $fiscalYear)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
        ]);

        if ($fiscalYear < 2000 || $fiscalYear > 2100) {
            return response()->json([
                'message' => 'fiscal_year must be between 2000 and 2100.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $tenantId = app('tenant_id');
        $user = $request->user();

        $budget = InitialBudget::where('tenant_id', $tenantId)
            ->where('fiscal_year', $fiscalYear)
            ->first();

        if ($budget) {
            $budget->update(['amount' => $request->input('amount')]);
        } else {
            $budget = InitialBudget::create([
                'tenant_id' => $tenantId,
                'fiscal_year' => $fiscalYear,
                'amount' => $request->input('amount'),
                'created_by_user_id' => $user?->id,
            ]);
        }

        return new InitialBudgetResource($budget->fresh());
    }

    public function destroyInitialBudget(int $fiscalYear)
    {
        $tenantId = app('tenant_id');

        InitialBudget::where('tenant_id', $tenantId)
            ->where('fiscal_year', $fiscalYear)
            ->delete();

        return response()->noContent();
    }

    // ── Employee Salary History (spec ②.1.B) ────────────────────────────────
    //
    // One row per (employee, target_month). Past rows are read-only after
    // the month is over; current + future rows can be edited or deleted.
    // Whenever a row that's effective today-or-earlier is created/updated/
    // deleted, the parent Employee's basic_salary / allowance / cost_per_hour
    // are recomputed from the most-recent-on-or-before row so legacy readers
    // (estimation, financial, forecast) see the right "current" value.

    public function indexSalaryHistory(Employee $employee)
    {
        return EmployeeSalaryHistoryResource::collection(
            $employee->salaryHistory()->orderByDesc('target_month')->get()
        );
    }

    public function storeSalaryHistory(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'target_month'  => 'required|date',
            'basic_salary'  => 'required|numeric|min:0',
            'allowance'     => 'sometimes|numeric|min:0',
            'notes'         => 'sometimes|nullable|string|max:500',
        ]);

        $month = $this->coerceTargetMonth($data['target_month']);
        $this->guardPastMonthEdit($month, 'create');

        $allowance = isset($data['allowance']) ? (float) $data['allowance'] : 0.0;
        $hours = max(1, $employee->workable_hours ?: 160);
        $costPerHour = ((float) $data['basic_salary'] + $allowance) / $hours;

        $row = EmployeeSalaryHistory::create([
            'tenant_id'          => $employee->tenant_id,
            'employee_id'        => $employee->id,
            'target_month'       => $month,
            'basic_salary'       => $data['basic_salary'],
            'allowance'          => $allowance,
            'cost_per_hour'      => round($costPerHour, 4),
            'workable_hours'     => $hours,
            'notes'              => $data['notes'] ?? null,
            'created_by_user_id' => $request->user()?->id,
        ]);

        $this->syncEmployeeCurrentSalary($employee);

        return new EmployeeSalaryHistoryResource($row->fresh());
    }

    public function updateSalaryHistory(Request $request, Employee $employee, EmployeeSalaryHistory $history)
    {
        abort_unless($history->employee_id === $employee->id, 404);

        $existingMonth = $history->target_month?->startOfMonth();
        $this->guardPastMonthEdit($existingMonth, 'update');

        $data = $request->validate([
            'basic_salary' => 'sometimes|required|numeric|min:0',
            'allowance'    => 'sometimes|numeric|min:0',
            'notes'        => 'sometimes|nullable|string|max:500',
        ]);

        $basic = (float) ($data['basic_salary'] ?? $history->basic_salary);
        $allowance = (float) ($data['allowance'] ?? $history->allowance);
        $hours = max(1, $history->workable_hours ?: 160);

        $history->update([
            'basic_salary'  => $basic,
            'allowance'     => $allowance,
            'cost_per_hour' => round(($basic + $allowance) / $hours, 4),
            'notes'         => $data['notes'] ?? $history->notes,
        ]);

        $this->syncEmployeeCurrentSalary($employee);

        return new EmployeeSalaryHistoryResource($history->fresh());
    }

    public function destroySalaryHistory(Employee $employee, EmployeeSalaryHistory $history)
    {
        abort_unless($history->employee_id === $employee->id, 404);
        $this->guardPastMonthEdit($history->target_month?->startOfMonth(), 'delete');

        // Don't allow deleting the only remaining row — the employee would
        // be left without any salary record and the Employee row's
        // denormalized cache would have nothing to sync from.
        $rowCount = $employee->salaryHistory()->count();
        if ($rowCount <= 1) {
            abort(422, 'Cannot delete the only salary history row. Add a replacement row first.');
        }

        $history->delete();
        $this->syncEmployeeCurrentSalary($employee);

        return response()->noContent();
    }

    /**
     * Reject edits/deletes/creates targeting a month that's already in the
     * past (per decision 2b: past rows are read-only once the month is over).
     */
    private function guardPastMonthEdit(?\Carbon\Carbon $month, string $action): void
    {
        if (! $month) {
            return;
        }
        $currentMonth = now()->startOfMonth();
        if ($month->lt($currentMonth)) {
            abort(422, "Cannot {$action} a salary row for a past month ({$month->format('Y-m')}). Past months are locked.");
        }
    }

    /**
     * Coerce an incoming date to the first day of its month. The spec keys
     * salary versions by month, not day — accept anything from the frontend
     * and normalise.
     */
    private function coerceTargetMonth(string $raw): \Carbon\Carbon
    {
        return \Carbon\Carbon::parse($raw)->startOfMonth();
    }

    /**
     * Recompute the Employee's denormalized "current" salary values from
     * the most-recent salary-history row whose target_month is on or
     * before today. Updates basic_salary + allowance only; the model save
     * hook re-derives monthly_salary + cost_per_hour from those.
     */
    private function syncEmployeeCurrentSalary(Employee $employee): void
    {
        $current = $employee->salaryHistory()
            ->where('target_month', '<=', now()->startOfDay())
            ->orderByDesc('target_month')
            ->first();

        if (! $current) {
            return;
        }

        $employee->update([
            'basic_salary' => $current->basic_salary,
            'allowance'    => $current->allowance,
        ]);
    }

    // ── Capacity Roles ──────────────────────────────────────────────────────

    public function indexCapacityRoles()
    {
        return CapacityRoleResource::collection(
            CapacityRole::orderBy('name')->get()
        );
    }

    public function storeCapacityRole(Request $request)
    {
        $tenantId = app('tenant_id');
        $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('capacity_roles', 'name')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'code' => [
                'required', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('capacity_roles', 'code')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
        ]);

        $role = CapacityRole::create($request->only(['name', 'code']));

        return new CapacityRoleResource($role);
    }

    public function updateCapacityRole(Request $request, CapacityRole $capacityRole)
    {
        $tenantId = app('tenant_id');
        $request->validate([
            'name' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('capacity_roles', 'name')
                    ->ignore($capacityRole->id)
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'code' => [
                'sometimes', 'required', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('capacity_roles', 'code')
                    ->ignore($capacityRole->id)
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
        ]);

        $capacityRole->update($request->only(['name', 'code']));

        return new CapacityRoleResource($capacityRole);
    }

    public function destroyCapacityRole(CapacityRole $capacityRole)
    {
        $capacityRole->delete();

        return response()->noContent();
    }

    // ── Skills ──────────────────────────────────────────────────────────────

    public function indexSkills()
    {
        return SkillResource::collection(
            Skill::orderBy('category')->orderBy('name')->get()
        );
    }

    public function storeSkill(Request $request)
    {
        $tenantId = app('tenant_id');
        $request->validate([
            'name'     => [
                'required', 'string', 'max:100',
                Rule::unique('skills', 'name')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'category' => 'required|string|max:50',
        ]);

        $skill = Skill::create($request->only(['name', 'category']));

        return new SkillResource($skill);
    }

    public function updateSkill(Request $request, Skill $skill)
    {
        $tenantId = app('tenant_id');
        $request->validate([
            'name' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('skills', 'name')
                    ->ignore($skill->id)
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'category' => 'sometimes|required|string|max:50',
        ]);

        $skill->update($request->only(['name', 'category']));

        return new SkillResource($skill);
    }

    public function destroySkill(Skill $skill)
    {
        $skill->delete();

        return response()->noContent();
    }

    // ── Employee Skills ─────────────────────────────────────────────────────

    public function employeeSkills(Employee $employee)
    {
        $employee->load('skills');

        return response()->json([
            'data' => $employee->skills->map(fn ($skill) => [
                'skill_id'     => $skill->id,
                'skill_name'   => $skill->name,
                'category'    => $skill->category,
                'proficiency' => $skill->pivot->proficiency,
            ]),
        ]);
    }

    public function assignSkill(Request $request, Employee $employee)
    {
        $tenantId = app('tenant_id');
        $request->validate([
            'skill_id'     => 'required|uuid|exists:skills,id',
            'proficiency'  => 'required|in:beginner,intermediate,expert',
        ]);

        $exists = EmployeeSkill::where('employee_id', $employee->id)
            ->where('skill_id', $request->input('skill_id'))
            ->exists();

        if ($exists) {
            EmployeeSkill::where('employee_id', $employee->id)
                ->where('skill_id', $request->input('skill_id'))
                ->update(['proficiency' => $request->input('proficiency')]);
        } else {
            EmployeeSkill::create([
                'tenant_id'    => $tenantId,
                'employee_id'  => $employee->id,
                'skill_id'     => $request->input('skill_id'),
                'proficiency'  => $request->input('proficiency'),
            ]);
        }

        return response()->json(['message' => 'Skill assigned successfully']);
    }

    public function removeSkill(Employee $employee, Skill $skill)
    {
        EmployeeSkill::where('employee_id', $employee->id)
            ->where('skill_id', $skill->id)
            ->delete();

        return response()->noContent();
    }
}
