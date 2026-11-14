<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/** Textos en español de la bitácora: movimientos, módulos y resumen de cada clic o cambio. */
class AuditLabels
{
    public const ACTIONS = [
        'view' => 'Consulta',
        'submit' => 'Acción',
        'created' => 'Creación',
        'updated' => 'Edición',
        'deleted' => 'Eliminación',
        'login' => 'Inicio de sesión',
        'logout' => 'Cierre de sesión',
        'blocked' => 'Bloqueo',
        'unblocked' => 'Reactivación',
        'sessions_revoked' => 'Sesiones cerradas',
        'two_factor_reset' => 'Reinicio de 2FA',
    ];

    public const ENTITIES = [
        'Panel' => 'Navegación del panel',
        'Location' => 'Localidad', 'Device' => 'Dispositivo', 'License' => 'Licencia', 'User' => 'Usuario de la app',
        'AdminUser' => 'Administrador', 'Role' => 'Rol', 'SystemParameter' => 'Parámetro del sistema', 'PairingToken' => 'Token de emparejamiento',
        'CouponBatch' => 'Lote de cupones', 'Customer' => 'Cliente', 'TopUpRule' => 'Regla de recarga', 'CouponPrefix' => 'Prefijo de cupones',
        'ActivationOrder' => 'Activación', 'Coupon' => 'Cupón QR', 'BarcodeCoupon' => 'Cupón de barras', 'RoleLimit' => 'Límites del rol',
    ];

    /** Rutas con un texto propio; el resto se describe por verbo + pantalla. */
    private const ROUTES = [
        'admin.dashboard' => 'Abrió el inicio',
        'admin.coupon-batches.create' => 'Abrió el formulario para generar un lote',
        'admin.coupon-batches.store' => 'Generó un lote de cupones',
        'admin.coupon-batches.show' => 'Abrió el detalle de un lote',
        'admin.coupon-batches.activate.form' => 'Abrió el formulario de activación',
        'admin.coupon-batches.activate' => 'Asignó o activó cupones de un lote',
        'admin.coupon-batches.download' => 'Descargó el CSV de un lote',
        'admin.coupon-batches.unassign' => 'Liberó cupones asignados',
        'admin.coupon-batches.void' => 'Anuló cupones de un lote',
        'admin.activation-orders.cancel' => 'Canceló una activación programada',
        'admin.coupons.toggle' => 'Activó o desactivó un cupón',
        'admin.coupons.adjust' => 'Ajustó el saldo de un cupón',
        'admin.coupons.topup' => 'Recargó un cupón',
        'admin.coupon-transactions.reverse' => 'Reversó un cobro',
        'admin.reports.batches' => 'Consultó el reporte por lote',
        'admin.reports.customers' => 'Consultó el reporte por cliente',
        'admin.reports.redemptions' => 'Consultó el reporte de canjes',
        'admin.audit.index' => 'Consultó la auditoría',
        'admin.audit.show' => 'Abrió el detalle de un registro de auditoría',
        'admin.audit-logs.show' => 'Abrió el detalle de un registro de auditoría',
        'admin.rbac.matrix.index' => 'Abrió la matriz de permisos',
        'admin.rbac.matrix.edit' => 'Abrió los permisos de un rol',
        'admin.rbac.matrix.update' => 'Guardó permisos y límites de un rol',
        'admin.rbac.accounts.add' => 'Añadió una cuenta a un rol',
        'admin.rbac.accounts.remove' => 'Quitó una cuenta de un rol',
        'admin.roles.index' => 'Abrió la lista de roles',
        'admin.roles.store' => 'Creó un rol',
        'admin.roles.update' => 'Renombró un rol',
        'admin.roles.destroy' => 'Eliminó un rol',
        'admin.admin-users.reset-2fa' => 'Reinició el 2FA de un administrador',
        'admin.admin-users.revoke-sessions' => 'Cerró las sesiones de un administrador',
        'admin.devices.block' => 'Bloqueó un dispositivo',
        'admin.devices.unblock' => 'Reactivó un dispositivo',
        'admin.system-settings.edit' => 'Abrió los parámetros del sistema',
        'admin.system-settings.update' => 'Guardó los parámetros del sistema',
        'admin.2fa.show' => 'Abrió la verificación en dos pasos',
        'admin.2fa.enable' => 'Activó la verificación en dos pasos',
        'admin.2fa.disable' => 'Desactivó la verificación en dos pasos',
        'admin.mail.test' => 'Envió un correo de prueba',
    ];

    /** Rutas de sondeo automático: no son un movimiento de la persona. */
    public const SKIP_ROUTES = ['admin.locations.map-status', 'admin.logout'];

    private const SENSITIVE_KEY = '/pass|token|secret|pin|totp|otp|authorization|^_method$|credential/i';

    public static function action(?string $action): string
    {
        return self::ACTIONS[$action] ?? ($action ?? '—');
    }

    public static function entity(?string $type): string
    {
        return self::ENTITIES[$type] ?? ($type ?? '—');
    }

    /** Qué hizo la persona, en una frase. */
    public static function describeRequest(Request $request): string
    {
        $route = (string) $request->route()?->getName();
        if (isset(self::ROUTES[$route])) {
            return self::ROUTES[$route];
        }

        $screen = $request->route('screen');
        $label = $screen ? (config("admin_screens.$screen.label") ?? $screen) : null;

        return match ($route) {
            'admin.screens.index' => 'Abrió la lista: '.($label ?? '—').($request->query() ? ' (con filtros o búsqueda)' : ''),
            'admin.screens.create' => 'Abrió el formulario de alta: '.$label,
            'admin.screens.edit' => 'Abrió la edición de un registro: '.$label,
            'admin.screens.store' => 'Creó un registro: '.$label,
            'admin.screens.update' => 'Guardó cambios de un registro: '.$label,
            'admin.screens.destroy' => 'Eliminó un registro: '.$label,
            'admin.screens.toggle-status' => 'Cambió el estado de un registro: '.$label,
            default => ($request->isMethod('GET') ? 'Consultó ' : 'Ejecutó ').($route ?: $request->path()),
        };
    }

    /** Resumen de un cambio de datos (alta, edición, baja). */
    public static function describeChange(string $action, string $entityType): string
    {
        $entity = self::entity($entityType);

        return match ($action) {
            'created' => "Creó: {$entity}",
            'updated' => "Editó: {$entity}",
            'deleted' => "Eliminó: {$entity}",
            default => self::action($action).": {$entity}",
        };
    }

    /** Datos enviados sin secretos: nunca contraseñas, tokens, PIN ni códigos de verificación. */
    public static function sanitize(array $input, int $depth = 0): array
    {
        $out = [];
        foreach (array_slice($input, 0, 60, true) as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key)) {
                $out[$key] = '[oculto]';
            } elseif (is_array($value)) {
                $out[$key] = $depth >= 2 ? '[…]' : self::sanitize($value, $depth + 1);
            } elseif ($value instanceof UploadedFile) {
                $out[$key] = '[archivo: '.$value->getClientOriginalName().']';
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = is_string($value) && mb_strlen($value) > 300 ? mb_substr($value, 0, 300).'… ('.mb_strlen($value).' caracteres)' : $value;
            }
        }

        return $out;
    }
}
