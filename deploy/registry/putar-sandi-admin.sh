#!/usr/bin/env bash
#
# Memutar kata sandi akun `admin` Harbor. Dijalankan sebagai root di server pertama:
#
#   sudo bash deploy/registry/putar-sandi-admin.sh
#
# `harbor_admin_password` di harbor.yml hanya dibaca saat database Harbor lahir, jadi mengganti isi
# rahasia.env saja tidak mengubah apa pun. Skrip ini mengganti kata sandinya lewat API, lalu
# rahasia.env — dengan urutan yang tidak pernah meninggalkan mesin ini tanpa kata sandi yang berlaku:
#
#   1. kata sandi baru ditulis ke berkas sementara di samping rahasia.env;
#   2. API mengganti kata sandi;
#   3. kata sandi baru dicoba; baru sesudah berhasil berkas sementara menggantikan rahasia.env.
#
# Bila langkah 2 berhasil tetapi 3 gagal, kata sandi yang berlaku ada di rahasia.env.baru — skrip
# menyebutnya dan berhenti. Kata sandi tidak pernah dicetak.
#
# harbor.yml ikut diperbarui pada putaran pasang.sh berikutnya; itu memicu prepare dan restart Harbor
# satu kali, tanpa mengubah kata sandi yang sudah ada di database.

set -euo pipefail
shopt -s inherit_errexit

BERKAS_RAHASIA='/etc/coreerp/registry/rahasia.env'
BERKAS_BARU="$BERKAS_RAHASIA.baru"

gagal() {
    printf '\nGAGAL: %s\n' "$1" >&2
    shift
    [ "$#" -eq 0 ] || printf '%s\n' "$@" >&2
    exit 1
}

[ "$(id -u)" -eq 0 ] || gagal 'Jalankan sebagai root.'
[ ! -f "$BERKAS_BARU" ] || gagal "$BERKAS_BARU sudah ada — sisa putaran yang gagal." \
    'Periksa kata sandi mana yang berlaku sebelum menghapusnya.'

lama="$(sed -n 's/^HARBOR_ADMIN_PASSWORD=//p' "$BERKAS_RAHASIA" | tail -n 1)"
[ -n "$lama" ] || gagal "Kata sandi admin tidak ada di $BERKAS_RAHASIA."

# Sama dengan pasang.sh: kebijakan kata sandi Harbor menuntut huruf besar, huruf kecil, dan angka.
baru=''
while :; do
    baru="$(openssl rand -base64 96 | tr -dc 'A-Za-z0-9')"
    baru="${baru:0:32}"
    if [ "${#baru}" -eq 32 ] && [[ $baru =~ [A-Z] ]] && [[ $baru =~ [a-z] ]] && [[ $baru =~ [0-9] ]]; then
        break
    fi
done

(
    umask 077
    sed "s/^HARBOR_ADMIN_PASSWORD=.*/HARBOR_ADMIN_PASSWORD=$baru/" "$BERKAS_RAHASIA" > "$BERKAS_BARU"
)

# Seluruh argumen yang memuat rahasia dikirim sebagai konfigurasi curl lewat stdin, bukan argumen proses.
status="$(printf 'user = "admin:%s"\ndata = "{\\"old_password\\":\\"%s\\",\\"new_password\\":\\"%s\\"}"\n' "$lama" "$lama" "$baru" \
    | docker exec -i harbor-core curl -sS -K - -o /dev/null -w '%{http_code}' -X PUT \
        -H 'Content-Type: application/json' http://localhost:8080/api/v2.0/users/1/password)"
if [ "$status" != '200' ]; then
    rm -f "$BERKAS_BARU"
    gagal "Harbor menolak penggantian kata sandi ($status). Kata sandi lama tetap berlaku."
fi

status="$(printf 'user = "admin:%s"\n' "$baru" \
    | docker exec -i harbor-core curl -sS -K - -o /dev/null -w '%{http_code}' http://localhost:8080/api/v2.0/users/current)"
[ "$status" = '200' ] || gagal "Kata sandi baru tidak dapat dipakai ($status)." \
    "Kata sandi yang sekarang berlaku ada di $BERKAS_BARU; pindahkan ke $BERKAS_RAHASIA setelah diperiksa."

mv "$BERKAS_BARU" "$BERKAS_RAHASIA"
printf 'Kata sandi admin Harbor diputar; %s diperbarui (tidak dicetak).\n' "$BERKAS_RAHASIA"
