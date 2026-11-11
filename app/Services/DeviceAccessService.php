<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Device;

/**
 * Bloqueo y reactivación de dispositivos con motivo y rastro de auditoría.
 *
 * Bloquear deshabilita el equipo y revoca sus tokens de Sanctum (cierre de sesión remoto):
 * la tablet lo notará en su próxima llamada al servidor; no hay empuje hacia el equipo.
 */
class DeviceAccessService
{
    public function block(Device $device, string $reason, ?AdminUser $admin = null): int
    {
        $admin ??= auth('admin')->user();

        $device->forceFill([
            'is_enabled' => false,
            'disabled_reason' => $reason,
            'disabled_at' => now(),
            'disabled_by' => $admin?->name,
        ])->saveQuietly();

        $revoked = $device->tokens()->delete();

        AdminAuditLog::record('blocked', 'Device', (string) $device->getKey(), [
            'is_enabled' => [true, false],
            'disabled_reason' => [null, $reason],
            'tokens_revoked' => [null, $revoked],
        ], $admin);

        return $revoked;
    }

    public function unblock(Device $device, string $reason, ?AdminUser $admin = null): void
    {
        $admin ??= auth('admin')->user();
        $previousReason = $device->disabled_reason;

        $device->forceFill([
            'is_enabled' => true,
            'disabled_reason' => null,
            'disabled_at' => null,
            'disabled_by' => null,
        ])->saveQuietly();

        AdminAuditLog::record('unblocked', 'Device', (string) $device->getKey(), [
            'is_enabled' => [false, true],
            'disabled_reason' => [$previousReason, null],
            'unblock_reason' => [null, $reason],
        ], $admin);
    }
}
