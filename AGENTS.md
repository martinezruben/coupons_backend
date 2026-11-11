# AGENTS.md — Coupify Backend

Backend Laravel 13 de **Coupify** (cupones prepago): panel de administración Blade/Alpine/Tailwind (usuarios, roles/RBAC, localidades, dispositivos, licencias, auditoría) y API de emparejamiento de dispositivos. Marca del producto: **Coupify**. El POS anterior (catálogo, ventas, turnos, NCF, sync offline) fue eliminado; el módulo de cupones está pendiente (hoy vive en `couponsapi`).

## Comandos

```bash
php artisan test                          # suite completa (debe quedar 100 % verde; el CI la corre en cada PR)
php artisan test tests/Feature/XxxTest.php  # test enfocado
vendor/bin/pint <archivos>                # formatter/lint (obligatorio antes de commit; el CI corre `pint --test` sobre todo el repo)
npm run build                             # assets Vite (obligatorio si se toca resources/js o CSS)
./deploy.sh                               # despliegue Docker (build/up/logs)
```

## Estructura clave

- `app/Http/Controllers/Api/` — API para la app Android: `AuthController` (registro por pairing token + licencia).
- `app/Http/Controllers/Admin/` — panel admin. El CRUD es **config-driven**: `ScreenCrudController` renderiza pantallas definidas en `config/admin_screens.php`. Para agregar una pantalla/reporte nuevo: entrada en `config/admin_screens.php` + grupo en `config/admin_nav_groups.php`; reportes usan `'readonly' => true`.
- `app/Support/` — motor de grids (`AdminGridQuery`, `AdminGridCell`), RBAC (`AdminRbac`), pairing tokens.
- `config/` — `admin_screens.php` (pantallas/columnas/filtros/`field_order`/`visible_limit`), `admin_nav_groups.php`, `admin_rbac.php`.
- `postman/` — referencia de la API (colecciones Postman).

## Reglas de arquitectura

- Todos los modelos usan **UUID** como PK (trait `HasUuidPrimaryKey`); nunca asumir `id` autoincremental.
- RBAC: permisos `{recurso}.view/.edit/.delete` donde recurso = clave de pantalla con `-`→`_`. Pantallas readonly solo exponen `.view`. super-admin pasa por `Gate::before` (AppServiceProvider), no necesita permisos.
- API usa Sanctum con modelo `Device` + middleware `device.operational` (valida licencia). El panel usa guard `admin` sobre `admin_users`.
- Los filtros de grid se definen en config (`apply.type`: `column`, `whereHas`, `date_from`, `date_to`); el orden de columnas del grid usa `grid.field_order`, independiente del orden de `fields` (form).
- Versión de la app: la app Android envía la cabecera `App-Version` (`1.4.2`); se guarda en `devices.app_version` y se compara con `system_parameters.min_app_version` (solo informa, no bloquea).
- PIN del usuario POS (acceso sin conexión): la contraseña del usuario es su PIN; la app compara el SHA-384 de lo tecleado con el campo `pin` del pull = `users.pin_sha384` (hexadecimal en minúsculas, 96 caracteres, `hash('sha384', trim($plain))`, UTF-8, sin sal; `pin4` es el PIN rápido, mismo algoritmo). Asignar una contraseña **en claro** a `User` guarda hash bcrypt y `pin_sha384` a la vez (`User::password()`); no hashear a mano con `Hash::make` antes de asignar o el PIN queda sin hash. Un usuario sin `pin_sha384` recibe en el pull el SHA-384 del hash bcrypt (nunca coincide): `php artisan pos:audit-pins` los lista y el panel los marca; se corrigen guardando su contraseña de nuevo.
- Pantallas solo para super-admin: `'super_admin_only' => true` en `config/admin_screens.php` (usuarios administradores `admin-users` y `roles`). No generan permisos `{recurso}.*`, no salen en la matriz RBAC y solo las ven/usan los roles de `config('admin.super_admin_roles')` (`AdminRbac::isSuperAdminOnly()` / `isSuperAdmin()`). La matriz «Permisos por rol» también es solo super-admin y no edita el rol super-admin. Los admins se desactivan, no se eliminan (`disable_delete`): el login exige `is_active` y el middleware `admin.active` corta sesiones de cuentas desactivadas o con `sessions_revoked_at` posterior al inicio de la sesión. `AdminUserGuard` impide desactivarse/quitarse el super-admin a uno mismo y dejar el sistema sin super-admin activo. Primer admin / recuperación: `php artisan admin:create` y `admin:reset-password`.
- Importes en grids: listar las columnas en `grid.money` para mostrarlas como `$1,234.56`. Todo formato de cifras pasa por `App\Support\Format` (PHP) y `resources/js/format.js` (gráficos); no usar `number_format` con coma decimal.

## Gotchas

- **Tests corren en sqlite en memoria** (`phpunit.xml`): las migraciones deben ser compatibles o hacer no-op para sqlite (ver `2026_08_23_000001_add_inactive_status_to_licenses_table.php`: `MODIFY COLUMN` en MySQL y `->change()` en sqlite, porque sqlite sí valida el CHECK de los enum).
- Después de cambiar `config/` en producción: `php artisan config:clear` (o rebuild del contenedor).
- `deploy.sh` y Docker esperan que `npm run build` se haya corrido.

## Docs del proyecto

- `documentation/docker/INSTALL_GUIDE.md` — instalación Docker desde cero.
