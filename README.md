# Coupify — Backend

Backend administrativo de **Coupify** (cupones prepago). Es el panel web con el que se administran usuarios, roles y permisos, localidades, dispositivos y licencias. Se reutiliza del backend POS anterior: el catálogo, las ventas, los turnos, los NCF y la sincronización offline se eliminaron. El módulo de cupones se construirá sobre esta base.

![PHP 8.3](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)
![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![MySQL 8.4](https://img.shields.io/badge/MySQL-8.4-4479A1?logo=mysql&logoColor=white)

> **Estado:** adaptación en curso. La API de cupones que consume la app Android vive hoy en el repositorio `couponsapi`; aquí falta migrarla (ver [Pendiente](#pendiente)).

## Funcionalidades

**Panel de administración** (`/admin`)
- **Ingreso**: login por guard `admin`, verificación en dos pasos (TOTP), bloqueo por intentos fallidos y cierre de sesiones.
- **Usuarios del panel** (`admin-users`) y **usuarios de la app** (`android-users`, con PIN offline).
- **Roles y permisos**: RBAC con Spatie (`{recurso}.view/.edit/.delete`), matriz de permisos por rol, rol `super-admin`.
- **Infraestructura**: localidades (con mapa), dispositivos (bloqueo remoto) y licencias.
- **Auditoría**: cambios de modelos clave y login/logout; bitácora de peticiones de la API.
- **Parámetros del sistema**: políticas de contraseña, correo (SMTP o Microsoft 365), contingencia por localidad con avisos por correo y versión mínima de la app.
- **CRUD configurable**: cada pantalla se define en `config/admin_screens.php`.

**API de dispositivos** (`/api`, Sanctum)
- `POST /api/auth/register-device`, `POST /api/auth/login`, `GET /api/auth/me`: emparejamiento del dispositivo con licencia.

## Puesta en marcha

Requisitos: PHP 8.3+ (el `composer.lock` actual requiere PHP 8.4), Composer y Node 20+. `.env.example` usa sqlite para desarrollar; en Docker se usa MySQL 8.4.

```bash
composer setup                 # dependencias, .env, clave, migraciones y assets
php artisan db:seed            # permisos y roles base (RbacSeeder)
php artisan admin:create       # primer administrador (o ADMIN_EMAIL/ADMIN_PASSWORD antes de sembrar)
composer dev                   # servidor, cola, logs y Vite
```

El panel queda en `http://localhost:8000/admin`. No hay credenciales por defecto.

| Variable | Valor | Para qué |
| --- | --- | --- |
| `APP_TIMEZONE` | `America/Santo_Domingo` | Zona horaria del backend |
| `APP_LOCALE` | `es` | Idioma del panel |
| `QUEUE_CONNECTION` | `database` | Cola de correos de contingencia |

## Despliegue con Docker

```bash
npm run build
./deploy.sh up         # app, mysql y phpmyadmin
./deploy.sh logs app
```

Guía completa: [`documentation/docker/INSTALL_GUIDE.md`](documentation/docker/INSTALL_GUIDE.md).

## Estructura

```
app/Http/Controllers/Api/      # registro de dispositivo y login
app/Http/Controllers/Admin/    # panel, RBAC, roles, parámetros
app/Support/                   # motor de grids, RBAC, formato
config/admin_screens.php       # pantallas del panel
config/admin_nav_groups.php    # grupos del menú
database/seeders/RbacSeeder.php
postman/                       # contratos de la API
```

## Tests y calidad

```bash
php artisan test
vendor/bin/pint --test
```

Convenciones del proyecto: [`AGENTS.md`](AGENTS.md).

## Pendiente

- Migrar el módulo de cupones (QR y código de barras, cobros, reversas, restore solo admin) desde `couponsapi`.
- Quitar dependencias que ya no se usan (`dompdf`, `phpspreadsheet`, `intervention/image`, `picqer`, `apexcharts`); requiere `composer update` con PHP 8.4.
- Decidir el futuro de dispositivos, licencias y contingencia (heredados del POS).
