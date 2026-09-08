FROM composer:2 AS vendor
WORKDIR /app
COPY api/composer.json api/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

FROM php:8.4-apache
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libpq-dev libzip-dev unzip \
    && docker-php-ext-install bcmath intl pdo_pgsql pgsql zip \
    && a2enmod rewrite \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY api/ .
COPY --from=vendor /app/vendor ./vendor
COPY database/migrations /var/www/database/migrations
# Worker deployment CoreERP menjalankan skrip ini tanpa memeriksa keberadaannya lebih dahulu.
COPY deploy/migrate.sh /coreerp/migrate.sh
RUN sed -i 's/\r$//' /coreerp/migrate.sh && chmod +x /coreerp/migrate.sh
RUN php artisan package:discover --ansi && chown -R www-data:www-data storage bootstrap/cache
