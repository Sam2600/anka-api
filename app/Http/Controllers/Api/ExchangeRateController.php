<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExchangeRateController extends Controller
{
    public function index()
    {
        $rates = ExchangeRate::where('tenant_id', app('tenant_id'))
            ->where('to_currency', 'USD')
            ->get(['from_currency', 'rate'])
            ->keyBy('from_currency')
            ->map(fn ($r) => (float) $r->rate);

        return response()->json(['data' => $rates]);
    }

    public function upsert(Request $request)
    {
        $validated = $request->validate([
            'from_currency' => ['required', 'string', 'size:3', Rule::in(['MMK', 'JPY', 'USD'])],
            'to_currency'   => ['sometimes', 'string', 'size:3', Rule::in(['MMK', 'JPY', 'USD'])],
            'rate'          => ['required', 'numeric', 'min:0.000001'],
        ]);

        $fromCurrency = $validated['from_currency'];
        $toCurrency = $validated['to_currency'] ?? 'USD';

        if ($fromCurrency === $toCurrency) {
            return response()->json([
                'message' => 'from_currency and to_currency must be different.',
            ], 422);
        }

        $rate = ExchangeRate::updateOrCreate(
            [
                'tenant_id'     => app('tenant_id'),
                'from_currency' => $fromCurrency,
                'to_currency'   => $toCurrency,
            ],
            [
                'rate' => $validated['rate'],
            ]
        );

        return response()->json(['data' => $rate]);
    }

    public function destroy(ExchangeRate $rate)
    {
        abort_if($rate->tenant_id !== app('tenant_id'), 403, 'Unauthorized');

        $rate->delete();

        return response()->noContent();
    }
}
