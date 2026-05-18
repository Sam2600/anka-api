<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HolidayResource;
use App\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HolidayController extends Controller
{
    public function index(Request $request)
    {
        $query = Holiday::query()->orderBy('date');

        if ($request->filled('from')) {
            $query->whereDate('date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('date', '<=', $request->input('to'));
        }

        return HolidayResource::collection($query->get());
    }

    public function store(Request $request)
    {
        $tenantId = app('tenant_id');

        $validated = $request->validate([
            'date' => [
                'required', 'date',
                Rule::unique('holidays', 'date')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'name' => 'required|string|max:150',
            'is_recurring' => 'sometimes|boolean',
        ]);

        $holiday = Holiday::create([
            'tenant_id' => $tenantId,
            'date' => $validated['date'],
            'name' => $validated['name'],
            'is_recurring' => $validated['is_recurring'] ?? false,
        ]);

        return new HolidayResource($holiday);
    }

    public function update(Request $request, Holiday $holiday)
    {
        $tenantId = app('tenant_id');

        $validated = $request->validate([
            'date' => [
                'sometimes', 'date',
                Rule::unique('holidays', 'date')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId))
                    ->ignore($holiday->id),
            ],
            'name' => 'sometimes|string|max:150',
            'is_recurring' => 'sometimes|boolean',
        ]);

        $holiday->update($validated);

        return new HolidayResource($holiday);
    }

    public function destroy(Holiday $holiday)
    {
        $holiday->delete();

        return response()->noContent();
    }
}
