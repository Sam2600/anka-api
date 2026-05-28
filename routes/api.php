<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AiAutoAssignController;
use App\Http\Controllers\Api\AiTeamBuilderContextController;
use App\Http\Controllers\Api\AiUsageController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\ContractTemplateController;
use App\Http\Controllers\Api\DealContractDraftController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\EstimationVersionController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MilestoneController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PhaseProgressLogController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\RankController;
use App\Http\Controllers\Api\ScheduleTrackingController;
use App\Http\Controllers\Api\ResourceAllocationController;
use App\Http\Controllers\Api\TeamCapacityController;
use App\Http\Controllers\Api\TenantAppRoleController;
use App\Http\Controllers\Api\TenantBankAccountController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\TimeEntryController;
use Illuminate\Support\Facades\Route;

// throttle:5,1 = max 5 attempts per minute per IP — brute-force protection.
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

// Auth routes are user-scoped — they do NOT need the tenant middleware.
// /auth/me returns the tenant info the frontend needs to SET X-Tenant-ID,
// so requiring X-Tenant-ID on /auth/me would be a chicken-and-egg problem.
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::delete('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/password', [AuthController::class, 'changePassword']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
});

// Super admin routes — no tenant scope, requires is_super_admin = true.
Route::middleware(['auth:sanctum', 'super_admin', 'throttle:60,1'])->prefix('admin')->group(function () {
    Route::get('/dashboard/stats', [AdminController::class, 'dashboardStats']);
    Route::get('/tenants', [TenantController::class, 'index']);
    Route::post('/tenants', [TenantController::class, 'store']);
    Route::get('/tenants/{id}', [TenantController::class, 'showAdmin']);
    Route::put('/tenants/{id}', [TenantController::class, 'updateAdmin']);
    Route::delete('/tenants/{id}', [TenantController::class, 'destroy']);
    Route::get('/tenants/{tenantId}/users', [TenantController::class, 'listUsers']);
    Route::post('/tenants/{tenantId}/users', [TenantController::class, 'createUser']);
    Route::put('/tenants/{tenantId}/users/{userId}', [TenantController::class, 'updateUser']);
    Route::delete('/tenants/{tenantId}/users/{userId}', [TenantController::class, 'deleteUser']);
    Route::get('/ai-usage', [AiUsageController::class, 'adminIndex']);
    Route::get('/audit-logs', [AdminController::class, 'auditLogs']);
    Route::get('/users', [AdminController::class, 'listAllUsers']);
});

// Business data routes — require tenant scope.
Route::middleware(['auth:sanctum', 'tenant', 'throttle:60,1'])->group(function () {
    // Deals — reads require view_crm (Executive, Sales, Admin), writes
    // require manage_crm (Sales, Admin). Aligns with the frontend route
    // permission table in lib/route-permissions.ts so Delivery and HR
    // can't reach the CRM API even by URL.
    Route::middleware('permission:view_crm')->group(function () {
        Route::get('/deals', [DealController::class, 'index']);
        Route::get('/deals/{deal}', [DealController::class, 'show']);
        Route::get('/deals/{deal}/contract', [DealController::class, 'linkedContract']);
    });

    Route::middleware('permission:manage_crm')->group(function () {
        Route::post('/deals', [DealController::class, 'store']);
        Route::put('/deals/{deal}', [DealController::class, 'update']);
        Route::patch('/deals/{deal}', [DealController::class, 'update']);
        Route::delete('/deals/{deal}', [DealController::class, 'destroy']);
        // chg-011 Phase B-breaking: removed PATCH /deals/{deal}/stage,
        // POST /deals/{deal}/win, POST /deals/{deal}/lose. Rank changes
        // now fire only from event triggers — Estimation flips C→B,
        // ContractDraftService flips B→A on draft generation and A→S on
        // counter-signed PDF upload. The Drop endpoint replaces /lose
        // and uses the orthogonal lifecycle_status flag.
        Route::post('/deals/{deal}/drop', [DealController::class, 'drop']);
    });

    // Rich employee + past-projects context for the AI Team Builder.
    // view_crm because the data shown (employees + past projects) is
    // already visible to anyone with CRM-read access via other endpoints.
    Route::middleware('permission:view_crm')->group(function () {
        Route::get('/deals/{deal}/ai-team-builder-context', [AiTeamBuilderContextController::class, 'show']);
    });

    // ── AI Contract Drafting (Project Pipeline ⑤ Contract Generation) ──
    // chg-011 Phase C. Wizard surface for generating English SES contracts
    // from the Yazaki-modelled template variants. Generates A→S via
    // ContractDraftService::markSigned (counter-signed PDF upload).
    Route::middleware('permission:view_crm')->group(function () {
        // Stream the rendered contract PDF inline for the wizard's preview
        // modal. Cached per draft+version on the local disk; cache invalidates
        // automatically on section edit/regenerate.
        Route::get('/contract-drafts/{contractDraft}/preview-pdf', [DealContractDraftController::class, 'previewPdf']);
        Route::get('/contract-templates', [ContractTemplateController::class, 'index'])
            ->name('contract-templates.index');
        Route::get('/contract-templates/{contractTemplate}', [ContractTemplateController::class, 'show'])
            ->name('contract-templates.show');
        Route::get('/deals/{deal}/contract-drafts', [DealContractDraftController::class, 'index']);
        Route::get('/contract-drafts/{contractDraft}', [DealContractDraftController::class, 'show'])
            ->name('contract-drafts.show');
    });
    Route::middleware('permission:manage_crm')->group(function () {
        // Start generation — fires B→A on first successful Claude call.
        Route::post('/deals/{deal}/contract-drafts', [DealContractDraftController::class, 'store']);
        // Per-section edit (wizard step 2).
        Route::patch('/contract-drafts/{contractDraft}/sections/{sectionKey}', [DealContractDraftController::class, 'updateSection']);
        // Re-run AI for a single section with updated wizard inputs.
        Route::post('/contract-drafts/{contractDraft}/regenerate-section', [DealContractDraftController::class, 'regenerateSection']);
        Route::post('/contract-drafts/{contractDraft}/finalise', [DealContractDraftController::class, 'finalise']);
        // Email send. send_contract_draft is manager-only; manage_crm fallback
        // for tenants that haven't seeded the new permission yet.
        Route::post('/contract-drafts/{contractDraft}/send', [DealContractDraftController::class, 'send']);
        // Counter-signed PDF upload — fires A→S via win_deal().
        // AI-backed verifier: read-only check that the uploaded signed PDF
        // matches what we sent and contains a customer signature. The
        // wizard calls this before mark-signed; the verdict drives the
        // gate / override UX. Does not mutate the draft.
        Route::post('/contract-drafts/{contractDraft}/verify-signed-pdf', [DealContractDraftController::class, 'verifySigned']);
        Route::post('/contract-drafts/{contractDraft}/mark-signed', [DealContractDraftController::class, 'markSigned']);
        Route::delete('/contract-drafts/{contractDraft}', [DealContractDraftController::class, 'destroy']);
    });

    // Estimation Versions — reads require view_crm; writes require manage_crm
    // because saving / restoring a version mutates the parent deal's cost fields.
    Route::middleware('permission:view_crm')->group(function () {
        Route::get('/deals/{deal}/estimation-versions', [EstimationVersionController::class, 'index']);
        Route::get('/estimation-versions/{id}', [EstimationVersionController::class, 'show']);
        Route::get('/estimation-versions/{id}/download/xlsx', [EstimationVersionController::class, 'downloadXlsx']);
    });
    Route::middleware('permission:manage_crm')->group(function () {
        Route::post('/deals/{deal}/estimation-versions', [EstimationVersionController::class, 'store']);
        Route::post('/deals/{deal}/estimation-versions/ai-draft', [EstimationVersionController::class, 'aiDraft']);
        Route::post('/deals/{deal}/estimation-versions/ai-delta', [EstimationVersionController::class, 'aiDelta']);
        Route::post('/estimation-versions/{id}/restore', [EstimationVersionController::class, 'restore']);
        // Spec ④.G — email the estimate XLSX to the customer (manual confirm).
        Route::post('/estimation-versions/{id}/send', [EstimationVersionController::class, 'sendXlsx']);
    });

    // Contracts (created only via win_deal; no store route). Reads shared by
    // CRM (deal -> linked contract lookup) and the dedicated Contracts page.
    Route::middleware('permission:view_contracts|view_crm')->group(function () {
        Route::get('/contracts',                       [ContractController::class, 'index']);
        Route::get('/contracts/{contract}',            [ContractController::class, 'show']);
        Route::get('/contracts/{contract}/project',    [ContractController::class, 'linkedProject']);
    });
    Route::middleware('permission:manage_crm')->group(function () {
        Route::put   ('/contracts/{contract}', [ContractController::class, 'update']);
        Route::patch ('/contracts/{contract}', [ContractController::class, 'update']);
        Route::delete('/contracts/{contract}', [ContractController::class, 'destroy']);
    });

    // Invoices — reads gated to anyone with billing/CRM visibility, mutations
    // require manage_crm (invoicing is the agency-side CRM extension).
    Route::middleware('permission:view_contracts|view_crm')->group(function () {
        Route::get('/invoices',            [InvoiceController::class, 'index']);
        Route::get('/invoices/{invoice}',  [InvoiceController::class, 'show']);
        Route::get('/invoices/{invoice}/export.xlsx', [InvoiceController::class, 'export']);
    });
    Route::middleware('permission:manage_crm')->group(function () {
        Route::post  ('/invoices',                  [InvoiceController::class, 'store']);
        Route::patch ('/invoices/{invoice}',        [InvoiceController::class, 'update']);
        Route::delete('/invoices/{invoice}',        [InvoiceController::class, 'destroy']);
        Route::patch ('/invoices/{invoice}/pay',    [InvoiceController::class, 'pay']);
        Route::post  ('/invoices/{invoice}/send',   [InvoiceController::class, 'send']);
        Route::post  ('/contracts/{contract}/invoices/preview', [InvoiceController::class, 'preview']);
    });

    // Projects (created only via win_deal; no store route). Read access is
    // shared by Delivery and CRM (linked project lookup); writes are Delivery's.
    Route::middleware('permission:view_projects|view_crm|view_schedule_tracking')->group(function () {
        Route::get('/projects',            [ProjectController::class, 'index']);
        Route::get('/projects/{project}',  [ProjectController::class, 'show']);
    });
    Route::middleware('permission:manage_projects')->group(function () {
        Route::put   ('/projects/{project}', [ProjectController::class, 'update']);
        Route::patch ('/projects/{project}', [ProjectController::class, 'update']);
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);
    });

    // Time Entries — own entries gated to track_time; approval/reject require
    // approve_time (a manager permission). Reads visible to anyone who can
    // log or approve time.
    Route::middleware('permission:track_time|approve_time')->group(function () {
        Route::get('/time-entries',                 [TimeEntryController::class, 'index']);
        Route::get('/time-entries/{time_entry}',    [TimeEntryController::class, 'show']);
    });
    Route::middleware('permission:track_time')->group(function () {
        Route::post  ('/time-entries',                                [TimeEntryController::class, 'store']);
        Route::delete('/time-entries/{time_entry}',                   [TimeEntryController::class, 'destroy']);
        Route::patch ('/time-entries/{time_entry}/submit',            [TimeEntryController::class, 'submit']);
    });
    Route::middleware('permission:approve_time')->group(function () {
        Route::patch('/time-entries/{time_entry}/approve', [TimeEntryController::class, 'approve']);
        Route::patch('/time-entries/{time_entry}/reject',  [TimeEntryController::class, 'reject']);
    });

    // Organization — departments and legacy job roles surface throughout the
    // app (employee lists, deal staffing, project assignments), so reads are
    // intentionally broad. Writes are HR/Admin via manage_organization.
    Route::middleware('permission:manage_organization|view_employees|view_crm|view_projects')->group(function () {
        Route::get('/departments', [OrganizationController::class, 'indexDepartments']);
        Route::get('/roles',       [OrganizationController::class, 'indexRoles']);
    });
    Route::middleware('permission:manage_organization')->group(function () {
        Route::post  ('/departments',               [OrganizationController::class, 'storeDepartment']);
        Route::put   ('/departments/{department}',  [OrganizationController::class, 'updateDepartment']);
        Route::delete('/departments/{department}',  [OrganizationController::class, 'destroyDepartment']);
        Route::post  ('/roles',         [OrganizationController::class, 'storeRole']);
        Route::put   ('/roles/{role}',  [OrganizationController::class, 'updateRole']);
        Route::delete('/roles/{role}',  [OrganizationController::class, 'destroyRole']);
    });

    // Employees — read needed by CRM (assignment), Projects, AI Team Builder,
    // and HR. Writes are HR's manage_employees.
    Route::middleware('permission:view_employees|view_crm|view_projects|manage_organization')->group(function () {
        Route::get('/employees', [OrganizationController::class, 'indexEmployees']);
    });
    Route::middleware('permission:manage_employees')->group(function () {
        Route::post  ('/employees',             [OrganizationController::class, 'storeEmployee']);
        Route::put   ('/employees/{employee}',  [OrganizationController::class, 'updateEmployee']);
        Route::delete('/employees/{employee}',  [OrganizationController::class, 'destroyEmployee']);
    });

    // Public holidays — drives holiday-aware capacity math everywhere, so
    // reads are broad. Writes are HR/Admin.
    Route::middleware('permission:manage_organization|view_employees|view_projects|view_crm')->group(function () {
        Route::get('/holidays',              [HolidayController::class, 'index']);
        Route::get('/team-capacity',         [TeamCapacityController::class, 'index']);
        Route::get('/resource-allocation',   [ResourceAllocationController::class, 'index']);
    });
    Route::middleware('permission:manage_organization')->group(function () {
        Route::post  ('/holidays',            [HolidayController::class, 'store']);
        Route::patch ('/holidays/{holiday}',  [HolidayController::class, 'update']);
        Route::delete('/holidays/{holiday}',  [HolidayController::class, 'destroy']);
    });

    // Salary history — sensitive. Reads gated to view_employees; writes to
    // manage_employees. CRM does NOT need salary history.
    Route::middleware('permission:view_employees')->group(function () {
        Route::get('/employees/{employee}/salary-history', [OrganizationController::class, 'indexSalaryHistory']);
    });
    Route::middleware('permission:manage_employees')->group(function () {
        Route::post  ('/employees/{employee}/salary-history',             [OrganizationController::class, 'storeSalaryHistory']);
        Route::put   ('/employees/{employee}/salary-history/{history}',   [OrganizationController::class, 'updateSalaryHistory']);
        Route::delete('/employees/{employee}/salary-history/{history}',   [OrganizationController::class, 'destroySalaryHistory']);
    });

    // Tenant-wide salary timeline — Forecast page reads it to compute
    // past-month payroll from applicable historical salaries.
    Route::middleware('permission:view_employees')->group(function () {
        Route::get('/employee-salary-history', [OrganizationController::class, 'indexAllSalaryHistory']);
    });

    // Global overheads — read by estimation; written by HR/Admin.
    Route::middleware('permission:manage_organization|manage_estimation|view_crm')->group(function () {
        Route::get('/global-overheads', [OrganizationController::class, 'indexOverheads']);
    });
    Route::middleware('permission:manage_organization')->group(function () {
        Route::post  ('/global-overheads',                  [OrganizationController::class, 'storeOverhead']);
        Route::put   ('/global-overheads/{globalOverhead}', [OrganizationController::class, 'updateOverhead']);
        Route::delete('/global-overheads/{globalOverhead}', [OrganizationController::class, 'destroyOverhead']);
    });

    // Company settings — read by every dashboard surface (currency, tax,
    // exchange rates display). Writes are tenant admin.
    Route::get('/company-settings', [OrganizationController::class, 'getSettings']);
    Route::middleware('permission:manage_tenant')->group(function () {
        Route::put('/company-settings', [OrganizationController::class, 'upsertSettings']);
    });

    // Initial Budgets — year-scoped target profit. Read by Finance/Forecast;
    // written by tenant admin (target-setting is an exec decision).
    Route::middleware('permission:view_reports|manage_tenant')->group(function () {
        Route::get('/initial-budgets', [OrganizationController::class, 'indexInitialBudgets']);
    });
    Route::middleware('permission:manage_tenant')->group(function () {
        Route::put('/initial-budgets/{fiscal_year}', [OrganizationController::class, 'upsertInitialBudget'])
            ->whereNumber('fiscal_year');
        Route::delete('/initial-budgets/{fiscal_year}', [OrganizationController::class, 'destroyInitialBudget'])
            ->whereNumber('fiscal_year');
    });

    // Capacity Roles — read by employee assignment, CRM staffing, AI team
    // builder. Written by HR/Admin.
    Route::middleware('permission:manage_organization|view_employees|view_crm|view_projects')->group(function () {
        Route::get('/capacity-roles', [OrganizationController::class, 'indexCapacityRoles']);
    });
    Route::middleware('permission:manage_organization')->group(function () {
        Route::post  ('/capacity-roles',                  [OrganizationController::class, 'storeCapacityRole']);
        Route::put   ('/capacity-roles/{capacityRole}',   [OrganizationController::class, 'updateCapacityRole']);
        Route::delete('/capacity-roles/{capacityRole}',   [OrganizationController::class, 'destroyCapacityRole']);
    });

    // Ranks — read by CRM staffing and HR. Written by HR/Admin.
    Route::middleware('permission:manage_organization|view_employees|view_crm|view_projects')->group(function () {
        Route::get('/ranks', [RankController::class, 'index']);
    });
    Route::middleware('permission:manage_organization')->group(function () {
        Route::post  ('/ranks',         [RankController::class, 'store']);
        Route::put   ('/ranks/{rank}',  [RankController::class, 'update']);
        Route::delete('/ranks/{rank}',  [RankController::class, 'destroy']);
    });

    // Skills — read everywhere employees appear; written by HR/Admin.
    Route::middleware('permission:manage_organization|view_employees|view_crm|view_projects')->group(function () {
        Route::get('/skills', [OrganizationController::class, 'indexSkills']);
    });
    Route::middleware('permission:manage_organization')->group(function () {
        Route::post  ('/skills',          [OrganizationController::class, 'storeSkill']);
        Route::put   ('/skills/{skill}',  [OrganizationController::class, 'updateSkill']);
        Route::delete('/skills/{skill}',  [OrganizationController::class, 'destroySkill']);
    });

    // Employee Skills — read alongside employees; written by HR.
    Route::middleware('permission:view_employees|view_crm|view_projects')->group(function () {
        Route::get('/employees/{employee}/skills', [OrganizationController::class, 'employeeSkills']);
    });
    Route::middleware('permission:manage_employees')->group(function () {
        Route::post  ('/employees/{employee}/skills',           [OrganizationController::class, 'assignSkill']);
        Route::delete('/employees/{employee}/skills/{skill}',   [OrganizationController::class, 'removeSkill']);
    });

    // AI usage logging — any authenticated tenant user can write a telemetry
    // event (these are emitted by the frontend whenever an AI feature runs).
    // No specific permission required beyond tenant scope.
    Route::post('/ai-usage', [AiUsageController::class, 'store']);

    // Exchange Rates — read used in currency conversion across CRM, Finance,
    // Forecast. Writes are tenant admin.
    Route::middleware('permission:view_reports|view_crm|manage_tenant')->group(function () {
        Route::get('/exchange-rates', [ExchangeRateController::class, 'index']);
    });
    Route::middleware('permission:manage_tenant')->group(function () {
        Route::put   ('/exchange-rates',          [ExchangeRateController::class, 'upsert']);
        Route::delete('/exchange-rates/{rate}',   [ExchangeRateController::class, 'destroy']);
    });

    // Tenant settings (own tenant only) — read is open to every tenant user
    // (the Sidebar/Header reads tenant name & logo). Writes are admin.
    Route::get('/tenant', [TenantController::class, 'show']);
    Route::middleware('permission:manage_tenant')->group(function () {
        Route::put   ('/tenant',        [TenantController::class, 'update']);
        Route::post  ('/tenant/logo',   [TenantController::class, 'uploadLogo']);
        Route::delete('/tenant/logo',   [TenantController::class, 'deleteLogo']);
    });

    // Tenant bank accounts — rendered at the bottom of the Invoice XLSX
    // export. CRUD endpoints back the Org → Company → Bank Accounts panel.
    Route::get('/tenant/bank-accounts', [TenantBankAccountController::class, 'index']);
    Route::post('/tenant/bank-accounts', [TenantBankAccountController::class, 'store']);
    Route::put('/tenant/bank-accounts/{bankAccount}', [TenantBankAccountController::class, 'update']);
    Route::delete('/tenant/bank-accounts/{bankAccount}', [TenantBankAccountController::class, 'destroy']);

    // Tenant-managed app roles + admin-editable permissions. List/catalog
    // are readable by anyone in the tenant (sidebar + role pickers); writes
    // require manage_tenant. The permission catalog itself is code-defined
    // in App\Support\PermissionCatalog — admins compose, they don't invent.
    Route::get('/tenant/app-roles', [TenantAppRoleController::class, 'index']);
    Route::get('/tenant/permission-catalog', [TenantAppRoleController::class, 'catalog']);
    Route::middleware('permission:manage_tenant')->group(function () {
        Route::post('/tenant/app-roles', [TenantAppRoleController::class, 'store']);
        Route::patch('/tenant/app-roles/{appRoleId}', [TenantAppRoleController::class, 'update']);
        Route::delete('/tenant/app-roles/{appRoleId}', [TenantAppRoleController::class, 'destroy']);
    });

    // Milestones — read shared by Contracts page and CRM; writes are CRM.
    Route::middleware('permission:view_contracts|view_crm')->group(function () {
        Route::get('/milestones',               [MilestoneController::class, 'index']);
        Route::get('/milestones/{milestone}',   [MilestoneController::class, 'show']);
    });
    Route::middleware('permission:manage_crm')->group(function () {
        Route::post  ('/milestones',                       [MilestoneController::class, 'store']);
        Route::put   ('/milestones/{milestone}',           [MilestoneController::class, 'update']);
        Route::patch ('/milestones/{milestone}',           [MilestoneController::class, 'update']);
        Route::delete('/milestones/{milestone}',           [MilestoneController::class, 'destroy']);
        Route::patch ('/milestones/{milestone}/accept',    [MilestoneController::class, 'accept']);
    });

    // Project Team Assignments — read is shared with anyone viewing the
    // project; mutations require manage_projects.
    Route::middleware('permission:view_projects|view_crm|view_schedule_tracking')->group(function () {
        Route::get('/projects/{project}/team',               [AiAutoAssignController::class, 'index']);
        Route::get('/projects/{project}/task-assignments',   [AiAutoAssignController::class, 'taskAssignmentsIndex']);
    });
    Route::middleware('permission:manage_projects')->group(function () {
        Route::post  ('/projects/{project}/team',                       [AiAutoAssignController::class, 'store']);
        Route::delete('/projects/{project}/team/{assignment}',          [AiAutoAssignController::class, 'destroy']);
        // @deprecated — replaced by the plan-team + confirm-team preview flow.
        Route::post('/projects/{project}/auto-assign',                  [AiAutoAssignController::class, 'autoAssign']);

        // AI team build — preview proposes employees for unfilled ghost roles;
        // confirm writes only the picks the user accepted. No DB writes in preview.
        Route::post('/projects/{project}/plan-team',                    [AiAutoAssignController::class, 'planTeamPreview']);
        Route::post('/projects/{project}/confirm-team',                 [AiAutoAssignController::class, 'confirmTeamPlan']);

        // Idle full-time employees the Team Preview dialog can pick from for
        // manual replacements / additions. Returns ZERO project_team_assignments
        // employees only — the same pool the AI now draws from.
        Route::get('/projects/{project}/available-employees',           [AiAutoAssignController::class, 'availableEmployees']);

        // Project Task Assignments (xlsx-driven AI task allocation, per-phase)
        Route::post ('/projects/{project}/assign-tasks',                                                          [AiAutoAssignController::class, 'assignTasks']);
        Route::patch('/projects/{project}/task-phase-assignments/{phaseAssignment}',                              [AiAutoAssignController::class, 'updateTaskPhaseAssignment']);
        Route::post ('/projects/{project}/task-phase-assignments/{phaseAssignment}/check-reassignment',           [AiAutoAssignController::class, 'checkReassignment']);
        Route::post ('/projects/{project}/task-phase-assignments/{phaseAssignment}/reassign',                     [AiAutoAssignController::class, 'reassignPhase']);
    });

    // Schedule tracking — daily progress logs + project/phase variance.
    // See SCHEDULE_TRACKING_IMPLEMENTATION_PLAN.md for the design. Reads
    // shared by dashboard viewers and the ICs logging the work; writes
    // require log_progress; unlock/destroy require manage_projects or
    // approve_time (a manager unlocking a sealed day).
    Route::middleware('permission:view_schedule_tracking|log_progress|manage_projects')->group(function () {
        Route::get('/phase-assignments/{phaseAssignment}/progress-logs', [PhaseProgressLogController::class, 'index']);
        // Literal /summary must precede the /{log} wildcard or Laravel binds
        // "summary" as the log id and answers 405 instead of dispatching here.
        Route::get('/phase-progress-logs/summary',  [PhaseProgressLogController::class, 'summary']);
        Route::get('/me/schedule-tracking/today',   [PhaseProgressLogController::class, 'today']);
    });
    Route::middleware('permission:log_progress')->group(function () {
        Route::post ('/phase-assignments/{phaseAssignment}/progress-logs', [PhaseProgressLogController::class, 'store']);
        Route::patch('/phase-progress-logs/{log}',                         [PhaseProgressLogController::class, 'update']);
    });
    Route::middleware('permission:manage_projects|approve_time')->group(function () {
        Route::delete('/phase-progress-logs/{log}',          [PhaseProgressLogController::class, 'destroy']);
        Route::post  ('/phase-progress-logs/{log}/unlock',   [PhaseProgressLogController::class, 'unlock']);
    });

    Route::middleware('permission:view_schedule_tracking|manage_projects')->group(function () {
        Route::get('/projects/{project}/schedule-tracking',             [ScheduleTrackingController::class, 'index']);
        Route::get('/projects/{project}/schedule-tracking/summary',     [ScheduleTrackingController::class, 'summary']);
        Route::get('/projects/{project}/schedule-tracking/by-assignee', [ScheduleTrackingController::class, 'byAssignee']);
    });
    // Per-day per-developer late-hours breakdown. Drives the Finance page's
    // overtime calc and the "Late Hours by Developer" table — view_reports
    // is the Finance gate.
    Route::middleware('permission:view_reports|view_schedule_tracking|manage_projects')->group(function () {
        Route::get('/projects/{project}/late-hours-by-day', [ScheduleTrackingController::class, 'lateHoursByDay']);
    });
});
