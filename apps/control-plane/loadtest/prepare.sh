#!/bin/sh
set -eu

php artisan migrate:fresh --force
php artisan db:seed --force
php artisan app:register-manifest /workspace/app.yaml
php artisan app:bootstrap-local-runtime /workspace/app.yaml \
    --placement=pooled-primary \
    --profile=pooled \
    --api-image="loadtest/core-api@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" \
    --ui-image="loadtest/core-ui@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb" \
    --compose-project=core-loadtest \
    --compose-file=docker-compose.yml \
    --api-service=api \
    --ui-service=ui \
    --database-service=db
