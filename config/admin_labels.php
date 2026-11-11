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
        'external_id' => 'ID externo',
        'total' => 'Total',
        'synced_at' => 'Sincronizada el',
        'occurred_at' => 'Fecha / hora',
        'shift_number' => 'N.º de turno',
        'start_time' => 'Inicio',
        'end_time' => 'Fin',
        'opening_balance' => 'Fondo inicial',
        'closing_balance' => 'Efectivo declarado',
        'product_name' => 'Producto',
        'product_sku' => 'SKU',
        'qty' => 'Cantidad',
        'unit_price' => 'Precio unitario',
        'discount' => 'Descuento',
        'tax' => 'Impuesto',
        'line_total' => 'Total línea',
        'payment_method' => 'Método de pago',
        'amount' => 'Monto',
        'reference' => 'Referencia',
        'last_sync_at' => 'Última sincronización',
        'last_pull_at' => 'Última descarga',
        'last_push_at' => 'Último envío',
        'last_success_at' => 'Último éxito',
        'last_error_at' => 'Último error',
        'last_error_message' => 'Mensaje del último error',
        'operation' => 'Operación',
        'entity' => 'Entidad',
        'records_count' => 'Registros',
        'started_at' => 'Inicio',
        'finished_at' => 'Fin',
        'error_message' => 'Mensaje de error',
        'action' => 'Acción',
        'entity_type' => 'Tipo de entidad',
        'entity_id' => 'ID de la entidad',
        'starts_at' => 'Desde',
        'ends_at' => 'Hasta',
        'buy_qty' => 'Compra (cantidad)',
        'pay_qty' => 'Paga (cantidad)',
        'created_at' => 'Creado',
        'updated_at' => 'Actualizado',
    ],

    'values' => [
        'status' => [
            'PAID' => 'Cobrada',
            'PENDING' => 'Pendiente',
            'VOIDED' => 'Anulada',
            'COMPLETED' => 'Completada',
            'SUCCESS' => 'Correcta',
            'FAILED' => 'Fallida',
            'ACTIVE' => 'Activa',
            'INACTIVE' => 'Inactiva',
            'EXPIRED' => 'Vencida',
            'REVOKED' => 'Revocada',
        ],
        'payment_method' => [
            'CASH' => 'Efectivo',
            'CARD' => 'Tarjeta',
            'TRANSFER' => 'Transferencia',
            'OTHER' => 'Otro',
        ],
        'role' => [
            'CASHIER' => 'Cajero',
            'MANAGER' => 'Gerente',
            'ADMIN' => 'Administrador',
        ],
        'operation' => [
            'PUSH' => 'Envío',
            'PULL' => 'Descarga',
        ],
    ],

];
