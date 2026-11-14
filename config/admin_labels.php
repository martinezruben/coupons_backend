<?php

/**
 * Textos en español del panel compartidos por todas las pantallas.
 *
 * - `fields`: etiqueta por nombre de columna cuando la pantalla no define una
 *   en `admin_screens.{pantalla}.labels` (encabezados, formularios y mensajes
 *   de validación).
 * - `values`: texto visible de valores enumerados guardados en inglés
 *   (badges del grid y opciones de filtros). La clave es el campo.
 */
return [

    'fields' => [
        'name' => 'Nombre',
        'full_name' => 'Nombre completo',
        'address' => 'Dirección',
        'description' => 'Descripción',
        'latitude' => 'Latitud',
        'longitude' => 'Longitud',
        'is_active' => 'Activo',
        'is_enabled' => 'Habilitado',
        'is_favorite' => 'Favorito',
        'is_synced' => 'Sincronizada',
        'status' => 'Estado',
        'type' => 'Tipo',
        'role' => 'Rol',
        'email' => 'Correo electrónico',
        'username' => 'Usuario',
        'password' => 'Contraseña',
        'sku' => 'SKU',
        'barcode' => 'Código de barras',
        'price' => 'Precio',
        'tax_rate' => 'IVA (%)',
        'value' => 'Valor',
        'image_url' => 'Imagen',
        'device_fingerprint' => 'Huella del dispositivo',
        'license_key' => 'Clave de licencia',
        'valid_from' => 'Válida desde',
        'valid_to' => 'Válida hasta',
        'last_sync_at' => 'Última sincronización',
        'action' => 'Acción',
        'entity_type' => 'Tipo de entidad',
        'entity_id' => 'ID de la entidad',
        'created_at' => 'Creado',
        'updated_at' => 'Actualizado',
    ],

    'values' => [
        'coupon_type' => [
            'QRCODE' => 'QR',
            'CODBAR' => 'Barras',
        ],
        'mode' => [
            'ASSIGN' => 'Asignación',
            'NOW' => 'Activación inmediata',
            'SCHEDULED' => 'Activación programada',
        ],
        'kind' => [
            'REDEEM' => 'Cobro',
            'TOPUP' => 'Recarga',
            'REVERSAL' => 'Reversa',
        ],
        'status' => [
            'GENERATING' => 'Generando',
            'READY' => 'Listo',
            'FAILED' => 'Fallido',
            'PENDING' => 'Programada',
            'APPLIED' => 'Aplicada',
            'CANCELLED' => 'Cancelada',
            'GENERATED' => 'Generado',
            'ASSIGNED' => 'Asignado',
            'VOID' => 'Anulado',
            'ACTIVE' => 'Activa',
            'INACTIVE' => 'Inactiva',
            'EXPIRED' => 'Vencida',
            'REVOKED' => 'Revocada',
        ],
        'role' => [
            'CASHIER' => 'Cajero',
            'MANAGER' => 'Gerente',
            'ADMIN' => 'Administrador',
        ],
    ],

];
