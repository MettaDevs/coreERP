#!/usr/bin/env bash
#
# update.sh tiruan untuk pengujian agen. Mencetak langkah `==> ` dengan bentuk yang sama dengan
# `scripts/update.sh`, tanpa menyentuh Docker.
#
#   FAKE_UPDATE_JEJAK   berkas tempat "mulai <folder>" dan "selesai <folder>" dicatat
#   FAKE_UPDATE_EXIT    kode keluar; bukan 0 berarti migrasi gagal
#   FAKE_UPDATE_JEDA    detik tidur di tengah migrasi
#   FAKE_UPDATE_BARIS   jumlah baris keluaran migrasi. Pengujian 409 memakai angka besar supaya
#                       keluarannya melebihi penyangga pipa: agen yang berhenti membaca akan membuat
#                       skrip ini mati kena SIGPIPE, dan "selesai" tidak pernah tercatat.

set -euo pipefail

folder="${1:?folder rilis wajib disebut}"

catat() {
    [ -z "${FAKE_UPDATE_JEJAK:-}" ] || printf '%s %s\n' "$1" "$folder" >> "$FAKE_UPDATE_JEJAK"
}

catat mulai

[ -f "$folder/manifest.json" ] || { printf 'manifest.json tidak ada di %s\n' "$folder" >&2; exit 9; }

printf '\n==> Memeriksa tanda tangan\n'
printf '    tanda tangan sah\n'

printf '\n==> Menjalankan migrasi\n'

[ -z "${FAKE_UPDATE_JEDA:-}" ] || sleep "$FAKE_UPDATE_JEDA"

for nomor in $(seq 1 "${FAKE_UPDATE_BARIS:-20}"); do
    printf 'migrasi %05d: 2026_09_14_000000_tabel_contoh ............................ DONE\n' "$nomor"
done

if [ "${FAKE_UPDATE_EXIT:-0}" != 0 ]; then
    printf 'SQLSTATE[42703]: Undefined column: kolom "nomor_rm" tidak ada\n' >&2
    printf 'GAGAL: migrasi gagal (tiruan)\n' >&2
    catat gagal
    exit "$FAKE_UPDATE_EXIT"
fi

printf '\n==> Memeriksa kesehatan\n'
printf '    core-app sehat\n'

catat selesai
