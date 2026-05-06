<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiUsageController extends Controller
{
    // ── Tenant-scoped: log a usage entry (called from Next.js route handler) ──

    public function store(Request $request)
    {
        $validated = $request->validate([
            'feature'            => 'required|string|max:100',
            'model'              => 'required|string|max:100',
            'input_tokens'       => 'required|integer|min:0',
            'output_tokens'      => 'required|integer|min:0',
            'estimated_cost_usd' => 'required|numeric|min:0',
        ]);

        $log = AiUsageLog::create(array_merge($validated, [
            'tenant_id' => app('tenant_id'),
            'user_id'   => $request->user()?->id,
        ]));

        return response()->json(['id' => $log->id], 201);
    }

    // ── Super-admin: aggregate usage across all tenants ───────────────────────

    public function adminIndex()
    {
        $rows = DB::table('ai_usage_logs as l')
            ->join('tenants as t', 'l.tenant_id', '=', 't.id')
            ->select(
                't.id as tenant_id',
                't.name as tenant_name',
                DB::raw('COUNT(*) as total_calls'),
                DB::raw('SUM(l.input_tokens) as total_input_tokens'),
                DB::raw('SUM(l.output_tokens) as total_output_tokens'),
                DB::raw('SUM(l.estimated_cost_usd) as total_cost')
            )
            ->groupBy('t.id', 't.name')
            ->orderByDesc('total_cost')
            ->get()
            ->map(fn($row) => [
                'tenant_id'           => $row->tenant_id,
                'tenant_name'         => $row->tenant_name,
                'total_calls'         => (int) $row->total_calls,
                'total_input_tokens'  => (int) $row->total_input_tokens,
                'total_output_tokens' => (int) $row->total_output_tokens,
                'total_cost'          => round((float) $row->total_cost, 6),
            ]);

        $totals = [
            'total_calls'          => $rows->sum('total_calls'),
            'total_input_tokens'   => $rows->sum('total_input_tokens'),
            'total_output_tokens'  => $rows->sum('total_output_tokens'),
            'total_cost'           => round($rows->sum('total_cost'), 6),
        ];

        return response()->json([
            'totals'  => $totals,
            'tenants' => $rows->values(),
        ]);
    }
}
