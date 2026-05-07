<?php

use App\Http\Controllers\Api\AiUsageController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\EstimationVersionController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MilestoneController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ProjectController;
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
    // Deals
    Route::apiResource('deals', DealController::class);
    Route::patch('/deals/{deal}/stage', [DealController::class,    'updateStage']);
    Route::post('/deals/{deal}/win', [DealController::class,    'win']);
    Route::post('/deals/{deal}/lose', [DealController::class,    'lose']);
    Route::get('/deals/{deal}/contract', [DealController::class,    'linkedContract']);

    // Estimation Versions
    Route::get('/deals/{deal}/estimation-versions', [EstimationVersionController::class, 'index']);
    Route::post('/deals/{deal}/estimation-versions', [EstimationVersionController::class, 'store']);
    Route::get('/estimation-versions/{id}', [EstimationVersionController::class, 'show']);
    Route::post('/estimation-versions/{id}/restore', [EstimationVersionController::class, 'restore']);

    // Contracts (created only via win_deal; no store route)
    Route::apiResource('contracts', ContractController::class)->only(['index', 'show', 'update', 'destroy']);
    Route::get('/contracts/{contract}/project', [ContractController::class, 'linkedProject']);

    // Invoices
    Route::apiResource('invoices', InvoiceController::class)->only(['index', 'show', 'store', 'destroy']);
    Route::patch('/invoices/{invoice}/pay', [InvoiceController::class, 'pay']);

    // Projects (created only via win_deal; no store route)
    Route::apiResource('projects', ProjectController::class)->only(['index', 'show', 'update', 'destroy']);

    // Time Entries
    Route::apiResource('time-entries', TimeEntryController::class)->only(['index', 'show', 'store', 'destroy']);
    Route::patch('/time-entries/{time_entry}/approve', [TimeEntryController::class, 'approve']);

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

    // AI usage logging (tenant-scoped)
    Route::post('/ai-usage', [AiUsageController::class, 'store']);

    // Tenant settings (own tenant only)
    Route::get('/tenant', [TenantController::class, 'show']);
    Route::put('/tenant', [TenantController::class, 'update']);

    // Milestones
    Route::apiResource('milestones', MilestoneController::class);
});
