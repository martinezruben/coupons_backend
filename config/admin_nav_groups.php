<?php

/**
 * Grupos del sidebar (estilo Snow: secciones colapsables con ítems indentados).
 *
 * Cada pantalla con CRUD en routes/web.php (`admin.screens.*`) debe aparecer aquí,
 * salvo vistas solo-detalle (p. ej. líneas/pagos de transacción): esas llevan
 * `exclude_from_nav` en config/admin_screens.php y no se listan.
 */
return [
    [
        'key' => 'infra',
        'icon' => 'building-office-2',
        'label' => 'Infraestructura',
        'screens' => ['locations', 'devices', 'licenses'],
    ],
    [
        'key' => 'coupons',
        'icon' => 'gift',
        'label' => 'Cupones',
        'screens' => ['coupons', 'coupon-barcodes', 'coupon-transactions', 'coupon-reports', 'topup-rules', 'coupon-audit-logs'],
    ],
    [
        'key' => 'ops',
        'icon' => 'briefcase',
        'label' => 'Operación',
        'screens' => ['android-users'],
    ],
    [
        'key' => 'logs',
        'icon' => 'arrows-right-left',
        'label' => 'Bitácora',
        'screens' => ['api-request-logs'],
    ],
    [
        'key' => 'system',
        'icon' => 'wrench-screwdriver',
        'label' => 'Sistema',
        'screens' => ['admin-users', 'roles', 'audit-log'],
    ],
];
