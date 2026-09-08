# Konteks pembangunan adalah AKAR REPO, bukan folder app ini.
#
# Module hidup di `modules/` pada akar repo, dan Composer memasangnya lewat repositori
# bertipe `path` yang menunjuk `../../modules/*/*`. Selama konteks pembangunan masih folder
# app, folder itu berada di luar jangkauan dan pemasangan dependensi gagal. Karena itu image
# ini menirukan susunan repo apa adanya: app di `apps/control-plane`, module di `modules`,
# keduanya di bawah satu akar. Dengan begitu tautan simbolik yang dibuat Composer tetap sah
# di dalam image, dan tidak ada satu pun kode module yang tersalin dua kali.
#
# Bangun dari akar repo:
#   docker build -f apps/control-plane/Dockerfile .

FROM composer:2 AS vendor
WORKDIR /repo
# Dependensi dipasang sebelum sisa kode disalin supaya lapisan ini tidak terbangun ulang
# setiap kali ada satu baris PHP berubah. Manifest module ikut disalin karena Composer
# membacanya saat menyelesaikan repositori path.
COPY apps/control-plane/composer.json apps/control-plane/composer.lock ./apps/control-plane/
COPY modules ./modules
WORKDIR /repo/apps/control-plane
# ext-gd hanya ada pada image runtime di bawah; image composer tidak membawanya.
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --ignore-platform-req=ext-gd
WORKDIR /repo
COPY . .
WORKDIR /repo/apps/control-plane
RUN php artisan wayfinder:generate --with-form --no-interaction

FROM node:22-bookworm-slim AS assets
# Susunan repo ditirukan di sini juga. `@apperp/ui` adalah workspace npm milik repo ini,
# jadi akar pemasangan npm adalah akar repo dan `packages/ui` harus berada di bawahnya —
# bukan folder app. Selama tahap ini masih `WORKDIR /app` dengan hanya isi app tersalin,
# `npm ci` tidak menemukan workspace yang disebut package.json akar dan berhenti sebelum
# satu paket pun terpasang.
WORKDIR /repo
# Wayfinder is generated in the Composer stage because this stage has no PHP.
ENV WAYFINDER_COMMAND=true
# `.npmrc` ikut karena di sanalah `ignore-scripts=true` berada. Tanpa berkas itu setiap
# skrip `install` milik dependensi ikut berjalan di dalam image.
COPY package.json package-lock.json .npmrc ./
COPY apps/control-plane/package.json ./apps/control-plane/
COPY packages/ui ./packages/ui
# npm dapat diam-diam melewatkan optional native binding ketika unduhannya gagal
# (npm/cli#4828): instalasinya tetap melapor sukses, lalu build meledak jauh
# kemudian dengan pesan yang menuduh lockfile, padahal penyebabnya jaringan.
# Pemeriksaan ini membuat kegagalan muncul di langkah yang benar, dan satu kali
# percobaan ulang menutup kasus yang memang cuma unduhan meleset.
RUN npm ci && node --input-type=module -e 'await import("rolldown")' || (rm -rf node_modules && npm ci && node --input-type=module -e 'await import("rolldown")')
WORKDIR /repo/apps/control-plane
COPY apps/control-plane/resources ./resources
COPY --from=vendor /repo/apps/control-plane/resources/js/actions ./resources/js/actions
COPY --from=vendor /repo/apps/control-plane/resources/js/routes ./resources/js/routes
COPY --from=vendor /repo/apps/control-plane/resources/js/wayfinder ./resources/js/wayfinder
COPY apps/control-plane/public ./public
COPY apps/control-plane/vite.config.ts apps/control-plane/tsconfig.json ./
RUN npm run build

FROM php:8.4-apache
# gd dibutuhkan PhpSpreadsheet (layout Excel laporan); zip oleh PhpWord dan PhpSpreadsheet.
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev unzip \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install bcmath gd intl pdo_pgsql pgsql zip \
    && a2enmod rewrite proxy proxy_http \
    && sed -ri 's!/var/www/html!/var/www/repo/apps/control-plane/public!g' /etc/apache2/sites-available/000-default.conf \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /repo
COPY apps/control-plane ./apps/control-plane
COPY modules ./modules
COPY --from=vendor /repo/apps/control-plane/vendor ./apps/control-plane/vendor
COPY --from=assets /repo/apps/control-plane/public/build ./apps/control-plane/public/build
# Susunan repo ditirukan di bawah /var/www/repo supaya `../../modules/*/*` tetap menunjuk
# tempat yang benar dari dalam vendor.
RUN mkdir -p /var/www && ln -s /repo /var/www/repo
WORKDIR /repo/apps/control-plane
RUN php artisan package:discover --ansi && chown -R www-data:www-data storage bootstrap/cache

# CONTAINER_ROLE selects web (default), scheduler, or worker. A deployment needs exactly one scheduler replica,
# otherwise the number sequence recovery job never runs.
RUN sed -i 's/\r$//' docker/entrypoint.sh && chmod +x docker/entrypoint.sh
ENTRYPOINT ["docker/entrypoint.sh"]
