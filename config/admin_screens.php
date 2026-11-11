<?php

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\ApiRequestLog;
use App\Models\BarcodeCoupon;
use App\Models\Coupon;
use App\Models\CouponAuditLog;
use App\Models\CouponTransaction;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\SystemParameter;
use App\Models\User;
use Spatie\Permission\Models\Role;

return [
    // Sin modelo: solo expone el permiso pulse.view en la matriz RBAC
    'pulse' => ['label' => 'Pulse (métricas)', 'icon' => 'bolt', 'readonly' => true, 'rbac' => true, 'exclude_from_nav' => true],
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
        'label' => 'Usuarios de la app (Coupify)',
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
    'coupons' => [
        'model' => Coupon::class,
        'label' => 'Cupones QR',
        'icon' => 'qr-code',
        'readonly' => true,
        'labels' => [
            'coupon_number' => 'Número',
            'coupon_active' => 'Estado',
            'coupon_date' => 'Emisión',
            'coupon_expire_date' => 'Vence',
            'coupon_amount' => 'Monto inicial',
            'coupon_balance' => 'Saldo',
        ],
        'fields' => ['coupon_number', 'coupon_active', 'coupon_date', 'coupon_expire_date', 'coupon_amount', 'coupon_balance'],
        'actions' => ['issue' => 'Emitir', 'toggle' => 'Activar/desactivar', 'adjust' => 'Ajustar saldo'],
        'grid' => [
            'filters' => [
                'coupon_active' => [
                    'label' => 'Estado',
                    'type' => 'select',
                    'options' => ['' => 'Todos', '1' => 'Activo', '0' => 'Inactivo'],
                    'apply' => ['type' => 'column', 'column' => 'coupon_active'],
                ],
                'expire_to' => [
                    'label' => 'Vence hasta',
                    'type' => 'date',
                    'apply' => ['type' => 'date_to', 'column' => 'coupon_expire_date'],
                ],
            ],
            'money' => ['coupon_amount', 'coupon_balance'],
            'sortable' => ['coupon_number', 'coupon_active', 'coupon_date', 'coupon_expire_date', 'coupon_amount', 'coupon_balance'],
            'search_columns' => ['coupon_number'],
            'default_sort' => ['key' => 'coupon_id', 'direction' => 'desc'],
        ],
    ],
    'coupon-barcodes' => [
        'model' => BarcodeCoupon::class,
        'label' => 'Cupones de barras',
        'icon' => 'ticket',
        'readonly' => true,
        'labels' => [
            'coupon_number' => 'Número',
            'coupon_active' => 'Estado',
            'coupon_date' => 'Emisión',
            'coupon_expire_date' => 'Vence',
            'coupon_amount' => 'Monto inicial',
            'coupon_balance' => 'Saldo',
        ],
        'fields' => ['coupon_number', 'coupon_active', 'coupon_date', 'coupon_expire_date', 'coupon_amount', 'coupon_balance'],
        'actions' => ['issue' => 'Emitir', 'toggle' => 'Activar/desactivar', 'adjust' => 'Ajustar saldo'],
        'grid' => [
            'filters' => [
                'coupon_active' => [
                    'label' => 'Estado',
                    'type' => 'select',
                    'options' => ['' => 'Todos', '1' => 'Activo', '0' => 'Inactivo'],
                    'apply' => ['type' => 'column', 'column' => 'coupon_active'],
                ],
                'expire_to' => [
                    'label' => 'Vence hasta',
                    'type' => 'date',
                    'apply' => ['type' => 'date_to', 'column' => 'coupon_expire_date'],
                ],
            ],
            'money' => ['coupon_amount', 'coupon_balance'],
            'sortable' => ['coupon_number', 'coupon_active', 'coupon_date', 'coupon_expire_date', 'coupon_amount', 'coupon_balance'],
            'search_columns' => ['coupon_number'],
            'default_sort' => ['key' => 'coupon_id', 'direction' => 'desc'],
        ],
    ],
    'coupon-transactions' => [
        'model' => CouponTransaction::class,
        'label' => 'Cobros de cupones',
        'icon' => 'receipt-percent',
        'readonly' => true,
        'labels' => [
            'transaction_id' => 'N.º',
            'created_at' => 'Fecha/hora',
            'coupon_number' => 'Cupón',
            'coupon_type' => 'Tipo',
            'transaction_amount' => 'Monto',
            'user_id' => 'Usuario',
            'location_id' => 'Localidad',
            'device_id' => 'Dispositivo',
            'reversed_of' => 'Reversa de',
        ],
        'fields' => ['transaction_id', 'created_at', 'coupon_number', 'coupon_type', 'transaction_amount', 'user_id', 'location_id', 'device_id', 'reversed_of'],
        'actions' => ['reverse' => 'Revertir'],
        'foreign_labels' => [
            'user_id' => ['relation' => 'user', 'attribute' => 'username', 'header' => 'Usuario'],
            'location_id' => ['relation' => 'location', 'attribute' => 'name', 'header' => 'Localidad'],
            'device_id' => ['relation' => 'device', 'attribute' => 'name', 'fallback_attribute' => 'device_fingerprint', 'header' => 'Dispositivo'],
        ],
        'grid' => [
            'filters' => [
                'coupon_type' => [
                    'label' => 'Tipo',
                    'type' => 'select',
                    'options' => ['' => 'Todos', 'QRCODE' => 'QR', 'CODBAR' => 'Barras'],
                    'apply' => ['type' => 'column', 'column' => 'coupon_type'],
                ],
                'location_id' => [
                    'label' => 'Localidad',
                    'type' => 'select',
                    'model' => Location::class,
                    'order_by' => 'name',
                    'label_column' => 'name',
                    'apply' => ['type' => 'column', 'column' => 'location_id'],
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
            'money' => ['transaction_amount'],
            'sortable' => ['transaction_id', 'created_at', 'coupon_number', 'coupon_type', 'transaction_amount'],
            'search_columns' => ['coupon_number'],
            'default_sort' => ['key' => 'transaction_id', 'direction' => 'desc'],
        ],
    ],
    'coupon-audit-logs' => [
        'model' => CouponAuditLog::class,
        'label' => 'Auditoría de cupones',
        'icon' => 'clipboard-document-list',
        'readonly' => true,
        'labels' => [
            'created_at' => 'Fecha/hora',
            'action' => 'Acción',
            'coupon_type' => 'Tipo',
            'coupon_number' => 'Cupón',
            'old_value' => 'Antes',
            'new_value' => 'Después',
            'reason' => 'Motivo',
            'user_id' => 'Usuario app',
            'admin_user_id' => 'Admin',
            'ip_address' => 'IP',
        ],
        'fields' => ['created_at', 'action', 'coupon_type', 'coupon_number', 'old_value', 'new_value', 'reason', 'user_id', 'admin_user_id', 'ip_address'],
        'foreign_labels' => [
            'user_id' => ['relation' => 'user', 'attribute' => 'username', 'header' => 'Usuario app'],
            'admin_user_id' => ['relation' => 'adminUser', 'attribute' => 'name', 'header' => 'Admin'],
        ],
        'grid' => [
            'filters' => [
                'action' => [
                    'label' => 'Acción',
                    'type' => 'select',
                    'options' => ['' => 'Todas', 'issue' => 'Emisión', 'restore' => 'Ajuste de saldo', 'reverse' => 'Reversa', 'activate' => 'Activación', 'deactivate' => 'Desactivación'],
                    'apply' => ['type' => 'column', 'column' => 'action'],
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
            'sortable' => ['created_at', 'action', 'coupon_number'],
            'search_columns' => ['coupon_number', 'reason'],
            'default_sort' => ['key' => 'id', 'direction' => 'desc'],
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
                    'options' => ['Location' => 'Localidad', 'Device' => 'Dispositivo', 'License' => 'Licencia', 'User' => 'Usuario POS', 'AdminUser' => 'Admin', 'Role' => 'Rol', 'SystemParameter' => 'Parámetro'],
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

    // ============================================================================
    // REPORTES PRIORITARIOS
    // ============================================================================

];
