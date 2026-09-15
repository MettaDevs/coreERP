#!/usr/bin/env bash
#
# Membuktikan image rilis layak dikirim ke server klien, sebelum perakit mendorongnya ke Harbor.
#
#   bash deploy/perakit/uji-image.sh <image>
#
# Keluar nol hanya bila seluruh syarat IMG-01 terpenuhi, dan berhenti pada syarat pertama yang dilanggar:
#
#   1. dpkg sehat — tidak ada paket yang dicabut paksa;
#   2. ekstensi PHP yang dibutuhkan termuat, di CLI dan di SAPI Apache;
#   3. pg_dump dan pg_restore berjalan;
#   4. hanya apps/core di /repo/apps, tanpa suite test dan resep pembangunan;
#   5. aplikasi menyala: migration, seed, dan pendaftaran manifest berjalan ke PostgreSQL kosong seperti
#      `core-migrate` di deploy/compose.edition.yaml, lalu peran web menjawab /up dan /login.
#
# Syarat 5 menyalakan container sekali pakai di jaringan sendiri dan membuangnya saat selesai, termasuk
# saat gagal. Ukuran terkompres dicetak di akhir sebagai catatan, bukan syarat.

set -euo pipefail
shopt -s inherit_errexit

image="${1:-}"
[ -n "$image" ] || { echo 'Pemakaian: bash deploy/perakit/uji-image.sh <image>' >&2; exit 2; }

gagal() {
    printf '\nMERAH: %s\n' "$1" >&2
    shift
    [ "$#" -eq 0 ] || printf '%s\n' "$@" >&2
    exit 1
}

lulus() {
    printf '  lulus  %s\n' "$*"
}

di_image() {
    docker run --rm --entrypoint sh "$image" -c "$1"
}

docker image inspect "$image" >/dev/null 2>&1 || gagal "Image $image tidak ada di mesin ini."
printf 'Uji image %s\n' "$image"

audit="$(di_image 'dpkg --audit; apt-get check -qq 2>&1')" || gagal 'dpkg atau apt melaporkan keadaan rusak:' "$audit"
[ -z "$audit" ] || gagal 'dpkg atau apt melaporkan keadaan rusak:' "$audit"
lulus 'dpkg sehat, tidak ada dependensi yang dilanggar'

# Nama seperti yang dicetak `php -m`. OPcache terdaftar sebagai "Zend OPcache".
ekstensi_wajib=(intl gd pdo_pgsql pgsql zip bcmath 'Zend OPcache' opentelemetry mbstring curl xml)
for sapi in cli apache2; do
    termuat="$(di_image "PHP_INI_SCAN_DIR=/etc/php/8.4/$sapi/conf.d php -c /etc/php/8.4/$sapi/php.ini -m")" \
        || gagal "php -m untuk SAPI $sapi gagal."
    for nama in "${ekstensi_wajib[@]}"; do
        printf '%s\n' "$termuat" | grep -qxF "$nama" || gagal "Ekstensi $nama tidak termuat di SAPI $sapi."
    done
done
lulus "ekstensi termuat di CLI dan Apache: ${ekstensi_wajib[*]}"

versi_pg="$(di_image 'pg_dump --version && pg_restore --version')" || gagal 'pg_dump atau pg_restore tidak berjalan:' "$versi_pg"
lulus "$(printf '%s' "$versi_pg" | tr '\n' ';' | sed 's/;$//; s/;/, /')"

aplikasi="$(di_image 'ls -1 /repo/apps')"
[ "$aplikasi" = 'core' ] || gagal 'Isi /repo/apps bukan hanya core:' "$aplikasi"
sisa="$(di_image 'ls -d /repo/apps/core/tests /repo/modules/*/*/tests /repo/apps/core/Dockerfile 2>/dev/null || true')"
[ -z "$sisa" ] || gagal 'Suite test atau resep pembangunan ikut ke image:' "$sisa"
lulus 'hanya apps/core, tanpa suite test dan Dockerfile'

akhiran="uji-image-$$"
jaringan="$akhiran"
bereskan() {
    docker rm -f "$akhiran-db" "$akhiran-web" >/dev/null 2>&1 || true
    docker network rm "$jaringan" >/dev/null 2>&1 || true
}
trap bereskan EXIT

docker network create "$jaringan" >/dev/null
sandi_db="$(openssl rand -hex 16)"
docker run -d --name "$akhiran-db" --network "$jaringan" --network-alias core-db \
    -e POSTGRES_DB=core_erp -e POSTGRES_USER=core_erp -e POSTGRES_PASSWORD="$sandi_db" \
    postgres:16-alpine >/dev/null
for _ in $(seq 1 30); do
    docker exec "$akhiran-db" pg_isready -U core_erp -d core_erp >/dev/null 2>&1 && break
    sleep 1
done

lingkungan=(
    -e APP_ENV=production -e APP_DEBUG=false -e APP_URL=http://localhost
    -e "APP_KEY=base64:$(openssl rand -base64 32)"
    -e DB_CONNECTION=pgsql -e DB_HOST=core-db -e DB_PORT=5432 -e DB_DATABASE=core_erp
    -e DB_USERNAME=core_erp -e "DB_PASSWORD=$sandi_db"
    -e SESSION_DRIVER=database -e SESSION_SECURE_COOKIE=false -e CACHE_STORE=database -e QUEUE_CONNECTION=database
    -e COREERP_PROVIDER_EMAIL=provider@uji-image.local -e "COREERP_PROVIDER_PASSWORD=$(openssl rand -hex 16)"
    -e OTEL_PHP_AUTOLOAD_ENABLED=false
)

if ! keluaran="$(docker run --rm --network "$jaringan" "${lingkungan[@]}" --entrypoint sh "$image" -c \
    'php artisan migrate --force --no-interaction && php artisan db:seed --force --no-interaction && php artisan app:register-manifest --no-interaction && php artisan environment:upgrade --no-interaction' 2>&1)"; then
    gagal 'Migration, seed, atau pendaftaran manifest gagal:' "$(printf '%s' "$keluaran" | tail -n 20)"
fi
lulus 'migration, seed, register-manifest, dan environment:upgrade berjalan ke PostgreSQL 16 kosong'

docker run -d --name "$akhiran-web" --network "$jaringan" "${lingkungan[@]}" "$image" >/dev/null
status_up=''
for _ in $(seq 1 60); do
    status_up="$(docker exec "$akhiran-web" sh -c 'php -r "echo @file_get_contents(\"http://127.0.0.1/up\") === false ? \"gagal\" : \"200\";"' 2>/dev/null || true)"
    [ "$status_up" = '200' ] && break
    sleep 1
done
[ "$status_up" = '200' ] || gagal 'Peran web tidak menjawab /up dalam 60 detik:' "$(docker logs --tail 30 "$akhiran-web" 2>&1)"
status_login="$(docker exec "$akhiran-web" sh -c 'php -r "\$h = @get_headers(\"http://127.0.0.1/login\"); echo \$h ? explode(\" \", \$h[0])[1] : \"gagal\";"')"
[ "$status_login" = '200' ] || gagal "/login dijawab $status_login, bukan 200:" "$(docker logs --tail 30 "$akhiran-web" 2>&1)"
lulus 'peran web menjawab /up dan /login 200'

ukuran="$(docker save "$image" | gzip -c | wc -c)"
printf '\nHijau. Ukuran terkompres ±%s MB (docker save | gzip).\n' "$(awk -v b="$ukuran" 'BEGIN {printf "%.1f", b/1e6}')"
