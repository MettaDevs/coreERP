FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# ext-gd hanya ada pada image runtime di bawah; image composer tidak membawanya.
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --ignore-platform-req=ext-gd
COPY . .
RUN php artisan wayfinder:generate --with-form --no-interaction

FROM node:22-bookworm-slim AS assets
WORKDIR /app
# Wayfinder is generated in the Composer stage because this stage has no PHP.
ENV WAYFINDER_COMMAND=true
COPY package.json package-lock.json ./
COPY .packages ./.packages
# npm dapat diam-diam melewatkan optional native binding ketika unduhannya gagal
# (npm/cli#4828): instalasinya tetap melapor sukses, lalu build meledak jauh
# kemudian dengan pesan yang menuduh lockfile, padahal penyebabnya jaringan.
# Pemeriksaan ini membuat kegagalan muncul di langkah yang benar, dan satu kali
# percobaan ulang menutup kasus yang memang cuma unduhan meleset.
RUN npm ci && node --input-type=module -e 'await import("rolldown")' || (rm -rf node_modules && npm ci && node --input-type=module -e 'await import("rolldown")')
COPY resources ./resources
COPY --from=vendor /app/resources/js/actions ./resources/js/actions
COPY --from=vendor /app/resources/js/routes ./resources/js/routes
COPY --from=vendor /app/resources/js/wayfinder ./resources/js/wayfinder
COPY public ./public
COPY vite.config.ts tsconfig.json ./
RUN npm run build

FROM php:8.4-apache
# gd dibutuhkan PhpSpreadsheet (layout Excel laporan); zip oleh PhpWord dan PhpSpreadsheet.
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev unzip \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install bcmath gd intl pdo_pgsql pgsql zip \
    && a2enmod rewrite proxy proxy_http \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
RUN php artisan package:discover --ansi && chown -R www-data:www-data storage bootstrap/cache

# CONTAINER_ROLE selects web (default), scheduler, or worker. A deployment needs exactly one scheduler replica,
# otherwise the number sequence recovery job never runs.
RUN sed -i 's/\r$//' docker/entrypoint.sh && chmod +x docker/entrypoint.sh
ENTRYPOINT ["docker/entrypoint.sh"]
