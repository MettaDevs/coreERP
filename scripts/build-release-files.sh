#!/usr/bin/env bash
#
# Menyusun berkas rilis yang ditarik agen situs untuk satu edisi: bundle tanpa arsip image.
#
#   scripts/build-release-files.sh <edisi> <image@sha256:digest> <folder keluaran> [--kunci <kunci privat>]
#
# Isinya:
#
#   manifest.json     edisi, rilis, image lewat digest registry, id image, daftar image pendamping
#   compose.yaml      deploy/compose.edition.yaml apa adanya
#   update.sh         scripts/update.sh apa adanya
#   SHA256SUMS        checksum ketiga berkas di atas — tidak lebih
#   SHA256SUMS.sig    tanda tangan atas SHA256SUMS, bila kunci disebut
#
# Bedanya dengan `build-bundle.sh`: image tidak ikut. Agen di server klien menariknya dari registry
# lewat digest, lalu `update.sh` memeriksa id image yang ditariknya sama dengan yang disebut manifest.
# Rantai kepercayaannya tetap utuh: tanda tangan menjamin SHA256SUMS, SHA256SUMS menjamin manifest,
# dan manifest menyebut isi image lewat digest yang tidak dapat dipindahkan seperti tag.
#
# `images.tar.gz` sengaja TIDAK disebut di SHA256SUMS. Agen menjalankan `sha256sum --check`, dan
# berkas yang disebut tetapi tidak ada akan menggagalkan pembaruan di server klien. admin.erp menolak
# SHA256SUMS yang menyebutnya.
#
# Image yang dirujuk harus sudah ada di mesin ini — alur rilis menjalankan skrip ini sesudah image
# didorong, jadi id image dibaca dari image yang sama persis dengan yang ada di registry.
set -euo pipefail

akar="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

gagal() {
    printf '%s\n' "$@" >&2
    exit 1
}

[ "$#" -ge 3 ] || gagal 'Pemakaian: scripts/build-release-files.sh <edisi> <image@sha256:digest> <folder keluaran> [--kunci <kunci privat>]'

edisi="$1"
image="$2"
keluaran="$3"
shift 3
kunci=""

while [ "$#" -gt 0 ]; do
    case "$1" in
        --kunci)
            [ "$#" -ge 2 ] || gagal '--kunci menuntut satu berkas kunci privat.'
            kunci="$2"
            shift 2
            ;;
        *) gagal "Argumen tidak dikenal: $1" ;;
    esac
done

manifest_edisi="$akar/editions/$edisi.yaml"
[ -f "$manifest_edisi" ] || gagal "Edisi \"$edisi\" tidak ada: $manifest_edisi tidak ditemukan."

case "$image" in
    *@sha256:*) ;;
    *) gagal "Image harus disebut lewat digest registry (…@sha256:…), bukan tag: $image" ;;
esac

rilis="$(sed -n 's/^rilis:[[:space:]]*//p' "$manifest_edisi" | head -n 1 | tr -d '[:space:]"'"'")"
[ -n "$rilis" ] || gagal "Manifest edisi \"$edisi\" tidak menyebut \`rilis:\`."

id_image="$(docker image inspect "$image" --format '{{.Id}}')"
[ -n "$id_image" ] || gagal "Image $image tidak ada di mesin ini."

mkdir -p "$keluaran"

cp "$akar/deploy/compose.edition.yaml" "$keluaran/compose.yaml"
cp "$akar/scripts/update.sh" "$keluaran/update.sh"

pendamping_json=""

while IFS= read -r baris; do
    [ -n "$baris" ] || continue
    pendamping_json="$pendamping_json${pendamping_json:+, }\"$baris\""
done < <(grep -oE '^[[:space:]]*image:[[:space:]]*[^$[:space:]][^[:space:]]*' "$akar/deploy/compose.edition.yaml" \
    | sed 's/^[[:space:]]*image:[[:space:]]*//' | sort -u)

cat > "$keluaran/manifest.json" <<JSON
{
  "edisi": "$edisi",
  "rilis": "$rilis",
  "image": "$image",
  "digest": "$id_image",
  "image_pendamping": [$pendamping_json],
  "dibangun_pada": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
JSON

(
    cd "$keluaran"
    sha256sum compose.yaml manifest.json update.sh > SHA256SUMS
)

if [ -n "$kunci" ]; then
    openssl dgst -sha256 -sign "$kunci" -out "$keluaran/SHA256SUMS.sig" "$keluaran/SHA256SUMS"
fi

printf 'Berkas rilis edisi %s rilis %s: %s\n' "$edisi" "$rilis" "$keluaran"
