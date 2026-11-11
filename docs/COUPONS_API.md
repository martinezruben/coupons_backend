# API de cupones (migrada desde couponsapi)

La app Coupify usa las mismas rutas y formatos que la API anterior. Autenticación: usuarios de la app (`users`, rol `CASHIER`/`MANAGER`/`ADMIN`), no usuarios del panel ni dispositivos.

## Endpoints
| Método | Ruta | Habilidad | Descripción |
|---|---|---|---|
| POST | `/api/login` | — | `username` (o `email`) + `password`; devuelve `token` con vencimiento y `user` |
| GET | `/api/profile` · POST `/api/logout` | — | Usuario actual · revoca solo el token actual |
| GET | `/api/balance` | `coupon:read` | Saldo QR (`coupon_number` + `coupon_token`) |
| POST | `/api/transaction` | `coupon:redeem` | Cobro QR (`amount`, `coupons[]`, `tokens[]`) |
| GET/POST | `/api/balancecb`, `/api/transactioncb` | read / redeem | Código de barras |
| POST | `/api/transactions/{id}/reverse` | `coupon:reverse` | Reversa auditada (MANAGER/ADMIN) |
| POST | `/api/restore`, `/api/restorecb` | `coupon:restore` | Fijar saldo (solo ADMIN, con `reason`, auditado) |

Habilidades por rol: `config/coupons.php` → `role_abilities`. Los usuarios se crean y desactivan en el panel (Operación → usuarios de la app); desactivar corta el acceso al instante.

## Panel de cupones (`/admin`, grupo «Cupones»)
- **Cupones QR / de barras:** listado con filtros (estado, vencimiento), saldo y búsqueda. El token QR **nunca** se muestra en el listado.
- **Cobros de cupones:** historial por tipo, localidad y fecha, con usuario, dispositivo y reversas.
- **Auditoría de cupones:** emisiones, ajustes de saldo, reversas y activaciones, con el administrador, el motivo y la IP.
- **Acciones con permiso propio** (matriz RBAC, columna «Acciones»): `Emitir` (genera números aleatorios y, en QR, el token; el contenido del QR es `@coupon=<número>@token=<token>` y se entrega una sola vez con CSV), `Activar/desactivar`, `Ajustar saldo` (nunca por encima del monto original) y `Revertir` cobros. Todas piden motivo y quedan auditadas.
- El rol `ops-manager` recibe ver, emitir y activar/desactivar; **ajustar saldo y revertir** solo el super-admin (o un rol a medida).
- Inicio: saldo pendiente, cobrado hoy y en 7 días, cobros de hoy, cupones por vencer y últimos cobros.

## Seguridad aplicada
Cobro con transacción de BD y `lockForUpdate`, decimales exactos, validación de monto/cupones/activo/vencimiento/token QR, errores genéricos, `Idempotency-Key`, rate limits (login 5/min, consulta 30/min, cobro 30/min, restricciones 10/min), tokens por dispositivo con vencimiento (`COUPONS_TOKEN_TTL_MINUTES`).

Dispositivo: si la app envía `Device-Fingerprint` de un dispositivo registrado, habilitado y con licencia, la transacción guarda `device_id` y `location_id` reales. Con `COUPONS_REQUIRE_DEVICE_BINDING=true` pasa a ser obligatorio.

## Compatibilidad con la app Android
- `user.id` se devuelve como entero (la app lo deserializa como `Int`): el de la API anterior si el usuario fue importado; si no, un entero estable derivado del UUID.
- `device_id`/`site_id` que enviaba el cliente se ignoran (en datos importados quedan en `legacy_device_id`/`legacy_site_id`).
- Activar `COUPONS_REQUIRE_IDEMPOTENCY_KEY` solo cuando la app envíe `Idempotency-Key`.

## Migración de datos
**No** apuntar este backend a la base de datos de `couponsapi`: ambas tienen tablas `users`, `cache`, `jobs`, etc. con otro formato y nombres de migración iguales. Se usa una BD nueva y se importa:

```bash
# 1. Conexión de solo lectura a la BD anterior (.env): LEGACY_DB_HOST / _DATABASE / _USERNAME / _PASSWORD
php artisan migrate
php artisan coupons:import-legacy --dry-run          # cuenta y valida, no escribe
php artisan coupons:import-legacy                     # importa; idempotente, no pisa saldos existentes

# 2. Ventana de corte: congelar escrituras en couponsapi y volver a correr con saldos al día
php artisan coupons:import-legacy --sync-balances     # imprime saldo total origen vs destino
```

Importa cupones QR y de barras (conserva `coupon_id`), `transactions` → `coupon_transactions` y `users` (copia el hash bcrypt: las contraseñas actuales siguen funcionando; admin → `ADMIN`, resto → `CASHIER`).

## Antes del corte (fase 4)
1. Probar contra una copia de la BD de producción y comparar saldos totales.
2. Ejecución en paralelo: mismas peticiones a ambas APIs y comparar respuestas.
3. Apuntar `couponsapi.temdoapps.com` al backend; dejar `couponsapi` como plan de reversa unos días.
4. Rotar las credenciales expuestas en el historial de `couponsapi`.
