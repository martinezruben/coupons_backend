<?php

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\ApiRequestLog;
use App\Models\Device;
use App\Models\Family;
use App\Models\License;
use App\Models\Location;
use App\Models\NcfSequence;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Shift;
use App\Models\Subfamily;
use App\Models\SyncLog;
use App\Models\SyncState;
use App\Models\SystemParameter;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionPayment;
use App\Models\User;
use Spatie\Permission\Models\Role;

return [
    // Sin modelo: `rbac` lo expone en la matriz de permisos solo con `dashboard.view`
    'dashboard' => ['label' => 'Dashboard comercial', 'icon' => 'chart-bar', 'readonly' => true, 'rbac' => true],
    // Sin modelo: solo expone el permiso pulse.view en la matriz RBAC
    'pulse' => ['label' => 'Pulse (métricas)', 'icon' => 'bolt', 'readonly' => true, 'rbac' => true, 'exclude_from_nav' => true],
    'dashboard-technical' => ['label' => 'Dashboard técnico', 'icon' => 'wrench-screwdriver', 'readonly' => true, 'rbac' => true, 'exclude_from_nav' => true],
    'locations' => [
        'model' => Location::class,
        'label' => 'Localidades',
        'icon' => 'map-pin',
        'fields' => ['name', 'address', 'latitude', 'longitude', 'is_active', 'last_sync_at'],
        'grid' => [
            'filters' => [
                'is_active' => [
                    'label' => 'Activo',
                    'type' => 'select',
                    'options' => ['' => 'Todos', '1' => 'Sí', '0' => 'No'],
                    'apply' => ['type' => 'column', 'column' => 'is_active'],
                ],
            ],
            'sortable' => ['name', 'address', 'latitude', 'longitude', 'is_active', 'last_sync_at'],
            'default_sort' => ['key' => 'name', 'direction' => 'asc'],
        ],
    ],
    'devices' => [
        'model' => Device::class,
        'label' => 'Dispositivos',
        'icon' => 'device-phone-mobile',
        'disable_create' => true,
        'labels' => [
            'registered_at' => 'Fecha de registro',
            'app_version' => 'Versión de la app',
        ],
        'fields' => ['location_id', 'device_fingerprint', 'name', 'is_enabled', 'app_version', 'registered_at', 'last_sync_at'],
        'foreign_labels' => [
            'location_id' => ['relation' => 'location', 'attribute' => 'name', 'header' => 'Localidad'],
        ],
        'grid' => [
            'filters' => [
                'location_id' => [
                    'label' => 'Localidad',
                    'type' => 'select',
                    'model' => Location::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'apply' => ['type' => 'column', 'column' => 'location_id'],
                ],
            ],
            'sortable' => ['location_id', 'device_fingerprint', 'name', 'is_enabled', 'app_version', 'registered_at', 'last_sync_at'],
            'default_sort' => ['key' => 'updated_at', 'direction' => 'desc'],
        ],
    ],
    'licenses' => [
        'model' => License::class,
        'label' => 'Licencias',
        'icon' => 'key',
        'labels' => [
            'id' => 'ID licencia (clave)',
            'valid_from' => 'Válida desde',
            'valid_to' => 'Caducidad (válida hasta)',
            'status' => 'Estado',
        ],
        'fields' => ['id', 'device_id', 'location_name', 'valid_from', 'valid_to', 'status'],
        'select_options' => [
            'status' => [
                'ACTIVE' => 'Activa',
                'INACTIVE' => 'Inactiva',
                'EXPIRED' => 'Vencida',
                'REVOKED' => 'Revocada',
            ],
        ],
        'foreign_labels' => [
            'device_id' => ['relation' => 'device', 'attribute' => 'name', 'fallback_attribute' => 'device_fingerprint', 'header' => 'Dispositivo'],
            'location_name' => [
                'virtual' => true,
                'chain' => ['device', 'location'],
                'attribute' => 'name',
                'header' => 'Localidad',
            ],
        ],
        'grid' => [
            'filters' => [
                'location_id' => [
                    'label' => 'Localidad',
                    'type' => 'select',
                    'model' => Location::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'apply' => ['type' => 'whereHas', 'relation' => 'device', 'column' => 'location_id'],
                ],
                'device_id' => [
                    'label' => 'Dispositivo',
                    'type' => 'select',
                    'model' => Device::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'fallback_column' => 'device_fingerprint',
                    'apply' => ['type' => 'column', 'column' => 'device_id'],
                ],
                'status' => [
                    'label' => 'Estado',
                    'type' => 'select',
                    'options' => ['' => 'Todos', 'ACTIVE' => 'Activa', 'EXPIRED' => 'Vencida', 'REVOKED' => 'Revocada'],
                    'apply' => ['type' => 'column', 'column' => 'status'],
                ],
            ],
            'sortable' => ['id', 'location_name', 'device_id', 'status', 'valid_from', 'valid_to'],
            'default_sort' => ['key' => 'created_at', 'direction' => 'desc'],
        ],
    ],
    'android-users' => [
        'model' => User::class,
        'label' => 'Usuarios POS (TapGo)',
        'icon' => 'users',
        'labels' => [
            'username' => 'Usuario',
            'full_name' => 'Nombre completo',
            'role' => 'Rol',
            'password' => 'Contraseña',
            'pin4' => 'PIN rápido (4 dígitos)',
            'is_active' => 'Activo',
            'location_id' => 'Localidad',
            'last_activity_at' => 'Última actividad',
            'pin_status' => 'PIN sin conexión',
        ],
        'select_options' => [
            'role' => [
                'CASHIER' => 'Cajero (CASHIER)',
                'MANAGER' => 'Gerente (MANAGER)',
                'ADMIN' => 'Administrador (ADMIN)',
            ],
        ],
        'fields' => ['username', 'full_name', 'role', 'is_active', 'location_id', 'last_activity_at', 'pin_status', 'password', 'pin4'],
        'foreign_labels' => [
            'location_id' => ['relation' => 'location', 'attribute' => 'name', 'header' => 'Localidad'],
            'last_activity_at' => ['virtual' => true, 'header' => 'Última actividad'],
            'pin_status' => ['virtual' => true, 'header' => 'PIN sin conexión'],
        ],
        'grid' => [
            'exclude_from_grid' => ['password', 'pin4'],
            'visible_limit' => 12,
            'filters' => [
                'role' => [
                    'label' => 'Rol',
                    'type' => 'select',
                    'options' => ['' => 'Todos', 'CASHIER' => 'CASHIER', 'MANAGER' => 'MANAGER', 'ADMIN' => 'ADMIN'],
                    'apply' => ['type' => 'column', 'column' => 'role'],
                ],
                'is_active' => [
                    'label' => 'Activo',
                    'type' => 'select',
                    'options' => ['' => 'Todos', '1' => 'Sí', '0' => 'No'],
                    'apply' => ['type' => 'column', 'column' => 'is_active'],
                ],
                'location_id' => [
                    'label' => 'Localidad',
                    'type' => 'select',
                    'model' => Location::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'apply' => ['type' => 'column', 'column' => 'location_id'],
                ],
                'pin' => [
                    'label' => 'PIN sin conexión',
                    'type' => 'select',
                    'options' => ['' => 'Todos', '1' => 'Sin PIN válido'],
                    'apply' => ['type' => 'scope', 'scope' => 'withoutOfflinePin'],
                ],
            ],
            'sortable' => ['username', 'full_name', 'role', 'is_active', 'location_id', 'last_activity_at'],
            'default_sort' => ['key' => 'username', 'direction' => 'asc'],
        ],
    ],
    'families' => [
        'model' => Family::class,
        'label' => 'Familias',
        'icon' => 'squares-2x2',
        'labels' => [
            'description' => 'Descripción para el operario (POS)',
            'image_url' => 'Imagen',
        ],
        'fields' => ['name', 'description', 'image_url'],
        'foreign_labels' => [
            'image_url' => ['virtual' => true, 'header' => 'Imagen'],
        ],
        'grid' => [
            'filters' => [],
            'exclude_from_grid' => ['description'],
            'columns' => [
                ['field' => 'image_url', 'label' => 'Img', 'width' => '60px', 'render' => fn ($value, $row) => $value ? "<img src='{$value}' alt='Familia' class='w-8 h-8 object-cover rounded-full'>" : ''],
                ['field' => 'name', 'label' => 'Nombre', 'sortable' => true],
            ],
            'sortable' => ['name'],
            'default_sort' => ['key' => 'name', 'direction' => 'asc'],
            'visible_limit' => 8,
        ],
    ],
    'subfamilies' => [
        'model' => Subfamily::class,
        'label' => 'Subfamilias',
        'icon' => 'rectangle-stack',
        'fields' => ['family_id', 'name'],
        'foreign_labels' => [
            'family_id' => ['relation' => 'family', 'attribute' => 'name', 'header' => 'Familia'],
        ],
        'grid' => [
            'filters' => [
                'family_id' => [
                    'label' => 'Familia',
                    'type' => 'select',
                    'model' => Family::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'apply' => ['type' => 'column', 'column' => 'family_id'],
                ],
            ],
            'sortable' => ['family_id', 'name'],
            'default_sort' => ['key' => 'name', 'direction' => 'asc'],
        ],
    ],
    'payment-methods' => [
        'model' => PaymentMethod::class,
        'label' => 'Métodos de pago',
        'icon' => 'credit-card',
        'labels' => [
            'name' => 'Nombre (botón en el POS)',
            'type' => 'Categoría',
            'color' => 'Color del botón',
        ],
        'fields' => ['name', 'type', 'color', 'is_enabled'],
        'select_options' => [
            'type' => [
                'CASH' => 'Efectivo',
                'CARD' => 'Tarjeta',
                'TRANSFER' => 'Transferencia',
                'OTHER' => 'Otro',
            ],
        ],
        'foreign_labels' => [],
        'grid' => [
            'visible_limit' => 8,
            'filters' => [
                'type' => [
                    'label' => 'Categoría',
                    'type' => 'select',
                    'options' => ['CASH' => 'Efectivo', 'CARD' => 'Tarjeta', 'TRANSFER' => 'Transferencia', 'OTHER' => 'Otro'],
                    'apply' => ['type' => 'column', 'column' => 'type'],
                ],
                'is_enabled' => [
                    'label' => 'Estado',
                    'type' => 'select',
                    'options' => ['1' => 'Activo', '0' => 'Inactivo'],
                    'apply' => ['type' => 'column', 'column' => 'is_enabled'],
                ],
            ],
            'sortable' => ['name', 'type', 'is_enabled'],
            'default_sort' => ['key' => 'name', 'direction' => 'asc'],
        ],
    ],
    'promotions' => [
        'model' => Promotion::class,
        'label' => 'Promociones',
        'icon' => 'tag',
        'labels' => [
            'value' => 'Valor',
            'starts_at' => 'Vigente desde',
            'ends_at' => 'Vigente hasta',
        ],
        'fields' => ['name', 'type', 'value', 'buy_qty', 'pay_qty', 'product_id', 'subfamily_id', 'family_id', 'starts_at', 'ends_at', 'is_active'],
        'labels' => [
            'value' => 'Valor',
            'buy_qty' => 'Lleva (cantidad)',
            'pay_qty' => 'Paga (cantidad)',
            'starts_at' => 'Vigente desde',
            'ends_at' => 'Vigente hasta',
        ],
        'select_options' => [
            'type' => [
                'PERCENT' => '% Descuento',
                'AMOUNT' => 'Monto fijo de descuento',
                'PRICE' => 'Precio de oferta',
                'BUNDLE' => 'Lleva N paga M (2x1, 3x2…)',
            ],
        ],
        'foreign_labels' => [
            'product_id' => ['relation' => 'product', 'attribute' => 'name', 'fallback_attribute' => 'sku', 'header' => 'Producto', 'depends' => 'subfamily_id', 'parent_column' => 'subfamily_id'],
            'subfamily_id' => ['relation' => 'subfamily', 'attribute' => 'admin_label', 'header' => 'Subfamilia', 'depends' => 'family_id', 'parent_column' => 'family_id'],
            'family_id' => ['relation' => 'family', 'attribute' => 'name', 'header' => 'Familia'],
        ],
        'grid' => [
            'visible_limit' => 9,
            'exclude_from_grid' => ['description'],
            'filters' => [
                'family_id' => [
                    'label' => 'Familia',
                    'type' => 'select',
                    'model' => Family::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'apply' => ['type' => 'column', 'column' => 'family_id'],
                ],
                'subfamily_id' => [
                    'label' => 'Subfamilia',
                    'type' => 'select',
                    'model' => Subfamily::class,
                    'depends' => 'family_id',
                    'apply' => ['type' => 'column', 'column' => 'subfamily_id'],
                ],
                'product_id' => [
                    'label' => 'Producto',
                    'type' => 'select',
                    'model' => Product::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'fallback_column' => 'sku',
                    'depends' => 'subfamily_id',
                    'parent_column' => 'subfamily_id',
                    'apply' => ['type' => 'column', 'column' => 'product_id'],
                ],
                'type' => [
                    'label' => 'Tipo',
                    'type' => 'select',
                    'options' => ['PERCENT' => '% Descuento', 'AMOUNT' => 'Monto fijo', 'PRICE' => 'Precio oferta', 'BUNDLE' => 'Lleva N paga M'],
                    'apply' => ['type' => 'column', 'column' => 'type'],
                ],
                'is_active' => [
                    'label' => 'Estado',
                    'type' => 'select',
                    'options' => ['1' => 'Activa', '0' => 'Inactiva'],
                    'apply' => ['type' => 'column', 'column' => 'is_active'],
                ],
            ],
            'sortable' => ['name', 'type', 'value', 'is_active', 'starts_at', 'ends_at'],
            'default_sort' => ['key' => 'name', 'direction' => 'asc'],
        ],
    ],
    'products' => [
        'model' => Product::class,
        'label' => 'Productos',
        'icon' => 'cube',
        'labels' => [
            'sku' => 'SKU',
            'barcode' => 'Código de barras',
            'name' => 'Nombre',
            'price' => 'Precio',
            'cost' => 'Costo (opcional)',
            'tax_rate' => 'IVA (%)',
            'is_active' => 'Activo',
            'is_favorite' => 'Favorito',
            'image_url' => 'Imagen',
        ],
        'fields' => ['sku', 'barcode', 'name', 'subfamily_id', 'price', 'cost', 'tax_rate', 'is_active', 'is_favorite', 'image_url'],
        'foreign_labels' => [
            'subfamily_id' => ['relation' => 'subfamily', 'attribute' => 'admin_label', 'header' => 'Subfamilia'],
        ],
        'grid' => [
            // Columnas de importe: se muestran como $1,234.56
            'money' => ['price'],
            'filters' => [
                'subfamily_id' => [
                    'label' => 'Subfamilia',
                    'type' => 'select',
                    'model' => Subfamily::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'apply' => ['type' => 'column', 'column' => 'subfamily_id'],
                ],
                'is_active' => [
                    'label' => 'Activo',
                    'type' => 'select',
                    'options' => ['' => 'Todos', '1' => 'Sí', '0' => 'No'],
                    'apply' => ['type' => 'column', 'column' => 'is_active'],
                ],
                'is_favorite' => [
                    'label' => 'Favorito',
                    'type' => 'select',
                    'options' => ['' => 'Todos', '1' => 'Sí', '0' => 'No'],
                    'apply' => ['type' => 'column', 'column' => 'is_favorite'],
                ],
            ],
            'columns' => [
                ['field' => 'image_url', 'label' => 'Img', 'width' => '60px', 'render' => fn ($value, $row) => $value ? "<img src='{$value}' alt='Producto' class='w-8 h-8 object-cover rounded'>" : ''],
                ['field' => 'name', 'label' => 'Nombre', 'sortable' => true],
                ['field' => 'sku', 'label' => 'SKU', 'sortable' => true],
                ['field' => 'subfamily_id', 'label' => 'Subfamilia', 'sortable' => true],
                ['field' => 'price', 'label' => 'Precio', 'sortable' => true],
                ['field' => 'tax_rate', 'label' => 'IVA (%)', 'sortable' => true],
                ['field' => 'is_active', 'label' => 'Activo', 'sortable' => true, 'render' => fn ($v) => $v ? 'Sí' : 'No'],
                ['field' => 'is_favorite', 'label' => 'Favorito', 'sortable' => true, 'render' => fn ($v) => $v ? 'Sí' : 'No'],
            ],
            'default_sort' => ['key' => 'name', 'direction' => 'asc'],
            'field_order' => ['sku', 'image_url'],
            'visible_limit' => 9,
        ],
    ],
    'shifts' => [
        'model' => Shift::class,
        'label' => 'Turnos',
        'icon' => 'clock',
        'fields' => ['location_id', 'device_id', 'user_id', 'shift_number', 'start_time', 'end_time', 'opening_balance', 'closing_balance'],
        'foreign_labels' => [
            'location_id' => ['relation' => 'location', 'attribute' => 'name', 'header' => 'Localidad'],
            'device_id' => ['relation' => 'device', 'attribute' => 'name', 'fallback_attribute' => 'device_fingerprint', 'header' => 'Dispositivo'],
            'user_id' => ['relation' => 'user', 'attribute' => 'full_name', 'fallback_attribute' => 'username', 'header' => 'Usuario'],
        ],
        'grid' => [
            // Columnas de importe: se muestran como $1,234.56
            'money' => ['opening_balance', 'closing_balance'],
            'filters' => [
                'location_id' => [
                    'label' => 'Localidad',
                    'type' => 'select',
                    'model' => Location::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'apply' => ['type' => 'column', 'column' => 'location_id'],
                ],
                'device_id' => [
                    'label' => 'Dispositivo',
                    'type' => 'select',
                    'model' => Device::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'fallback_column' => 'device_fingerprint',
                    'apply' => ['type' => 'column', 'column' => 'device_id'],
                ],
                'user_id' => [
                    'label' => 'Usuario',
                    'type' => 'select',
                    'model' => User::class,
                    'order_by' => 'full_name',
                    'label_column' => 'full_name',
                    'fallback_column' => 'username',
                    'apply' => ['type' => 'column', 'column' => 'user_id'],
                ],
            ],
            'sortable' => ['location_id', 'device_id', 'user_id', 'shift_number', 'start_time', 'end_time'],
            'default_sort' => ['key' => 'start_time', 'direction' => 'desc'],
        ],
    ],
    'transactions' => [
        'model' => Transaction::class,
        'label' => 'Transacciones',
        'icon' => 'banknotes',
        'labels' => [
            'occurred_at' => 'Fecha / hora',
            'items_count' => 'Líneas',
        ],
        'fields' => ['occurred_at', 'external_id', 'location_id', 'device_id', 'shift_id', 'user_id', 'status', 'total', 'items_count', 'is_synced', 'synced_at'],
        'foreign_labels' => [
            'location_id' => ['relation' => 'location', 'attribute' => 'name', 'header' => 'Localidad'],
            'device_id' => ['relation' => 'device', 'attribute' => 'name', 'fallback_attribute' => 'device_fingerprint', 'header' => 'Dispositivo'],
            'shift_id' => ['relation' => 'shift', 'attribute' => 'shift_number', 'header' => 'Turno', 'prefix' => '#', 'fallback_to_own_column' => true],
            'user_id' => ['relation' => 'user', 'attribute' => 'full_name', 'fallback_attribute' => 'username', 'header' => 'Usuario'],
            'items_count' => ['virtual' => true, 'header' => 'Líneas'],
        ],
        'grid' => [
            // Columnas de importe: se muestran como $1,234.56
            'money' => ['total'],
            'visible_limit' => 12,
            'filters' => [
                'location_id' => [
                    'label' => 'Localidad',
                    'type' => 'select',
                    'model' => Location::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'apply' => ['type' => 'column', 'column' => 'location_id'],
                ],
                'device_id' => [
                    'label' => 'Dispositivo',
                    'type' => 'select',
                    'model' => Device::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'fallback_column' => 'device_fingerprint',
                    'apply' => ['type' => 'column', 'column' => 'device_id'],
                ],
                'status' => [
                    'label' => 'Estado',
                    'type' => 'select',
                    'options' => ['' => 'Todos', 'PENDING' => 'Pendiente', 'PAID' => 'Cobrada', 'VOIDED' => 'Anulada'],
                    'apply' => ['type' => 'column', 'column' => 'status'],
                ],
            ],
            'sortable' => ['external_id', 'location_id', 'device_id', 'shift_id', 'user_id', 'status', 'total', 'occurred_at', 'items_count'],
            'default_sort' => ['key' => 'occurred_at', 'direction' => 'desc'],
        ],
    ],
    'transaction-items' => [
        'exclude_from_nav' => true,
        'model' => TransactionItem::class,
        'label' => 'Items Transacción',
        'icon' => 'list-bullet',
        'fields' => ['transaction_id', 'product_id', 'product_name', 'product_sku', 'qty', 'unit_price', 'discount', 'tax', 'line_total'],
        'foreign_labels' => [
            'transaction_id' => ['relation' => 'transaction', 'attribute' => 'external_id', 'header' => 'Transacción'],
            'product_id' => ['relation' => 'product', 'attribute' => 'name', 'fallback_attribute' => 'sku', 'header' => 'Producto'],
        ],
        'grid' => [
            // Columnas de importe: se muestran como $1,234.56
            'money' => ['unit_price', 'discount', 'tax', 'line_total'],
            'filters' => [
                'transaction_id' => [
                    'label' => 'Transacción',
                    'type' => 'select',
                    'model' => Transaction::class,
                    'order_by' => 'occurred_at',
                    'label_column' => 'external_id',
                    'apply' => ['type' => 'column', 'column' => 'transaction_id'],
                ],
                'product_id' => [
                    'label' => 'Producto',
                    'type' => 'select',
                    'model' => Product::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'fallback_column' => 'sku',
                    'apply' => ['type' => 'column', 'column' => 'product_id'],
                ],
            ],
            'sortable' => ['transaction_id', 'product_id', 'product_name', 'qty', 'line_total'],
            'default_sort' => ['key' => 'updated_at', 'direction' => 'desc'],
        ],
    ],
    'transaction-payments' => [
        'exclude_from_nav' => true,
        'model' => TransactionPayment::class,
        'label' => 'Pagos Transacción',
        'icon' => 'wallet',
        'fields' => ['transaction_id', 'payment_method', 'amount', 'reference'],
        'foreign_labels' => [
            'transaction_id' => ['relation' => 'transaction', 'attribute' => 'external_id', 'header' => 'Transacción'],
        ],
        'grid' => [
            // Columnas de importe: se muestran como $1,234.56
            'money' => ['amount'],
            'filters' => [
                'transaction_id' => [
                    'label' => 'Transacción',
                    'type' => 'select',
                    'model' => Transaction::class,
                    'order_by' => 'occurred_at',
                    'label_column' => 'external_id',
                    'apply' => ['type' => 'column', 'column' => 'transaction_id'],
                ],
                'payment_method' => [
                    'label' => 'Método',
                    'type' => 'select',
                    'options' => ['' => 'Todos', 'CASH' => 'CASH', 'CARD' => 'CARD', 'TRANSFER' => 'TRANSFER', 'OTHER' => 'OTHER'],
                    'apply' => ['type' => 'column', 'column' => 'payment_method'],
                ],
            ],
            'sortable' => ['transaction_id', 'payment_method', 'amount'],
            'default_sort' => ['key' => 'created_at', 'direction' => 'desc'],
        ],
    ],
    'sync-states' => [
        'model' => SyncState::class,
        'label' => 'Estado de Sincronización',
        'icon' => 'arrow-path',
        'labels' => [
            'last_sync_since' => 'Última sync (hace)',
        ],
        'fields' => ['location_id', 'device_id', 'last_pull_at', 'last_push_at', 'last_success_at', 'last_sync_since', 'last_error_at', 'last_error_message'],
        'foreign_labels' => [
            'location_id' => ['relation' => 'location', 'attribute' => 'name', 'header' => 'Localidad'],
            'device_id' => ['relation' => 'device', 'attribute' => 'name', 'fallback_attribute' => 'device_fingerprint', 'header' => 'Dispositivo'],
            'last_sync_since' => ['virtual' => true, 'header' => 'Última sync (hace)'],
        ],
        'grid' => [
            'filters' => [
                'location_id' => [
                    'label' => 'Localidad',
                    'type' => 'select',
                    'model' => Location::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'apply' => ['type' => 'column', 'column' => 'location_id'],
                ],
                'device_id' => [
                    'label' => 'Dispositivo',
                    'type' => 'select',
                    'model' => Device::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'fallback_column' => 'device_fingerprint',
                    'apply' => ['type' => 'column', 'column' => 'device_id'],
                ],
            ],
            'sortable' => ['location_id', 'device_id', 'last_pull_at', 'last_push_at', 'last_success_at'],
            'default_sort' => ['key' => 'updated_at', 'direction' => 'desc'],
        ],
    ],
    'sync-logs' => [
        'model' => SyncLog::class,
        'label' => 'Logs de Sincronización',
        'icon' => 'clipboard-document-list',
        'fields' => ['location_id', 'device_id', 'operation', 'entity', 'records_count', 'status', 'started_at', 'finished_at', 'error_message'],
        'foreign_labels' => [
            'location_id' => ['relation' => 'location', 'attribute' => 'name', 'header' => 'Localidad'],
            'device_id' => ['relation' => 'device', 'attribute' => 'name', 'fallback_attribute' => 'device_fingerprint', 'header' => 'Dispositivo'],
        ],
        'grid' => [
            'filters' => [
                'location_id' => [
                    'label' => 'Localidad',
                    'type' => 'select',
                    'model' => Location::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'apply' => ['type' => 'column', 'column' => 'location_id'],
                ],
                'device_id' => [
                    'label' => 'Dispositivo',
                    'type' => 'select',
                    'model' => Device::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'fallback_column' => 'device_fingerprint',
                    'apply' => ['type' => 'column', 'column' => 'device_id'],
                ],
                'status' => [
                    'label' => 'Estado',
                    'type' => 'select',
                    'options' => ['' => 'Todos', 'SUCCESS' => 'Correcta', 'FAILED' => 'Fallida'],
                    'apply' => ['type' => 'column', 'column' => 'status'],
                ],
                'operation' => [
                    'label' => 'Operación',
                    'type' => 'select',
                    'options' => ['' => 'Todos', 'PUSH' => 'Envío', 'PULL' => 'Descarga'],
                    'apply' => ['type' => 'column', 'column' => 'operation'],
                ],
            ],
            'sortable' => ['location_id', 'device_id', 'operation', 'status', 'started_at', 'records_count'],
            'default_sort' => ['key' => 'started_at', 'direction' => 'desc'],
        ],
    ],
    'api-request-logs' => [
        'model' => ApiRequestLog::class,
        'label' => 'Llamadas API',
        'icon' => 'server-stack',
        'readonly' => true,
        'labels' => [
            'created_at' => 'Fecha/hora',
            'method' => 'Método',
            'path' => 'Ruta',
            'parameters' => 'Parámetros',
            'response_status' => 'HTTP',
            'response_summary' => 'Respuesta',
            'location_id' => 'Localidad',
            'device_id' => 'Dispositivo',
            'device_fingerprint' => 'Huella',
            'ip_address' => 'IP',
            'duration_ms' => 'ms',
        ],
        'fields' => [
            'created_at', 'method', 'path', 'parameters', 'response_status', 'response_summary', 'location_id', 'device_id', 'device_fingerprint', 'ip_address', 'duration_ms',
        ],
        'foreign_labels' => [
            'location_id' => ['relation' => 'location', 'attribute' => 'name', 'header' => 'Localidad'],
            'device_id' => ['relation' => 'device', 'attribute' => 'name', 'fallback_attribute' => 'device_fingerprint', 'header' => 'Dispositivo'],
        ],
        'grid' => [
            'filters' => [
                'location_id' => [
                    'label' => 'Localidad',
                    'type' => 'select',
                    'model' => Location::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'apply' => ['type' => 'column', 'column' => 'location_id'],
                ],
                'device_id' => [
                    'label' => 'Dispositivo',
                    'type' => 'select',
                    'model' => Device::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'fallback_column' => 'device_fingerprint',
                    'apply' => ['type' => 'column', 'column' => 'device_id'],
                ],
                'method' => [
                    'label' => 'Método',
                    'type' => 'select',
                    'options' => ['' => 'Todos', 'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE'],
                    'apply' => ['type' => 'column', 'column' => 'method'],
                ],
                'response_status' => [
                    'label' => 'HTTP',
                    'type' => 'select',
                    'options' => [
                        '' => 'Todos',
                        '200' => '200',
                        '401' => '401',
                        '403' => '403',
                        '422' => '422',
                        '429' => '429',
                        '500' => '500',
                    ],
                    'apply' => ['type' => 'column', 'column' => 'response_status'],
                ],
            ],
            'sortable' => ['created_at', 'method', 'path', 'response_status', 'duration_ms', 'location_id', 'device_id'],
            'search_columns' => ['method', 'path', 'parameters', 'response_summary', 'device_fingerprint', 'ip_address'],
            'default_sort' => ['key' => 'created_at', 'direction' => 'desc'],
        ],
    ],
    'system-settings' => [
        'model' => SystemParameter::class,
        'label' => 'Parámetros del sistema',
        'icon' => 'cog-6-tooth',
        'exclude_from_nav' => true,
        'disable_create' => true,
        'disable_delete' => true,
        'labels' => [
            'mail_driver' => 'Proveedor de correos',
            'mail_from_address' => 'Correo de envío',
            'mail_from_name' => 'Nombre del remitente',
            'smtp_host' => 'Servidor SMTP',
            'smtp_port' => 'Puerto SMTP',
            'smtp_username' => 'Usuario SMTP',
            'smtp_password' => 'Contraseña SMTP',
            'smtp_encryption' => 'Encriptación SMTP',
            'office365_tenant_id' => 'ID del Tenant',
            'office365_client_id' => 'ID de Cliente',
            'office365_client_secret' => 'Secreto de Cliente',
            'office365_scopes' => 'Permisos (Scopes)',
            'contingency_enabled' => 'Habilitar notificaciones de contingencia',
            'contingency_email_list' => 'Correos para notificaciones (uno por línea)',
            'contingency_resend_hours' => 'Intervalo de reenvío (horas)',
        ],
        'select_options' => [
            'mail_driver' => [
                'smtp' => 'SMTP',
                '365' => 'Office 365 / Microsoft Graph',
            ],
            'smtp_encryption' => [
                'tls' => 'TLS',
                'ssl' => 'SSL',
            ],
        ],
        'fields' => [
            'mail_driver',
            'mail_from_address',
            'mail_from_name',
            'smtp_host',
            'smtp_port',
            'smtp_username',
            'smtp_password',
            'smtp_encryption',
            'office365_tenant_id',
            'office365_client_id',
            'office365_client_secret',
            'office365_scopes',
            'contingency_enabled',
            'contingency_email_list',
            'contingency_resend_hours',
        ],
        'field_tabs' => [
            'General' => ['mail_driver', 'mail_from_address', 'mail_from_name'],
            'SMTP' => ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption'],
            'Office 365' => ['office365_tenant_id', 'office365_client_id', 'office365_client_secret', 'office365_scopes'],
            'Contingencia' => ['contingency_enabled', 'contingency_email_list', 'contingency_resend_hours'],
        ],
        'field_types' => [
            'mail_driver' => 'select',
            'smtp_encryption' => 'select',
            'office365_scopes' => 'textarea',
            'smtp_password' => 'password',
            'office365_client_secret' => 'password',
            'contingency_enabled' => 'checkbox',
            'contingency_email_list' => 'textarea',
            'contingency_resend_hours' => 'number',
        ],
    ],
    'ncf-sequences' => [
        'model' => NcfSequence::class,
        'label' => 'NCF (Series de consecutivos)',
        'icon' => 'hashtag',
        'labels' => [
            'type' => 'Tipo',
            'establishment' => 'Establecimiento',
            'start' => 'Inicio',
            'end' => 'Fin',
            'current' => 'Contador actual',
            'location_id' => 'Localidad',
        ],
        'enable_toggle_check' => true,
        'fields' => ['type', 'establishment', 'location_id', 'start', 'end', 'current'],
        'foreign_labels' => [
            'location_id' => ['relation' => 'location', 'attribute' => 'name', 'header' => 'Localidad', 'fallback_attribute' => 'code'],
        ],
        'grid' => [
            'filters' => [
                'type' => ['label' => 'Tipo', 'type' => 'select', 'options' => ['01' => 'Venta 01', '04' => 'NC 04', '05' => 'ND 05', '07' => 'Guía 07', 'E31' => 'RD Bienes', 'E32' => 'RD Servicios', 'E33' => 'RD Comb.', 'E34' => 'RD Imp.'], 'apply' => ['type' => 'column', 'column' => 'type']],
                'location_id' => ['label' => 'Localidad', 'type' => 'select', 'model' => Location::class, 'order_by' => 'name', 'label_column' => 'name', 'apply' => ['type' => 'column', 'column' => 'location_id']],
            ],
            'sortable' => ['type', 'establishment', 'location_id', 'start', 'end', 'current'],
            'default_sort' => ['key' => 'type', 'direction' => 'asc'],
        ],
    ],
    'ncf-report' => [
        'model' => NcfSequence::class,
        'label' => 'Reporte NCF',
        'icon' => 'document-text',
        'readonly' => true,
        'fields' => ['type', 'location_id', 'establishment', 'start', 'end', 'current'],
        'labels' => [
            'type' => 'Tipo',
            'location_id' => 'Localidad',
            'establishment' => 'Establecimiento',
            'start' => 'Desde',
            'end' => 'Hasta',
            'current' => 'Actual',
        ],
        'foreign_labels' => [
            'location_id' => ['relation' => 'location', 'attribute' => 'name'],
        ],
        'grid' => [
            'filters' => [
                'type' => [
                    'label' => 'Tipo',
                    'type' => 'select',
                    'options' => ['01' => 'Venta 01', '04' => 'NC 04', '05' => 'ND 05', '07' => 'Guía 07', 'E31' => 'RD Bienes', 'E32' => 'RD Servicios', 'E33' => 'RD Comb.', 'E34' => 'RD Imp.'],
                    'apply' => ['type' => 'column', 'column' => 'type'],
                ],
                'location_id' => [
                    'label' => 'Localidad',
                    'type' => 'select',
                    'model' => Location::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'apply' => ['type' => 'column', 'column' => 'location_id'],
                ],
            ],
            'columns' => [
                ['field' => 'location_id', 'label' => 'Localidad', 'sortable' => true, 'width' => '150px'],
                ['field' => 'type', 'label' => 'Tipo NCF', 'sortable' => true, 'width' => '100px'],
                ['field' => 'establishment', 'label' => 'Establecimiento', 'sortable' => true, 'width' => '100px'],
                ['field' => 'start', 'label' => 'Desde', 'sortable' => true, 'width' => '100px'],
                ['field' => 'current', 'label' => 'Actual', 'sortable' => true, 'width' => '100px'],
                ['field' => 'end', 'label' => 'Hasta', 'sortable' => true, 'width' => '100px'],
                [
                    'field' => 'remaining',
                    'label' => 'Restantes',
                    'sortable' => false,
                    'render' => fn ($row) => NcfSequence::where('type', $row->type)
                        ->where(function ($q) use ($row) {
                            if ($row->location_id) {
                                $q->where('location_id', $row->location_id);
                            } else {
                                $q->whereNull('location_id');
                            }
                        })
                        ->sum(function ($seq) {
                            return max(0, $seq->end - $seq->current + 1);
                        }),
                ],
            ],
            'default_sort' => ['key' => 'type', 'direction' => 'asc'],
        ],
    ],
    // Usuarios administradores del panel: solo super-admin (no aparece en la matriz de permisos)
    'admin-users' => [
        'model' => AdminUser::class,
        'label' => 'Usuarios administradores',
        'icon' => 'user-circle',
        'super_admin_only' => true,
        // Se desactivan, no se eliminan: conserva el historial y la auditoría
        'disable_delete' => true,
        'labels' => [
            'name' => 'Nombre',
            'email' => 'Correo',
            'password' => 'Contraseña',
            'is_active' => 'Activo',
            'roles_list' => 'Roles',
            'totp_status' => 'Verificación en 2 pasos',
            'last_login_at' => 'Último acceso',
        ],
        'fields' => ['name', 'email', 'is_active', 'roles_list', 'totp_status', 'last_login_at', 'password'],
        'foreign_labels' => [
            'roles_list' => ['virtual' => true, 'header' => 'Roles'],
            'totp_status' => ['virtual' => true, 'header' => 'Verificación en 2 pasos'],
            'last_login_at' => ['virtual' => true, 'header' => 'Último acceso'],
        ],
        'grid' => [
            'exclude_from_grid' => ['password'],
            'visible_limit' => 8,
            'filters' => [
                'is_active' => [
                    'label' => 'Activo',
                    'type' => 'select',
                    'options' => ['' => 'Todos', '1' => 'Sí', '0' => 'No'],
                    'apply' => ['type' => 'column', 'column' => 'is_active'],
                ],
                'role' => [
                    'label' => 'Rol',
                    'type' => 'select',
                    'model' => Role::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'apply' => ['type' => 'whereHas', 'relation' => 'roles', 'column' => 'roles.id'],
                ],
            ],
            'sortable' => ['name', 'email', 'is_active'],
            'default_sort' => ['key' => 'name', 'direction' => 'asc'],
        ],
    ],
    // Roles: pantalla propia (RoleManagementController); el nombre del ítem y el permiso salen de aquí
    'roles' => [
        'label' => 'Roles',
        'icon' => 'identification',
        'route' => 'admin.roles.index',
        'active_pattern' => 'admin.roles.*',
        'readonly' => true,
        'super_admin_only' => true,
    ],

    'audit-log' => [
        'model' => AdminAuditLog::class,
        'label' => 'Auditoría',
        'icon' => 'shield-check',
        'readonly' => true,
        'fields' => ['created_at', 'admin_user_id', 'action', 'entity_type', 'entity_id', 'changes', 'ip'],
        'labels' => [
            'created_at' => 'Fecha / hora',
            'admin_user_id' => 'Usuario',
            'entity_type' => 'Entidad',
            'changes' => 'Cambios',
            'ip' => 'IP',
        ],
        'foreign_labels' => [
            'admin_user_id' => ['relation' => 'adminUser', 'attribute' => 'name', 'header' => 'Usuario'],
            'changes' => ['virtual' => true, 'header' => 'Cambios'],
        ],
        'grid' => [
            'visible_limit' => 8,
            'filters' => [
                'admin_user_id' => [
                    'label' => 'Usuario',
                    'type' => 'select',
                    'model' => AdminUser::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'apply' => ['type' => 'column', 'column' => 'admin_user_id'],
                ],
                'action' => [
                    'label' => 'Acción',
                    'type' => 'select',
                    'options' => ['created' => 'Creación', 'updated' => 'Edición', 'deleted' => 'Eliminación', 'login' => 'Login', 'logout' => 'Logout', 'blocked' => 'Bloqueo', 'unblocked' => 'Reactivación', 'sessions_revoked' => 'Sesiones cerradas', 'two_factor_reset' => 'Reinicio de 2FA'],
                    'apply' => ['type' => 'column', 'column' => 'action'],
                ],
                'entity_type' => [
                    'label' => 'Entidad',
                    'type' => 'select',
                    'options' => ['Product' => 'Producto', 'Family' => 'Familia', 'Subfamily' => 'Subfamilia', 'Location' => 'Localidad', 'Device' => 'Dispositivo', 'License' => 'Licencia', 'Shift' => 'Turno', 'User' => 'Usuario POS', 'AdminUser' => 'Admin', 'Role' => 'Rol', 'SystemParameter' => 'Parámetro'],
                    'apply' => ['type' => 'column', 'column' => 'entity_type'],
                ],
                'date_from' => [
                    'label' => 'Desde',
                    'type' => 'date',
                    'apply' => ['type' => 'date_from', 'column' => 'created_at'],
                ],
                'date_to' => [
                    'label' => 'Hasta',
                    'type' => 'date',
                    'apply' => ['type' => 'date_to', 'column' => 'created_at'],
                ],
            ],
            'sortable' => ['created_at', 'action', 'entity_type'],
            'default_sort' => ['key' => 'created_at', 'direction' => 'desc'],
        ],
    ],
    'transactions-report' => [
        'model' => Transaction::class,
        'label' => 'Reporte Transacciones',
        'icon' => 'receipt-percent',
        'readonly' => true,
        'labels' => [
            'occurred_at' => 'Fecha / hora',
            'items_count' => 'Líneas',
        ],
        'fields' => ['occurred_at', 'external_id', 'location_id', 'device_id', 'user_id', 'status', 'total', 'items_count'],
        'foreign_labels' => [
            'location_id' => ['relation' => 'location', 'attribute' => 'name', 'header' => 'Localidad'],
            'device_id' => ['relation' => 'device', 'attribute' => 'name', 'fallback_attribute' => 'device_fingerprint', 'header' => 'Dispositivo'],
            'user_id' => ['relation' => 'user', 'attribute' => 'full_name', 'fallback_attribute' => 'username', 'header' => 'Usuario'],
            'items_count' => ['virtual' => true, 'header' => 'Líneas'],
        ],
        'grid' => [
            // Columnas de importe: se muestran como $1,234.56
            'money' => ['total'],
            'visible_limit' => 10,
            'filters' => [
                'date_from' => [
                    'label' => 'Desde',
                    'type' => 'date',
                    'apply' => ['type' => 'date_from', 'column' => 'occurred_at'],
                ],
                'date_to' => [
                    'label' => 'Hasta',
                    'type' => 'date',
                    'apply' => ['type' => 'date_to', 'column' => 'occurred_at'],
                ],
                'location_id' => [
                    'label' => 'Localidad',
                    'type' => 'select',
                    'model' => Location::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'apply' => ['type' => 'column', 'column' => 'location_id'],
                ],
            ],
            'sortable' => ['external_id', 'location_id', 'device_id', 'user_id', 'status', 'total', 'occurred_at', 'items_count'],
            'default_sort' => ['key' => 'occurred_at', 'direction' => 'desc'],
        ],
    ],

    // ============================================================================
    // REPORTES PRIORITARIOS
    // ============================================================================

    'products-margin' => [
        'label' => 'Margen por producto',
        'icon' => 'scale',
        'readonly' => true,
        'rbac' => true,
        'route' => 'admin.reports.products-margin',
    ],

    'products-no-movement' => [
        'label' => 'Productos sin movimiento',
        'icon' => 'archive-box-x-mark',
        'readonly' => true,
        'rbac' => true,
        'route' => 'admin.reports.products-no-movement',
    ],

    'products-best-sellers' => [
        'model' => Product::class,
        'label' => 'Productos Más Vendidos',
        'icon' => 'trophy',
        'readonly' => true,
        'route' => 'admin.reports.products-best-sellers',
        'labels' => [
            'name' => 'Producto',
            'sku' => 'SKU',
            'sold_qty' => 'Cantidad Vendida',
            'total_revenue' => 'Ingresos Totales',
            'avg_price' => 'Precio Promedio',
            'tax_rate' => 'IVA (%)',
        ],
        'fields' => ['name', 'sku', 'sold_qty', 'total_revenue', 'avg_price', 'tax_rate'],
        'foreign_labels' => [
            'sold_qty' => ['virtual' => true, 'header' => 'Cantidad Vendida'],
            'total_revenue' => ['virtual' => true, 'header' => 'Ingresos Totales'],
            'avg_price' => ['virtual' => true, 'header' => 'Precio Promedio'],
        ],
        'grid' => [
            'visible_limit' => 20,
            'filters' => [
                'date_from' => [
                    'label' => 'Desde',
                    'type' => 'date',
                    'apply' => ['type' => 'date_from', 'column' => 'transactions.occurred_at'],
                ],
                'date_to' => [
                    'label' => 'Hasta',
                    'type' => 'date',
                    'apply' => ['type' => 'date_to', 'column' => 'transactions.occurred_at'],
                ],
                'location_id' => [
                    'label' => 'Localidad',
                    'type' => 'select',
                    'model' => Location::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'apply' => ['type' => 'column', 'column' => 'transactions.location_id'],
                ],
            ],
            'columns' => [
                ['field' => 'name', 'label' => 'Producto', 'sortable' => true],
                ['field' => 'sku', 'label' => 'SKU', 'sortable' => true, 'width' => '100px'],
                ['field' => 'sold_qty', 'label' => 'Cantidad', 'sortable' => true, 'width' => '100px'],
                ['field' => 'total_revenue', 'label' => 'Ingresos', 'sortable' => true, 'width' => '120px', 'render' => fn ($v) => '$'.number_format($v, 2)],
                ['field' => 'avg_price', 'label' => 'Precio Prom.', 'sortable' => true, 'width' => '120px', 'render' => fn ($v) => '$'.number_format($v, 2)],
                ['field' => 'tax_rate', 'label' => 'IVA (%)', 'sortable' => true, 'width' => '80px'],
            ],
            'default_sort' => ['key' => 'sold_qty', 'direction' => 'desc'],
        ],
    ],

    'payment-methods-report' => [
        'model' => PaymentMethod::class,
        'label' => 'Ingresos por Método de Pago',
        'icon' => 'chart-pie',
        'readonly' => true,
        'route' => 'admin.reports.payment-methods',
        'labels' => [
            'name' => 'Método de Pago',
            'total_transactions' => 'Transacciones',
            'total_amount' => 'Monto Total',
            'percentage' => '% del Total',
        ],
        'fields' => ['name', 'total_transactions', 'total_amount', 'percentage'],
        'foreign_labels' => [
            'total_transactions' => ['virtual' => true, 'header' => 'Transacciones'],
            'total_amount' => ['virtual' => true, 'header' => 'Monto Total'],
            'percentage' => ['virtual' => true, 'header' => '% del Total'],
        ],
        'grid' => [
            'filters' => [
                'date_from' => [
                    'label' => 'Desde',
                    'type' => 'date',
                    'apply' => ['type' => 'date_from', 'column' => 'transaction_payments.created_at'],
                ],
                'date_to' => [
                    'label' => 'Hasta',
                    'type' => 'date',
                    'apply' => ['type' => 'date_to', 'column' => 'transaction_payments.created_at'],
                ],
            ],
            'columns' => [
                ['field' => 'name', 'label' => 'Método de Pago', 'sortable' => true],
                ['field' => 'total_transactions', 'label' => 'Transacciones', 'sortable' => true, 'width' => '120px'],
                ['field' => 'total_amount', 'label' => 'Monto Total', 'sortable' => true, 'width' => '150px', 'render' => fn ($v) => '$'.number_format($v, 2)],
                ['field' => 'percentage', 'label' => '% del Total', 'sortable' => true, 'width' => '100px', 'render' => fn ($v) => number_format($v, 2).'%'],
            ],
            'default_sort' => ['key' => 'total_amount', 'direction' => 'desc'],
        ],
    ],

    'users-performance-report' => [
        'model' => User::class,
        'label' => 'Desempeño de Usuarios',
        'icon' => 'user-group',
        'readonly' => true,
        'route' => 'admin.reports.users-performance',
        'labels' => [
            'username' => 'Usuario',
            'full_name' => 'Nombre Completo',
            'transaction_count' => 'Transacciones',
            'total_sales' => 'Ingresos Totales',
            'avg_transaction' => 'Ticket Promedio',
            'last_activity' => 'Última Actividad',
        ],
        'fields' => ['username', 'full_name', 'transaction_count', 'total_sales', 'avg_transaction', 'last_activity'],
        'foreign_labels' => [
            'transaction_count' => ['virtual' => true, 'header' => 'Transacciones'],
            'total_sales' => ['virtual' => true, 'header' => 'Ingresos Totales'],
            'avg_transaction' => ['virtual' => true, 'header' => 'Ticket Promedio'],
            'last_activity' => ['virtual' => true, 'header' => 'Última Actividad'],
        ],
        'grid' => [
            'visible_limit' => 15,
            'filters' => [
                'date_from' => [
                    'label' => 'Desde',
                    'type' => 'date',
                    'apply' => ['type' => 'date_from', 'column' => 'transactions.occurred_at'],
                ],
                'date_to' => [
                    'label' => 'Hasta',
                    'type' => 'date',
                    'apply' => ['type' => 'date_to', 'column' => 'transactions.occurred_at'],
                ],
                'location_id' => [
                    'label' => 'Localidad',
                    'type' => 'select',
                    'model' => Location::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'apply' => ['type' => 'column', 'column' => 'transactions.location_id'],
                ],
            ],
            'columns' => [
                ['field' => 'full_name', 'label' => 'Nombre Completo', 'sortable' => true],
                ['field' => 'username', 'label' => 'Usuario', 'sortable' => true, 'width' => '100px'],
                ['field' => 'transaction_count', 'label' => 'Transacciones', 'sortable' => true, 'width' => '120px'],
                ['field' => 'total_sales', 'label' => 'Ingresos', 'sortable' => true, 'width' => '150px', 'render' => fn ($v) => '$'.number_format($v, 2)],
                ['field' => 'avg_transaction', 'label' => 'Ticket Prom.', 'sortable' => true, 'width' => '120px', 'render' => fn ($v) => '$'.number_format($v, 2)],
                ['field' => 'last_activity', 'label' => 'Última Actividad', 'sortable' => true, 'width' => '150px'],
            ],
            'default_sort' => ['key' => 'total_sales', 'direction' => 'desc'],
        ],
    ],
];
