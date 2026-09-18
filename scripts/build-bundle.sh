#!/usr/bin/env bash
#
# Menyusun satu bundle yang dapat dipasang di server pelanggan tanpa git, tanpa composer, tanpa npm,
# dan tanpa akses ke repo kita.
#
# Itu perbedaannya dengan `deploy.sh` di repo penyebaran, yang **membangun di server pelanggan**: ia
# menyinkronkan repo lewat git lalu `docker build`, sehingga menuntut keempat hal di atas. Bundle
# menghapus keempatnya dengan membawa image yang sudah jadi.
#
#   scripts/build-bundle.sh --rilis <nomor> [--keluaran <folder>] [--kunci <berkas kunci privat>]
#   scripts/build-bundle.sh --rilis 0.2.1
#   scripts/build-bundle.sh --rilis 0.2.1 --kunci ~/.coreerp/rilis.key
#
# **Nama edisi sudah tidak diminta.** Folder `editions/` dihapus pada 18 September 2026: yang
# dibagikan ke klien satu image berisi seluruh modul, dan modul yang boleh dibuka sebuah tenant
# dikunci lisensi dari admin.erp — bukan dipangkas dari image.
#
# Nomor rilis diminta lewat `--rilis`, sama dengan `deploy/perakit/rakit.sh`, dan itu satu-satunya
# sumbernya. Berkas apa pun di repo yang ikut menyimpan nomor rilis akan menyimpang dari tag yang
# benar-benar ada di registry, dan menyimpangnya baru terlihat di tangan admin yang memasang.
#
# Isi bundle:
#
#   coreerp-<rilis>/
#     manifest.json     keterangan rilis: edisi, nomor rilis, tag dan digest image, daftar module
#     images.tar.gz     **seluruh** image yang dibutuhkan runtime, hasil `docker save`
#     compose.yaml      berkas compose yang ikut, bukan yang ditarik dari repo lain
#     update.sh         skrip pemasangan; salinan yang terpasang di server yang benar-benar jalan
#     SHA256SUMS        checksum seluruh berkas di atas
#     SHA256SUMS.sig    tanda tangan atas SHA256SUMS, bila kunci privat disebut
#
# **Seluruh** image, bukan hanya image CoreERP. Runtime-nya juga membutuhkan PostgreSQL dan perender
# PDF, dan keduanya datang dari registry publik. Bundle yang hanya membawa image edisi masih harus
# menarik keduanya saat dipasang — dan bila tarikan itu gagal, gagalnya pada langkah menyalakan
# container, sesudah admin mengira pemasangannya berhasil. Ongkosnya nyata: bundle menjadi ratusan
# megabyte lebih besar.
#
# **Checksum dan tanda tangan bukan pengganti satu sama lain.** Checksum menjaga dari berkas yang
# rusak saat disalin; tanda tangan menjaga dari berkas yang diganti orang. Bundle tanpa tanda tangan
# tetap dapat dibangun — untuk mencoba di mesin sendiri — dan `update.sh` yang menolaknya di tempat
# pelanggan.
#
# Kunci **publik** sengaja tidak ikut di dalam bundle. Bundle yang membawa kunci pemverifikasinya
# sendiri tidak memverifikasi apa pun terhadap orang yang mengganti keduanya sekaligus; ia hanya
# membuktikan dirinya konsisten dengan dirinya sendiri. Kunci publik dipasang sekali di server lewat
# jalur yang berbeda dari bundle-nya. Alasan lengkapnya di `docs/todo/bundle-on-prem/README.md`.

set -euo pipefail

akar="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

gagal() {
    printf '%s\n' "$@" >&2
    exit 1
}

PAKAI='Pemakaian: scripts/build-bundle.sh --rilis <nomor> [--keluaran <folder>] [--kunci <berkas kunci privat>]'

rilis=""
keluaran=""
kunci=""

while [ "$#" -gt 0 ]; do
    case "$1" in
        --rilis)
            [ "$#" -ge 2 ] || gagal '--rilis menuntut satu nomor rilis.' "$PAKAI"
            rilis="$2"
            shift 2
            ;;
        --keluaran)
            [ "$#" -ge 2 ] || gagal '--keluaran menuntut satu folder.' "$PAKAI"
            keluaran="$2"
            shift 2
            ;;
        --kunci)
            [ "$#" -ge 2 ] || gagal '--kunci menuntut satu berkas kunci privat.' "$PAKAI"
            kunci="$2"
            shift 2
            ;;
        *)
            gagal \
                "Argumen tidak dikenal: $1" \
                '' \
                "$PAKAI" \
                'Nama edisi sudah tidak diminta: yang dibangun satu bundle untuk seluruh klien.'
            ;;
    esac
done

# Bentuknya dikunci di sini, bukan dipercaya begitu saja. Nomor rilis ikut menjadi nama folder
# bundle dan tag image di dalamnya; yang salah bentuk baru ketahuan di folder unduhan admin.
case "$rilis" in
    '') gagal 'Nomor rilis belum disebut.' '' "$PAKAI" ;;
    *[!0-9.]*|.*|*.) gagal "Nomor rilis \"$rilis\" bukan angka bertitik." '' "$PAKAI" ;;
esac

for perintah in docker php sha256sum tar gzip; do
    command -v "$perintah" >/dev/null 2>&1 \
        || gagal "Perintah \`$perintah\` tidak ada di PATH; ia dibutuhkan untuk menyusun bundle."
done

if [ -n "$kunci" ]; then
    command -v openssl >/dev/null 2>&1 \
        || gagal 'Perintah `openssl` tidak ada di PATH, padahal --kunci disebut.'
    [ -f "$kunci" ] || gagal "Berkas kunci privat tidak ditemukan: $kunci"
fi

berkas_compose="$akar/deploy/compose.edition.yaml"

[ -f "$berkas_compose" ] || gagal \
    "Berkas compose bundle tidak ditemukan: $berkas_compose" \
    'Bundle harus membawa berkas compose-nya sendiri; menarik dari repo lain berarti pemasangan' \
    'menuntut akses yang justru hendak dihapus bundle ini.'

nama_bundle="coreerp-$rilis"
keluaran="${keluaran:-$akar/dist}"
tujuan="$keluaran/$nama_bundle"

if [ -e "$tujuan" ]; then
    gagal \
        "$tujuan sudah ada." \
        '' \
        'Bundle tidak ditimpa: menimpanya berarti sebuah bundle yang mungkin sudah dikirim ke' \
        'pelanggan berubah isinya tanpa berubah namanya, dan checksum yang sudah dicatat orang' \
        'lain berhenti cocok tanpa sebab yang terlihat.' \
        '' \
        'Hapus foldernya sendiri bila memang ingin menyusun ulang.'
fi

image="coreerp-edisi:$rilis"

printf 'Membangun image sebagai %s...\n\n' "$image"
bash "$akar/scripts/build-edition.sh" "$image"

# Daftar module dihitung ulang di sini, bukan disalin dari langkah di atas. Manifest rilis harus
# menyebut apa yang benar-benar ada di dalam image, dan `edition:modules` adalah satu-satunya tempat
# penolakan modul bahan uji ditulis.
if ! daftar_module="$(cd "$akar/apps/core" && php artisan edition:modules --daftar 2>&1)"; then
    printf '%s\n' "$daftar_module" >&2
    gagal 'Daftar module yang ikut ke dalam image gagal dihitung.'
fi

module_json=""

while IFS= read -r baris; do
    baris="$(printf '%s' "${baris%$'\r'}" | tr -d '[:space:]')"
    [ -n "$baris" ] || continue
    module_json="$module_json${module_json:+, }\"$baris\""
done <<< "$daftar_module"

# Image pendamping dibaca dari berkas compose yang ikut di dalam bundle, bukan ditulis tangan di
# sini. Daftar yang ditulis tangan akan menyimpang pada hari sebuah layanan ditambahkan, dan
# menyimpangnya baru ketahuan di mesin pelanggan.
pendamping=()

while IFS= read -r baris; do
    [ -n "$baris" ] || continue
    pendamping+=("$baris")
done < <(grep -oE '^[[:space:]]*image:[[:space:]]*[^$[:space:]][^[:space:]]*' "$berkas_compose" \
    | sed 's/^[[:space:]]*image:[[:space:]]*//' | sort -u)

if [ ${#pendamping[@]} -eq 0 ]; then
    gagal \
        "Tidak satu pun image pendamping terbaca dari $berkas_compose." \
        'Runtime membutuhkan PostgreSQL dan perender PDF; bundle tanpa keduanya bergantung pada registry' \
        'saat dipasang, dan gagalnya baru terlihat sesudah admin mengira berhasil.'
fi

mkdir -p "$tujuan"

printf '\nMenarik image pendamping bila belum ada di mesin ini...\n'

for satu in "${pendamping[@]}"; do
    if docker image inspect "$satu" >/dev/null 2>&1; then
        printf '  sudah ada: %s\n' "$satu"
    else
        printf '  menarik:   %s\n' "$satu"
        docker pull --quiet "$satu" >/dev/null
    fi
done

printf '\nMenyimpan %d image ke dalam bundle...\n' "$((${#pendamping[@]} + 1))"
docker save "$image" "${pendamping[@]}" | gzip > "$tujuan/images.tar.gz"

# Digest image dicatat supaya `update.sh` dapat membuktikan image yang dimuatnya memang image yang
# disebut manifest — bukan image bertag sama yang kebetulan sudah ada di mesin itu.
digest="$(docker image inspect "$image" --format '{{.Id}}')"

cp "$berkas_compose" "$tujuan/compose.yaml"
cp "$akar/scripts/update.sh" "$tujuan/update.sh"
chmod +x "$tujuan/update.sh"

pendamping_json=""

for satu in "${pendamping[@]}"; do
    pendamping_json="$pendamping_json${pendamping_json:+, }\"$satu\""
done

# `edisi` tetap ditulis, dan nilainya tetap `coreerp`. Tidak ada lagi edisi per pelanggan, tetapi
# `update.sh` di server klien membacanya dan mencetaknya, dan agen menolak berkas rilis yang
# `edisi`-nya berbeda dari yang terpasang. Bidangnya hilang bersama CP-04 di
# `docs/todo/registry-harbor/README.md`, yang mengubah kedua sisi sekaligus.
cat > "$tujuan/manifest.json" <<JSON
{
  "edisi": "coreerp",
  "rilis": "$rilis",
  "image": "$image",
  "digest": "$digest",
  "image_pendamping": [$pendamping_json],
  "module": [$module_json],
  "dibangun_pada": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
JSON

# Checksum dihitung dari dalam folder bundle supaya berkas SHA256SUMS memuat nama relatif. Nama
# absolut membuat pemeriksaan gagal di mesin pelanggan, yang folder unduhannya jelas berbeda.
(
    cd "$tujuan"
    sha256sum images.tar.gz compose.yaml update.sh manifest.json > SHA256SUMS
)

if [ -n "$kunci" ]; then
    printf 'Menandatangani SHA256SUMS...\n'
    openssl dgst -sha256 -sign "$kunci" -out "$tujuan/SHA256SUMS.sig" "$tujuan/SHA256SUMS"
else
    printf '\nPeringatan: bundle ini TIDAK ditandatangani.\n' >&2
    printf 'Ia dapat dipakai untuk mencoba di mesin sendiri, dan akan ditolak update.sh di tempat\n' >&2
    printf 'pelanggan. Sebutkan --kunci untuk menandatanganinya.\n' >&2
fi

ukuran="$(du -sh "$tujuan" | cut -f1)"

printf '\nBundle selesai: %s (%s)\n' "$tujuan" "$ukuran"
printf 'Rilis %s, image %s\n' "$rilis" "$image"

if [ -n "$module_json" ]; then
    printf 'Module di dalamnya: %s\n' "$(printf '%s' "$module_json" | tr -d '"')"
else
    printf 'Module di dalamnya: (tidak ada; Core saja)\n'
fi
