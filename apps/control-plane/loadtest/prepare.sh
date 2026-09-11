#!/bin/sh
set -eu

# Urutannya sama persis dengan yang dijalankan `start.ps1` pada stack pengembangan. Kalau
# yang di sini menyimpang, yang diuji bukan runtime yang dijalankan orang.
#
# Manifest ditunjuk dengan id module, bukan jalur berkas. Bentuk berjalur ditolak sejak F3-30:
# module hidup di dalam repo dan ditemukan `ModuleRegistry` dengan memindai folder, jadi jalur
# yang diketik pemakai berarti dua sumber kebenaran yang bisa berselisih.
#
# `app:bootstrap-local-runtime` tidak dipanggil lagi. Ia menempatkan container app beserta
# image edisinya; module tidak punya container, jadi tidak ada yang perlu ditempatkan.

MODULES="${LOADTEST_MODULES:-management-aset human-resources}"

php artisan migrate:fresh --force --no-interaction
php artisan db:seed --force --no-interaction

for module in $MODULES; do
    php artisan app:register-manifest "$module"
    php artisan module:migrate "$module"
done
