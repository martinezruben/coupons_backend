<?php

return [
    /*
    | Roles de backend considerados “super” (p. ej. para políticas o UI).
    | El acceso real a cada pantalla CRUD lo define Spatie: `{recurso}.view|edit|delete`.
    */
    /*
    | Verificación en dos pasos (TOTP) obligatoria para todos los administradores: quien no la tiene
    | activa solo puede abrir la pantalla para activarla. En pruebas se apaga con ADMIN_MFA_REQUIRED=false.
    */
    'mfa_required' => (bool) env('ADMIN_MFA_REQUIRED', true),

    /*
    | Registro de dispositivos nuevos: cuántos administradores con permiso de registro (y su autenticador)
    | deben aprobar cada alta.
    */
    'device_registration_approvals' => (int) env('ADMIN_DEVICE_APPROVALS', 2),

    'super_admin_roles' => [
        'super-admin',
    ],

    /*
    | Correo del administrador que la migración `assign_super_admin_role` debe dejar como super-admin.
    | Por defecto, la cuenta del propietario; si ese correo no existe no se asigna a nadie más.
    */
    'bootstrap_super_admin_email' => env('ADMIN_BOOTSTRAP_EMAIL', 'admin@coupify.noxutech.com'),
];
