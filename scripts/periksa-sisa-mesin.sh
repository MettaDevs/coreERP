#!/usr/bin/env bash
#
# Membuktikan bahwa berkas sisa mesin pembangun tidak ikut masuk ke image edisi.
#
# Pemeriksa ini lahir dari satu temuan pada image yang dibangun di mesin pengembang: di dalamnya
# ada 40 berkas `storage/framework/testing/` — termasuk hasil ekspor `.docx` dan gambar identitas
# milik tenant bahan uji — dan 64 view Blade terkompilasi. Semuanya berasal dari suite test yang
# pernah dijalankan di mesin itu, bukan dari kode yang dikirim.
#
# Dua di antaranya berbahaya, bukan sekadar kotor:
#
#   1. Berkas di `storage/app` dan `storage/framework/testing` **disalin ke volume pelanggan**.
#      Docker mengisi named volume yang masih kosong dari isi image pada path yang sama, jadi
#      berkas mesin pengembang berakhir di storage pelanggan pada boot pertama.
#   2. View Blade terkompilasi dipilih berdasarkan waktu ubah. Satu view basi dari mesin
#      pengembang dapat menggantikan template yang sebenarnya dikirim, dan yang terlihat pelanggan
#      adalah halaman versi lama tanpa satu pun pesan galat.
#
# CI tidak dapat menemukan ini sendiri: runner melakukan checkout bersih, sehingga sisa itu tidak
# pernah ada di sana. Pemeriksa yang hanya lulus karena keadaannya tidak pernah terjadi tidak
# memeriksa apa pun. Maka pemeriksa ini **membuat keadaannya sendiri**: ia menanam berkas penanda
# di tempat sisa itu muncul, lalu memastikan penanda tersebut tidak sampai ke dalam image.
#
# Pemakaian:
#   scripts/periksa-sisa-mesin.sh tanam
#   scripts/periksa-sisa-mesin.sh periksa <image>
#   scripts/periksa-sisa-mesin.sh bersihkan
#
# Urutannya: `tanam`, lalu bangun image seperti biasa, lalu `periksa`, lalu `bersihkan`.

set -euo pipefail

akar="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
app="$akar/apps/core"

PENANDA='SISA-MESIN-PEMBANGUN-PENANDA'

# Tempat sisa mesin pembangun benar-benar muncul. Keempatnya ditanam, dan keempatnya harus hilang.
TERLARANG=(
    "storage/framework/testing/disks/penanda-sisa.txt"
    "storage/framework/views/penanda-sisa.php"
    "storage/framework/cache/data/penanda-sisa.txt"
    "storage/logs/penanda-sisa.log"
    "storage/app/penanda-sisa.json"
)

# Penanda kendali. Ia berada di tempat yang memang harus ikut ke dalam image, dan keberadaannya
# diperiksa bersama yang lain. Tanpa ini, sebuah `docker build` yang ternyata memakai konteks lain
# — atau cache lapisan lama — akan melaporkan "tidak ada penanda terlarang" dan terlihat seperti
# lulus, padahal ia tidak pernah melihat satu pun berkas yang ditanam.
KENDALI="resources/penanda-kendali.txt"

gagal() {
    printf '%s\n' "$@" >&2
    exit 1
}

tanam() {
    local relatif berkas
    for relatif in "${TERLARANG[@]}" "$KENDALI"; do
        berkas="$app/$relatif"
        mkdir -p "$(dirname "$berkas")"
        printf '%s\n' "$PENANDA" > "$berkas"
    done
    printf 'Ditanam %d penanda terlarang dan 1 penanda kendali di dalam konteks pembangunan.\n' \
        "${#TERLARANG[@]}"
}

bersihkan() {
    local relatif
    for relatif in "${TERLARANG[@]}" "$KENDALI"; do
        rm -f "$app/$relatif"
    done
    printf 'Penanda dibersihkan dari konteks pembangunan.\n'
}

periksa() {
    local image="$1"
    [ -n "$image" ] || gagal 'Nama image belum disebut.'

    command -v docker >/dev/null 2>&1 || gagal 'Perintah `docker` tidak ada di PATH.'

    # Satu kali masuk ke dalam image, bukan satu kali per penanda: `docker run` berbiaya beberapa
    # ratus milidetik, dan tujuh kali biaya itu tidak membeli kejelasan apa pun.
    local daftar
    daftar="$(printf '%s\n' "${TERLARANG[@]}" "$KENDALI")"

    local hasil
    hasil="$(printf '%s\n' "$daftar" | docker run --rm -i --entrypoint sh "$image" -c '
        while IFS= read -r relatif; do
            [ -n "$relatif" ] || continue
            if [ -e "/repo/apps/core/$relatif" ]; then
                echo "ADA $relatif"
            else
                echo "TIDAK $relatif"
            fi
        done
    ')" || gagal "Tidak dapat memeriksa isi image \"$image\"."

    local bocor=() kendali_terlihat=no relatif keadaan
    while read -r keadaan relatif; do
        [ -n "$relatif" ] || continue
        if [ "$relatif" = "$KENDALI" ]; then
            [ "$keadaan" = 'ADA' ] && kendali_terlihat=yes
            continue
        fi
        [ "$keadaan" = 'ADA' ] && bocor+=("$relatif")
    done <<< "$hasil"

    if [ "$kendali_terlihat" = 'no' ]; then
        gagal \
            "Penanda kendali \"$KENDALI\" tidak ada di dalam image \"$image\"." \
            '' \
            'Artinya image ini tidak dibangun dari konteks yang ditanami, jadi pemeriksaan ini' \
            'tidak membuktikan apa pun. Jalankan `tanam`, bangun image-nya, baru `periksa`.'
    fi

    if [ ${#bocor[@]} -gt 0 ]; then
        printf 'Berkas sisa mesin pembangun masuk ke dalam image "%s":\n' "$image" >&2
        printf '  %s\n' "${bocor[@]}" >&2
        gagal \
            '' \
            'Tambahkan path tersebut ke `.dockerignore` di akar repo. Berkas di bawah `storage/`' \
            'ikut disalin ke volume pelanggan pada boot pertama, dan view Blade terkompilasi yang' \
            'basi dapat menggantikan template yang sebenarnya dikirim.'
    fi

    printf 'Image "%s" bersih: %d penanda terlarang tidak ada, penanda kendali ada.\n' \
        "$image" "${#TERLARANG[@]}"
}

# Membuktikan bahwa `periksa` bisa merah — tanpa membangun ulang image edisi.
#
# Sebuah pemeriksa yang belum pernah gagal tidak dapat dibedakan dari pemeriksa yang tidak
# memeriksa apa pun, dan yang kedua jauh lebih berbahaya karena ia mengakhiri pencarian. Di sini
# jalur merahnya dibuat dengan image sekali pakai berisi tiga baris: ia menyalin `storage/` apa
# adanya, memakai `.dockerignore` miliknya sendiri yang kosong. BuildKit mencari
# `<Dockerfile>.dockerignore` lebih dulu sebelum jatuh ke `.dockerignore` akar konteks, dan itulah
# yang membuat langkah ini selesai dalam hitungan detik alih-alih menunggu satu bangunan penuh.
buktikan_merah() {
    local sementara
    sementara="$(mktemp -d)"

    printf '%s\n' \
        'FROM alpine' \
        'COPY apps/core/storage /repo/apps/core/storage' \
        'COPY apps/core/resources /repo/apps/core/resources' \
        > "$sementara/Merah.Dockerfile"
    : > "$sementara/Merah.Dockerfile.dockerignore"

    local image='coreerp-sisa-mesin:uji-merah'

    tanam >/dev/null
    docker build --quiet --file "$sementara/Merah.Dockerfile" --tag "$image" "$akar" >/dev/null

    # Subshell, bukan pemanggilan biasa: `gagal` menutup proses dengan `exit`, dan `exit` di dalam
    # fungsi mengakhiri seluruh skrip — termasuk langkah pembersihan di bawah.
    local kode=0
    ( periksa "$image" ) >/dev/null 2>&1 || kode=$?

    docker image rm --force "$image" >/dev/null 2>&1 || true
    bersihkan >/dev/null
    rm -rf "$sementara"

    if [ "$kode" -eq 0 ]; then
        gagal \
            'Pemeriksa hijau pada image yang memang memuat seluruh berkas penanda.' \
            'Ia tidak memeriksa apa pun.'
    fi

    printf 'Pemeriksa gagal seperti yang diharapkan, dengan kode keluar %d.\n' "$kode"
}

case "${1:-}" in
    tanam) tanam ;;
    bersihkan) bersihkan ;;
    periksa) shift; periksa "${1:-}" ;;
    buktikan-merah) buktikan_merah ;;
    *)
        gagal \
            'Pemakaian: scripts/periksa-sisa-mesin.sh tanam' \
            '           scripts/periksa-sisa-mesin.sh periksa <image>' \
            '           scripts/periksa-sisa-mesin.sh bersihkan' \
            '           scripts/periksa-sisa-mesin.sh buktikan-merah'
        ;;
esac
