<?php

use App\Http\Controllers\Api\AiAutoAssignController;
use App\Http\Controllers\Api\AiTeamBuilderContextController;
use App\Http\Controllers\Api\AiUsageController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\ContractTemplateController;
use App\Http\Controllers\Api\DealContractDraftController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\EstimationVersionController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MilestoneController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\RankController;
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
        Route::post('/contract-drafts/{contractDraft}/mark-signed', [DealContractDraftController::class, 'markSigned']);
        Route::delete('/contract-drafts/{contractDraft}', [DealContractDraftController::class, 'destroy']);
    });

    // Estimation Versions — reads require view_crm; writes require manage_crm
    // because saving / restoring a version mutates the parent deal's cost fields.
    Route::middleware('permission:view_crm')->group(function () {
        Route::get('/deals/{deal}/estimation-versions', [EstimationVersionController::class, 'index']);
        Route::get('/estimation-versions/{id}', [EstimationVersionController::class, 'show']);
    });
    Route::middleware('permission:manage_crm')->group(function () {
        Route::post('/deals/{deal}/estimation-versions', [EstimationVersionController::class, 'store']);
        Route::post('/estimation-versions/{id}/restore', [EstimationVersionController::class, 'restore']);
    });

    // Contracts (created only via win_deal; no store route)
    Route::apiResource('contracts', ContractController::class)->only(['index', 'show', 'update', 'destroy']);
    Route::get('/contracts/{contract}/project', [ContractController::class, 'linkedProject']);

    // Invoices
    Route::apiResource('invoices', InvoiceController::class)->only(['index', 'show', 'store', 'destroy']);
    Route::patch('/invoices/{invoice}',      [InvoiceController::class, 'update']);
    Route::patch('/invoices/{invoice}/pay',  [InvoiceController::class, 'pay']);
    Route::post('/invoices/{invoice}/send',  [InvoiceController::class, 'send']);

    // Projects (created only via win_deal; no store route)
    Route::apiResource('projects', ProjectController::class)->only(['index', 'show', 'update', 'destroy']);

    // Time Entries
    Route::apiResource('time-entries', TimeEntryController::class)->only(['index', 'show', 'store', 'destroy']);
    Route::patch('/time-entries/{time_entry}/approve', [TimeEntryController::class, 'approve']);
    Route::patch('/time-entries/{time_entry}/submit',  [TimeEntryController::class, 'submit']);
    Route::patch('/time-entries/{time_entry}/reject',  [TimeEntryController::class, 'reject']);

    // Organization
    Route::get('/departments', [OrganizationController::class, 'indexDepartments']);
    Route::post('/departments', [OrganizationController::class, 'storeDepartment']);
    Route::put('/departments/{department}', [OrganizationController::class, 'updateDepartment']);
    Route::delete('/departments/{department}', [OrganizationController::class, 'destroyDepartment']);

    Route::get('/roles', [OrganizationController::class, 'indexRoles']);
    Route::post('/roles', [OrganizationController::class, 'storeRole']);
    Route::put('/roles/{role}', [OrganizationController::class, 'updateRole']);
    Route::delete('/roles/{role}', [OrganizationController::class, 'destroyRole']);

    Route::get('/employees', [OrganizationController::class, 'indexEmployees']);
    Route::post('/employees', [OrganizationController::class, 'storeEmployee']);
    Route::put('/employees/{employee}', [OrganizationController::class, 'updateEmployee']);
    Route::delete('/employees/{employee}', [OrganizationController::class, 'destroyEmployee']);

    Route::get('/global-overheads', [OrganizationController::class, 'indexOverheads']);
    Route::post('/global-overheads', [OrganizationController::class, 'storeOverhead']);
    Route::put('/global-overheads/{globalOverhead}', [OrganizationController::class, 'updateOverhead']);
    Route::delete('/global-overheads/{globalOverhead}', [OrganizationController::class, 'destroyOverhead']);

    Route::get('/company-settings', [OrganizationController::class, 'getSettings']);
    Route::put('/company-settings', [OrganizationController::class, 'upsertSettings']);

    // Capacity Roles
    Route::get('/capacity-roles', [OrganizationController::class, 'indexCapacityRoles']);
    Route::post('/capacity-roles', [OrganizationController::class, 'storeCapacityRole']);
    Route::put('/capacity-roles/{capacityRole}', [OrganizationController::class, 'updateCapacityRole']);
    Route::delete('/capacity-roles/{capacityRole}', [OrganizationController::class, 'destroyCapacityRole']);

    // Ranks — tenant-managed seniority tiers used by the AI Team Builder.
    // Defaults seeded as Junior/Mid/Senior/Lead but tenants can add custom
    // ranks (e.g. "Principal", "Staff Engineer") via this endpoint.
    Route::get('/ranks', [RankController::class, 'index']);
    Route::post('/ranks', [RankController::class, 'store']);
    Route::put('/ranks/{rank}', [RankController::class, 'update']);
    Route::delete('/ranks/{rank}', [RankController::class, 'destroy']);

    // Skills
    Route::get('/skills', [OrganizationController::class, 'indexSkills']);
    Route::post('/skills', [OrganizationController::class, 'storeSkill']);
    Route::put('/skills/{skill}', [OrganizationController::class, 'updateSkill']);
    Route::delete('/skills/{skill}', [OrganizationController::class, 'destroySkill']);

    // Employee Skills
    Route::get('/employees/{employee}/skills', [OrganizationController::class, 'employeeSkills']);
    Route::post('/employees/{employee}/skills', [OrganizationController::class, 'assignSkill']);
    Route::delete('/employees/{employee}/skills/{skill}', [OrganizationController::class, 'removeSkill']);

    // AI usage logging (tenant-scoped)
    Route::post('/ai-usage', [AiUsageController::class, 'store']);

    // Exchange Rates
    Route::get('/exchange-rates', [ExchangeRateController::class, 'index']);
    Route::put('/exchange-rates', [ExchangeRateController::class, 'upsert']);
    Route::delete('/exchange-rates/{rate}', [ExchangeRateController::class, 'destroy']);

    // Tenant settings (own tenant only)
    Route::get('/tenant', [TenantController::class, 'show']);
    Route::put('/tenant', [TenantController::class, 'update']);

    // Milestones
    Route::apiResource('milestones', MilestoneController::class);
    Route::patch('/milestones/{milestone}/accept', [MilestoneController::class, 'accept']);

    // Project Team Assignments
    Route::get('/projects/{project}/team', [AiAutoAssignController::class, 'index']);
    Route::post('/projects/{project}/team', [AiAutoAssignController::class, 'store']);
    Route::delete('/projects/{project}/team/{assignment}', [AiAutoAssignController::class, 'destroy']);
    Route::post('/projects/{project}/auto-assign', [AiAutoAssignController::class, 'autoAssign']);

    // Project Task Assignments (xlsx-driven AI task allocation)
    Route::post('/projects/{project}/assign-tasks', [AiAutoAssignController::class, 'assignTasks']);
    Route::get('/projects/{project}/task-assignments', [AiAutoAssignController::class, 'taskAssignmentsIndex']);
    Route::patch('/projects/{project}/task-assignments/{assignment}', [AiAutoAssignController::class, 'updateTaskAssignment']);
});
