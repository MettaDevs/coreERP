#!/bin/sh
set -eu

php artisan migrate:fresh --force
php artisan db:seed --force
php artisan app:register-manifest /workspace/app.yaml
php artisan app:bootstrap-local-runtime /workspace/app.yaml \
    --placement=pooled-primary \
    --profile=pooled \
    --edition-image="loadtest/core-edition@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" \
    --compose-project=core-loadtest \
    --compose-file=docker-compose.yml
