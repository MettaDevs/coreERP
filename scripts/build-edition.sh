#!/usr/bin/env bash
#
# Bangun satu image untuk satu edisi pelanggan.
#
#   scripts/build-edition.sh <edisi> [tag]
#   scripts/build-edition.sh apotek-sejahtera
#   scripts/build-edition.sh praktek-dr-budi coreerp-praktek:uji
#
# Daftar module tidak dibaca langsung dari manifest edisi. `php artisan edition:resolve` yang
# menghitungnya, karena daftar di manifest hanya menyebut yang dibeli pelanggan — dependency
# transitif, module penghubung, dan penolakan bahan uji seluruhnya ditambahkan di sana. Skrip
# ini hanya meneruskan hasilnya ke `docker build`.
#
# Aman dijalankan dua kali: ia tidak mengubah satu berkas pun di repo, dan `docker build` yang
# diulang menghasilkan image yang sama dengan tag yang sama. Pembaruan on-prem dijalankan admin
# di tempat pelanggan, jadi bentuk ini memang harus tahan diulang.

set -euo pipefail

akar="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

gagal() {
    printf '%s\n' "$@" >&2
    exit 1
}

# Daftar edisi yang ada, untuk ditempelkan pada pesan galat. Sebuah pesan "edisi tidak
# ditemukan" yang tidak menyebut apa saja yang ada memaksa orang membuka folder sendiri.
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

edisi="${1:-}"
tag="${2:-}"

if [ -z "$edisi" ]; then
    gagal \
        'Edisi belum disebut.' \
        '' \
        'Pemakaian: scripts/build-edition.sh <edisi> [tag]' \
        "Edisi yang ada: $(edisi_yang_ada)"
fi

manifest="$akar/editions/$edisi.yaml"

if [ ! -f "$manifest" ]; then
    gagal \
        "Edisi \"$edisi\" tidak ada: $manifest tidak ditemukan." \
        "Edisi yang ada: $(edisi_yang_ada)"
fi

for perintah in php docker; do
    command -v "$perintah" >/dev/null 2>&1 \
        || gagal "Perintah \`$perintah\` tidak ada di PATH; ia dibutuhkan untuk membangun image edisi."
done

# `edition:resolve --daftar` mencetak satu id module per baris dan tidak mencetak apa pun untuk
# edisi Core saja. Keluarannya ditangkap, bukan dibiarkan mengalir, supaya bisa diperiksa
# bentuknya sebelum dipakai; kalau perintahnya gagal, pesannya dicetak ulang apa adanya karena
# di sanalah sebab kegagalannya dijelaskan.
if ! keluaran="$(cd "$akar/apps/core" && php artisan edition:resolve "$edisi" --daftar 2>&1)"; then
    printf '%s\n' "$keluaran" >&2
    gagal "Daftar module edisi \"$edisi\" gagal dihitung."
fi

modul=()

while IFS= read -r baris; do
    baris="${baris%$'\r'}"
    baris="$(printf '%s' "$baris" | tr -d '[:space:]')"

    [ -n "$baris" ] || continue

    # Bentuk id module dikunci di sini, bukan dipercaya begitu saja. Baris yang bukan id — sisa
    # hiasan, peringatan, apa pun — akan diteruskan ke `docker build` sebagai nama module dan
    # baru ketahuan sebagai kegagalan yang membingungkan jauh di dalam bangunan.
    case "$baris" in
        [a-z0-9]*[!a-z0-9-]*) gagal "Keluaran \`edition:resolve\` tidak berbentuk id module: \"$baris\"" ;;
        [a-z0-9]*) modul+=("$baris") ;;
        *) gagal "Keluaran \`edition:resolve\` tidak berbentuk id module: \"$baris\"" ;;
    esac
done <<< "$keluaran"

daftar="${modul[*]:-}"

if [ -z "$tag" ]; then
    # Nomor rilis dibaca dari satu kunci di kolom paling kiri manifest. Bentuknya dijaga
    # `editions/README.md`; bila tidak ada, tag jatuh ke `local` daripada membangun image tanpa
    # penanda versi sama sekali.
    rilis="$(sed -n 's/^rilis:[[:space:]]*//p' "$manifest" | head -n 1 | tr -d '[:space:]"'"'")"
    tag="coreerp-$edisi:${rilis:-local}"
fi

if [ ${#modul[@]} -eq 0 ]; then
    printf 'Edisi "%s" tidak membeli satu module pun; image berisi Core saja.\n' "$edisi"
else
    printf 'Edisi "%s" memuat %d module: %s\n' "$edisi" "${#modul[@]}" "$daftar"
fi

printf 'Tag image: %s\n\n' "$tag"

# Konteks pembangunan adalah akar repo, bukan folder app. Alasannya ada di komentar paling atas
# `apps/core/Dockerfile`.
#
# `PASANG_OTEL` diteruskan apa adanya dari lingkungan dan bawaannya `1`, jadi siapa pun yang
# menjalankan skrip ini dengan tangan — termasuk pelanggan yang membangun dari sumber —
# mendapat image utuh tanpa perlu tahu variabel ini ada. Yang menyetelnya ke `0` hanya alur
# `edition.yml`, yang membangun untuk memverifikasi lalu membuang hasilnya.
docker build \
    --file "$akar/apps/core/Dockerfile" \
    --build-arg "MODUL=$daftar" \
    --build-arg "PASANG_OTEL=${PASANG_OTEL:-1}" \
    --tag "$tag" \
    "$akar"

printf '\nImage edisi "%s" selesai dibangun sebagai %s.\n' "$edisi" "$tag"
