<?php

/**
 * Definición de roles RBAC (demo / documentación).
 *
 * Cada pantalla CRUD tiene permisos: `{recurso}.view`, `{recurso}.edit`, `{recurso}.delete`
 * (`recurso` = clave de pantalla con `-` → `_`, p. ej. `transaction-items` → `transaction_items`).
 *
 * - super-admin: todos los permisos (generado en seeder).
 * - ops-manager: operación POS + sync; lectura/escritura/borrado en esas pantallas.
 * - ops-viewer: solo lectura (`view`) en las mismas pantallas que ops-manager.
 */
return [
    /**
     * Pantallas “operativas” (excluye usuarios backend, roles y permisos).
     *
     * @var list<string>
     */
    'ops_screen_keys' => [
        'locations',
        'devices',
        'licenses',
        'android-users',
        'families',
        'subfamilies',
        'products',
        'shifts',
        'transactions',
        'transaction-items',
        'transaction-payments',
        'sync-states',
        'sync-logs',
        'api-request-logs',
    ],

];
