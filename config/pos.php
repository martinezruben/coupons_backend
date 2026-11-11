<?php

/**
 * Configuración del POS / registro de dispositivos Android.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Localidad por defecto al registrar un dispositivo con código de pareja
    |--------------------------------------------------------------------------
    |
    | UUID de `locations.id`. Si es null, se usa la primera localidad activa
    | ordenada por nombre.
    |
    */
    'default_registration_location_id' => env('POS_DEFAULT_REGISTRATION_LOCATION_ID'),

    /*
    |--------------------------------------------------------------------------
    | Vigencia de licencia creada en el registro inicial (pareja)
    |--------------------------------------------------------------------------
    */
    'new_license_validity_days' => (int) env('POS_NEW_LICENSE_VALIDITY_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | ¿`transaction_items.line_total` incluye el impuesto de la línea?
    |--------------------------------------------------------------------------
    |
    | Define el ingreso neto para el margen: true = ingreso neto = line_total - tax
    | (precios con impuesto incluido); false = ingreso neto = line_total.
    |
    */
    'line_total_includes_tax' => (bool) env('POS_LINE_TOTAL_INCLUDES_TAX', true),

    /*
    |--------------------------------------------------------------------------
    | NCF (Número de Comprobante Fiscal) - Configuración RD/EC
    |--------------------------------------------------------------------------
    | ncf.enabled   → toggle global del módulo NCF (true/false)
    | ncf.country   → EC (01/04/05/07) | DO (E31/E32/E33/E34)
    | ncf.mode      → global | by_location
    | ncf.start     → contador inicio rango
    | ncf.end       → contador fin rango
    | ncf.low_threshold → notificar cuando quedan N números
    */
    'ncf' => [
        'enabled' => (bool) env('POS_NCF_ENABLED', true),
        'country' => env('POS_NCF_COUNTRY', 'EC'),
        'mode' => env('POS_NCF_MODE', 'by_location'),
        'start' => (int) env('POS_NCF_START', 1),
        'end' => (int) env('POS_NCF_END', 999999999),
        'low_threshold' => (int) env('POS_NCF_LOW_THRESHOLD', 100),
    ],

];
