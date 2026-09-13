# Konsol operator vendor.
#
# ## Kenapa ia punya image sendiri, bukan menumpang image Core
#
# Karena keduanya dijalankan orang yang berbeda di alamat yang berbeda, dan yang satu tidak boleh
# ikut mati bersama yang lain. Menumpangkan konsol ke image Core juga berarti setiap penempatan
# on-prem ikut membawa konsol operator vendor ke mesin pelanggan — perkakas yang tidak ada
# urusannya dengan mereka, lengkap dengan seluruh rutenya.
#
# ## Ia jauh lebih kurus daripada Core, dan itu disengaja
#
# Tanpa module, tanpa antrean, tanpa penjadwal, tanpa perender PDF, tanpa klien PostgreSQL untuk
# dump. Yang dibutuhkannya cuma membaca dan menulis beberapa tabel milik Core, lalu memanggil Core
# lewat HTTP. Setiap ekstensi yang tidak ada di sini adalah ekstensi yang tidak perlu ditambal.
#
# ## Ia TIDAK punya migration
#
# Skema yang dibacanya milik Core, dan hanya Core yang boleh mengubahnya. Kalau konsol ikut punya
# migration, dua aplikasi akan berebut satu tabel dan urutannya tidak pernah dapat dipastikan lagi.
# Karena itu tidak ada layanan `migrate` untuk image ini di compose mana pun.

# ------------------------------------------------------------------ vendor

FROM composer:2 AS vendor
WORKDIR /repo/apps/control-plane
COPY apps/control-plane/composer.json apps/control-plane/composer.lock ./
# `--no-scripts` karena skrip Laravel menuntut seluruh pohon aplikasi yang belum tersalin di sini.
# Ia dijalankan lagi sesudah COPY di bawah.
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
COPY apps/control-plane ./
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ------------------------------------------------------------------ aset

FROM node:22-bookworm-slim AS assets
WORKDIR /repo
# Root workspace lebih dulu, lalu package.json tiap workspace yang dirujuknya. `npm ci` menuntut
# seluruhnya hadir sebelum ia mau memasang apa pun, dan menyalin sumbernya sekalian di sini akan
# membuang singgahan lapisan pada tiap perubahan satu baris kode.
COPY package.json package-lock.json .npmrc ./
COPY apps/control-plane/package.json ./apps/control-plane/
COPY apps/core/package.json ./apps/core/
COPY packages/ui ./packages/ui
RUN npm ci
COPY apps/control-plane/resources ./apps/control-plane/resources
COPY apps/control-plane/vite.config.ts apps/control-plane/tsconfig.json ./apps/control-plane/
WORKDIR /repo/apps/control-plane
# `npm run build` di sini menjalankan `ui:build` lebih dulu — kelas milik `@apperp/ui` hanya lahir
# kalau sumbernya ikut dipindai, dan tanpa itu bangunannya tetap hijau sementara CSS keluarannya
# kehilangan setiap kelas yang cuma dipakai komponen paket. Yang terlihat cuma tata letak berantakan.
RUN npm run build

# ------------------------------------------------------------------ runtime

FROM php:8.4-apache

# `pdo_pgsql` dan `pgsql` untuk membaca tabel Core. `intl` dipakai pemformatan tanggal dan angka
# pada layar operator. Tidak ada gd, zip, maupun opentelemetry: konsol tidak menghasilkan dokumen
# dan tidak diinstrumentasi.
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libpq-dev \
    && docker-php-ext-install intl pdo_pgsql pgsql \
    && a2enmod rewrite \
    && sed -ri 's!/var/www/html!/var/www/repo/apps/control-plane/public!g' /etc/apache2/sites-available/000-default.conf \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /repo
COPY --from=vendor /repo/apps/control-plane ./apps/control-plane
COPY --from=assets /repo/apps/control-plane/public/build ./apps/control-plane/public/build

# Suite test tidak ikut ke server. Ia bukan bagian runtime, dan berkas resep pembangunannya pun
# tidak: sebuah image runtime tidak punya keperluan apa pun dengan Dockerfile yang membangunnya.
RUN rm -rf apps/control-plane/tests && rm -f apps/control-plane/Dockerfile

# Susunan repo ditirukan di bawah /var/www/repo supaya jalur relatif dari dalam vendor tetap
# menunjuk tempat yang benar — sama seperti image Core.
RUN mkdir -p /var/www && ln -s /repo /var/www/repo

WORKDIR /repo/apps/control-plane
RUN php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache
