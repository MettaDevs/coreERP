#!/bin/sh
set -eu

php artisan migrate --force
