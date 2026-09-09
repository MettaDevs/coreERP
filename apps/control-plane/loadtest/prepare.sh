#!/bin/sh
set -eu

php artisan migrate:fresh --force
php artisan db:seed --force
# Manifest ditunjuk dengan id module, bukan jalur berkas. Bentuk lama ditolak sejak F3-30:
# module hidup di dalam repo dan ditemukan `ModuleRegistry` dengan memindai folder, jadi
# jalur yang diketik pemakai berarti dua sumber kebenaran yang bisa berselisih.
php artisan app:register-manifest management-aset
php artisan app:bootstrap-local-runtime /workspace/app.yaml \
    --placement=pooled-primary \
    --profile=pooled \
    --edition-image="loadtest/core-edition@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" \
    --compose-project=core-loadtest \
    --compose-file=docker-compose.yml
