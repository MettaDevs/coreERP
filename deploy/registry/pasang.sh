#!/usr/bin/env bash
#
# Memasang atau menyelaraskan Harbor — registry image CoreERP — di server pertama. Dijalankan sebagai
# root dari salinan repo di server itu:
#
#   sudo bash deploy/registry/pasang.sh --izin-ui "203.0.113.7/32 198.51.100.0/24"
#   sudo bash deploy/registry/pasang.sh                    # putaran berikutnya
#
# --izin-ui, daftar alamat yang boleh membuka UI dan API admin lewat internet, hanya wajib pada
# pemasangan pertama. Nilainya disimpan di /etc/coreerp/registry/setelan.env dan dipakai ulang;
# menyebutnya lagi mengganti daftar itu.
#
# Yang dikerjakan, berurutan: memeriksa mesin → folder → installer terkunci sha256 → rahasia →
# harbor.yml → tindihan compose → prepare dan up bila setelan berubah → rute Traefik → pemeriksaan.
#
# Idempoten: putaran kedua tanpa perubahan setelan tidak menulis berkas apa pun dan tidak me-restart satu
# container pun. Itu sebabnya skrip ini TIDAK memanggil `install.sh` bawaan Harbor — ia selalu menjalankan
# `docker compose down -v` sebelum menyalakan ulang — dan hanya menjalankan `prepare` bila setelan berubah,
# karena `prepare` membuat rahasia internal baru setiap kali dan compose lalu membuat ulang containernya.
#
# Menaikkan versi Harbor sengaja ditolak tanpa --naikkan-versi. Pembaruan Harbor dapat memigrasikan
# database saat container menyala — v2.15.2 menaikkan PostgreSQL bawaan dari 15 ke 18 — jadi database
# dicadangkan lebih dulu. Urutannya di RUNBOOK.md.

set -euo pipefail
shopt -s inherit_errexit

HARBOR_VERSI='v2.15.2'
# sha256 installer online v2.15.2. Dihitung sesudah tanda tangan sigstore-nya diperiksa dengan
# `cosign verify-blob` terhadap identitas workflow rilis goharbor; perintahnya di SPIKE.md.
HARBOR_SHA256='88f6a7436b31890e8e472972a7433d36b7d6a36de9adeb86337fdc9fe7fb5fa3'

HOST_REGISTRY='registry.erp.grenery.xyz'
PROYEK='harbor'
RUMAH='/opt/harbor'
INSTALLER="$RUMAH/harbor"
# Harus sama dengan data_volume di harbor.yml.tmpl.
DATA='/var/lib/harbor'
FOLDER_SETELAN='/etc/coreerp/registry'
BERKAS_RAHASIA="$FOLDER_SETELAN/rahasia.env"
BERKAS_SETELAN="$FOLDER_SETELAN/setelan.env"
FOLDER_RUTE='/etc/dokploy/traefik/dynamic'
BERKAS_RUTE="$FOLDER_RUTE/coreerp-registry.yml"
JARINGAN_TRAEFIK='dokploy-network'

folder_skrip="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

gagal() {
    printf '\nGAGAL: %s\n' "$1" >&2
    shift
    [ "$#" -eq 0 ] || printf '%s\n' "$@" >&2
    exit 1
}

langkah() {
    printf '\n==> %s\n' "$*"
}

izin_ui=''
naikkan_versi=0
while [ "$#" -gt 0 ]; do
    case "$1" in
        --izin-ui) [ "$#" -ge 2 ] || gagal '--izin-ui butuh nilai'; izin_ui="$2"; shift 2 ;;
        --naikkan-versi) naikkan_versi=1; shift ;;
        *) gagal "pilihan tidak dikenal: $1" 'Pemakaian: sudo bash pasang.sh [--izin-ui "CIDR CIDR ..."] [--naikkan-versi]' ;;
    esac
done

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

compose() {
    docker compose -p "$PROYEK" --project-directory "$INSTALLER" \
        -f "$INSTALLER/docker-compose.yml" -f "$INSTALLER/docker-compose.override.yml" "$@"
}

# Menyalin berkas hanya bila isinya berbeda, supaya putaran tanpa perubahan tidak menyentuh apa pun.
# Pulang 0 bila berkas ditulis.
pasang_bila_beda() {
    local sumber="$1" tujuan="$2" mode="$3"
    if [ -f "$tujuan" ] && cmp -s "$sumber" "$tujuan"; then
        return 1
    fi
    install -m "$mode" -o root -g root "$sumber" "$tujuan"
    printf '    ditulis: %s\n' "$tujuan"
}

# 32 karakter. Kebijakan kata sandi Harbor menuntut huruf besar, huruf kecil, dan angka; kata sandi admin
# yang tidak memenuhinya diterima saat pemasangan tetapi tidak dapat diputar lewat UI sesudahnya.
kata_sandi_baru() {
    local acak
    while :; do
        acak="$(openssl rand -base64 96 | tr -dc 'A-Za-z0-9')"
        acak="${acak:0:32}"
        if [ "${#acak}" -eq 32 ] && [[ $acak =~ [A-Z] ]] && [[ $acak =~ [a-z] ]] && [[ $acak =~ [0-9] ]]; then
            printf '%s' "$acak"
            return
        fi
    done
}

# Bukan `compose up --wait`: jobservice Harbor keluar dan di-restart beberapa kali selama core belum
# menjawab — normal, terukur empat kali pada pemasangan pertama — dan --wait menganggap status unhealthy
# pertama itu kegagalan. Di sini yang ditunggu keadaan akhirnya: setiap container berjalan dan, bila
# punya healthcheck, sehat.
tunggu_sehat() {
    local batas=$((SECONDS + 300)) keadaan belum
    while :; do
        keadaan="$(docker ps -aq --filter "label=com.docker.compose.project=$PROYEK" \
            | xargs -r docker inspect --format '{{.Name}} {{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{else}}healthy{{end}}')"
        belum="$(printf '%s\n' "$keadaan" | grep -v ' running healthy$' || true)"
        if [ -z "$belum" ] && [ "$(printf '%s\n' "$keadaan" | grep -c .)" -eq "$jumlah_layanan" ]; then
            return
        fi
        [ "$SECONDS" -lt "$batas" ] || gagal 'Harbor belum sehat setelah lima menit:' "$belum"
        sleep 5
    done
}

# Dibaca sebagai data, bukan di-`source`, supaya isi berkas setelan tidak pernah dieksekusi.
nilai_dari() {
    sed -n "s/^$2=//p" "$1" | tail -n 1
}

langkah 'Memeriksa mesin'
[ "$(id -u)" -eq 0 ] || gagal 'Jalankan sebagai root.'
for perintah in docker envsubst sha256sum curl openssl tar cmp; do
    command -v "$perintah" >/dev/null || gagal "Perintah $perintah tidak ada."
done
versi_compose="$(docker compose version --short 2>/dev/null)" || gagal 'Docker Compose v2 tidak ada.'
# `!reset` di compose.dokploy.yml baru dikenal sejak Compose 2.24. Versi yang lebih lama mengabaikannya
# diam-diam dan proxy Harbor kembali menerbitkan port ke internet.
if ! printf '2.24.0\n%s\n' "${versi_compose#v}" | sort -V -C; then
    gagal "Docker Compose $versi_compose terlalu lama; butuh 2.24 atau lebih baru."
fi
[ "$(docker network inspect "$JARINGAN_TRAEFIK" --format '{{.Attachable}}' 2>/dev/null)" = 'true' ] \
    || gagal "Jaringan $JARINGAN_TRAEFIK tidak ada atau tidak attachable." 'Harbor dipasang di belakang Traefik milik Dokploy.'
[ -d "$FOLDER_RUTE" ] || gagal "Folder rute dinamis Traefik $FOLDER_RUTE tidak ada."

langkah 'Menyiapkan folder'
install -d -m 0755 -o root -g root "$RUMAH" "$DATA"
mkdir -p /etc/coreerp
install -d -m 0700 -o root -g root "$FOLDER_SETELAN"

langkah "Installer Harbor $HARBOR_VERSI"
versi_terpasang=''
[ ! -f "$INSTALLER/.versi" ] || versi_terpasang="$(cat "$INSTALLER/.versi")"
if [ "$versi_terpasang" = "$HARBOR_VERSI" ]; then
    printf '    sudah terpasang\n'
else
    if [ -n "$versi_terpasang" ] && [ "$naikkan_versi" -ne 1 ]; then
        gagal "Harbor $versi_terpasang terpasang, skrip ini untuk $HARBOR_VERSI." \
            'Menaikkan versi dapat memigrasikan database. Cadangkan dulu, lalu ulangi dengan --naikkan-versi (RUNBOOK.md).'
    fi
    if [ -z "$versi_terpasang" ] && [ -d "$DATA/database" ]; then
        gagal "Data Harbor ada di $DATA/database tetapi installer di $INSTALLER tidak." \
            'Bila ini pemulihan dari cadangan, ikuti RUNBOOK.md; skrip ini tidak menebak versi data yang ada.'
    fi
    berkas_installer="$tmp/harbor-online-installer-$HARBOR_VERSI.tgz"
    curl -fsSL --retry 3 -o "$berkas_installer" \
        "https://github.com/goharbor/harbor/releases/download/$HARBOR_VERSI/harbor-online-installer-$HARBOR_VERSI.tgz"
    printf '%s  %s\n' "$HARBOR_SHA256" "$berkas_installer" | sha256sum -c --quiet - \
        || gagal 'sha256 installer tidak cocok dengan yang dikunci di skrip ini.'
    tar xzf "$berkas_installer" -C "$RUMAH" --no-same-owner
    printf '%s\n' "$HARBOR_VERSI" > "$INSTALLER/.versi"
    printf '    diekstrak ke %s\n' "$INSTALLER"
fi

langkah 'Rahasia'
if [ -f "$BERKAS_RAHASIA" ]; then
    printf '    dipakai ulang: %s\n' "$BERKAS_RAHASIA"
else
    # Kata sandi database dibaca Harbor setiap kali menyala, tetapi hanya ditulis ke database saat database
    # itu lahir. Rahasia baru untuk database lama membuat Harbor tidak dapat membuka databasenya sendiri.
    [ ! -d "$DATA/database" ] || gagal "Database Harbor sudah ada tetapi $BERKAS_RAHASIA hilang." \
        'Pulihkan berkas itu dari cadangan; jangan membuat yang baru.'
    (
        umask 077
        printf 'HARBOR_ADMIN_PASSWORD=%s\nHARBOR_DB_PASSWORD=%s\n' "$(kata_sandi_baru)" "$(kata_sandi_baru)" > "$BERKAS_RAHASIA"
    )
    printf '    dibuat: %s (tidak dicetak)\n' "$BERKAS_RAHASIA"
fi
chown root:root "$BERKAS_RAHASIA"
chmod 0600 "$BERKAS_RAHASIA"
kata_sandi_admin="$(nilai_dari "$BERKAS_RAHASIA" HARBOR_ADMIN_PASSWORD)"
kata_sandi_db="$(nilai_dari "$BERKAS_RAHASIA" HARBOR_DB_PASSWORD)"
[ -n "$kata_sandi_admin" ] && [ -n "$kata_sandi_db" ] || gagal "$BERKAS_RAHASIA tidak memuat kedua kata sandi."

langkah 'Daftar alamat yang boleh membuka UI'
if [ -n "$izin_ui" ]; then
    for cidr in $izin_ui; do
        [[ $cidr =~ ^[0-9A-Fa-f:.]+(/[0-9]{1,3})?$ ]] || gagal "Bukan alamat atau CIDR: $cidr"
    done
    # Hanya baris ini yang diganti. Setelan lain di berkas yang sama — REGISTRY_SIMPAN_RILIS,
    # REGISTRY_TOKEN_MENIT untuk atur-harbor.sh — dibawa apa adanya.
    [ ! -f "$BERKAS_SETELAN" ] || grep -v '^REGISTRY_IZIN_UI=' "$BERKAS_SETELAN" > "$tmp/setelan.env" || true
    printf 'REGISTRY_IZIN_UI=%s\n' "$izin_ui" >> "$tmp/setelan.env"
    pasang_bila_beda "$tmp/setelan.env" "$BERKAS_SETELAN" 0644 || printf '    tidak berubah\n'
elif [ ! -f "$BERKAS_SETELAN" ]; then
    gagal '--izin-ui wajib pada pemasangan pertama.' \
        'Tanpa daftar itu UI dan API admin Harbor terbuka untuk seluruh internet.'
fi
izin_ui="$(nilai_dari "$BERKAS_SETELAN" REGISTRY_IZIN_UI)"
[ -n "$izin_ui" ] || gagal "REGISTRY_IZIN_UI kosong di $BERKAS_SETELAN."
printf '    %s\n' "$izin_ui"

langkah 'harbor.yml dan tindihan compose'
# Tanda kutip tunggal disengaja: envsubst hanya mengganti variabel yang disebut di daftar ini.
# shellcheck disable=SC2016
HARBOR_ADMIN_PASSWORD="$kata_sandi_admin" HARBOR_DB_PASSWORD="$kata_sandi_db" \
    envsubst '${HARBOR_ADMIN_PASSWORD} ${HARBOR_DB_PASSWORD}' < "$folder_skrip/harbor.yml.tmpl" > "$tmp/harbor.yml"
grep -q "^data_volume: $DATA\$" "$tmp/harbor.yml" || gagal "data_volume di harbor.yml.tmpl bukan $DATA."
pasang_bila_beda "$tmp/harbor.yml" "$INSTALLER/harbor.yml" 0600 || printf '    harbor.yml tidak berubah\n'
pasang_bila_beda "$folder_skrip/compose.dokploy.yml" "$INSTALLER/docker-compose.override.yml" 0644 \
    || printf '    tindihan compose tidak berubah\n'

langkah 'Stack Harbor'
# Sidik jari setelan yang terakhir berhasil dinyalakan. Selama sama, `prepare` tidak dijalankan.
sidik="$(cat "$INSTALLER/.versi" "$INSTALLER/harbor.yml" "$INSTALLER/docker-compose.override.yml" | sha256sum | cut -d' ' -f1)"
sidik_lama=''
[ ! -f "$INSTALLER/.sidik-terpasang" ] || sidik_lama="$(cat "$INSTALLER/.sidik-terpasang")"
if [ "$sidik" != "$sidik_lama" ] || [ ! -f "$INSTALLER/docker-compose.yml" ]; then
    printf '    setelan berubah, menjalankan prepare\n'
    (cd "$INSTALLER" && ./prepare) > "$tmp/prepare.log" 2>&1 || { cat "$tmp/prepare.log" >&2; gagal 'prepare Harbor gagal.'; }
fi
jumlah_layanan="$(compose config --services | wc -l)"
jumlah_berjalan="$(compose ps --status running --services | wc -l)"
if [ "$sidik" != "$sidik_lama" ] || [ "$jumlah_berjalan" -ne "$jumlah_layanan" ]; then
    compose up -d --remove-orphans
    # nginx di proxy Harbor me-resolve `core`, `portal`, dan `registry` sekali saat menyala. Container
    # yang dibuat ulang tanpa proxy — yang terjadi setiap `prepare` mengganti rahasia core — mendapat
    # alamat baru, dan proxy terus mengirim ke alamat lama. Pada putaran kedua spike, alamat lama core
    # sudah dipakai registryctl, dan `/v2/` dijawab 401 tanpa realm oleh container yang salah.
    compose restart proxy
    tunggu_sehat
    (umask 077 && printf '%s\n' "$sidik" > "$INSTALLER/.sidik-terpasang")
    printf '    %s layanan menyala\n' "$jumlah_layanan"
else
    printf '    %s layanan sudah berjalan dengan setelan yang sama\n' "$jumlah_layanan"
fi

langkah 'Rute Traefik'
daftar_izin=''
for cidr in $izin_ui; do
    daftar_izin+="          - '$cidr'"$'\n'
done
# shellcheck disable=SC2016
DAFTAR_IZIN_UI="${daftar_izin%$'\n'}" envsubst '${DAFTAR_IZIN_UI}' \
    < "$folder_skrip/traefik/coreerp-registry.yml.tmpl" > "$tmp/coreerp-registry.yml"
pasang_bila_beda "$tmp/coreerp-registry.yml" "$BERKAS_RUTE" 0644 || printf '    tidak berubah\n'

langkah 'Pemeriksaan'
# Setiap port Harbor yang terbit di alamat selain loopback adalah pintu masuk yang melewati Traefik.
terbuka="$(docker ps --filter "label=com.docker.compose.project=$PROYEK" --format '{{.Names}} {{.Ports}}' \
    | grep -E '(0\.0\.0\.0|\[::\]):[0-9]+->' || true)"
[ -z "$terbuka" ] || gagal 'Container Harbor menerbitkan port di alamat publik:' "$terbuka"
printf '    tidak ada port Harbor di alamat publik\n'

# Traefik membaca rute baru dalam hitungan detik; `/v2/` yang dijawab 401 berarti rute, sertifikat, dan
# Harbor tersambung. Jawaban lain — 404 dari router tenant, 502/503 — berarti salah satunya belum.
status=''
for _ in $(seq 1 30); do
    status="$(curl -s -o /dev/null -w '%{http_code}' --resolve "$HOST_REGISTRY:443:127.0.0.1" "https://$HOST_REGISTRY/v2/" || true)"
    [ "$status" = '401' ] && break
    sleep 2
done
[ "$status" = '401' ] || gagal "https://$HOST_REGISTRY/v2/ lewat Traefik dijawab $status, bukan 401."
realm="$(curl -sI --resolve "$HOST_REGISTRY:443:127.0.0.1" "https://$HOST_REGISTRY/v2/" | tr -d '\r' | sed -n 's/^[Ww]ww-[Aa]uthenticate: //p')"
case "$realm" in
    *"realm=\"https://$HOST_REGISTRY/service/token\""*) printf '    /v2/ dijawab 401 dengan realm https://%s/service/token\n' "$HOST_REGISTRY" ;;
    *) gagal "Realm token Harbor salah: $realm" 'Periksa external_url di harbor.yml.tmpl.' ;;
esac

printf '\nHarbor %s berjalan di https://%s\n' "$HARBOR_VERSI" "$HOST_REGISTRY"
printf 'Kata sandi admin ada di %s dan tidak dicetak.\n' "$BERKAS_RAHASIA"
