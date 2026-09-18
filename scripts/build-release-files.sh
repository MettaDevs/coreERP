#!/usr/bin/env bash
#
# Menyusun berkas rilis yang ditarik agen situs: bundle tanpa arsip image.
#
#   scripts/build-release-files.sh --rilis <nomor> <image@sha256:digest> <folder keluaran> [--kunci <kunci privat>]
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
# Image yang dirujuk harus sudah ada di mesin ini — skrip ini dijalankan sesudah image didorong,
# jadi id image dibaca dari image yang sama persis dengan yang ada di registry.
#
# ## Kenapa manifest masih menyebut `edisi`, padahal edisi per pelanggan sudah dicabut
#
# Nilainya tetap — `coreerp` — dan itu jembatan yang disengaja. Folder `editions/` dihapus pada
# 18 September 2026: yang dibagikan satu image berisi seluruh modul, dan modul yang boleh dibuka
# sebuah tenant dikunci lisensi dari admin.erp. Tetapi admin.erp masih menyimpan rilis berkunci
# (edisi, rilis), dan agen di server klien menolak berkas rilis yang `edisi`-nya berbeda dari yang
# terpasang. Membuang bidangnya di sini lebih dulu berarti setiap situs yang sudah berjalan menolak
# pembaruan berikutnya. Bidang itu hilang bersama CP-04 di `docs/todo/registry-harbor/README.md`,
# yang mengubah kedua sisi sekaligus.
#
# ## Kenapa nomor rilis diminta, bukan dibaca
#
# Sama dengan `deploy/perakit/rakit.sh --rilis`, dan sengaja: satu angka, satu sumber, yaitu
# operator. Berkas apa pun di repo yang ikut menyimpan nomor rilis akan menyimpang dari tag yang
# benar-benar ada di Harbor, dan menyimpangnya baru terlihat saat sebuah situs menarik rilis yang
# tidak pernah dirakit.

set -euo pipefail

akar="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

gagal() {
    printf '%s\n' "$@" >&2
    exit 1
}

PAKAI='Pemakaian: scripts/build-release-files.sh --rilis <nomor> <image@sha256:digest> <folder keluaran> [--kunci <kunci privat>]'

rilis=''
kunci=''
posisi=()

while [ "$#" -gt 0 ]; do
    case "$1" in
        --rilis)
            [ "$#" -ge 2 ] || gagal '--rilis menuntut satu nomor rilis.' "$PAKAI"
            rilis="$2"
            shift 2
            ;;
        --kunci)
            [ "$#" -ge 2 ] || gagal '--kunci menuntut satu berkas kunci privat.' "$PAKAI"
            kunci="$2"
            shift 2
            ;;
        -*) gagal "Argumen tidak dikenal: $1" "$PAKAI" ;;
        *) posisi+=("$1"); shift ;;
    esac
done

[ "${#posisi[@]}" -eq 2 ] || gagal 'Image dan folder keluaran wajib disebut, keduanya.' "$PAKAI"

image="${posisi[0]}"
keluaran="${posisi[1]}"

# Bentuknya dikunci di sini, bukan dipercaya begitu saja. Nomor rilis yang salah bentuk berakhir
# sebagai nama tag di registry dan sebagai kunci baris di admin.erp; keduanya tidak dapat diperbaiki
# tanpa menyentuh data yang sudah terkirim.
case "$rilis" in
    '') gagal 'Nomor rilis belum disebut.' "$PAKAI" ;;
    *[!0-9.]*|.*|*.) gagal "Nomor rilis \"$rilis\" bukan angka bertitik." "$PAKAI" ;;
esac

# Tetap `coreerp`, dan alasannya di komentar paling atas berkas ini.
edisi='coreerp'


case "$image" in
    *@sha256:*) ;;
    *) gagal "Image harus disebut lewat digest registry (…@sha256:…), bukan tag: $image" ;;
esac


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
