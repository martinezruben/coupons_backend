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
        'key' => 'catalog',
        'icon' => 'book-open',
        'label' => 'Catálogo',
        'screens' => ['families', 'subfamilies', 'promotions', 'products'],
    ],
    [
        'key' => 'ops',
        'icon' => 'briefcase',
        'label' => 'Operación',
        'screens' => ['android-users', 'shifts', 'transactions', 'payment-methods', 'ncf-sequences'],
    ],
    [
        'key' => 'sync',
        'icon' => 'arrows-right-left',
        'label' => 'Sincronización',
        'screens' => ['sync-states', 'sync-logs', 'api-request-logs'],
    ],
    [
        'key' => 'system',
        'icon' => 'wrench-screwdriver',
        'label' => 'Sistema',
        'screens' => ['admin-users', 'roles', 'audit-log'],
    ],
    [
        'key' => 'reports',
        'icon' => 'chart-bar',
        'label' => 'Reportes',
        'screens' => [
            'products-best-sellers',
            'products-margin',
            'products-no-movement',
            'payment-methods-report',
            'users-performance-report',
            'ncf-report',
            'transactions-report',
            'cierre-caja',
        ],
    ],
];
