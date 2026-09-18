#!/usr/bin/env bash
#
# Bangun image yang dibagikan ke klien.
#
#   scripts/build-edition.sh [tag]
#   scripts/build-edition.sh
#   scripts/build-edition.sh coreerp-edisi:uji
#
# **Satu image untuk seluruh klien, bukan satu image per pelanggan.** Sampai 18 September 2026
# skrip ini menuntut nama edisi sebagai argumen, membaca `editions/<edisi>.yaml`, lalu memangkas
# image mengikuti daftar modul yang dibeli pelanggan itu. Bentuk itu dicabut: image yang dibagikan
# sekarang satu per rilis dan berisi seluruh modul, dan yang menentukan modul mana yang boleh
# dibuka sebuah tenant adalah lisensi yang diterbitkan admin.erp. Alasannya di
# `docs/todo/registry-harbor/README.md`.
#
# Yang tersisa dari pemangkasan justru bagian yang tidak pernah berurusan dengan pelanggan: modul
# ber-`kind: internal-fixture` tetap tidak ikut. Daftarnya tidak ditulis di sini melainkan dihitung
# `php artisan edition:modules`, karena daftar yang ditulis tangan akan ketinggalan pada hari
# sebuah modul mendarat — dan ketinggalannya muncul sebagai menu yang hilang di layar klien.
#
# Tag tidak lagi dihitung dari nomor rilis. Nomor rilis dipegang operator dan diberikan kepada
# perakit lewat `--rilis`; skrip ini hanya membangun, jadi tag-nya disebut pemanggil atau jatuh ke
# `coreerp-edisi:local`.
#
# Aman dijalankan dua kali: ia tidak mengubah satu berkas pun di repo, dan `docker build` yang
# diulang menghasilkan image yang sama dengan tag yang sama.

set -euo pipefail

akar="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

gagal() {
    printf '%s\n' "$@" >&2
    exit 1
}

tag="${1:-}"

[ "$#" -le 1 ] || gagal \
    'Terlalu banyak argumen.' \
    '' \
    'Pemakaian: scripts/build-edition.sh [tag]' \
    'Nama edisi sudah tidak dipakai: yang dibangun satu image untuk seluruh klien.'

for perintah in php docker; do
    command -v "$perintah" >/dev/null 2>&1 \
        || gagal "Perintah \`$perintah\` tidak ada di PATH; ia dibutuhkan untuk membangun image."
done

# `edition:modules --daftar` mencetak satu id modul per baris. Keluarannya ditangkap, bukan
# dibiarkan mengalir, supaya bisa diperiksa bentuknya sebelum dipakai; kalau perintahnya gagal,
# pesannya dicetak ulang apa adanya karena di sanalah sebab kegagalannya dijelaskan.
if ! keluaran="$(cd "$akar/apps/core" && php artisan edition:modules --daftar 2>&1)"; then
    printf '%s\n' "$keluaran" >&2
    gagal 'Daftar modul yang ikut ke dalam image gagal dihitung.'
fi

modul=()

while IFS= read -r baris; do
    baris="${baris%$'\r'}"
    baris="$(printf '%s' "$baris" | tr -d '[:space:]')"

    [ -n "$baris" ] || continue

    # Bentuk id modul dikunci di sini, bukan dipercaya begitu saja. Baris yang bukan id — sisa
    # hiasan, peringatan, apa pun — akan diteruskan ke `docker build` sebagai nama modul dan
    # baru ketahuan sebagai kegagalan yang membingungkan jauh di dalam bangunan.
    case "$baris" in
        [a-z0-9]*[!a-z0-9-]*) gagal "Keluaran \`edition:modules\` tidak berbentuk id modul: \"$baris\"" ;;
        [a-z0-9]*) modul+=("$baris") ;;
        *) gagal "Keluaran \`edition:modules\` tidak berbentuk id modul: \"$baris\"" ;;
    esac
done <<< "$keluaran"

# Nol modul berarti penghitungnya salah alamat, dan image yang dibangun dari situ berisi Core saja.
# Image itu lulus setiap pemeriksaan kebocoran karena memang tidak ada yang bocor, jadi tidak ada
# langkah sesudah ini yang akan menangkapnya. `edition:modules` sendiri sudah menolak folder modul
# yang kosong; baris ini menjaga jalur yang tersisa — keluaran yang habis tersaring pemeriksa
# bentuk di atas.
[ ${#modul[@]} -gt 0 ] || gagal \
    'Tidak satu pun modul terbaca dari `edition:modules`.' \
    'Image yang dibangun dari daftar kosong berisi Core saja, dan tidak ada pemeriksaan sesudah' \
    'ini yang dapat membedakannya dari image yang benar.'

daftar="${modul[*]}"

# Tag bawaan sengaja tidak memuat nomor rilis. Nomor rilis dipegang operator dan diberikan kepada
# `deploy/perakit/rakit.sh --rilis`; sebuah angka yang diambil skrip ini dari tempat lain akan
# menyimpang dari tag yang benar-benar ada di registry, dan menyimpangnya baru terlihat saat sebuah
# situs menarik rilis yang tidak pernah dirakit.
tag="${tag:-coreerp-edisi:local}"

printf 'Image memuat %d modul: %s\n' "${#modul[@]}" "$daftar"
printf 'Tag image: %s\n\n' "$tag"

# Konteks pembangunan adalah akar repo, bukan folder app. Alasannya ada di komentar paling atas
# `apps/core/Dockerfile`.
#
# `PASANG_OTEL` diteruskan apa adanya dari lingkungan dan bawaannya `1`, jadi siapa pun yang
# menjalankan skrip ini dengan tangan mendapat image utuh tanpa perlu tahu variabel ini ada. Yang
# menyetelnya ke `0` hanya alur `edition.yml`, yang membangun untuk memverifikasi lalu membuang
# hasilnya.
docker build \
    --file "$akar/apps/core/Dockerfile" \
    --build-arg MODUL="$daftar" \
    --build-arg PASANG_OTEL="${PASANG_OTEL:-1}" \
    --tag "$tag" \
    "$akar"

printf '\nImage selesai dibangun sebagai %s.\n' "$tag"
