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

];
