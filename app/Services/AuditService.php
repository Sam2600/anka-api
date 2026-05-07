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
        ?string $tenantId = null,
        string $level = 'info',
    ): void {
        $currentUser = auth()->user();

        AuditLog::create([
            'user_id'     => $userId ?? ($currentUser?->id),
            'tenant_id'   => $tenantId ?? ($currentUser?->tenant_id),
            'action'      => $action,
            'level'       => $level,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'details'     => $details,
            'ip_address'  => Request::ip(),
        ]);
    }

    public static function logError(
        string $action,
        ?string $details = null,
        ?string $tenantId = null,
    ): void {
        self::log($action, null, null, $details, null, $tenantId, 'error');
    }

    public static function logWarning(
        string $action,
        ?string $details = null,
        ?string $tenantId = null,
    ): void {
        self::log($action, null, null, $details, null, $tenantId, 'warning');
    }

    public static function logCritical(
        string $action,
        ?string $details = null,
        ?string $tenantId = null,
    ): void {
        self::log($action, null, null, $details, null, $tenantId, 'critical');
    }
}
