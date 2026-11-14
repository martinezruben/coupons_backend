<?php

return [
    /*
    | Roles de backend considerados “super” (p. ej. para políticas o UI).
    | El acceso real a cada pantalla CRUD lo define Spatie: `{recurso}.view|edit|delete`.
    */
    'super_admin_roles' => [
        'super-admin',
    ],

    /*
    | Correo del administrador que la migración `assign_super_admin_role` debe dejar como super-admin.
    | Por defecto, la cuenta del propietario; si ese correo no existe no se asigna a nadie más.
    */
    'bootstrap_super_admin_email' => env('ADMIN_BOOTSTRAP_EMAIL', 'admin@coupify.noxutech.com'),
];
