#!/bin/sh
set -e
cd /var/www/html

# Create temp directory before starting
mkdir -p /tmp/php
chmod 1777 /tmp/php

# Set temp directory environment variable
export TMPDIR=/tmp/php

# Wait for MySQL to be ready
echo "Waiting for MySQL..."
max_retries=30
retry=0
# Credenciales desde el entorno (.env vía env_file), no escritas en el script
while ! php -r 'new PDO("mysql:host=".getenv("DB_HOST").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' 2>/dev/null; do
    retry=$((retry + 1))
    if [ $retry -ge $max_retries ]; then
        echo "MySQL connection failed after $max_retries attempts"
        exit 1
    fi
    echo "MySQL not ready, retrying... ($retry/$max_retries)"
    sleep 1
done
echo "MySQL is ready!"

# Asegurar APP_KEY (definido en build, pero verifica)
if ! grep -q '^APP_KEY=base64:' .env; then
    echo "WARN: APP_KEY no encontrado -> generando"
    php artisan key:generate --force --show 2>&1 || true
fi

# Limpiar config cache (tests dependen de APP_ENV=testing del .env/phpunit)
php artisan config:clear --no-interaction 2>/dev/null || true
php artisan optimize:clear --no-interaction 2>/dev/null || true
# Symlink storage:link (imágenes/familias/productos visibles en grid)
if [ ! -L public/storage ]; then
    php artisan storage:link 2>/dev/null || true
fi

# Descubrir paquetes (necesario tras composer install --no-scripts)
php artisan package:discover --ansi 2>&1 || echo "WARN: package:discover falló"

echo "Tap&Go listo."
exec "$@"