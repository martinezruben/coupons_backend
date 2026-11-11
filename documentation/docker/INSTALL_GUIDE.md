# Guía de Instalación Limpia — Backend Coupify POS (Coupify + MySQL en Docker)

> Documento de referencia para equipos y asistentes de IA. Captura TODO lo aprendido al hacer correr el backend Coupify (`pos-backend-coupify`) en contenedores Docker desde cero, incluyendo los errores que aparecen y cómo evitarlos.

---

## 1. Requisitos previos

| Herramienta | Versión mínima | Notas |
|-------------|----------------|-------|
| Docker | 24.x | Engine + Compose plugin (v5+) |
| (Opcional) Android Studio | — | Solo si se usa su JDK interno |

> No se requiere instalar PHP, Composer, Node ni MySQL localmente: **todo corre dentro de contenedores**.

---

## 2. Estructura de archivos

```
pos-backend-coupify/
├── Dockerfile                  # Imagen Coupify (nginx+php-fpm) (multietapa simplificada, 1 solo stage Alpine)
├── docker-compose.yml          # 3 servicios: app, mysql, phpmyadmin
├── .env                        # Variables de entorno (NO versionar)
├── .dockerignore
├── documentation/
│   └── docker/
│       └── INSTALL_GUIDE.md  # ← Este archivo
├── docker/
│   ├── supervisord.conf
│   ├── nginx/default.conf
│   ├── php/local.ini
│   └── entrypoint.sh
└── (código del repo)
```

---

## 3. Pasos de instalación (limpios)

### A. Clonar repositorio y entrar al directorio
```bash
git clone https://github.com/martinezruben/pos-backend-coupify.git
cd pos-backend-coupify
```

### B. Crear `.env` para Docker
Usar MySQL (no SQLite) — ajustar las variables `DB_*`:
```bash
DB_CONNECTION=mysql
DB_HOST=mysql            # nombre del servicio compose
DB_PORT=3306
DB_DATABASE=kopagpos
DB_USERNAME=kopagpos
DB_PASSWORD=secret
DB_SOCKET=
```
> El `APP_KEY` se genera automáticamente en build (no dejarlo vacío).

### C. Build + levantar stack
```bash
docker compose up -d --build
```
Esto:
1. Construye la imagen `kopagpos-app:latest` (PHP 8.4 + exts + Composer 2.9 + npm/Vite).
2. Descarga e inicia MySQL 8.4 (volumen `db_data`).
3. Descarga e inicia phpMyAdmin.

### D. Verificar
```bash
# Estado de contenedores
docker compose ps -a

# Migraciones (debe decir "Migration completed")
docker compose exec app php artisan migrate:status

# HTTP
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8082/admin/login  # → 200
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8081             # → 200 (phpMyAdmin)
```

### E. Crear el primer administrador
Una instalación nueva no trae usuarios del panel. Crea el primer super-admin desde la consola (pide la contraseña sin mostrarla; debe cumplir la política configurada, mínimo 8 caracteres por defecto):
```bash
docker compose exec app php artisan admin:create admin@tuempresa.com --name="Administrador"
```
Luego entra en `http://localhost:8082/admin/login`. Desde **Sistema → Usuarios administradores** y **Sistema → Roles** (solo super-admin) se crean el resto de usuarios y roles.

Si se pierde el acceso:
```bash
docker compose exec app php artisan admin:reset-password admin@tuempresa.com --activate --reset-2fa
```
Esto cambia la contraseña, reactiva la cuenta, reinicia su verificación en dos pasos y cierra sus sesiones abiertas. Evita `--password=...` en servidores compartidos: queda en el historial de la consola.

---

## 4. Puertos (verificar colisiones antes de levantar)

| Servicio | Puerto interno | Puerto anfitrión | Nota |
|----------|----------------|------------------|------|
| App (nginx) | 80 | **8082** | 8080 está reservado para Portainer |
| MySQL | 3306 | 127.0.0.1:3306 | Solo desde el servidor |
| phpMyAdmin | 80 | 127.0.0.1:**8081** | Solo desde el servidor; pide usuario y clave de la BD |
| Caddy (perfil `https`) | 80 / 443 | 80 / 443 | Opcional, HTTPS automático |

MySQL y phpMyAdmin no quedan expuestos a la red. Para usarlos desde tu equipo abre un túnel SSH:

```bash
ssh -L 8081:127.0.0.1:8081 -L 3306:127.0.0.1:3306 usuario@servidor
# luego: http://localhost:8081 (phpMyAdmin)
```

---

## 5. Errores conocidos y cómo evitan el paso 3

| # | Síntoma | Causa | Fix |
|---|---------|-------|-----|
| 1 | `Vite manifest not found` / login 500 | `package.json` excluido por `.dockerignore` | **No excluir** `package.json`, `vite.config.js`, `tailwind.config.js` del `.dockerignore` |
| 2 | `composer install` exit 100 / `plugin-api-version 2.9.0` | `composer:2` (v2.8) incompatible con lock | Usar `COPY --from=composer:2.9` |
| 3 | `... requires php >=8.4` | `composer.lock` hecho con PHP 8.4 | Runtime: `php:8.4-fpm-alpine` (no 8.3) |
| 4 | `unexpected jvm signature V` / KSP2 fail | N/A (era problema del backend) | — |
| 5 | `Unable to load dynamic library 'gd'/'intl'/'zip'` | `apk del .build-deps` borra libs runtime | `apk add --no-cache libpng libzip icu-libs libjpeg-turbo freetype` **después** de borrar .build-deps |
| 6 | MySQL `Restarting (1)` `unknown variable default-authentication-plugin` | Var eliminada en MySQL 8.4 | Quitar la opción; usar `--character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci` |
| 7 | MySQL `Table 'mysql.plugin' doesn't exist` | Volumen `db_data` corrupto | `docker compose down -v` para destruir volumen |
| 8 | `apt-get: not found` en builder | `composer:2` es Alpine sin apt | No usar `composer:2` como builder; instalar exts en Alpine con `docker-php-ext-install` |
| 9 | nginx `[emerg] "server" directive is not allowed here` | `default.conf` en `conf.d/` (fuera de `http{}`) | Copiar a `/etc/nginx/http.d/` y borrar `/etc/nginx/conf.d/default.conf` |
| 10 | App arranca pero HTTP 000 | Supervisord no crea `/var/log/supervisor`, o php-fpm no corre | `mkdir -p /var/log/supervisor`; supervisord.conf con `nodaemon=true`, `command=php-fpm -F` |
| 11 | `MissingAppKeyException` | APP_KEY vacío y entrypoint lo genera mal (duplica) | Generar `APP_KEY` en build (sed en .env) y no tocar en runtime; entrypoint solo verifica |
| 12 | `env: can't execute 'bash': No such file or directory` | Entrypoint usa `#!/usr/bin/env bash` pero Alpine tiene `sh` | Usar `#!/bin/sh` en `entrypoint.sh` |
| 13 | phpmyadmin 500 / mysql auth | MySQL 8.4 usa `caching_sha2_password` por defecto | phpMyAdmin moderno lo soporta; no usar `mysql_native_password` |

---

## 6. Comandos útiles de operación

```bash
# Ver logs en vivo
docker compose logs -f app

# Entrar al contenedor
docker compose exec app sh

# Ejecutar artisan
docker compose exec app php artisan [comando]

# Parar todo
docker compose down -v          # incluye borrar volúmenes (MySQL datos)

# Reconstruir sin cache
docker compose build --no-cache

# Ver migraciones
docker compose exec app php artisan migrate:status
```

---

## 7. Producción: HTTPS y credenciales

`./deploy.sh` genera un `.env` de producción (`APP_ENV=production`, `APP_DEBUG=false`) con contraseñas aleatorias para la BD y para `root`. Si el `.env` ya existía, revisa estos valores a mano:

```dotenv
APP_ENV=production
APP_DEBUG=false
DB_PASSWORD=<aleatoria, no "secret">
MYSQL_ROOT_PASSWORD=<aleatoria>
```

> `MYSQL_ROOT_PASSWORD` solo se aplica al crear el volumen de datos. En una base ya creada, cambia las contraseñas con `ALTER USER` dentro de MySQL (`./deploy.sh db`) y luego actualiza el `.env`.

### HTTPS con Caddy (certificados automáticos)

1. Apunta el DNS del dominio a este servidor y deja libres los puertos 80 y 443.
2. En `.env`:
   ```dotenv
   DOMAIN=pos.tu-dominio.com
   APP_URL=https://pos.tu-dominio.com
   TRUSTED_PROXIES=*
   SESSION_SECURE_COOKIE=true
   ```
3. Levanta el stack con el perfil `https`:
   ```bash
   docker compose -p kopagpos --profile https up -d
   php artisan config:clear   # dentro del contenedor app
   ```
4. Cierra el puerto `8082` al exterior (firewall), o publícalo solo en `127.0.0.1`, para que todo el tráfico pase por Caddy. `TRUSTED_PROXIES=*` solo es seguro si la app no es accesible directamente.

Caddy obtiene y renueva los certificados de Let's Encrypt solo y agrega HSTS. Nginx ya envía `X-Frame-Options`, `X-Content-Type-Options` y `Referrer-Policy`.

## 8. Pendientes de producción

- [x] `APP_ENV=production` y `APP_DEBUG=false` en el `.env` generado.
- [x] HTTPS (perfil `https` con Caddy).
- [x] MySQL y phpMyAdmin solo accesibles desde el servidor.
- [ ] Generar `APP_KEY` via secret, no en .env plano.
- [ ] Backup automático del volumen `db_data` (`docker run --rm -v kopagpos-backend_db_data:/src ...` o `mysqldump`).
- [ ] Rotar el `ghp_...` token de GitHub expuesto en el remote `pos-kopagpos`.
