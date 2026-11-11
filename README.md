# Tap&Go POS — Backend

Backend del sistema punto de venta **Tap&Go**. Expone la API que usa la app Android (offline-first) y el panel web con el que se administran localidades, dispositivos, catálogo, ventas y reportes.

![PHP 8.3](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)
![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![MySQL 8.4](https://img.shields.io/badge/MySQL-8.4-4479A1?logo=mysql&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-compose-2496ED?logo=docker&logoColor=white)

![Dashboard comercial](docs/images/dashboard-comercial.png)

## Contenido

- [Funcionalidades](#funcionalidades)
- [Capturas](#capturas)
- [Arquitectura](#arquitectura)
- [Modelo de datos](#modelo-de-datos)
- [Puesta en marcha](#puesta-en-marcha)
- [Despliegue con Docker](#despliegue-con-docker)
- [Estructura del proyecto](#estructura-del-proyecto)
- [Tests y calidad](#tests-y-calidad)
- [Documentación](#documentación)

## Funcionalidades

**API para la app Android** (`/api`, Sanctum)
- Registro del dispositivo con token de emparejamiento y validación de licencia en cada llamada (`device.operational`).
- Sincronización offline-first: `POST /api/sync/push` (ventas, turnos, pagos) y `GET /api/sync/pull` (catálogo, usuarios, métodos de pago e imágenes optimizadas).
- Reportes para el POS: `GET /api/reports/dashboard` y `/api/reports/by-location`.

**Panel de administración** (`/admin`)
- **Dashboard comercial**: ventas, ticket promedio, anulaciones, tendencia diaria, mezcla por familia y método de pago, top de productos y localidades.
- **Dashboard técnico**: localidades en contingencia, dispositivos sin sincronizar, licencias por vencer y salud de la sincronización. Cada alerta abre su detalle en un modal.
- Ambos dashboards se filtran por localidad.
- **CRUD configurable**: cada pantalla se define en `config/admin_screens.php` (columnas, filtros, formularios, importes).
- **Reportes** con exportación a Excel, CSV y PDF: productos más vendidos, ingresos por método de pago, rendimiento de usuarios, cierre de caja y transacciones.
- **Seguridad**: permisos por rol (RBAC con Spatie), verificación en dos pasos (TOTP), bloqueo por intentos fallidos y auditoría de cambios.
- **Operación**: contingencia por localidad con avisos por correo (SMTP o Microsoft 365), parámetros del sistema y logs de API y sincronización.
- **Facturación dominicana**: secuencias NCF con alerta de rango bajo. El plan de e-CF con la DGII está en `docs/implementacion-dgii.md`.

El panel está en español y usa un formato de cifras único (`$13,095.50`, `39.8%`).

## Capturas

| Dashboard técnico: contingencia en modal | Reporte de ingresos por método de pago |
| --- | --- |
| ![Dashboard técnico](docs/images/dashboard-tecnico-contingencia.png) | ![Reporte por método de pago](docs/images/reporte-metodos-pago.png) |
| **Grid de transacciones** | **Inicio de sesión** |
| ![Transacciones](docs/images/transacciones.png) | ![Inicio de sesión](docs/images/login.png) |

<p align="center">
  <img src="docs/images/movil-dashboard.png" alt="Dashboard en el móvil" width="280"><br>
  <sub>El panel también funciona en el móvil.</sub>
</p>

## Arquitectura

![Arquitectura de componentes](docs/images/arquitectura.svg)

| Componente | Detalle |
| --- | --- |
| **Contenedor `app`** | Una imagen con Nginx, PHP-FPM y el scheduler, orquestados por `supervisord` (`docker/supervisord.conf`). Publica el puerto `8082`. |
| **Laravel** | Una sola aplicación sirve la API (`routes/api.php`, Sanctum con el modelo `Device`) y el panel (`routes/web.php`, guard `admin`). La lógica de negocio vive en `app/Services`. |
| **Scheduler** | `schedule:run` cada 60 s (tareas en `routes/console.php`), por ejemplo los recordatorios de contingencia. |
| **Cola** | `QUEUE_CONNECTION=database`: los trabajos se guardan en la tabla `jobs`. Los correos de contingencia y el aviso de NCF usan la cola. |
| **MySQL 8.4** | Datos de negocio, sesiones, caché, cola y permisos. phpMyAdmin en el puerto `8081`. |
| **Almacenamiento** | `./storage` montado como volumen: imágenes de productos y familias, miniaturas WebP y logs. |
| **Correo** | Se configura desde *Parámetros del sistema*: SMTP o Microsoft 365 (Graph API). |

> [!WARNING]
> `supervisord` no levanta un worker de colas, así que en Docker los trabajos de la tabla `jobs` (correos de contingencia, aviso de NCF) quedan pendientes. Hasta que se agregue el proceso, se puede correr a mano con `./deploy.sh ssh` y `php artisan queue:work`.

## Modelo de datos

Todas las tablas de negocio usan UUID como clave primaria. `admin_users` y las tablas de permisos de Spatie usan enteros.

### Operación POS

```mermaid
erDiagram
    locations ||--o{ devices : "tiene"
    devices ||--o{ licenses : "tiene"
    locations ||--o{ users : "localidad principal"
    users }o--o{ locations : "user_locations"
    locations ||--o{ shifts : ""
    devices ||--o{ shifts : ""
    users ||--o{ shifts : "abre"
    locations ||--o{ transactions : ""
    devices ||--o{ transactions : "registra"
    users ||--o{ transactions : "vende"
    transactions ||--o{ transaction_items : "líneas"
    transactions ||--o{ transaction_payments : "pagos"
    products ||--o{ transaction_items : ""
    payment_methods ||--o{ transaction_payments : "payment_method (lógica)"
    families ||--o{ subfamilies : ""
    subfamilies ||--o{ products : ""
    families ||--o{ promotions : "opcional"
    subfamilies ||--o{ promotions : "opcional"
    products ||--o{ promotions : "opcional"
    locations ||--o{ ncf_sequences : "location_id"

    locations {
        uuid id PK
        string name
        string address
        bool is_active
        decimal latitude
        decimal longitude
        datetime last_sync_at
        datetime contingency_started_at
    }
    devices {
        uuid id PK
        uuid location_id FK
        string device_fingerprint
        string name
        bool is_enabled
        datetime last_sync_at
        datetime registered_at
    }
    licenses {
        uuid id PK
        uuid device_id FK
        string license_key
        datetime valid_from
        datetime valid_to
        enum status "ACTIVE, EXPIRED, REVOKED"
    }
    users {
        uuid id PK
        uuid location_id FK
        string username
        string full_name
        string role
        bool is_active
    }
    shifts {
        uuid id PK
        uuid location_id FK
        uuid device_id FK
        uuid user_id FK
        int shift_number
        datetime start_time
        datetime end_time
        decimal opening_balance
        decimal closing_balance
    }
    transactions {
        uuid id PK
        string external_id
        uuid location_id FK
        uuid device_id FK
        uuid user_id FK
        string shift_id
        enum status "PENDING, PAID, VOIDED"
        decimal total
        datetime occurred_at
        bool is_synced
        string ncf
    }
    transaction_items {
        uuid id PK
        uuid transaction_id FK
        uuid product_id FK
        string product_name
        decimal qty
        decimal unit_price
        decimal discount
        decimal tax
        decimal line_total
    }
    transaction_payments {
        uuid id PK
        uuid transaction_id FK
        string payment_method "id de payment_methods o CASH/CARD"
        decimal amount
        string reference
    }
    payment_methods {
        string id PK
        string name
        enum type "CASH, CARD, TRANSFER, OTHER"
        bool is_enabled
    }
    products {
        uuid id PK
        uuid subfamily_id FK
        string sku
        string barcode
        string name
        decimal price
        decimal tax_rate
        bool is_active
    }
    families {
        uuid id PK
        string name
        string image_url
    }
    subfamilies {
        uuid id PK
        uuid family_id FK
        string name
    }
    promotions {
        uuid id PK
        string type
        decimal value
        uuid product_id
        uuid subfamily_id
        uuid family_id
        datetime starts_at
        datetime ends_at
    }
    ncf_sequences {
        int id PK
        string type
        string location_id
        int start
        int end
        int current
    }
```

### Sincronización, auditoría y seguridad del panel

```mermaid
erDiagram
    locations ||--o{ sync_logs : ""
    devices ||--o{ sync_logs : ""
    locations ||--o{ sync_states : ""
    devices ||--o{ sync_states : ""
    locations ||--o{ api_request_logs : ""
    devices ||--o{ api_request_logs : ""
    locations ||--o{ contingency_audit_logs : ""
    admin_users ||--o{ model_has_roles : ""
    roles ||--o{ model_has_roles : ""
    roles ||--o{ role_has_permissions : ""
    permissions ||--o{ role_has_permissions : ""
    admin_users ||--o{ admin_audit_logs : "admin_user_id"

    sync_logs {
        uuid id PK
        uuid location_id FK
        uuid device_id FK
        enum operation "PUSH, PULL"
        string entity
        int records_count
        enum status "SUCCESS, FAILED"
        datetime started_at
    }
    sync_states {
        uuid id PK
        uuid location_id FK
        uuid device_id FK
        datetime last_pull_at
        datetime last_push_at
        datetime last_success_at
    }
    api_request_logs {
        uuid id PK
        uuid location_id FK
        uuid device_id FK
        string method
        string path
        int response_status
        int duration_ms
    }
    contingency_audit_logs {
        int id PK
        uuid location_id FK
        string event
        text sent_to
    }
    admin_users {
        int id PK
        string email
        bool is_active
        bool totp_enabled
    }
    roles {
        int id PK
        string name
    }
    permissions {
        int id PK
        string name "recurso.view / .edit / .delete"
    }
    model_has_roles {
        int role_id FK
        int model_id FK
    }
    role_has_permissions {
        int role_id FK
        int permission_id FK
    }
    admin_audit_logs {
        uuid id PK
        string admin_user_id
        string action
        string entity_type
        text changes
    }
    system_parameters {
        int id PK
        string mail_driver "smtp o 365"
        bool contingency_enabled
        json contingency_email_list
        bool sync_paused
    }
```

Relaciones sin clave foránea en la base de datos:
- `transaction_payments.payment_method` guarda el `id` de `payment_methods` o, en ventas antiguas, una categoría (`CASH`, `CARD`, `TRANSFER`, `OTHER`).
- `promotions` apunta a producto, subfamilia o familia según su alcance.
- `ncf_sequences.location_id` identifica la localidad de la secuencia.

## Puesta en marcha

Requisitos: PHP 8.3 con GD, Composer y Node 20+. `.env.example` usa sqlite, así que no hace falta MySQL para desarrollar; en Docker se usa MySQL 8.4.

```bash
composer setup                 # instala dependencias, crea .env, genera la clave, migra y compila assets
php artisan db:seed            # datos de demostración (DemoPosSeeder)
composer dev                   # servidor, cola, logs y Vite en paralelo
```

El panel queda en `http://localhost:8000/admin`. El seeder de demostración crea el usuario `backend.admin@demo.local` con la contraseña `Backend123!`. Úsalo solo en local.

Variables clave del `.env`:

| Variable | Valor | Para qué |
| --- | --- | --- |
| `APP_TIMEZONE` | `America/Santo_Domingo` | Todo el backend opera en GMT-4 |
| `APP_LOCALE` | `es` | Idioma del panel y de los mensajes de validación |
| `QUEUE_CONNECTION` | `database` | Cola para los correos de contingencia |

## Despliegue con Docker

```bash
npm run build          # los assets se compilan antes de construir la imagen
./deploy.sh up         # construye y levanta app, mysql y phpmyadmin
./deploy.sh logs app   # logs del contenedor
./deploy.sh ssh        # shell dentro del contenedor
```

| Servicio | Puerto |
| --- | --- |
| Panel y API | `8082` |
| phpMyAdmin | `8081` |
| MySQL | `3306` |

Después de cambiar `config/` o el `.env` en producción, corre `php artisan config:clear` (o reconstruye el contenedor). La guía completa está en [`documentation/docker/INSTALL_GUIDE.md`](documentation/docker/INSTALL_GUIDE.md).

## Estructura del proyecto

```
app/
├── Http/Controllers/Api/      # API para Android: auth, sync, reportes
├── Http/Controllers/Admin/    # Panel: dashboards, CRUD por config, reportes, RBAC
├── Services/                  # Dashboard, reportes, sync, NCF, miniaturas
├── Support/                   # Motor de grids, RBAC, formato de cifras
└── Jobs/, Observers/          # Contingencia (correos y recordatorios)
config/
├── admin_screens.php          # Definición de cada pantalla del panel
├── admin_nav_groups.php       # Grupos del menú lateral
└── admin_rbac.php             # Roles de referencia
resources/views/admin/         # Vistas Blade del panel
resources/js/                  # Alpine y gráficos (ApexCharts)
lang/es/                       # Traducciones al español
postman/                       # Colecciones con los contratos de la API
docker/                        # Nginx, PHP y supervisord
```

## Tests y calidad

```bash
php artisan test         # suite completa (sqlite en memoria)
vendor/bin/pint          # formato del código PHP
```

Los 3 tests de `FamilyImageUploadTest` necesitan una base de datos real con `DemoPosSeeder` y fallan en sqlite. Las convenciones del proyecto están en [`AGENTS.md`](AGENTS.md).

## Documentación

- [Guía de instalación con Docker](documentation/docker/INSTALL_GUIDE.md)
- [Plan de facturación electrónica DGII](docs/implementacion-dgii.md)
- Referencia de la API: colecciones Postman en [`postman/`](postman/)
