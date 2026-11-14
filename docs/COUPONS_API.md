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
| POST | `/api/topup` · `/api/topupcb` | `coupon:topup` | Recarga (TOP-UP) de un cupón QR (`coupon_number`, `coupon_token`, `amount`) o de barras; sujeta a las reglas de recarga |
| POST | `/api/transactions/{id}/reverse` | `coupon:reverse` | Reversa auditada (MANAGER/ADMIN) |
| POST | `/api/restore`, `/api/restorecb` | `coupon:restore` | Fijar saldo (solo ADMIN, con `reason`, auditado) |

Habilidades por rol: `config/coupons.php` → `role_abilities`. Los usuarios se crean y desactivan en el panel (Operación → usuarios de la app); desactivar corta el acceso al instante.

## Lotes, activación y clientes (panel)
Los cupones se **generan** en lotes y se **activan** aparte, para un cliente:

```
GENERATED ──asignar──▶ ASSIGNED ──activar (ahora o en una fecha)──▶ ACTIVE ⇄ INACTIVE
    └────────────────────── anular lote (solo los no activos) ──────────────▶ VOID
```

- **Generar** (Cupones → Lotes de cupones → Generar lote): de 1 a 20 000 cupones, QR o de barras, con monto, vencimiento y prefijo opcional. Corre en segundo plano (cola) y es todo o nada. Los números son aleatorios (12 dígitos, únicos entre ambos tipos de cupón) y los QR llevan un token aleatorio de 128 bits. Los cupones nacen **sin activar**: no se pueden consultar como activos ni cobrar.
- **Los tokens solo existen en el archivo CSV del lote.** En la base se guarda únicamente su hash (`h1:` + SHA-256); ninguna pantalla ni listado los muestra. El archivo (`lote, secuencia, numero, token, contenido_qr, monto, vence`) se descarga **una sola vez** (permiso `Descargar archivo`) y se elimina al terminar la descarga, o a las `COUPONS_EXPORT_TTL_HOURS` (24 h) si nadie lo descargó (`coupons:purge-exports`, cada hora). Si se pierde, se anula el lote y se genera otro. La descarga queda auditada.
- **Asignar y activar** (en el lote): se elige la selección (todos los elegibles, un rango de la secuencia, los primeros N o una lista de números pegada o subida en CSV, hasta 20 000), el **cliente** y el momento: *solo asignar* (reserva para el cliente), *activar ahora* o *activar en una fecha y hora* (entre 1 minuto y 1 año hacia adelante). Antes de aplicar se muestra un resumen con la cantidad. Solo se asignan los cupones generados; se activan los generados o los asignados al mismo cliente. «Al vender» equivale a activar ahora.
- **Activaciones programadas:** `coupons:activate-due` corre cada minuto, es idempotente y las aplica cuando llega su fecha. Se pueden **cancelar** mientras estén pendientes (los cupones quedan asignados, sin activarse). Los asignados sin activación pendiente se pueden **liberar**.
- **Anular lote:** anula los cupones aún no activos y cancela las activaciones pendientes; los activos no se tocan.
- **Separación de funciones:** quien generó un lote **no puede asignarlo, activarlo ni liberarlo** (`COUPONS_SEPARATION_OF_DUTIES`, activa por defecto).
- **Auditoría:** cada operación deja una fila en la auditoría de cupones (`batch_create`, `batch_generate`, `batch_download`, `assign`, `activate`, `schedule`, `cancel_activation`, `unassign`, `void_batch`, `export_expired`) con el administrador, el motivo, la IP, el lote y la orden; cada orden guarda quién la pidió, cuándo, la selección y el cliente, y cada cupón guarda su orden, su cliente y su fecha de activación.
- **Clientes** (Clientes): registro simple (nombre, documento, correo, teléfono, notas); se desactivan, no se borran.
- **Permisos** (matriz de roles, columna «Acciones» de *Lotes de cupones*): `generate`, `download`, `activate` (asignar, activar y liberar), `cancel` y `void`; los listados de lotes, activaciones y clientes tienen su propio permiso de ver.

Los cupones que ya existían (importados) quedan como `ACTIVE`/`INACTIVE` según su bandera, sin lote.

## Recargas (TOP-UP) y sus reglas
Una recarga suma saldo (y monto) a un cupón activo y no vencido, deja un movimiento `TOPUP` y una auditoría. Nunca supera `COUPONS_MAX_BALANCE` (100000.00 por defecto). Por defecto solo MANAGER y ADMIN tienen `coupon:topup` (`config/coupons.php`); las recargas **no** se pueden revertir y **no** cuentan como cobro en los reportes.

Las reglas viven en **Cupones → Reglas de recarga** (solo el super-admin las edita). Sin reglas de un tipo, ese tipo no restringe nada; las reglas inactivas se ignoran y cada regla puede limitarse a cupones QR o de barras:

| Tipo | Efecto |
|---|---|
| **Por localidad** | Solo se puede recargar en las localidades de las reglas activas (la localidad sale del dispositivo, `Device-Fingerprint`; sin dispositivo no hay localidad y se rechaza) |
| **Por día de la semana** | Solo se puede recargar los días indicados (`1`=lunes … `7`=domingo, p. ej. `1,2,3`) |
| **Por monto diario** | Tope de lo que se recarga a un mismo cupón en un día (varias reglas: aplica la menor; puede limitarse a una localidad) |

Las recargas hechas desde el panel respetan las reglas de día y de monto, pero no la de localidad (no tienen dispositivo). Errores: 422 con `Top-ups are not allowed on this weekday` (+ `allowed_weekdays`), `... at this location`, `Daily top-up limit exceeded` (+ `daily_limit`, `topped_up_today`, `remaining`) o `Balance limit exceeded`.

> Interpretación: las notas de la app («TOP-UP por localidad / por monto por día / por día de la semana») se implementaron como restricciones de recarga. Si se pensaban como bonos o descuentos, hay que ajustarlo.

## Panel de cupones (`/admin`, grupo «Cupones»)
- **Cupones QR / de barras:** listado con filtros (estado, vencimiento), saldo y búsqueda. El token QR **nunca** se muestra en el listado.
- **Cobros de cupones:** historial por tipo, localidad y fecha, con usuario, dispositivo y reversas.
- **Auditoría de cupones:** emisiones, ajustes de saldo, reversas y activaciones, con el administrador, el motivo y la IP.
- **Reporte de canjes** (Cupones → Reportes de canjes): cobrado, reversado, neto y recargado entre dos fechas, agrupado por día, localidad, dispositivo, usuario o tipo de cupón; filtros por tipo y localidad; exportación CSV (UTF-8 con BOM, abre bien en Excel).
- **Acciones con permiso propio** (matriz RBAC, columna «Acciones»): `Recargar` (con nota opcional), `Activar/desactivar` (solo cupones activos/inactivos; los demás se activan por lote), `Ajustar saldo` (nunca por encima del monto original) y `Revertir` cobros. Todas piden motivo y quedan auditadas.
- El rol `ops-manager` recibe ver, emitir y activar/desactivar, más ver reportes y reglas; **recargar, ajustar saldo, revertir y editar reglas** solo el super-admin (o un rol a medida).
- Inicio: saldo pendiente, cobrado hoy y en 7 días, cobros de hoy, cupones por vencer y últimos cobros.

## Permisos, perfiles, límites y alcance (RBAC)

**Permisos de lotes** (`coupon_batches.*`): `generate`, `download`, `assign` (asignar a cliente y liberar), `activate` (activar una selección), `activate_all` (operar sobre el lote completo), `schedule` (activar en una fecha), `cancel` (cancelar programadas), `void`. El formulario de activación solo ofrece los modos y selecciones que la cuenta puede usar; el servidor lo vuelve a comprobar. Con `COUPONS_SEPARATION_OF_DUTIES` quien generó un lote no lo asigna ni activa.

**Perfiles de partida** (`RbacSeeder`, solo se crean si no existen y nunca se sobrescriben): `generador` (genera y descarga), `operador` (asigna, activa, programa, cancela), `ventas` (activa selecciones; límite de 500 cupones por operación), `supervisor` (anula, reversa), `auditor` (solo lectura, bitácoras y reportes).

**Límites por rol** (pantalla del rol → «Límites del rol»): máximo de cupones por activación, por lote y valor total del lote. Con varios roles gana el más permisivo; un rol sin límite no limita; el super-admin nunca tiene límites. Los cambios se auditan.

**Alcance por cliente**: cada administrador ve todos los clientes (`all`) o solo los elegidos (`selected`, en el formulario de la cuenta). Aplica a clientes, cupones, activaciones, lotes (embudo y órdenes), tablero y reportes; un registro fuera de alcance responde 404. Un alcance «selected» vacío no ve nada.

**Cuentas por rol**: en la pantalla del rol se añaden y quitan cuentas (solo super-admin, auditado, sin tocar el rol `super-admin`).

## Tablero y reportes

`/admin` muestra KPIs y gráficas (7/14/30 días, filtro por cliente). Reportes en «Reportes»: *Por lote* y *Por cliente* (filtros y CSV con celdas neutralizadas contra fórmulas) y *Canjes*.

## Generar lotes y auditoría total

**Formulario «Generar lote»:** vencimiento por defecto de un año contado desde mañana (editable o vacío); el monto de cada cupón es un **número entero**; el prefijo se elige del catálogo **Prefijos de cupones** (menú Cupones; solo los activos, sin escribirlo a mano); las notas son un área de hasta 2,000 caracteres. Un resumen en vivo muestra el valor total del lote.

**Auditoría (`/admin/audit`):** se guarda *todo* movimiento del panel con fecha, usuario, IP y navegador:
- **Clics y consultas:** cada página abierta y cada acción enviada (ruta, método, resultado, datos enviados sin contraseñas, tokens, PIN ni códigos; resumen en español).
- **Cambios de datos:** altas, ediciones y bajas con el valor **antes y después** (lotes, clientes, prefijos, reglas, activaciones, cupones, usuarios, roles, límites, parámetros…). Los cambios hechos por la API o por jobs no ensucian esta bitácora (tienen su propio registro de cupones).
- La pantalla filtra por usuario, tipo de registro, movimiento, módulo, fechas y texto; cada fila se abre para ver el detalle técnico y exporta a CSV. La retención la controla `pos:prune-logs`.

## Lista de cupones y detalle completo

**Cupones (`/admin/coupons`):** QR y de barras en una sola lista; el tipo se muestra como un icono junto al número y se puede filtrar por tipo, estado, cliente, lote, vencimiento y número. Cada tipo conserva su permiso (`coupons.view`, `coupon_barcodes.view`): quien solo tiene uno ve solo ese tipo.

**Detalle (`/admin/coupons/{qr|barcode}/{id}`):** al hacer clic en un cupón se ve su historial completo, lo más reciente primero: creación (lote, creador, monto inicial), asignación y activación, cada cobro, recarga y reversa (monto, **localidad**, dispositivo, usuario, IP y **saldo tras el movimiento**), ajustes y cambios de estado con su motivo y el antes/después, los cambios de datos de la auditoría del panel y quién consultó el cupón. Incluye un resumen (cobrado neto, recargado, localidades donde se usó) y respeta el alcance por cliente. El token de los QR nunca se muestra.

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
