<?php

/**
 * Grupos de la navegación lateral. Cada pantalla con CRUD en routes/web.php (`admin.screens.*`) debe
 * aparecer aquí, salvo vistas de solo detalle (`exclude_from_nav` en config/admin_screens.php).
 * `system` recibe además, según permisos, «Permisos por rol», «Parámetros del sistema» y «Pulse».
 */
return [
    [
        'key' => 'customers',
        'icon' => 'user-group',
        'label' => 'Clientes',
        'screens' => ['customers'],
    ],
    [
        'key' => 'coupons',
        'icon' => 'gift',
        'label' => 'Cupones',
        'screens' => ['coupon-batches', 'coupon-activations', 'coupons', 'coupon-barcodes', 'coupon-transactions', 'topup-rules'],
    ],
    [
        'key' => 'reports',
        'icon' => 'chart-bar',
        'label' => 'Reportes',
        'screens' => ['coupon-reports', 'coupon-audit-logs'],
    ],
    [
        'key' => 'infra',
        'icon' => 'building-office-2',
        'label' => 'Infraestructura',
        'screens' => ['locations', 'devices', 'licenses'],
    ],
    [
        'key' => 'system',
        'icon' => 'wrench-screwdriver',
        'label' => 'Configuración',
        'screens' => ['android-users', 'admin-users', 'roles', 'audit-log', 'api-request-logs'],
    ],
];
