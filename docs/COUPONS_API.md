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

Las reglas viven en **Cupones → Reglas de uso** (solo el super-admin las edita). Sin reglas de un tipo, ese tipo no restringe nada; las reglas inactivas se ignoran y cada regla puede limitarse a cupones QR o de barras:

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

## Reglas de uso (condiciones asignadas a cupones)

Las reglas de uso se **asignan a cupones**: por lote, por rango de fechas (emisión o vencimiento), por número de cupón (hasta 5,000) o a todos. Una regla solo se aplica a los cupones asignados; el detalle de un cupón lista las reglas que le aplican.

Una regla suma las condiciones que se activen y **todas deben cumplirse**: localidades, días de la semana, horario (puede cruzar la medianoche), tope por recarga y tope por día y cupón. Con varias reglas activas basta con que **una** se cumpla completa; sin reglas no hay restricciones. Pueden aplicar a QR, a barras o a ambos. Las recargas desde el panel no tienen localidad y no evalúan esa condición. Las reglas anteriores de un solo tipo siguen funcionando igual y pasan al formato nuevo al editarlas.

## Permisos específicos por usuario

`/admin/rbac/users` (solo super-admin, menú Configuración): además de los permisos de sus roles, a una persona se le pueden dar permisos directos con la misma matriz que los roles. Los que ya vienen de un rol aparecen en gris y no se quitan aquí. Un super-admin no necesita permisos. Los botones del detalle de un cupón tienen cada uno su permiso por tipo de cupón: ver historial, activar, desactivar, recargar y ajustar saldo. La pantalla trae una sección **Permisos independientes**: acciones que se conceden una por una sin dar acceso a editar (ajustar saldo, recargar, revertir cobros, anular lotes, descargar, ver el historial completo de un cupón, exportar CSV…), con las sensibles marcadas. Cada cambio guarda en la auditoría qué se agregó y qué se quitó, y las visitas a la pantalla quedan como consultas.

## Localidad del canje y auditoría de cupones

**Localidad:** `/api/transaction`, `/api/transactioncb`, `/api/topup` y `/api/topupcb` aceptan un `location_id` opcional (uuid de una localidad existente) que envía el dispositivo Android. Si no viene, se usa la localidad del dispositivo registrado (`Device-Fingerprint`). La transacción guarda la localidad y su origen (`location_source`: `request` o `device`); las reglas de uso de localidad y las reversas usan/heredan ese valor. Los reportes por lote y por cliente (y su CSV) muestran **dónde se canjeó** (neto por localidad), y el detalle del cupón indica la localidad de cada movimiento.

**Auditoría de cupones:** cada fila tiene **Ver detalle**, un modal con la acción explicada, quién la hizo, el cupón, el antes y después, el comentario completo (hasta ahora se recortaba a 255), el lote, la activación (modo, cliente, selección, fechas) y lo que se envió en el panel.

## MFA obligatorio y registro de dispositivos con doble aprobación

**MFA:** todos los administradores deben tener la verificación en dos pasos (TOTP) activa. Tras iniciar sesión, quien aún no la tiene solo puede abrir la pantalla para activarla (QR + código + códigos de recuperación); no se puede desactivar. Si un super-admin reinicia el 2FA de alguien, esa persona debe activarlo de nuevo al entrar. Se controla con `ADMIN_MFA_REQUIRED` (por defecto `true`; en pruebas `false`). Al activarlo en producción, todas las cuentas sin 2FA serán obligadas a configurarlo en su próximo acceso.

**Registrar un dispositivo (Dispositivos → «+ Registrar dispositivo»):** requiere el permiso `devices.register` (acción independiente en la matriz y en permisos por usuario). El formulario pide el identificador del dispositivo, un nombre opcional y la **localidad** a la que se asigna, y además el **autenticador de dos administradores distintos** con ese permiso y su 2FA activo (se elige a cada persona y se escribe su código de 6 dígitos). Un código no se puede reutilizar y los intentos fallidos se limitan por persona. El alta crea el dispositivo y su licencia y queda en la auditoría (quién registró y quién aprobó).

**Vinculación:** un dispositivo desconocido ya **no** puede darse de alta solo con el código de la localidad (`/api/auth/register-device` responde `DEVICE_NOT_REGISTERED`); el terminal registrado en el panel se vincula con el código de *su* localidad, que se obtiene desde **Dispositivos** (icono de llave en la fila del dispositivo); ya no se pide desde Localidades. `POS_ALLOW_DEVICE_SELF_REGISTRATION=true` restaura el comportamiento anterior.

## Menú del panel administrable

**Configuración → Menú del panel** (solo super-admin; `/admin/menu`): edita la barra de navegación sin tocar código. Los **grupos** (padres) se pueden crear, renombrar, cambiar de icono, ordenar, ocultar y eliminar (si no tienen pantallas). Las **opciones** (hijos: pantallas y accesos como Permisos por rol o Parámetros) se renombran, cambian de icono, se reordenan, se mueven a otro grupo o se ocultan; además se pueden agregar **enlaces propios** (una página del sitio con «/…» o una dirección «https://…», con permiso opcional y opción de abrir en otra pestaña). Una vista previa muestra el resultado antes de guardar.

Reglas: el menú se guarda en `menu_groups` y `menu_items` (sembrados con el menú original); cada pantalla aparece una sola vez (se mueve, no se quita); el propio editor no se puede ocultar; pantallas nuevas del código que aún no estén en el menú guardado se añaden solas a su grupo; «Restablecer» vuelve al menú original. Ocultar una opción solo la saca de la barra: los permisos siguen mandando sobre quién entra a cada pantalla. Cada cambio queda en la auditoría.

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

## Controles de cobro y seguridad

- **Límites de velocidad** (`config/coupons.php` → `limits`, variables `COUPONS_LIMIT_*`; `0` = sin límite): cobros por cupón al día (10 por defecto), monto diario por usuario y por localidad, y un monto desde el cual el cobro exige la habilidad `coupon:redeem_large` (MANAGER/ADMIN). Al superarlos la API responde 429 (o 403 para el monto grande) con `limit` en el cuerpo y se genera una alerta.
- **Separación de funciones:** quien hizo un cobro no puede revertirlo (`COUPONS_SELF_REVERSE_ALLOWED`); pasadas `COUPONS_REVERSE_WINDOW_HOURS` (72) solo un ADMIN revierte. Fijar el saldo (`/restore` y «Ajustar saldo» del panel) crea una **solicitud** (API: 202 `pending_approval`) que aprueba otro administrador en Cupones → Ajustes de saldo (permiso `coupon_adjustments.approve`; nadie aprueba lo que pidió). `COUPONS_RESTORE_REQUIRES_APPROVAL=false` vuelve al ajuste inmediato.
- **Alertas** (Cupones → Alertas de seguridad, `coupon_security_alerts.view/resolve`): cobro bloqueado por límite, cupón cobrado en dos localidades en pocos minutos, cobros fuera de horario (`COUPONS_ALERT_BUSINESS_HOURS="7-22"`), reversas repetidas, intentos de revertir lo propio, consultas fallidas seguidas (enumeración) y ajustes pendientes. Las de severidad alta se envían por correo a los super-administradores. Una misma alerta no se repite durante 10 minutos.
- Permisos nuevos (sin asignar a ningún rol, solo super-admin): `coupon_adjustments.view/approve`, `coupon_security_alerts.view/resolve`.

## Dispositivo, idempotencia y parámetros

- **Obligatorios por defecto:** `Idempotency-Key` en cobros y recargas, y `Device-Fingerprint` de un dispositivo registrado, habilitado, con licencia y localidad activas. Un 401/403/429 no guarda la clave: se puede reintentar con la misma cuando se corrija.
- **Localidad:** con dispositivo registrado se usa siempre la del dispositivo; el `location_id` que envíe el cliente se ignora (solo cuenta si no hay dispositivo).
- **Usuario y localidad:** el usuario debe tener esa localidad (la principal o una de su lista de localidades); un ADMIN opera en cualquiera. Si no, 403 y alerta `user_wrong_location`.
- **`POST /transaction` y `/transactioncb`** devuelven `transaction_id` (primer movimiento del cobro), `transaction_ids`, `date` (ISO 8601) y un `transaction_id` por cupón.
- **Parámetros:** todos los `COUPONS_*` se editan en Parámetros del sistema → «Cupones: cobro, límites y alertas». Lo guardado allí pisa a `.env`, se aplica de inmediato y queda en la auditoría.

## Dispensadores, sesiones y logs

- **Dispensadores:** cada localidad tiene «Número de dispensadores» (entero de 1 a 20, 1 por defecto; se edita en Localidades). Baja a la app en `POST /api/login` y `GET /api/profile` del usuario (`dispensers` y `location`, con la localidad del dispositivo si viene `Device-Fingerprint`, o la del usuario) y en la API de dispositivos (`/auth/register-device`, `/auth/login`, `/auth/me`: `location_id`, `location_name`, `dispensers`).
- **Usuarios del panel vs. usuarios de la app:** los administradores (`admin_users`, guard `admin`) usan el panel; los usuarios del POS (`users`, por localidad) usan la app y la API de cupones. Las sesiones se gestionan por separado: *Cerrar sus sesiones* en cada ficha.
- **Bloqueo de cuenta (usuarios de la app):** tras `login_max_attempts` (5) intentos fallidos la cuenta se bloquea `login_lockout_minutes` (15) y se cierran sus sesiones; la respuesta sigue siendo un 401 genérico. Un login correcto reinicia el contador; el administrador desbloquea desde Usuarios de la app. Genera la alerta `user_locked`.
- **Sesiones:** cambiar la contraseña o desactivar a un usuario de la app revoca sus tokens; también hay un botón *Cerrar sus sesiones*.
- **Logs de peticiones:** los números de cupón (`coupon_number`, `coupons`) se guardan enmascarados (`********9012`).

## Errores de la API

Todos los errores de `/api/*` son JSON con `error` (texto en inglés, para personas) y `code` (estable: la app lo traduce). Los 422 de validación añaden `message` y `errors`; los 429, `retry_after` (segundos).

| `code` | HTTP | Cuándo |
|---|---|---|
| `invalid_credentials` | 401 | Login fallido, usuario inactivo o cuenta bloqueada (misma respuesta) |
| `unauthenticated` | 401 | Falta el token, venció o se revocaron las sesiones |
| `forbidden` | 403 | El rol no tiene la habilidad |
| `device_not_authorized` | 403 | Falta `Device-Fingerprint` o el dispositivo no está habilitado/licenciado |
| `user_wrong_location` | 403 | El usuario no pertenece a la localidad del dispositivo |
| `supervisor_approval_required` | 403 | Monto por encima del umbral de supervisor |
| `cannot_reverse_own_transaction` | 403 | Quien cobró no puede revertir |
| `coupon_not_found` | 404 | No existe (o el token QR no coincide) |
| `coupon_inactive_or_expired` | 422 | Cupón inactivo, vencido o sin activar |
| `insufficient_balance` | 400 | Saldo menor al cobro (`balance`, `amount_required`) |
| `coupon_daily_limit_reached`, `user_daily_limit_reached`, `location_daily_limit_reached` | 429 | Límites de velocidad |
| `balance_limit_exceeded`, `topup_*` | 422 | Reglas de recarga |
| `reversal_window_expired`, `transaction_not_reversible`, `transaction_already_reversed` | 422/409 | Reversas |
| `idempotency_key_required`, `idempotency_key_invalid`, `idempotency_key_reused`, `request_in_progress` | 400/422/409 | `Idempotency-Key` |
| `validation_failed` | 422 | Datos inválidos |
| `rate_limited` | 429 | Demasiadas peticiones |
| `not_found` | 404 | Ruta o recurso inexistente |

## Contrato de la app (v1.1.0 en adelante)

**Cabeceras en toda petición de cupones:** `Authorization: Bearer <token del usuario>`, `Device-Fingerprint`, `App-Version` (p. ej. `1.1.0`) y, en cobros y recargas, `Idempotency-Key` (UUID, 8 a 100 caracteres `A-Za-z0-9._:-`).

**426 por versión mínima.** Si en Parámetros del sistema hay una «Versión mínima de la app» y `App-Version` es menor, o no viene, `/login` y todas las rutas de cupones responden `426 {"error":"App update required","code":"app_update_required","min_app_version":"1.1.0","app_version":"1.0.4"}`. Sin versión mínima no se bloquea nada; se apaga con *Bloquear apps por debajo de la versión mínima*. No aplica a `/auth/*` (emparejar y renovar el dispositivo).

**Surtidores.** `dispensers` (0 a 20) viaja tal cual: `0` = la localidad no usa surtidores. En `POST /transaction` y `/transactioncb` el campo opcional `dispenser` (entero) debe estar entre 1 y el número de surtidores de la localidad del dispositivo (si no: 422 `invalid_dispenser`); con 0 surtidores se ignora; con *Exigir el surtidor* activo y sin enviarlo: 422 `dispenser_required`. Queda guardado en el movimiento (columna «Surtidor» en Cobros de cupones) y se devuelve en la respuesta.

| Operación | Ruta | Rol (habilidad) | Cuerpo | Respuesta 200 |
|---|---|---|---|---|
| Cobro | `POST /transaction`, `/transactioncb` | CASHIER+ (`coupon:redeem`) | `amount` (texto o número, 2 decimales), `coupons[]`, `tokens[]` (QR), `dispenser?` | `transaction_id`, `transaction_ids[]`, `date`, `dispenser`, `coupons[{coupon_number, transaction_id, balance_initial, balance, transaction_amount}]`, `sumbalance`, `sumtransaction_amount` |
| Recarga | `POST /topup`, `/topupcb` | MANAGER+ (`coupon:topup`) | `coupon_number`, `coupon_token` (QR), `amount` | `coupon_number`, `date`, `topup_amount`, `balance`, `transaction_id` |
| Reversa | `POST /transactions/{id}/reverse` | MANAGER+ (`coupon:reverse`) | `reason` (5 a 255) | `transaction_id` (la reversa), `original_transaction_id`, `date`, `coupon_number`, `balance` |
| Fijar saldo | `POST /restore`, `/restorecb` | ADMIN (`coupon:restore`) | `coupon_number`, `coupon_amount` (el saldo nuevo, no mayor al monto del cupón), `reason` | Aprobación activa (por defecto): **202** `{coupon_number, status:"pending_approval", request_id}`; sin ella: `coupon_number`, `date`, `balance` |

Reglas de las operaciones restringidas: quien hizo un cobro no lo revierte; pasadas 72 h solo un ADMIN revierte; una reversa solo se hace una vez (409 `transaction_already_reversed`); fijar saldo queda pendiente hasta que otro administrador del panel lo apruebe. Recargas y reversas siguen las reglas de recarga y los límites; los errores llevan `code` (ver *Errores de la API*).

## Login con dispositivo y datos de la venta

- **`POST /login`** (con `COUPONS_REQUIRE_DEVICE_BINDING` activo) exige `Device-Fingerprint` de una terminal registrada, habilitada y con licencia, y que el usuario pertenezca a su localidad (su localidad principal o una de su lista; un ADMIN entra en cualquiera). Se evalúa solo con la clave ya verificada, así que una clave incorrecta sigue dando el 401 genérico. Errores: 403 `device_not_authorized` o 403 `user_wrong_location`.
- **`GET /auth/me`** devuelve `location_id`, `location_name`, `dispensers`, `license_valid_to`; una terminal bloqueada recibe 403 con `code: DEVICE_BLOCKED`.
- **`POST /transaction` y `/transactioncb`** devuelven además `amount` (monto de la venta), `created_at`, `location_id` y `location_name` (de la terminal), para el ticket y la conciliación.
