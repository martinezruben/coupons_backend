#!/bin/bash
# =============================================================================
# deploy.sh — Automatiza la instalación y despliegue limpio del backend
#             Tap&Go POS en Docker.
#
# Uso:
#   chmod +x deploy.sh
#   ./deploy.sh          # build + levanta todo + health check
#   ./deploy.sh stop     # detiene y elimina contenedores (+ volúmenes opcional)
#   ./deploy.sh logs     # tail de logs del app
#   ./deploy.sh ssh      # entra al contenedor app
#   ./deploy.sh db       # entra a MySQL
#
# Requisitos: Docker + Docker Compose (plugin) instalados.
# =============================================================================

set -euo pipefail

# Colores / helpers
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
log()  { echo -e "${BLUE}[deploy]${NC} $*"; }
ok()   { echo -e "${GREEN}[OK]${NC} $*"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $*"; }
err()  { echo -e "${RED}[ERROR]${NC} $*" >&2; }

# Directorio del script (asume que este archivo está en la raíz del repo)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

PROJECT_NAME="kopagpos"
APP_PORT=${APP_PORT:-8082}
PMA_PORT=${PMA_PORT:-8081}
DB_PORT=${DB_PORT:-3306}

# -----------------------------------------------------------------------------
# Helpers de estado
# -----------------------------------------------------------------------------
check_port() {
    local port="$1"; local name="$2"
    if ss -ltn 2>/dev/null | grep -q ":${port}\b"; then
        warn "Puerto ${port} (${name}) está en uso — se usará aun así."
        return 1
    fi
    return 0
}

compose() {
    docker compose -p "$PROJECT_NAME" "$@"
}

# -----------------------------------------------------------------------------
# Pre-flight: validar entorno
# -----------------------------------------------------------------------------
preflight() {
    log "Validando entorno..."
    command -v docker >/dev/null || { err "Docker no está instalado"; exit 1; }

    # docker compose v2 plugin
    if docker compose version >/dev/null 2>&1; then
        ok "Docker Compose v2 disponible"
    else
        err "Docker Compose plugin no encontrado (docker compose)"; exit 1
    fi

    # Colisiones de puerto (solo warn)
    check_port "$APP_PORT" "App"  || true
    check_port "$PMA_PORT" "phpMyAdmin" || true
    check_port "$DB_PORT"  "MySQL"  || true

    ok "Entorno válido"
}

# -----------------------------------------------------------------------------
# Genera .env si no existe (MySQL, puerto 8082, APP_KEY generado)
# -----------------------------------------------------------------------------
# Contraseña aleatoria para la BD (el .env de producción no debe usar valores por defecto)
random_secret() {
    openssl rand -hex 16 2>/dev/null || php -r 'echo bin2hex(random_bytes(16));'
}

ensure_env() {
    if [ -f .env ]; then
        ok ".env ya existe"
    else
        log "Generando .env inicial..."
        cat > .env <<EOF
APP_NAME=Tap&Go
APP_ENV=production
APP_KEY=$(php -r 'echo "base64:".base64_encode(random_bytes(32));' 2>/dev/null || echo "base64:$(openssl rand -base64 32)")
APP_DEBUG=false
# Con HTTPS delante (proxy o nginx con TLS): APP_URL=https://tu-dominio y SESSION_SECURE_COOKIE=true
APP_URL=http://localhost

APP_TIMEZONE=America/Santo_Domingo
APP_LOCALE=es
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=${MYSQL_DATABASE:-kopagpos}
DB_USERNAME=${MYSQL_USER:-kopagpos}
DB_PASSWORD=${MYSQL_PASSWORD:-$(random_secret)}
DB_SOCKET=
MYSQL_ROOT_PASSWORD=$(random_secret)

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

PULSE_ENABLED=true
CACHE_STORE=database

MAIL_MAILER=log
MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="\${APP_NAME}"

VITE_APP_NAME="\${APP_NAME}"
EOF
        ok ".env generado"
    fi
}

# -----------------------------------------------------------------------------
# Build + up
# -----------------------------------------------------------------------------
deploy_up() {
    preflight
    ensure_env

    log "Construyendo imágenes (esto puede tardar varios minutos la primera vez)..."
    compose build --no-cache
    ok "Imagen construida"

    log "Levantando stack..."
    compose up -d

    log "Esperando a que los servicios estén listos (hasta 90s)..."
    local waited=0
    while [ $waited -lt 90 ]; do
        # MySQL listo?
        if docker compose -p "$PROJECT_NAME" exec -T mysql mysqladmin ping -h 127.0.0.1 --silent 2>/dev/null | grep -q "alive"; then
            ok "MySQL está listo"
            break
        fi
        sleep 3; waited=$((waited+3))
    done

    sleep 10  # app container startup (entrypoint: migrate + cache)

    # Migraciones (best-effort; la BD puede tardar)
    log "Ejecutando migraciones..."
    compose exec -T app php artisan migrate --force --no-interaction 2>/dev/null || \
        warn "migrate falló (revisar logs). El entrypoint ya las corre."

    health_check
}

# -----------------------------------------------------------------------------
# Health check
# -----------------------------------------------------------------------------
health_check() {
    echo ""
    log "=== Estado de contenedores ==="
    compose ps --format "table {{.Name}}\t{{.Status}}\t{{.Ports}}"

    echo ""
    log "=== Health check HTTP ==="
    local app_code pma_code
    app_code=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:${APP_PORT}/admin/login" 2>/dev/null || echo "000")
    pma_code=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:${PMA_PORT}" 2>/dev/null || echo "000")

    if [ "$app_code" = "200" ]; then
        ok "App Tap&Go: http://localhost:${APP_PORT}  → ${app_code}"
    else
        err "App Tap&Go: http://localhost:${APP_PORT}  → ${app_code} (Falla)"
    fi
    if [ "$pma_code" = "200" ]; then
        ok "phpMyAdmin:  http://localhost:${PMA_PORT}  → ${pma_code}"
    else
        err "phpMyAdmin:  http://localhost:${PMA_PORT}  → ${pma_code}"
    fi

    echo ""
    log "=== Accesos ==="
    echo "  App:        http://localhost:${APP_PORT}/admin/login"
    echo "  phpMyAdmin: http://localhost:${PMA_PORT}  (solo desde el servidor; usuario y clave de la BD en .env)"
    echo "  MySQL:      127.0.0.1:${DB_PORT}  (solo desde el servidor)"
}

# -----------------------------------------------------------------------------
# Sub-comandos
# -----------------------------------------------------------------------------
case "${1:-up}" in
    up)
        deploy_up
        ;;
    stop)
        log "Deteniendo stack..."
        compose down -v 2>/dev/null || compose down
        ok "Stack detenido"
        ;;
    restart)
        compose restart
        sleep 10
        health_check
        ;;
    logs)
        compose logs -f "${2:-app}"
        ;;
    ssh)
        compose exec app sh
        ;;
    db)
        # Credenciales desde el propio contenedor de MySQL (vienen del .env)
        compose exec mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
        ;;
    rebuild)
        compose build --no-cache
        compose up -d --force-recreate
        sleep 15
        health_check
        ;;
    *)
        echo "Uso: $0 {up|stop|restart|logs [app]|ssh|db|rebuild}"
        exit 1
        ;;
esac
