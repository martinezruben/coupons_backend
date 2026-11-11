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

    // Vida del token de la app (minutos). 720 = 12 h.
    'token_ttl_minutes' => (int) env('COUPONS_TOKEN_TTL_MINUTES', 720),

    // Horas de vida de la clave de idempotencia.
    'idempotency_ttl_hours' => 48,

    // Habilidades del token según el rol del usuario de la app.
    'role_abilities' => [
        'CASHIER' => ['coupon:read', 'coupon:redeem'],
        'MANAGER' => ['coupon:read', 'coupon:redeem', 'coupon:reverse'],
        'ADMIN' => ['coupon:read', 'coupon:redeem', 'coupon:reverse', 'coupon:restore'],
    ],
];
