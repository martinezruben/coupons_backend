<?php

/**
 * Catálogo del sync. Los métodos de pago ya NO viven aquí: se gestionan
 * desde el panel (Operación → Métodos de pago, tabla payment_methods)
 * y viajan por GET /api/sync/pull → data.paymentMethods.
 */
return [
    'license_plan_default' => env('SYNC_LICENSE_PLAN_DEFAULT', 'standard'),

    'payment_methods' => [
        // Deprecated: gestionados en BD (PaymentMethod). IDs legacy iniciales:
        // pm-cash (Efectivo/CASH), pm-card (Tarjeta/CARD),
        // pm-transfer (Transferencia/TRANSFER), pm-other (Otro/OTHER).
    ],
];
