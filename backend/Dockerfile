FROM php:8.3-apache
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev libicu-dev libonig-dev unzip git ca-certificates clamav clamav-freshclam \
    && docker-php-ext-install pdo_mysql bcmath mbstring zip intl opcache \
    && a2enmod rewrite headers \
    && sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/aqari.conf \
    && a2enconf aqari \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . /var/www/html
WORKDIR /var/www/html
RUN mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache \
    && printf 'upload_max_filesize=12M\npost_max_size=13M\nmemory_limit=256M\nexpose_php=Off\n' > /usr/local/etc/php/conf.d/aqari.ini
COPY .docker/entrypoint.sh /usr/local/bin/aqari-entrypoint
RUN chmod +x /usr/local/bin/aqari-entrypoint
ENTRYPOINT ["aqari-entrypoint"]
CMD ["apache2-foreground"]
