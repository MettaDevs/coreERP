#!/usr/bin/env bash
#
# Menyusun satu bundle yang dapat dipasang di server pelanggan tanpa git, tanpa composer, tanpa npm,
# dan tanpa akses ke repo kita.
#
# Itu perbedaannya dengan `deploy.sh` di repo penyebaran, yang **membangun di server pelanggan**: ia
# menyinkronkan repo lewat git lalu `docker build`, sehingga menuntut keempat hal di atas. Bundle
# menghapus keempatnya dengan membawa image yang sudah jadi.
#
#   scripts/build-bundle.sh <edisi> [--keluaran <folder>] [--kunci <berkas kunci privat>]
#   scripts/build-bundle.sh apotek-sejahtera
#   scripts/build-bundle.sh apotek-sejahtera --kunci ~/.coreerp/rilis.key
#
# Isi bundle:
#
#   coreerp-<edisi>-<rilis>/
#     manifest.json     keterangan rilis: edisi, nomor rilis, tag dan digest image, daftar module
#     images.tar.gz     **seluruh** image yang dibutuhkan runtime, hasil `docker save`
#     compose.yaml      berkas compose yang ikut, bukan yang ditarik dari repo lain
#     update.sh         skrip pemasangan; salinan yang terpasang di server yang benar-benar jalan
#     SHA256SUMS        checksum seluruh berkas di atas
#     SHA256SUMS.sig    tanda tangan atas SHA256SUMS, bila kunci privat disebut
#
# **Seluruh** image, bukan hanya image edisi. Runtime-nya juga membutuhkan PostgreSQL dan perender
# PDF, dan keduanya datang dari registry publik. Bundle yang hanya membawa image edisi akan gagal
# menyala di mesin tanpa internet — dan gagalnya pada langkah menyalakan container, sesudah admin
# mengira pemasangannya berhasil. Ongkosnya nyata: bundle menjadi ratusan megabyte lebih besar.
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

edisi_yang_ada() {
    local berkas nama daftar=()

    for berkas in "$akar"/editions/*.yaml; do
        [ -e "$berkas" ] || continue
        nama="$(basename "$berkas" .yaml)"
        daftar+=("$nama")
    done

    if [ ${#daftar[@]} -eq 0 ]; then
        printf '(tidak ada satu pun di %s/editions)' "$akar"
    else
        printf '%s' "${daftar[*]}"
    fi
}

edisi=""
keluaran=""
kunci=""

while [ "$#" -gt 0 ]; do
    case "$1" in
        --keluaran)
            [ "$#" -ge 2 ] || gagal '--keluaran menuntut satu folder.'
            keluaran="$2"
            shift 2
            ;;
        --kunci)
            [ "$#" -ge 2 ] || gagal '--kunci menuntut satu berkas kunci privat.'
            kunci="$2"
            shift 2
            ;;
        -*)
            gagal "Argumen tidak dikenal: $1"
            ;;
        *)
            [ -z "$edisi" ] || gagal "Edisi sudah disebut sebagai \"$edisi\"; tidak dapat menerima \"$1\" juga."
            edisi="$1"
            shift
            ;;
    esac
done

if [ -z "$edisi" ]; then
    gagal \
        'Edisi belum disebut.' \
        '' \
        'Pemakaian: scripts/build-bundle.sh <edisi> [--keluaran <folder>] [--kunci <berkas kunci privat>]' \
        "Edisi yang ada: $(edisi_yang_ada)"
fi

manifest_edisi="$akar/editions/$edisi.yaml"

[ -f "$manifest_edisi" ] || gagal \
    "Edisi \"$edisi\" tidak ada: $manifest_edisi tidak ditemukan." \
    "Edisi yang ada: $(edisi_yang_ada)"

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

# Nomor rilis dibaca dari manifest edisi, sama seperti yang dilakukan `build-edition.sh`. Bundle
# tanpa nomor rilis tidak dapat dibedakan dari bundle lain di folder unduhan admin, dan admin yang
# tidak dapat membedakannya akan memasang yang salah.
rilis="$(sed -n 's/^rilis:[[:space:]]*//p' "$manifest_edisi" | head -n 1 | tr -d '[:space:]"'"'")"

[ -n "$rilis" ] || gagal \
    "Manifest edisi \"$edisi\" tidak menyebut \`rilis:\`." \
    'Bentuk manifest edisi dijelaskan di editions/README.md.'

pelanggan="$(sed -n 's/^pelanggan:[[:space:]]*//p' "$manifest_edisi" | head -n 1 | sed 's/[[:space:]]*$//' | tr -d '"'"'")"

nama_bundle="coreerp-$edisi-$rilis"
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

image="coreerp-edisi:$edisi-$rilis"

printf 'Membangun image edisi "%s" sebagai %s...\n\n' "$edisi" "$image"
bash "$akar/scripts/build-edition.sh" "$edisi" "$image"

# Daftar module dihitung ulang di sini, bukan dibaca dari manifest edisi. Manifest hanya menyebut
# yang dibeli pelanggan; dependency, module penghubung, dan penolakan bahan uji seluruhnya
# ditambahkan `edition:resolve`. Manifest rilis harus menyebut apa yang benar-benar ada di dalam
# image, bukan apa yang diminta.
if ! daftar_module="$(cd "$akar/apps/control-plane" && php artisan edition:resolve "$edisi" --daftar 2>&1)"; then
    printf '%s\n' "$daftar_module" >&2
    gagal "Daftar module edisi \"$edisi\" gagal dihitung."
fi

module_json=""

while IFS= read -r baris; do
    baris="$(printf '%s' "${baris%$'\r'}" | tr -d '[:space:]')"
    [ -n "$baris" ] || continue
    module_json="$module_json${module_json:+, }\"$baris\""
done <<< "$daftar_module"

# Image pendamping dibaca dari berkas compose yang ikut di dalam bundle, bukan ditulis tangan di
# sini. Daftar yang ditulis tangan akan menyimpang pada hari sebuah layanan ditambahkan, dan
# menyimpangnya baru ketahuan di mesin pelanggan yang tidak punya internet untuk menambalnya.
pendamping=()

while IFS= read -r baris; do
    [ -n "$baris" ] || continue
    pendamping+=("$baris")
done < <(grep -oE '^[[:space:]]*image:[[:space:]]*[^$[:space:]][^[:space:]]*' "$berkas_compose" \
    | sed 's/^[[:space:]]*image:[[:space:]]*//' | sort -u)

if [ ${#pendamping[@]} -eq 0 ]; then
    gagal \
        "Tidak satu pun image pendamping terbaca dari $berkas_compose." \
        'Runtime membutuhkan PostgreSQL dan perender PDF; bundle tanpa keduanya akan gagal menyala' \
        'di mesin tanpa internet, dan gagalnya baru terlihat sesudah admin mengira berhasil.'
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

cat > "$tujuan/manifest.json" <<JSON
{
  "edisi": "$edisi",
  "pelanggan": "$pelanggan",
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
printf 'Edisi "%s" rilis %s, image %s\n' "$edisi" "$rilis" "$image"

if [ -n "$module_json" ]; then
    printf 'Module di dalamnya: %s\n' "$(printf '%s' "$module_json" | tr -d '"')"
else
    printf 'Module di dalamnya: (tidak ada; Core saja)\n'
fi
