<?php

return [
    // Si es true, POST /transaction y /transactioncb exigen el header Idempotency-Key.
    // Dejar en false hasta que la app Android lo envíe (si lo envía, se aplica igual).
    'require_idempotency_key' => (bool) env('COUPONS_REQUIRE_IDEMPOTENCY_KEY', false),

    // Los cupones QR exigen su token en cada cobro (la app ya envía "tokens").
    'require_qr_token' => (bool) env('COUPONS_REQUIRE_QR_TOKEN', true),

    // Si es true, las operaciones de cupones exigen la cabecera Device-Fingerprint de un
    // dispositivo registrado, habilitado y con licencia activa. false = compatible con la app actual.
    'require_device_binding' => (bool) env('COUPONS_REQUIRE_DEVICE_BINDING', false),

    'max_coupons_per_transaction' => (int) env('COUPONS_MAX_PER_TRANSACTION', 20),

    'max_amount' => env('COUPONS_MAX_AMOUNT', '100000.00'),

    // Saldo máximo que puede tener un cupón tras una recarga.
    'max_balance' => env('COUPONS_MAX_BALANCE', '100000.00'),

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
    'idempotency_ttl_hours' => 48,

    // Habilidades del token según el rol del usuario de la app.
    'role_abilities' => [
        'CASHIER' => ['coupon:read', 'coupon:redeem'],
        // Recargar (coupon:topup) crea saldo: solo MANAGER y ADMIN; ajustable por rol aquí.
        'MANAGER' => ['coupon:read', 'coupon:redeem', 'coupon:topup', 'coupon:reverse'],
        'ADMIN' => ['coupon:read', 'coupon:redeem', 'coupon:topup', 'coupon:reverse', 'coupon:restore'],
    ],
];
