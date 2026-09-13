# Konteks pembangunan adalah AKAR REPO, bukan folder app ini.
#
# Module hidup di `modules/` pada akar repo, dan Composer memasangnya lewat repositori
# bertipe `path` yang menunjuk `../../modules/*/*`. Selama konteks pembangunan masih folder
# app, folder itu berada di luar jangkauan dan pemasangan dependensi gagal. Karena itu image
# ini menirukan susunan repo apa adanya: app di `apps/core`, module di `modules`,
# keduanya di bawah satu akar. Dengan begitu tautan simbolik yang dibuat Composer tetap sah
# di dalam image, dan tidak ada satu pun kode module yang tersalin dua kali.
#
# Bangun dari akar repo:
#   docker build -f apps/core/Dockerfile .
#
# ## Image per edisi
#
# `MODUL` adalah daftar id module yang dibeli satu edisi, dipisah spasi. Isinya dihitung
# `php artisan edition:resolve <edisi> --daftar` — yang sudah menutup dependency transitif dan
# modul penghubung — dan diteruskan `scripts/build-edition.sh`:
#
#   docker build -f apps/core/Dockerfile --build-arg MODUL="management-aset" .
#   docker build -f apps/core/Dockerfile --build-arg MODUL="" .
#
# Daftar kosong berarti **Core saja**, dan itu edisi yang sah: `editions/praktek-dr-budi.yaml`
# memang tidak membeli satu module pun.
#
# Nilai bawaannya `semua`, dan itu sengaja bukan sinonim dari daftar kosong. Tanpa argumen,
# image memuat seluruh module di repo persis seperti sebelum edisi ada — bentuk yang dipakai
# `compose.yaml` di repo erp-dev, dan yang harus tetap bekerja. Membuat "tanpa argumen" berarti
# Core saja akan membuat pemanggilan lama diam-diam menghasilkan image yang berbeda isinya.
ARG MODUL=semua

FROM composer:2 AS vendor
ARG MODUL
WORKDIR /repo
# Dependensi dipasang sebelum sisa kode disalin supaya lapisan ini tidak terbangun ulang
# setiap kali ada satu baris PHP berubah. Manifest module ikut disalin karena Composer
# membacanya saat menyelesaikan repositori path.
COPY scripts/edisi-pangkas.php ./scripts/
COPY apps/core/composer.json apps/core/composer.lock ./apps/core/
COPY modules ./modules
# Module yang tidak dibeli dibuang di sini, yaitu SEBELUM pemasangan. Urutannya tidak bisa
# dibalik ke sisi mana pun:
#
# - membuang foldernya lebih dulu tanpa memangkas `composer.json` membuat pemasangan gagal,
#   karena ketiga module di-require sebagai paket path yang tiba-tiba tidak ada;
# - membuangnya sesudah pemasangan meninggalkan nama dan namespace module itu di
#   `vendor/composer/installed.json` dan `vendor/composer/autoload_psr4.php`.
#
# Yang kedua adalah kebocoran, bukan sisa yang bisa diabaikan: klaim produknya berbunyi "modul
# yang tidak dibeli tidak ada di server pelanggan", bukan "kodenya tidak ada". Alasan lengkap
# beserta cara `composer.lock` disegarkan ada di `scripts/edisi-pangkas.php`.
RUN php scripts/edisi-pangkas.php pasang --modul="$MODUL"
WORKDIR /repo/apps/core
# ext-gd dan ext-opentelemetry hanya ada pada image runtime di bawah; image composer tidak
# membawa keduanya. Tahap ini cuma mengunduh berkas vendor — `--no-scripts` membuatnya tidak
# pernah menjalankan kode yang butuh ekstensi itu, jadi melewati pemeriksaannya aman di sini.
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --ignore-platform-req=ext-gd --ignore-platform-req=ext-opentelemetry
WORKDIR /repo
COPY . .
# `COPY . .` di atas menaruh kembali `composer.json`, `composer.lock`, dan seluruh folder module
# yang utuh dari konteks — termasuk yang baru saja dibuang. Pemangkasan karena itu diulang, kali
# ini memakai hasil simpanan langkah pertama, jadi tidak ada penyelesaian dependency kedua kali.
#
# Ia harus berjalan sebelum perintah artisan mana pun di bawahnya: penyedia layanan module
# didaftarkan dengan memindai folder `modules/`, sedangkan autoload di `vendor/` sudah dipangkas.
# Folder module yang kembali tanpa pemetaan PSR-4-nya membuat boot berhenti pada kelas yang tidak
# ditemukan.
RUN php scripts/edisi-pangkas.php ulangi --modul="$MODUL"
WORKDIR /repo/apps/core
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
COPY apps/core/package.json ./apps/core/
# Manifest pusat admin ikut, dan **hanya** manifestnya — nol berkas sumber.
#
# Ia disebut `workspaces` pada `package.json` akar, dan `npm ci` berhenti sebelum satu paket pun
# terpasang bila sebuah workspace yang disebut di sana tidak ada di konteks pembangunan. Kegagalannya
# menuduh lockfile, bukan folder yang hilang, jadi ia mahal didiagnosa.
#
# Yang menjaga pusat admin tetap di luar image pelanggan bukan baris ini melainkan tahap akhir, yang
# hanya menyalin `COPY --from=vendor /repo/apps/core`. Jalur 1 `scripts/verify-edition.sh` menagih
# hasilnya: ia menghitung folder di `/repo/apps` pada image yang sudah jadi dan menolak apa pun
# selain `core`.
COPY apps/control-plane/package.json ./apps/control-plane/
COPY packages/ui ./packages/ui
# npm dapat diam-diam melewatkan optional native binding ketika unduhannya gagal
# (npm/cli#4828): instalasinya tetap melapor sukses, lalu build meledak jauh
# kemudian dengan pesan yang menuduh lockfile, padahal penyebabnya jaringan.
# Pemeriksaan ini membuat kegagalan muncul di langkah yang benar, dan satu kali
# percobaan ulang menutup kasus yang memang cuma unduhan meleset.
RUN npm ci && node --input-type=module -e 'await import("rolldown")' || (rm -rf node_modules && npm ci && node --input-type=module -e 'await import("rolldown")')
# UI module ikut dibangun dari folder module, jadi folder itu harus ada di tahap ini — dan
# hanya berisi module yang dibeli. Yang menentukan isi bundel adalah folder apa yang ada saat
# build berjalan, bukan sebuah daftar kedua: `resources/js/app.tsx` menyapu
# `../../../../modules/*/*/ui/Pages/**/*.tsx` dan `resources/css/app.css` memindai
# `@source '../../../../modules/*/*/ui'`. Keduanya pola glob, jadi UI module yang tidak dibeli
# hilang dari bundel karena foldernya memang tidak ada. Salinannya diambil dari tahap `vendor`
# yang sudah dipangkas, bukan dari konteks yang masih utuh.
COPY --from=vendor /repo/modules ./modules
WORKDIR /repo/apps/core
COPY apps/core/resources ./resources
COPY --from=vendor /repo/apps/core/resources/js/actions ./resources/js/actions
COPY --from=vendor /repo/apps/core/resources/js/routes ./resources/js/routes
COPY --from=vendor /repo/apps/core/resources/js/wayfinder ./resources/js/wayfinder
COPY apps/core/public ./public
COPY apps/core/vite.config.ts apps/core/tsconfig.json ./
RUN npm run build

FROM php:8.4-apache
# gd dibutuhkan PhpSpreadsheet (layout Excel laporan); zip oleh PhpWord dan PhpSpreadsheet.
#
# `opentelemetry` tidak dibundel PHP, jadi ia dikompilasi lewat `pecl` di lapisan yang sama —
# bukan lapisan sendiri, karena lapisan runtime inilah yang dikirim ke pelanggan. Alat
# kompilasinya sengaja tidak ikut dipasang di sini: image `php` resmi sudah membawa $PHPIZE_DEPS
# secara permanen, dan itu pula yang dipakai `docker-php-ext-install` di baris atas. Membuangnya
# sesudah `pecl` selesai karena itu bukan penghematan yang gratis — ia mencabut perkakas milik
# image dasar dan mematikan setiap `docker-php-ext-install` yang ditambahkan sesudah baris ini.
# Yang memang sisa hanyalah sumber dan hasil build `pecl` di /tmp/pear, dan itu dibuang.
#
# Versinya dipin, seperti `composer.lock` dan `npm ci` memin milik mereka. `pecl install`
# tanpa versi menarik rilis terbaru pada saat build, jadi image yang dibangun bulan depan
# dari commit yang sama bisa berbeda isinya — kegagalan yang tidak punya commit untuk
# disalahkan, dan muncul pertama kali di mesin pelanggan.
#
# `PASANG_OTEL=0` melewatkan kompilasinya. Ini dipakai alur `edition.yml`, yang membangun
# image hanya untuk memverifikasi pemangkasan module pada setiap PR dan tidak pernah
# mengirimkannya. Alur `release.yml` — yang membangun image yang benar-benar dikirim —
# memakai nilai bawaan, jadi artefak produksi selalu membawa ekstensinya. Perlu disadari
# konsekuensinya: image yang diuji saat PR karena itu bukan image yang dikirim.
ARG PASANG_OTEL=1
# Klien PostgreSQL: `pg_dump` dan `pg_restore`, yang dipakai `environment:copy`.
#
# Ia dijalankan sebagai **klien lewat jaringan**, bukan lewat docker socket. Jalur socket itulah
# yang dulu membuat worker penempatan tidak pernah bisa berjalan di environment mana pun yang
# dihasilkan repo ini: kode yang harus berbicara dengan daemon di luar dirinya untuk mengerjakan
# hal yang sebenarnya bisa ia kerjakan sendiri. Harga jalur klien satu paket di image ini.
#
# **Versinya 17, dan angka itu punya alasan yang harus dibaca sebelum diubah.** `pg_dump` menolak
# server yang **lebih baru** daripada dirinya, tetapi bekerja normal terhadap server yang lebih
# lama. Repo ini menyebut dua versi server yang berbeda — `deploy/compose.edition.yaml` menjalankan
# `postgres:16-alpine` sementara halaman release menampilkan `postgres:17` — dan selama
# ketidakcocokan itu belum diputuskan pemilik produk, klien 17 adalah satu-satunya angka yang aman
# terhadap keduanya. Menurunkannya ke 16 akan membuat penyalinan gagal pada armada mana pun yang
# ternyata sudah berjalan di 17, dan gagalnya baru terlihat saat menyalin.
#
# Paketnya datang dari Debian sendiri: image dasar ini trixie, dan trixie membawa klien 17. Tidak
# ada repositori pihak ketiga, tidak ada kunci yang harus dipercaya. Itu berhenti benar pada hari
# image dasarnya pindah ke rilis Debian yang membawa versi lain, dan pada hari itu barisnya gagal
# saat membangun — bukan diam-diam memasang klien yang terlalu tua.
ARG VERSI_KLIEN_PG=17
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev unzip "postgresql-client-${VERSI_KLIEN_PG}" \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install bcmath gd intl pdo_pgsql pgsql zip \
    && if [ "$PASANG_OTEL" = "1" ]; then \
        pecl install opentelemetry-1.2.1 \
        && docker-php-ext-enable opentelemetry; \
    fi \
    && rm -rf /tmp/pear \
    && a2enmod rewrite \
    && sed -ri 's!/var/www/html!/var/www/repo/apps/core/public!g' /etc/apache2/sites-available/000-default.conf \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /repo
# Ini satu-satunya tahap yang dikirim ke pelanggan, jadi ini satu-satunya tahap yang isinya
# harus bersih. Tahap `vendor` dan `assets` di atas boleh memuat module apa pun sepanjang
# pembangunan: keduanya dibuang begitu bangunan selesai dan tidak pernah menjadi lapisan image
# akhir. Yang menentukan bukan apa yang sempat ada, melainkan apa yang tersalin ke sini.
#
# Karena itu seluruh pohon app dan module diambil dari tahap `vendor` yang sudah dipangkas,
# bukan dari konteks pembangunan — konteks masih memuat ketiga module, `composer.json` yang
# me-require ketiganya, dan `composer.lock` yang mencatat namespace-nya.
COPY --from=vendor /repo/apps/core ./apps/core
COPY --from=vendor /repo/modules ./modules
COPY --from=assets /repo/apps/core/public/build ./apps/core/public/build
# Berkas yang hanya dipakai saat membangun tidak ikut ke server pelanggan. Ada dua kelompok, dan
# keduanya dibuang karena alasan yang sama sekaligus berbeda tingkatnya.
#
# **Suite test.** Ia bukan bagian dari runtime, dan sesudah metadata Composer dipangkas ia
# satu-satunya tempat tersisa di Core yang menyebut namespace module secara harfiah: penjaga batas
# menguji dirinya dengan kode palsu bernama seperti module contoh, dan beberapa test Core mengimpor
# namespace test module aset. Nama itu terbaca sebagai jejak module yang tidak dibeli, dan
# pemeriksaan kebocoran memang mencarinya. `tests/` milik module ikut dibuang dengan alasan yang
# sama, sebelum ada module yang menulis test batas yang menyebut nama tetangganya.
#
# **Resep pembangunannya sendiri.** `Dockerfile` ikut tersalin bersama pohon app, dan komentarnya
# menyebut nama module — termasuk yang tidak dibeli. Itu ditemukan alur `edition` pada percobaan
# pertama, dan yang salah bukan komentarnya: sebuah image runtime tidak punya keperluan apa pun
# dengan resep yang membangunnya, dan selama berkas itu ikut, setiap kalimat yang ditulis di sini
# menjadi calon kebocoran berikutnya. Komentar di atas karena itu tidak lagi mengeja namespace-nya,
# dan berkasnya tetap dibuang — dua lapis, karena yang pertama bergantung pada disiplin orang.
RUN rm -rf apps/core/tests modules/*/*/tests     && rm -f apps/core/Dockerfile apps/core/.dockerignore
# Susunan repo ditirukan di bawah /var/www/repo supaya `../../modules/*/*` tetap menunjuk
# tempat yang benar dari dalam vendor.
RUN mkdir -p /var/www && ln -s /repo /var/www/repo
WORKDIR /repo/apps/core
# Log yang lahir saat tahap `vendor` menjalankan artisan ikut tersalin bersama pohon app. Ia
# tidak berguna di server pelanggan, dan isinya bisa menyebut apa saja yang kebetulan terjadi
# saat membangun — termasuk nama module.
RUN rm -f storage/logs/*.log \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

# CONTAINER_ROLE selects web (default), scheduler, or worker. A deployment needs exactly one scheduler replica,
# otherwise the number sequence recovery job never runs.
RUN sed -i 's/\r$//' docker/entrypoint.sh && chmod +x docker/entrypoint.sh
ENTRYPOINT ["docker/entrypoint.sh"]
