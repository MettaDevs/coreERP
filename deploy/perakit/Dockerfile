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
# ## Kenapa FrankenPHP, dan kenapa tetap Debian slim
#
# Sejak 3 Oktober 2026 peran web dilayani FrankenPHP dalam mode worker lewat Laravel Octane: Laravel
# di-boot sekali per worker, bukan per permintaan. Diukur pada endpoint aset dengan 2 CPU, kapasitasnya
# naik dari 125 menjadi 350 pengguna serentak. Sebelumnya image ini Apache prefork + mod_php dari paket
# Debian.
#
# FrankenPHP menuntut PHP ZTS, dan PHP paket Debian NTS — jadi PHP tidak lagi datang dari apt. Ia
# dibangun di tahap `php` dari image FrankenPHP resmi (turunan image `php` ZTS), lalu hanya hasilnya
# yang disalin: binari `frankenphp` dan `php`, `libphp.so`, ekstensi, dan ini-nya. Perkakas kompilasi
# ($PHPIZE_DEPS, lapisan 355 MB tak terkompres) tetap tidak pernah menjadi lapisan image akhir — alasan
# yang sama yang dulu membuat image ini memakai Debian slim alih-alih `php:8.4-apache`.
#
# Pustaka yang dibutuhkan hasil salinan itu dipasang lewat apt, dari daftar yang dihitung di tahap `php`
# (`ldd` atas setiap binari dan ekstensi, lalu `dpkg -S`). Dengan begitu dpkg tetap sehat dan tahu
# setiap berkas di luar /usr/local, dan yang dipasang persis yang dipakai — tidak ada daftar tangan yang
# bisa basi ketika ekstensi bertambah.

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

# PHP ZTS, FrankenPHP, dan ekstensinya. Versi image dan opentelemetry dipin: tanpa pin, commit yang sama
# dapat menghasilkan image berbeda bulan depan.
#
# Ekstensi yang dipasang di sini ditambah yang sudah dikompilasi ke dalam PHP resmi (curl, mbstring, xml,
# sqlite3, readline, sodium) sama dengan yang dulu datang dari paket Debian; `uji-image.sh` menagihnya.
# `pcntl` dipakai `octane:frankenphp` untuk meneruskan SIGTERM ke server saat container dihentikan.
FROM dunglas/frankenphp:1.12.7-php8.4-trixie AS php
RUN install-php-extensions bcmath gd intl pdo_pgsql pgsql zip pcntl opcache opentelemetry-1.2.1
# Paket Debian pemilik setiap pustaka yang dibutuhkan binari, libphp, dan ekstensi. Pustaka di /usr/local
# bukan milik paket mana pun; ia disalin langsung ke tahap akhir.
#
# `realpath` wajib: trixie memakai usrmerge, jadi `ldd` menyebut /lib/x86_64-linux-gnu/... sementara dpkg
# mencatat berkasnya di /usr/lib/x86_64-linux-gnu/..., dan `dpkg -S` atas jalur pertama tidak menemukan
# pemiliknya.
RUN set -eu; \
    for f in /usr/local/bin/frankenphp /usr/local/bin/php /usr/local/lib/*.so* $(find "$(php-config --extension-dir)" -name '*.so'); do \
        ldd "$f" | awk '/=> \//{print $3}'; \
    done | sort -u | xargs -r realpath | sort -u | grep -v '^/usr/local/' > /tmp/pustaka.txt; \
    xargs -r dpkg -S < /tmp/pustaka.txt | cut -d: -f1 | sort -u > /paket-runtime.txt; \
    test -s /paket-runtime.txt; \
    mkdir -p /keluaran/usr/local/bin /keluaran/usr/local/lib/php; \
    cp /usr/local/bin/frankenphp /usr/local/bin/php /keluaran/usr/local/bin/; \
    cp -a /usr/local/lib/*.so* /keluaran/usr/local/lib/; \
    cp -a /usr/local/lib/php/extensions /keluaran/usr/local/lib/php/; \
    cp -a /usr/local/etc /keluaran/usr/local/; \
    cp /usr/local/etc/php/php.ini-production /keluaran/usr/local/etc/php/php.ini; \
    sed 's/^memory_limit = .*/memory_limit = -1/' /usr/local/etc/php/php.ini-production > /keluaran/usr/local/etc/php/php-cli.ini

# `pg_dump` dan `pg_restore` dipakai `environment:copy` dan cadangan agen. Hanya kedua binarinya yang
# disalin; paket postgresql-client membawa psql, Perl, dan skrip pembungkus yang tidak dipakai image
# ini.
#
# Versi 17: `pg_dump` menolak server yang lebih baru daripada dirinya tetapi bekerja terhadap yang lebih
# lama. Klien on-prem menjalankan postgres:16, dan halaman rilis menyebut 17; 17 aman terhadap keduanya.
FROM debian:trixie-slim AS pgklien
ARG VERSI_KLIEN_PG=17
RUN apt-get update && apt-get install -y --no-install-recommends "postgresql-client-${VERSI_KLIEN_PG}"
# Paket pemilik pustaka kedua binari itu, dihitung dengan cara yang sama dengan tahap `php`.
RUN for f in /usr/lib/postgresql/${VERSI_KLIEN_PG}/bin/pg_dump /usr/lib/postgresql/${VERSI_KLIEN_PG}/bin/pg_restore; do \
        ldd "$f" | awk '/=> \//{print $3}'; \
    done | sort -u | xargs -r realpath | sort -u | xargs -r dpkg -S | cut -d: -f1 | sort -u > /paket-pg.txt \
    && test -s /paket-pg.txt

FROM debian:trixie-slim
ARG VERSI_KLIEN_PG=17
# `php.ini` adalah `php.ini-production` milik PHP itu sendiri — padanan php.ini Debian yang dulu dipakai:
# display_errors mati, batas memori 128M untuk peran web. Binari `php` membaca `php-cli.ini` lebih dulu,
# yang sama kecuali batas memorinya dicabut, seperti php.ini CLI Debian dulu: worker antrean (ekspor
# laporan), penjadwal, `migrate`, dan `environment:copy` tidak pernah dibatasi 128M. Batas unggah mengikuti
# batas lampiran dokumen, 10 MB (`coreerp.attachments.max_kb`), sama dengan apps/core/Dockerfile.
# `expose_php` dimatikan supaya jawaban tidak mengumumkan versi PHP.
#
# Peran web berjalan sebagai www-data tetapi tetap membuka port 80, supaya proxy, healthcheck, dan compose
# di depannya tidak berubah: `setcap` memberi binari frankenphp hak itu, lalu perkakasnya dicabut lewat
# apt (bukan paksa, jadi dpkg tetap sehat). Caddy menulis konfigurasi dan datanya ke $XDG_CONFIG_HOME dan
# $XDG_DATA_HOME, yang diserahkan ke www-data.
ENV PHP_INI_DIR=/usr/local/etc/php XDG_CONFIG_HOME=/config XDG_DATA_HOME=/data
COPY --from=php /paket-runtime.txt /tmp/paket-runtime.txt
COPY --from=pgklien /paket-pg.txt /tmp/paket-pg.txt
COPY --from=php /keluaran/ /
RUN apt-get update \
    && cat /tmp/paket-runtime.txt /tmp/paket-pg.txt | sort -u | xargs apt-get install -y --no-install-recommends ca-certificates libcap2-bin \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && apt-get purge -y --auto-remove libcap2-bin \
    && ldconfig \
    && printf 'upload_max_filesize = 10M\npost_max_size = 12M\n' > "$PHP_INI_DIR/conf.d/coreerp-uploads.ini" \
    && printf 'expose_php = Off\n' > "$PHP_INI_DIR/conf.d/coreerp-expose.ini" \
    && mkdir -p /config /data && chown -R www-data:www-data /config /data \
    && rm -rf /var/lib/apt/lists/* /usr/share/doc/* /usr/share/man/* /tmp/paket-runtime.txt /tmp/paket-pg.txt
COPY --from=pgklien /usr/lib/postgresql/${VERSI_KLIEN_PG}/bin/pg_dump /usr/lib/postgresql/${VERSI_KLIEN_PG}/bin/pg_restore /usr/local/bin/
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
