<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    /**
     * Platform-wide dashboard statistics.
     */
    public function dashboardStats()
    {
        $totalTenants = Tenant::count();
        $activeTenants = Tenant::where('is_active', true)->count();
        $inactiveTenants = $totalTenants - $activeTenants;

        $totalUsers = User::whereNull('deleted_at')->whereNotNull('tenant_id')->count();

        // AI usage totals
        $aiStats = DB::table('ai_usage_logs')
            ->selectRaw('COUNT(*) as total_calls, SUM(input_tokens + output_tokens) as total_tokens, COALESCE(SUM(estimated_cost_usd), 0) as total_cost')
            ->first();

        // Tenant signups over last 6 months
        $signupData = DB::table('tenants')
            ->selectRaw("strftime('%Y-%m', created_at) as month, COUNT(*) as count")
            ->where('created_at', '>=', now()->subMonths(6))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // Recent signups (last 5)
        $recentSignups = Tenant::orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['id', 'name', 'slug', 'plan', 'is_active', 'created_at']);

        // Plan distribution
        $planDistribution = DB::table('tenants')
            ->selectRaw('COALESCE(plan, \'free\') as plan, COUNT(*) as count')
            ->groupBy('plan')
            ->get();

        return response()->json([
            'data' => [
                'total_tenants' => $totalTenants,
                'active_tenants' => $activeTenants,
                'inactive_tenants' => $inactiveTenants,
                'total_users' => $totalUsers,
                'ai_usage' => [
                    'total_calls' => (int) ($aiStats->total_calls ?? 0),
                    'total_tokens' => (int) ($aiStats->total_tokens ?? 0),
                    'total_cost' => (float) ($aiStats->total_cost ?? 0),
                ],
                'signups_over_time' => $signupData->map(fn ($s) => [
                    'month' => $s->month,
                    'count' => $s->count,
                ]),
                'recent_signups' => $recentSignups->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'slug' => $t->slug,
                    'plan' => $t->plan ?? 'free',
                    'is_active' => $t->is_active,
                    'created_at' => $t->created_at,
                ]),
                'plan_distribution' => $planDistribution->map(fn ($p) => [
                    'plan' => $p->plan,
                    'count' => $p->count,
                ]),
            ],
        ]);
    }

    public function auditLogs()
    {
        $logs = AuditLog::with('user:id,first_name,last_name,email')
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json([
            'data' => $logs->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'details' => $log->details,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => "{$log->user->first_name} {$log->user->last_name}",
                    'email' => $log->user->email,
                ] : null,
            ]),
            'meta' => [
                'total' => $logs->total(),
                'per_page' => $logs->perPage(),
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }
}
