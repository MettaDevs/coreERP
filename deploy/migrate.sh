#!/bin/sh
# Dijalankan worker deployment CoreERP di dalam image API, setelah database sehat dan
# sebelum API/UI dinyalakan. Lihat docs/dev/13-publishing-an-app-release.md.
#
# Skrip ini hanya menjalankan migration app. Ia tidak menyentuh database Core dan tidak
# melakukan seeding data bisnis.
set -eu

php /var/www/html/artisan migrate --force --no-interaction --path=/var/www/database/migrations
