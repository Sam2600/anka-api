<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Request;

class AuditService
{
    public static function log(
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        ?string $details = null,
        ?string $userId = null,
    ): void {
        $currentUser = auth()->user();

        AuditLog::create([
            'user_id'     => $userId ?? ($currentUser?->id),
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'details'     => $details,
            'ip_address'  => Request::ip(),
        ]);
    }
}
