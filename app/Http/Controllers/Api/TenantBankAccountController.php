<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TenantBankAccount;
use Illuminate\Http\Request;

/**
 * CRUD for the tenant's bank accounts rendered at the bottom of the
 * Invoice XLSX export. Tenant-scoped via the BelongsToTenant trait on
 * the model; only the active tenant's rows are visible.
 *
 * The Org → Company tab lets ops add/remove/reorder accounts. Each row
 * has a label (e.g. "Kanbawza Bank Limited (USD)") plus the standard
 * bank fields. All bank-detail fields are nullable so partial entries
 * still work — only `label` is required.
 */
class TenantBankAccountController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => TenantBankAccount::orderBy('sort_order')->get()->map(fn ($b) => $this->serialize($b)),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateInput($request);

        $bank = TenantBankAccount::create($validated);

        return response()->json(['data' => $this->serialize($bank)], 201);
    }

    public function update(Request $request, TenantBankAccount $bankAccount)
    {
        $validated = $this->validateInput($request, partial: true);

        $bankAccount->update($validated);

        return response()->json(['data' => $this->serialize($bankAccount->fresh())]);
    }

    public function destroy(TenantBankAccount $bankAccount)
    {
        $bankAccount->delete();

        return response()->noContent();
    }

    private function validateInput(Request $request, bool $partial = false): array
    {
        $rules = [
            'label'          => 'required|string|max:255',
            'account_name'   => 'nullable|string|max:255',
            'account_no'     => 'nullable|string|max:100',
            'branch_name'    => 'nullable|string|max:255',
            'branch_address' => 'nullable|string|max:500',
            'branch_no'      => 'nullable|string|max:50',
            'swift_code'     => 'nullable|string|max:50',
            'sort_order'     => 'nullable|integer|min:0|max:9999',
        ];

        if ($partial) {
            $rules['label'] = 'sometimes|required|string|max:255';
            $rules = array_map(fn ($r) => str_starts_with($r, 'sometimes|') ? $r : 'sometimes|'.$r, $rules);
        }

        return $request->validate($rules);
    }

    private function serialize(TenantBankAccount $bank): array
    {
        return [
            'id'             => $bank->id,
            'label'          => $bank->label,
            'account_name'   => $bank->account_name,
            'account_no'     => $bank->account_no,
            'branch_name'    => $bank->branch_name,
            'branch_address' => $bank->branch_address,
            'branch_no'      => $bank->branch_no,
            'swift_code'     => $bank->swift_code,
            'sort_order'     => (int) $bank->sort_order,
        ];
    }
}
