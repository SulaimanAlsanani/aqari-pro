# Reproducible staging image. This repository contains a Laravel overlay,
# not a complete application: build a clean Laravel 12 + Sanctum base first.
FROM php:8.3-cli-bookworm AS builder
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libpq-dev libicu-dev libzip-dev libonig-dev \
    && docker-php-ext-install pdo_pgsql pgsql intl mbstring zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN composer create-project laravel/laravel /opt/aqari "^12.0" --no-interaction --prefer-dist --no-scripts
WORKDIR /opt/aqari
RUN composer require "laravel/sanctum:^4.0" --no-interaction --prefer-dist --no-scripts
COPY backend/app/ app/
COPY backend/config/ config/
COPY backend/database/migrations/ database/migrations/
COPY backend/bootstrap/app.php bootstrap/app.php
COPY backend/routes/api.php routes/api.php
COPY backend/routes/web.php routes/web.php
RUN mkdir -p public/staging storage/app/private storage/framework/cache/data \
    storage/framework/sessions storage/framework/views bootstrap/cache
COPY backend/frontend/staging.html public/staging/index.html
COPY backend/frontend/*.js public/staging/
RUN composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader \
    && rm -f .env && test ! -e public/.env

FROM php:8.3-apache-bookworm AS staging
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev libicu-dev libzip-dev libonig-dev clamav clamav-freshclam curl \
    && docker-php-ext-install pdo_pgsql pgsql intl mbstring zip \
    && rm -rf /var/lib/apt/lists/*
RUN a2enmod rewrite headers \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
    && printf '<Directory /var/www/html/public>\nAllowOverride All\nRequire all granted\n</Directory>\n' >> /etc/apache2/apache2.conf
COPY --from=builder /opt/aqari /var/www/html
WORKDIR /var/www/html
RUN mkdir -p storage/app/private storage/framework/cache/data \
    storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache \
    && test ! -e .env
# The application is internal-only. All public traffic must cross Caddy HTTPS.
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
  CMD curl --fail --silent --show-error http://127.0.0.1/up >/dev/null || exit 1
