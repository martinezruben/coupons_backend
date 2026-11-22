<?php

// Estos valores son los de partida: los de cupones también se editan en el panel (Parámetros del sistema → Cupones)
// y lo guardado allí tiene prioridad sobre .env.
return [
    // Si es true, POST /transaction, /transactioncb y las recargas exigen el header Idempotency-Key (la app ya lo envía).
    'require_idempotency_key' => (bool) env('COUPONS_REQUIRE_IDEMPOTENCY_KEY', true),

    // Los cupones QR exigen su token en cada cobro (la app ya envía "tokens").
    'require_qr_token' => (bool) env('COUPONS_REQUIRE_QR_TOKEN', true),

    // Si es true, las operaciones de cupones exigen la cabecera Device-Fingerprint de un
    // dispositivo registrado, habilitado y con licencia activa, y el usuario debe pertenecer a su localidad.
    'require_device_binding' => (bool) env('COUPONS_REQUIRE_DEVICE_BINDING', true),

    // Vínculo real con el terminal: si es true, toda petición de cupones debe traer `Device-Token` (el token privado del
    // terminal, no copiable como la huella) y la sesión del usuario debe haberse iniciado en ese mismo terminal.
    // La app (desde su versión 1.1) ya lo envía; las sesiones iniciadas antes deben iniciar sesión de nuevo.
    'require_device_token' => (bool) env('COUPONS_REQUIRE_DEVICE_TOKEN', true),

    // Si es true, un cobro desde una localidad con surtidores debe indicar `dispenser` (1..N).
    'require_dispenser' => (bool) env('COUPONS_REQUIRE_DISPENSER', true),

    // Si es true y hay una versión mínima en Parámetros del sistema, la API responde 426 a las apps más viejas.
    'enforce_min_app_version' => (bool) env('COUPONS_ENFORCE_MIN_APP_VERSION', true),

    'max_coupons_per_transaction' => (int) env('COUPONS_MAX_PER_TRANSACTION', 20),

    'max_amount' => env('COUPONS_MAX_AMOUNT', '100000.00'),

    // Saldo máximo que puede tener un cupón tras una recarga.
    'max_balance' => env('COUPONS_MAX_BALANCE', '100000.00'),

    // Intentos de inicio de sesión fallidos antes de bloquear la cuenta del usuario de la app, y minutos de bloqueo.
    'login_max_attempts' => (int) env('COUPONS_LOGIN_MAX_ATTEMPTS', 5),
    'login_lockout_minutes' => (int) env('COUPONS_LOGIN_LOCKOUT_MINUTES', 15),

    // Vida del token de la app (minutos). 720 = 12 h.
    'token_ttl_minutes' => (int) env('COUPONS_TOKEN_TTL_MINUTES', 720),

    // El archivo CSV de un lote (único lugar con los tokens en claro) se borra tras su primera
    // descarga o al pasar estas horas, lo que ocurra primero.
    'export_ttl_hours' => (int) env('COUPONS_EXPORT_TTL_HOURS', 24),

    // Quien genera un lote no puede asignarlo ni activarlo (separación de funciones).
    'separation_of_duties' => (bool) env('COUPONS_SEPARATION_OF_DUTIES', true),

    // Máximo de cupones por orden de activación.
    'max_activation_size' => 20000,

    // Horas de vida de la clave de idempotencia.
    'idempotency_ttl_hours' => (int) env('COUPONS_IDEMPOTENCY_TTL_HOURS', 48),

    // Límites de velocidad de cobros (0 = sin límite). Se cuentan por día natural del servidor.
    'limits' => [
        'per_coupon_daily_count' => (int) env('COUPONS_LIMIT_PER_COUPON_DAILY_COUNT', 10),
        'per_user_daily_amount' => env('COUPONS_LIMIT_PER_USER_DAILY_AMOUNT', '0'),
        'per_location_daily_amount' => env('COUPONS_LIMIT_PER_LOCATION_DAILY_AMOUNT', '0'),
        // Un cobro por encima de este monto exige la habilidad coupon:redeem_large (supervisor).
        'large_redeem_threshold' => env('COUPONS_LIMIT_LARGE_REDEEM', '0'),
    ],

    // Separación de funciones.
    'self_reverse_allowed' => (bool) env('COUPONS_SELF_REVERSE_ALLOWED', false),
    // Pasadas estas horas, solo un ADMIN puede revertir un cobro.
    'reverse_window_hours' => (int) env('COUPONS_REVERSE_WINDOW_HOURS', 72),
    // Fijar el saldo crea una solicitud que debe aprobar otro administrador.
    'restore_requires_approval' => (bool) env('COUPONS_RESTORE_REQUIRES_APPROVAL', true),

    // Reemplazar un cupón perdido crea una solicitud que debe aprobar otro administrador.
    'replace_requires_approval' => (bool) env('COUPONS_REPLACE_REQUIRES_APPROVAL', true),

    // Alertas de seguridad.
    'alerts' => [
        'enabled' => (bool) env('COUPONS_ALERTS_ENABLED', true),
        // Horario normal de cobros «HH-HH» (24 h); fuera de él se alerta. Vacío = no se evalúa.
        'business_hours' => env('COUPONS_ALERT_BUSINESS_HOURS', ''),
        'multi_location_minutes' => (int) env('COUPONS_ALERT_MULTI_LOCATION_MINUTES', 10),
        'failed_lookups' => (int) env('COUPONS_ALERT_FAILED_LOOKUPS', 10),
        'reversals_per_hour' => (int) env('COUPONS_ALERT_REVERSALS_PER_HOUR', 3),
        'email_severities' => ['high'],
    ],

    // Habilidades del token según el rol del usuario de la app.
    'role_abilities' => [
        'CASHIER' => ['coupon:read', 'coupon:redeem'],
        // Recargar (coupon:topup) crea saldo: solo MANAGER y ADMIN; ajustable por rol aquí.
        'MANAGER' => ['coupon:read', 'coupon:redeem', 'coupon:redeem_large', 'coupon:topup', 'coupon:reverse'],
        'ADMIN' => ['coupon:read', 'coupon:redeem', 'coupon:redeem_large', 'coupon:topup', 'coupon:reverse', 'coupon:restore'],
    ],
];
