#!/usr/bin/env bash
#
# Memasang pemicu rilis di server pertama, sekali, sebagai root:
#
#   sudo bash deploy/perakit/pasang-pemicu.sh
#
# Yang dipasang:
#
#   /usr/local/sbin/coreerp-rilis      pembungkus perakit (deploy/perakit/coreerp-rilis), root 0755
#   /etc/sudoers.d/coreerp-rilis       `deploy` boleh menjalankan pembungkus itu saja, tanpa kata sandi
#
# Ini satu-satunya perubahan setelan keamanan yang dibutuhkan alur `rilis.yml`, dan cakupannya sengaja satu
# perintah: pemegang secret SSH `deploy` dapat merakit rilis dari commit yang sudah ada di `main`, tidak lebih.
# Idempoten: dijalankan dua kali tanpa perubahan kedua. Aturan sudoers diperiksa `visudo -c` sebelum dipasang;
# aturan yang rusak dapat mengunci sudo seluruh server.

set -euo pipefail
shopt -s inherit_errexit

PEMBUNGKUS='/usr/local/sbin/coreerp-rilis'
SUDOERS='/etc/sudoers.d/coreerp-rilis'
PENGGUNA="${COREERP_PENGGUNA_DEPLOY:-deploy}"

gagal() {
    printf '\nGAGAL: %s\n' "$*" >&2
    exit 1
}

[ "$(id -u)" -eq 0 ] || gagal 'Jalankan sebagai root.'
id "$PENGGUNA" >/dev/null 2>&1 || gagal "User $PENGGUNA tidak ada di server ini."
command -v visudo >/dev/null || gagal 'visudo tidak ada.'

folder_skrip="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
kerja="$(mktemp -d)"
trap 'rm -rf "$kerja"' EXIT

if [ -f "$PEMBUNGKUS" ] && cmp -s "$folder_skrip/coreerp-rilis" "$PEMBUNGKUS"; then
    printf '%s tidak berubah\n' "$PEMBUNGKUS"
else
    install -m 0755 -o root -g root "$folder_skrip/coreerp-rilis" "$PEMBUNGKUS"
    printf 'dipasang: %s\n' "$PEMBUNGKUS"
fi

# Tanpa argumen bintang: sudo mencocokkan argumen persis bila tidak disebut, dan mengizinkan argumen apa pun
# bila perintahnya disebut telanjang. Pembungkusnya sendiri yang membatasi argumen, jadi perintah telanjang
# di sini memang yang dimaksud.
printf '# Dipasang deploy/perakit/pasang-pemicu.sh. Pemicu rilis dari alur GitHub rilis.yml.\n%s ALL=(root) NOPASSWD: %s\n' \
    "$PENGGUNA" "$PEMBUNGKUS" > "$kerja/sudoers"

if [ -f "$SUDOERS" ] && cmp -s "$kerja/sudoers" "$SUDOERS"; then
    printf '%s tidak berubah\n' "$SUDOERS"
else
    visudo -cf "$kerja/sudoers" >/dev/null || gagal 'Aturan sudoers tidak lolos visudo; tidak dipasang.'
    install -m 0440 -o root -g root "$kerja/sudoers" "$SUDOERS"
    visudo -c >/dev/null || { rm -f "$SUDOERS"; gagal 'sudoers seluruhnya tidak lolos visudo sesudah pemasangan; aturan dicabut lagi.'; }
    printf 'dipasang: %s\n' "$SUDOERS"
fi

sudo -l -U "$PENGGUNA" | grep -F "$PEMBUNGKUS" >/dev/null || gagal "sudo tidak mengizinkan $PENGGUNA menjalankan $PEMBUNGKUS."
printf '%s boleh menjalankan %s dan tidak ada yang lain dari aturan ini.\n' "$PENGGUNA" "$PEMBUNGKUS"
