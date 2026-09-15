# Image rilis CoreERP untuk server klien on-prem dikelola: satu image per rilis, berisi Core dan
# seluruh module, ditarik dari Harbor lewat digest. Rancangannya di docs/todo/registry-harbor (IMG-01).
#
# Bangun dari akar repo:
#   docker build -f deploy/perakit/Dockerfile .
#
# ## Kenapa berkas tersendiri, bukan apps/core/Dockerfile
#
# apps/core/Dockerfile juga membangun SaaS dev. Pemilik produk memutuskan pada 15 September 2026 image
# ramping ini dipisah dulu sampai terbukti di server klien; menggabungkannya kelak cukup mengganti
# tahap akhir di sana dengan tahap akhir di sini. Tahap `vendor` dan `assets` sengaja disamakan dengan
# berkas itu supaya yang berbeda hanya runtime.
#
# ## Kenapa tanpa pemangkasan edisi
#
# Satu image untuk semua klien: app yang boleh dibuka tenant dijaga lisensi, bukan dengan membuang
# module dari image. `MODUL` dan `scripts/edisi-pangkas.php` karena itu tidak dipakai di sini.
#
# ## Kenapa Debian slim, bukan php:8.4-apache
#
# Image `php` resmi membawa perkakas kompilasi ($PHPIZE_DEPS) secara permanen — lapisan 355 MB tak
# terkompres yang tidak pernah dipakai di server klien. Diukur 15 September 2026 pada Core dan seluruh
# module: 237,1 MB terkompres dengan `php:8.4-apache`. PHP dari paket Debian tidak membawanya.
#
# Perl tetap ada (±14 MB terkompres): `apache2-bin` Debian menuntut `perl:any`, dan PRD melarang
# mencabut paket secara paksa. Mencabutnya dengan `dpkg --force-depends` memang menghemat 14 MB, tetapi
# meninggalkan dpkg dalam keadaan rusak — `apt-get install` apa pun sesudahnya menarik Perl kembali atau
# berhenti. Selisih itu hanya dibayar sekali per klien: ia di lapisan basis, dan rilis biasa hanya
# menambah lapisan kode.

FROM composer:2 AS vendor
WORKDIR /repo
# Dependensi dipasang sebelum sisa kode disalin supaya lapisan ini tidak terbangun ulang setiap kali
# satu baris PHP berubah. Manifest module ikut disalin karena Composer membacanya saat menyelesaikan
# repositori path `../../modules/*/*`.
COPY apps/core/composer.json apps/core/composer.lock ./apps/core/
COPY modules ./modules
WORKDIR /repo/apps/core
# ext-gd dan ext-opentelemetry hanya ada di tahap akhir. `--no-scripts` membuat tahap ini cuma mengunduh
# berkas vendor, jadi melewati pemeriksaan kedua ekstensi itu aman di sini.
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --ignore-platform-req=ext-gd --ignore-platform-req=ext-opentelemetry
WORKDIR /repo
COPY . .
WORKDIR /repo/apps/core
RUN php artisan wayfinder:generate --with-form --no-interaction

FROM node:22-bookworm-slim AS assets
# `@apperp/ui` adalah workspace npm di akar repo, jadi susunan repo ditirukan di sini juga.
WORKDIR /repo
# Wayfinder sudah dibangkitkan di tahap vendor; tahap ini tidak punya PHP.
ENV WAYFINDER_COMMAND=true
# `.npmrc` membawa `ignore-scripts=true`; tanpanya skrip `install` setiap dependensi ikut berjalan.
COPY package.json package-lock.json .npmrc ./
COPY apps/core/package.json ./apps/core/
# Hanya manifest pusat admin, nol berkas sumber: `npm ci` berhenti bila workspace yang disebut
# package.json akar tidak ada. Pusat admin tetap di luar image karena tahap akhir hanya menyalin
# apps/core.
COPY apps/control-plane/package.json ./apps/control-plane/
COPY packages/ui ./packages/ui
# npm dapat diam-diam melewatkan optional native binding saat unduhannya gagal (npm/cli#4828);
# pemeriksaan rolldown memunculkan kegagalannya di langkah ini, dan satu percobaan ulang menutup
# unduhan yang cuma meleset.
RUN npm ci && node --input-type=module -e 'await import("rolldown")' || (rm -rf node_modules && npm ci && node --input-type=module -e 'await import("rolldown")')
# UI module dibangun dari folder module lewat pola glob di app.tsx dan app.css.
COPY --from=vendor /repo/modules ./modules
WORKDIR /repo/apps/core
COPY apps/core/resources ./resources
COPY --from=vendor /repo/apps/core/resources/js/actions ./resources/js/actions
COPY --from=vendor /repo/apps/core/resources/js/routes ./resources/js/routes
COPY --from=vendor /repo/apps/core/resources/js/wayfinder ./resources/js/wayfinder
COPY apps/core/public ./public
COPY apps/core/vite.config.ts apps/core/tsconfig.json ./
RUN npm run build

# Ekstensi opentelemetry tidak dipaketkan Debian. Ia dikompilasi di tahap terpisah dan hanya berkas
# .so-nya yang disalin, supaya perkakas kompilasi tidak pernah menjadi lapisan image akhir.
#
# Versinya dipin: `pecl install` tanpa versi menarik rilis terbaru saat build, sehingga commit yang sama
# dapat menghasilkan image berbeda bulan depan.
FROM debian:trixie-slim AS otel
RUN apt-get update \
    && apt-get install -y --no-install-recommends php8.4-dev php-pear make gcc libc6-dev ca-certificates \
    && pecl install opentelemetry-1.2.1 \
    && cp "$(php-config8.4 --extension-dir)/opentelemetry.so" /opentelemetry.so

# `pg_dump` dan `pg_restore` dipakai `environment:copy` dan cadangan agen. Hanya kedua binarinya yang
# disalin; paket postgresql-client membawa psql, Perl, dan skrip pembungkus yang tidak dipakai image
# ini. Pustaka yang dibutuhkan keduanya, libpq5, sudah ada lewat php8.4-pgsql.
#
# Versi 17: `pg_dump` menolak server yang lebih baru daripada dirinya tetapi bekerja terhadap yang lebih
# lama. Klien on-prem menjalankan postgres:16, dan halaman rilis menyebut 17; 17 aman terhadap keduanya.
FROM debian:trixie-slim AS pgklien
ARG VERSI_KLIEN_PG=17
RUN apt-get update && apt-get install -y --no-install-recommends "postgresql-client-${VERSI_KLIEN_PG}"

FROM debian:trixie-slim
ARG VERSI_KLIEN_PG=17
# gd dibutuhkan PhpSpreadsheet; zip oleh PhpWord dan PhpSpreadsheet. Sisanya — curl, mbstring, xml,
# sqlite3, readline, opcache — dibawa image php resmi yang dipakai SaaS secara bawaan; tanpa mereka
# ekstensi yang termuat di on-prem berbeda dari SaaS.
#
# Konfigurasi Apache meniru image php resmi yang dipakai SaaS — prefork + mod_php, DocumentRoot di
# public Core, AllowOverride All untuk .htaccess Laravel, log ke stdout/stderr — supaya perilaku web
# on-prem sama dengan SaaS.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates apache2 libapache2-mod-php8.4 \
        php8.4-cli php8.4-bcmath php8.4-curl php8.4-gd php8.4-intl php8.4-mbstring php8.4-pgsql \
        php8.4-sqlite3 php8.4-xml php8.4-zip php8.4-opcache php8.4-readline \
    && (a2dismod -f mpm_event || true) \
    && a2enmod mpm_prefork php8.4 rewrite \
    && sed -ri 's!/var/www/html!/var/www/repo/apps/core/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/>\n\tOptions -Indexes\n\tAllowOverride All\n</Directory>\nDirectoryIndex index.php index.html\n' > /etc/apache2/conf-available/coreerp.conf \
    && a2enconf coreerp \
    && ln -sfT /dev/stderr /var/log/apache2/error.log \
    && ln -sfT /dev/stdout /var/log/apache2/access.log \
    && ln -sfT /dev/stdout /var/log/apache2/other_vhosts_access.log \
    && printf '#!/bin/sh\nset -e\n. /etc/apache2/envvars\nmkdir -p "$APACHE_RUN_DIR" "$APACHE_LOCK_DIR" "$APACHE_LOG_DIR"\nrm -f "$APACHE_PID_FILE"\nexec apache2 -DFOREGROUND "$@"\n' > /usr/local/bin/apache2-foreground \
    && chmod +x /usr/local/bin/apache2-foreground \
    && rm -rf /var/lib/apt/lists/* /usr/share/doc/* /usr/share/man/*
COPY --from=pgklien /usr/lib/postgresql/${VERSI_KLIEN_PG}/bin/pg_dump /usr/lib/postgresql/${VERSI_KLIEN_PG}/bin/pg_restore /usr/local/bin/
COPY --from=otel /opentelemetry.so /tmp/opentelemetry.so
RUN mv /tmp/opentelemetry.so "$(php -r 'echo ini_get("extension_dir");')/" \
    && echo 'extension=opentelemetry.so' > /etc/php/8.4/mods-available/opentelemetry.ini \
    && phpenmod opentelemetry
WORKDIR /repo
# Pohon app dan module diambil dari tahap vendor — bersama vendor dan wayfinder — dan aset dari tahap
# assets. Konteks pembangunan tidak pernah disalin langsung ke tahap ini.
COPY --from=vendor /repo/apps/core ./apps/core
COPY --from=vendor /repo/modules ./modules
COPY --from=assets /repo/apps/core/public/build ./apps/core/public/build
# Suite test dan resep pembangunan tidak dipakai runtime; keduanya tidak ikut ke server klien.
RUN rm -rf apps/core/tests modules/*/*/tests && rm -f apps/core/Dockerfile apps/core/.dockerignore
# Susunan repo ditirukan di bawah /var/www/repo supaya `../../modules/*/*` dari dalam vendor tetap sah.
RUN mkdir -p /var/www && ln -s /repo /var/www/repo
WORKDIR /repo/apps/core
# Log yang lahir saat tahap vendor menjalankan artisan tidak berguna di server klien.
RUN rm -f storage/logs/*.log \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache
# CONTAINER_ROLE memilih web (bawaan), scheduler, atau worker; lihat docker/entrypoint.sh.
RUN sed -i 's/\r$//' docker/entrypoint.sh && chmod +x docker/entrypoint.sh
ENTRYPOINT ["docker/entrypoint.sh"]
