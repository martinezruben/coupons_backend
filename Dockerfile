FROM php:8.4-fpm-alpine
LABEL maintainer="TapGo POS"

# Extensiones del sistema + librerías para compilar PHP exts
RUN apk add --no-cache --virtual .build-deps \
        autoconf build-base icu-dev freetype-dev libjpeg-turbo-dev libpng-dev \
        libwebp-dev \
        libzip-dev oniguruma-dev \
    && apk add --no-cache \
        nginx supervisor git zip unzip nodejs npm \
        curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) pdo_mysql mysqli gd intl zip bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && mkdir -p /var/log/supervisor /run/nginx

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .

# Backend deps
RUN --mount=type=cache,target=/tmp/cache-composer \
    composer install --no-interaction --no-progress --prefer-dist --no-scripts && \
    composer dump-autoload --optimize --no-scripts && \
    php artisan package:discover --ansi

# Frontend assets (Vite)
RUN if [ -f package.json ]; then \
        npm ci --prefer-offline 2>/dev/null || npm install --no-audit --no-fund && \
        npm run build; \
    fi

# Create writable temp directory for PHP operations
RUN mkdir -p /tmp/php && chmod 1777 /tmp/php

# Permisos + libs de runtime
RUN chown -R www-data:www-data /var/www/html && \
    find /var/www/html -type f -exec chmod 644 {} \; && \
    find /var/www/html/storage -type f -exec chmod 664 {} \; && \
    find /var/www/html/storage -type d -exec chmod 775 {} \; && \
    find /var/www/html/bootstrap/cache -type d -exec chmod 775 {} \; && \
    apk add --no-cache libpng libzip icu-libs libjpeg-turbo freetype libwebp && \
    apk del .build-deps

COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
RUN rm -f /etc/nginx/conf.d/default.conf
COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

COPY --chown=www-data:www-data docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Set PHP temp directory by default
ENV TMPDIR=/tmp/php

EXPOSE 80
ENTRYPOINT ["entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf", "-n"]