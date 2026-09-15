#!/usr/bin/env bash
#
# Memasang agen situs CoreERP di server Ubuntu milik klien. Dijalankan sekali, sebagai root.
#
#   Online : bash pasang.sh --admin-url https://admin.erp.contoh --token <token> [--ref <commit>]
#   Offline: bash pasang.sh --paket /media/usb/paket-situs --bundle /media/usb/coreerp-apotek-0.2.0
#
#   Pilihan untuk keduanya:
#     --release-key BERKAS     kunci publik rilis dari berkas ini, bukan dari jalur bawaannya
#     --app-url URL            alamat CoreERP yang dibuka pengguna (bawaan https://<nama host>)
#     --provider-email EMAIL   akun admin provider di Core (bawaan provider@coreerp.local)
#     --app-port PORT          port host aplikasi, ditulis ke .env (bawaan dari env.template)
#     --app-bind ALAMAT        alamat IPv4 tempat port itu diikat, ditulis ke .env (bawaan dari env.template)
#
#   COREERP_PROYEK dan COREERP_FOLDER_CADANGAN yang disebut saat memasang ditulis ke agent/agent.env,
#   supaya timer dan perintah yang dijalankan tangan sesudahnya memakai nilai yang sama.
#
# Yang dikerjakan, berurutan: memeriksa mesin → memasang paket yang belum ada → menyiapkan folder →
# menaruh agen, update.sh, dan kunci publik rilis → membuat .env dengan rahasia yang lahir di sini →
# memasang unit systemd → mendaftarkan situs.
#
# Tiga hal yang sengaja:
#
# 1. **Rahasia aplikasi lahir di server ini** — APP_KEY, kata sandi database, kata sandi admin provider
#    — dan tidak pernah dikirim ke admin.erp. Kata sandi admin provider dicetak sekali di terminal ini.
#
# 2. **Kunci publik rilis datang lewat jalur yang berbeda dari admin.erp.** Pada jalur online ia diambil
#    dari repo GitHub pada ref yang disebut, bukan dari admin.erp. Kunci inilah yang memeriksa setiap
#    rilis; bila ia datang dari admin.erp, admin.erp yang dibobol cukup mengganti kuncinya lalu
#    menandatangani rilisnya sendiri, dan seluruh pemeriksaan tanda tangan di agen menjadi hiasan.
#    Menyebut --ref sebagai SHA commit, bukan nama cabang, membuat isi yang diambil tidak dapat berubah
#    di bawah nama yang sama.
#
# 3. **Jalur online tidak memasang rilis pertama.** Operator memintanya dari halaman situs di admin.erp,
#    supaya pemasangan pertama pun tercatat sebagai operasi — dengan langkah, hasil, dan jejak audit —
#    dan berjalan di dalam jendela pembaruan yang disepakati klien.

set -euo pipefail
shopt -s inherit_errexit

RUMAH="${COREERP_HOME:-/opt/coreerp}"
FOLDER_SYSTEMD="${COREERP_SYSTEMD_DIR:-/etc/systemd/system}"
FOLDER_PERINTAH="${COREERP_BIN_DIR:-/usr/local/bin}"
BERKAS_ENV="$RUMAH/.env"
BERKAS_SETELAN_AGEN="$RUMAH/agent/agent.env"
REPO_MENTAH='https://raw.githubusercontent.com/MettaDevs/coreERP'

folder_skrip="$(cd "$(dirname "${BASH_SOURCE[0]:-.}")" && pwd)"

gagal() {
    printf '\n' >&2
    printf 'GAGAL: %s\n' "$1" >&2
    shift
    [ "$#" -eq 0 ] || printf '%s\n' "$@" >&2
    exit 1
}

langkah() {
    printf '\n==> %s\n' "$*"
}

pemakaian() {
    gagal "$1" \
        '' \
        'Pemakaian:' \
        '  bash pasang.sh --admin-url URL --token TOKEN [--ref REF]' \
        '  bash pasang.sh --paket FOLDER --bundle FOLDER' \
        'Pilihan: --release-key BERKAS  --app-url URL  --provider-email EMAIL  --app-port PORT  --app-bind ALAMAT'
}

alamat_admin=''
token=''
ref='main'
paket=''
bundle=''
kunci_rilis_sumber=''
app_url=''
email_provider='provider@coreerp.local'
port_aplikasi=''
alamat_ikat=''

while [ "$#" -gt 0 ]; do
    case "$1" in
        --admin-url | --token | --ref | --paket | --bundle | --release-key | --app-url | --provider-email | --app-port | --app-bind)
            [ "$#" -ge 2 ] || pemakaian "$1 menuntut satu nilai."
            case "$1" in
                --admin-url) alamat_admin="$2" ;;
                --token) token="$2" ;;
                --ref) ref="$2" ;;
                --paket) paket="$2" ;;
                --bundle) bundle="$2" ;;
                --release-key) kunci_rilis_sumber="$2" ;;
                --app-url) app_url="$2" ;;
                --provider-email) email_provider="$2" ;;
                --app-port) port_aplikasi="$2" ;;
                --app-bind) alamat_ikat="$2" ;;
            esac
            shift 2
            ;;
        *) pemakaian "Argumen tidak dikenal: $1" ;;
    esac
done

if [ -n "$alamat_admin$token" ] && [ -z "$paket$bundle" ]; then
    moda=online
    if [ -z "$alamat_admin" ] || [ -z "$token" ]; then
        pemakaian 'Jalur online menuntut --admin-url dan --token.'
    fi
elif [ -n "$paket$bundle" ] && [ -z "$alamat_admin$token" ]; then
    moda=offline
    if [ -z "$paket" ] || [ -z "$bundle" ]; then
        pemakaian 'Jalur offline menuntut --paket dan --bundle.'
    fi
else
    pemakaian 'Pilih satu jalur: online (--admin-url, --token) atau offline (--paket, --bundle).'
fi

cocok_ref() {
    [[ "$1" =~ ^[A-Za-z0-9._/-]{1,100}$ ]] && [[ "$1" != *..* ]]
}

port_sah() {
    [[ "$1" =~ ^[1-9][0-9]{0,4}$ ]] && [ "$1" -le 65535 ]
}

# Hanya IPv4: alamat ini disisipkan ke bentuk pendek `ALAMAT:PORT:80` di compose, tempat titik dua IPv6
# bertabrakan dengan pemisahnya. Tanpa nol di depan, yang dibaca sebagian pengurai sebagai oktal.
ipv4_sah() {
    local oktet='(0|[1-9][0-9]{0,2})' satu

    [[ "$1" =~ ^$oktet\.$oktet\.$oktet\.$oktet$ ]] || return 1

    for satu in "${BASH_REMATCH[@]:1}"; do
        [ "$satu" -le 255 ] || return 1
    done
}

# Nilai yang ditulis ke agent.env dibatasi pada huruf yang diterima agen saat membacanya; lihat
# `muat_setelan` di coreerp-agent.
nilai_setelan_sah() {
    [[ "$1" =~ ^[A-Za-z0-9_./:@+-]+$ ]]
}

# port_didengar PORT — pulang 0 bila ada soket TCP yang mendengarkan port itu di alamat mana pun.
#
# Dibaca langsung dari /proc, bukan lewat `ss`: iproute2 tidak dijamin terpasang, dan skrip ini tidak
# memasang paket hanya untuk memeriksa satu port. Keadaan 0A di sana adalah LISTEN.
port_didengar() {
    local heks berkas ada=()

    heks="$(printf '%04X' "$1")"

    for berkas in /proc/net/tcp /proc/net/tcp6; do
        [ ! -r "$berkas" ] || ada+=("$berkas")
    done

    [ "${#ada[@]}" -gt 0 ] || return 1

    awk -v port="$heks" '
        FNR > 1 && $4 == "0A" { n = split($2, bagian, ":"); if (bagian[n] == port) ketemu = 1 }
        END { exit !ketemu }' "${ada[@]}"
}

# --- 1. Mesin ------------------------------------------------------------------------------------------

langkah 'Memeriksa mesin'

[ "$(id -u)" -eq 0 ] || gagal 'Skrip pasang harus dijalankan sebagai root.' 'Ia memasang paket, unit systemd, dan folder di /opt.'

[ -r /etc/os-release ] || gagal 'Sistem operasi tidak dapat dikenali: /etc/os-release tidak ada.'
# shellcheck disable=SC1091 # berkas milik sistem operasi, bukan bagian repo
. /etc/os-release
[ "${ID:-}" = ubuntu ] || gagal \
    "Skrip pasang hanya mendukung Ubuntu; mesin ini ${PRETTY_NAME:-tidak dikenal}." \
    'Nama paket Docker dan Compose yang dipasangnya milik Ubuntu.'

printf '    %s, root\n' "${PRETTY_NAME:-Ubuntu}"

if [ "$moda" = online ]; then
    cocok_ref "$ref" || gagal "--ref tidak sah: $ref"
else
    [ -f "$paket/site.json" ] || gagal "Paket pendaftaran tidak lengkap: $paket/site.json tidak ada."
    [ -d "$bundle" ] || gagal "Folder bundle tidak ditemukan: $bundle"
    paket="$(cd "$paket" && pwd)"
    bundle="$(cd "$bundle" && pwd)"
fi

[ -z "$port_aplikasi" ] || port_sah "$port_aplikasi" \
    || gagal "--app-port tidak sah: $port_aplikasi" 'Sebut satu nomor port 1-65535.'

[ -z "$alamat_ikat" ] || ipv4_sah "$alamat_ikat" \
    || gagal "--app-bind tidak sah: $alamat_ikat" \
        'Sebut satu alamat IPv4: 127.0.0.1, atau alamat gateway bridge Docker bila reverse proxy-nya' \
        'berjalan di dalam Docker.'

# Nilai yang tidak diterima agen tidak ditulis ke agent.env: agen yang menolak berkasnya berhenti di setiap
# putaran, dan pasang.sh sendiri memanggil agen beberapa baris di bawah.
for kunci in COREERP_PROYEK COREERP_FOLDER_CADANGAN; do
    if [ -n "${!kunci:-}" ] && ! nilai_setelan_sah "${!kunci}"; then
        gagal "$kunci tidak dapat ditulis ke agent.env: ${!kunci}" \
            'Nilainya hanya boleh memuat huruf, angka, dan . _ / : @ + - — tanpa kutip atau spasi.'
    fi
done

# --- 2. Paket ------------------------------------------------------------------------------------------
#
# Hanya yang belum ada yang dipasang, dan hanya itu yang disentuh. Skrip ini **tidak** mengubah web
# server yang sudah berjalan, firewall, maupun setelan SSH — walaupun rancangan on-prem dikelola
# menganjurkan SSH hanya dengan kunci dan firewall yang ketat. Semua itu setelan server milik klien, dan
# mengubahnya menuntut persetujuan klien, bukan satu baris perintah yang dijalankan atas nama kita.
# Server klinik pertama sudah melayani web reservasi di port 80 dan 443; skrip yang "merapikan" setelan
# itu dapat mematikan layanan yang tidak ada hubungannya dengan CoreERP.

langkah 'Memeriksa paket'

kurang=()
command -v docker >/dev/null 2>&1 || kurang+=(docker.io docker-compose-v2)

if command -v docker >/dev/null 2>&1 && ! docker compose version >/dev/null 2>&1; then
    # Docker yang sudah ada mungkin dipasang dari repo Docker sendiri. Mencampur paket Compose Ubuntu ke
    # sana dapat menabrak paket yang sudah ada, dan Docker itu bisa jadi menjalankan layanan lain milik
    # klien.
    gagal \
        'Docker sudah terpasang, tetapi Docker Compose v2 tidak ada.' \
        '' \
        'Skrip ini tidak mengubah pemasangan Docker yang sudah ada. Pasang plugin Compose dari sumber' \
        'yang sama dengan Docker-nya (docker-compose-v2 dari Ubuntu, atau docker-compose-plugin dari' \
        'repo Docker), lalu jalankan lagi.'
fi

command -v jq >/dev/null 2>&1 || kurang+=(jq)
command -v curl >/dev/null 2>&1 || kurang+=(curl ca-certificates)
command -v openssl >/dev/null 2>&1 || kurang+=(openssl)
command -v flock >/dev/null 2>&1 || kurang+=(util-linux)

if [ "${#kurang[@]}" -gt 0 ]; then
    if [ "$moda" = offline ]; then
        gagal \
            "Paket berikut belum terpasang: ${kurang[*]}" \
            '' \
            'Jalur offline tidak dapat mengunduhnya. Pasang dari cermin apt lokal atau berkas .deb yang' \
            'dibawa bersama paket, lalu jalankan lagi.'
    fi

    printf '    memasang: %s\n' "${kurang[*]}"
    apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends "${kurang[@]}" >/dev/null

    if [ -d /run/systemd/system ] && command -v systemctl >/dev/null 2>&1; then
        systemctl enable --now docker >/dev/null 2>&1 || true
    fi
else
    printf '    seluruh paket sudah ada\n'
fi

docker info >/dev/null 2>&1 || gagal \
    'Docker tidak berjalan.' \
    'Nyalakan layanan Docker (systemctl start docker), lalu jalankan lagi.'

# --- 3. Berkas agen ------------------------------------------------------------------------------------

langkah 'Mengambil berkas agen'

bahan="$(mktemp -d)"
trap 'rm -rf "$bahan"' EXIT

# ambil JALUR_DI_REPO — menaruh berkasnya di $bahan dengan nama dasarnya.
ambil() {
    local jalur="$1" nama sumber

    nama="$(basename "$jalur")"

    if [ "$moda" = offline ]; then
        for sumber in "$paket/$nama" "$folder_skrip/$nama" "$bundle/$nama"; do
            if [ -f "$sumber" ]; then
                cp "$sumber" "$bahan/$nama"
                return 0
            fi
        done
        gagal "Berkas $nama tidak ada di paket ($paket), di samping pasang.sh, maupun di bundle."
    fi

    curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --tlsv1.2 \
        --connect-timeout 15 --max-time 120 \
        --output "$bahan/$nama" "$REPO_MENTAH/$ref/$jalur" \
        || gagal "Gagal mengambil $jalur dari $REPO_MENTAH/$ref."
}

ambil deploy/agent/coreerp-agent
ambil deploy/agent/coreerp-agent.service
ambil deploy/agent/coreerp-agent.timer
ambil deploy/agent/env.template
ambil scripts/update.sh

if [ -n "$kunci_rilis_sumber" ]; then
    [ -f "$kunci_rilis_sumber" ] || gagal "Kunci publik rilis tidak ditemukan: $kunci_rilis_sumber"
    cp "$kunci_rilis_sumber" "$bahan/kunci-rilis.pub"
else
    ambil deploy/agent/kunci-rilis.pub
fi

openssl pkey -pubin -in "$bahan/kunci-rilis.pub" -noout 2>/dev/null \
    || gagal 'Kunci publik rilis yang diambil bukan kunci publik PEM yang sah.'

# Kunci rilis yang sudah terpasang tidak diganti diam-diam. Ia akar kepercayaan setiap pembaruan
# sesudahnya; mengganti kunci adalah keputusan yang disadari, bukan efek samping memasang ulang.
if [ -f "$RUMAH/kunci-rilis.pub" ] && ! cmp -s "$RUMAH/kunci-rilis.pub" "$bahan/kunci-rilis.pub"; then
    gagal \
        "Kunci publik rilis yang sudah terpasang berbeda dari yang baru diambil: $RUMAH/kunci-rilis.pub" \
        '' \
        'Skrip ini tidak mengganti kunci rilis. Bila penggantian itu memang disengaja, pindahkan kunci' \
        'yang lama dengan sadar lalu jalankan lagi.'
fi

# Port aplikasi diperiksa sebelum satu berkas pun ditulis, dan hanya pada pemasangan pertama: sesudahnya
# port itu memang didengar CoreERP sendiri.
#
# Server klien lazim sudah melayani situs lain. Tanpa pemeriksaan ini tabrakannya baru ketahuan saat
# container pertama dinyalakan — pada jalur online lewat admin.erp, di dalam jendela pembaruan, jauh dari
# orang yang sedang memasang dan dapat memilih port lain dalam sepuluh detik.
port_efektif="${port_aplikasi:-$(sed -n 's/^CORE_APP_PORT=//p' "$bahan/env.template" | tail -n 1)}"

if [ ! -e "$BERKAS_ENV" ]; then
    port_sah "$port_efektif" || gagal "env.template menyebut CORE_APP_PORT yang tidak sah: ${port_efektif:-(kosong)}"

    if port_didengar "$port_efektif"; then
        gagal \
            "Port $port_efektif sudah didengar layanan lain di server ini." \
            '' \
            'CoreERP tidak dapat menyala di port yang sama. Pilih port yang bebas, lalu jalankan lagi dengan' \
            'pilihan yang sama ditambah --app-port:' \
            '  bash pasang.sh ... --app-port PORT' \
            '' \
            'Reverse proxy di depan CoreERP kemudian diarahkan ke port itu.'
    fi
fi

# agent.env yang sudah ada tidak diubah. Bila isinya berbeda dari yang disebut sekarang, perintah di dalam
# skrip ini — yang membawa nilai dari lingkungannya — akan memakai nilai yang baru, sementara timer
# memakai nilai di berkas: dua proyek compose atau dua folder cadangan untuk satu server.
setelan_agen=()
[ -z "${COREERP_PROYEK:-}" ] || setelan_agen+=(COREERP_PROYEK)
[ -z "${COREERP_FOLDER_CADANGAN:-}" ] || setelan_agen+=(COREERP_FOLDER_CADANGAN)

if [ -e "$BERKAS_SETELAN_AGEN" ]; then
    for kunci in "${setelan_agen[@]}"; do
        tertulis="$(sed -n "s/^$kunci=//p" "$BERKAS_SETELAN_AGEN" | tail -n 1)"

        [ "$tertulis" = "${!kunci}" ] || gagal \
            "$BERKAS_SETELAN_AGEN sudah ada dan menyebut $kunci yang berbeda." \
            "  di berkas: ${tertulis:-(tidak disebut)}" \
            "  diminta  : ${!kunci}" \
            '' \
            'Skrip ini tidak mengubah agent.env yang sudah ada. Samakan nilai yang disebut dengan isi' \
            'berkasnya, atau ubah berkasnya dengan sadar lalu jalankan lagi.'
    done
fi

install -d -m 0755 "$RUMAH" "$RUMAH/bin"
install -d -m 0700 "$RUMAH/agent" "$RUMAH/agent/releases" "$RUMAH/agent/outbox" "$RUMAH/agent/log" "$RUMAH/keadaan"
install -d -m 0755 "$RUMAH/agent/license"
install -d -m 0700 "${COREERP_FOLDER_CADANGAN:-$RUMAH/cadangan}"

install -m 0755 "$bahan/coreerp-agent" "$RUMAH/bin/coreerp-agent"
install -m 0755 "$bahan/update.sh" "$RUMAH/update.sh"
install -m 0644 "$bahan/kunci-rilis.pub" "$RUMAH/kunci-rilis.pub"

# Pembungkus di PATH yang membawa COREERP_HOME, supaya perintah yang dicetak di akhir berlaku apa adanya
# walaupun COREERP_HOME bukan bawaan.
install -d -m 0755 "$FOLDER_PERINTAH"
# shellcheck disable=SC2016 # `${COREERP_HOME:-...}` dan "$@" memang ditulis harfiah ke pembungkusnya
printf '#!/bin/sh\nexport COREERP_HOME="${COREERP_HOME:-%s}"\nexec "%s" "$@"\n' "$RUMAH" "$RUMAH/bin/coreerp-agent" \
    > "$FOLDER_PERINTAH/coreerp-agent"
chmod 0755 "$FOLDER_PERINTAH/coreerp-agent"

printf '    %s\n' "$RUMAH/bin/coreerp-agent" "$RUMAH/update.sh" "$RUMAH/kunci-rilis.pub"

# Nilai yang disebut saat memasang hanya hidup selama skrip ini berjalan. Tanpa berkas ini, timer dan
# perintah yang dijalankan tangan besok memakai bawaan lagi.
if [ "${#setelan_agen[@]}" -gt 0 ] && [ ! -e "$BERKAS_SETELAN_AGEN" ]; then
    (
        umask 077
        {
            printf '# Setelan server CoreERP, dibaca unit systemd dan agen. Ditulis pasang.sh; aturan isinya di coreerp-agent.\n'
            for kunci in "${setelan_agen[@]}"; do
                printf '%s=%s\n' "$kunci" "${!kunci}"
            done
        } > "$BERKAS_SETELAN_AGEN.baru"
    )

    chmod 0600 "$BERKAS_SETELAN_AGEN.baru"
    mv -f "$BERKAS_SETELAN_AGEN.baru" "$BERKAS_SETELAN_AGEN"
    printf '    %s\n' "$BERKAS_SETELAN_AGEN"
fi

# --- 4. Setelan aplikasi -------------------------------------------------------------------------------

langkah 'Menyiapkan setelan aplikasi'

acak_alfanumerik() {
    local panjang="$1" hasil='' potongan

    # Hanya huruf dan angka: Compose membaca `$` di berkas env sebagai awal variabel dan `#` sebagai awal
    # komentar, dan kata sandi yang terpotong diam-diam baru ketahuan saat tidak ada yang dapat masuk.
    while [ "${#hasil}" -lt "$panjang" ]; do
        potongan="$(openssl rand -base64 48)"
        hasil="$hasil${potongan//[^A-Za-z0-9]/}"
    done

    printf '%s' "${hasil:0:$panjang}"
}

kata_sandi_provider=''

if [ -e "$BERKAS_ENV" ]; then
    # Tidak pernah ditimpa. APP_KEY baru membuat seluruh data terenkripsi tidak terbaca lagi, dan kata
    # sandi database baru tidak cocok dengan database yang sudah dibuat dengan yang lama.
    printf '    %s sudah ada; tidak ditimpa\n' "$BERKAS_ENV"
    [ -z "$port_aplikasi$alamat_ikat" ] \
        || printf '    --app-port dan --app-bind hanya berlaku pada pemasangan pertama; ubah .env itu dengan tangan\n'
else
    [ -n "$app_url" ] || app_url="https://$(hostname -f 2>/dev/null || cat /proc/sys/kernel/hostname)"

    kunci_aplikasi="base64:$(openssl rand -base64 32)"
    kata_sandi_db="$(acak_alfanumerik 32)"
    kata_sandi_provider="$(acak_alfanumerik 24)"

    (
        umask 077
        while IFS= read -r baris || [ -n "$baris" ]; do
            baris="${baris//@@APP_URL@@/"$app_url"}"
            baris="${baris//@@CORE_APP_KEY@@/"$kunci_aplikasi"}"
            baris="${baris//@@CORE_DB_PASSWORD@@/"$kata_sandi_db"}"
            baris="${baris//@@COREERP_PROVIDER_EMAIL@@/"$email_provider"}"
            baris="${baris//@@COREERP_PROVIDER_PASSWORD@@/"$kata_sandi_provider"}"
            baris="${baris//@@COREERP_LICENSE_DIR@@/"$RUMAH/agent/license"}"

            # Port dan alamat ikat punya nilai sungguhan di env.template, bukan isian: berkas itu yang
            # menyebut bawaannya. Pilihan yang disebut menggantikan barisnya.
            case "$baris" in
                CORE_APP_PORT=*) [ -z "$port_aplikasi" ] || baris="CORE_APP_PORT=$port_aplikasi" ;;
                CORE_APP_BIND=*) [ -z "$alamat_ikat" ] || baris="CORE_APP_BIND=$alamat_ikat" ;;
            esac

            printf '%s\n' "$baris"
        done < "$bahan/env.template" > "$BERKAS_ENV.baru"
    )

    if grep -q '@@[A-Z_]*@@' "$BERKAS_ENV.baru"; then
        rm -f "$BERKAS_ENV.baru"
        gagal 'env.template memuat isian yang tidak dikenal skrip pasang; .env tidak dibuat.'
    fi

    # Pilihan yang tidak menemukan barisnya di env.template tidak boleh hilang tanpa suara: port yang
    # diperiksa bebas di atas harus port yang benar-benar ditulis.
    for satu in "CORE_APP_PORT=$port_efektif" ${alamat_ikat:+"CORE_APP_BIND=$alamat_ikat"}; do
        if ! grep -qxF "$satu" "$BERKAS_ENV.baru"; then
            rm -f "$BERKAS_ENV.baru"
            gagal "env.template tidak memuat baris ${satu%%=*}; .env tidak dibuat."
        fi
    done

    chmod 0600 "$BERKAS_ENV.baru"
    mv -f "$BERKAS_ENV.baru" "$BERKAS_ENV"
    printf '    %s dibuat (hanya dapat dibaca root)\n' "$BERKAS_ENV"
    printf '    aplikasi didengar di %s:%s\n' "$(sed -n 's/^CORE_APP_BIND=//p' "$BERKAS_ENV" | tail -n 1)" "$port_efektif"
fi

# --- 5. systemd ----------------------------------------------------------------------------------------

langkah 'Memasang unit systemd'

install -d -m 0755 "$FOLDER_SYSTEMD"

for unit in coreerp-agent.service coreerp-agent.timer; do
    while IFS= read -r baris || [ -n "$baris" ]; do
        printf '%s\n' "${baris//\/opt\/coreerp/"$RUMAH"}"
    done < "$bahan/$unit" > "$FOLDER_SYSTEMD/$unit.baru"
    chmod 0644 "$FOLDER_SYSTEMD/$unit.baru"
    mv -f "$FOLDER_SYSTEMD/$unit.baru" "$FOLDER_SYSTEMD/$unit"
    printf '    %s\n' "$FOLDER_SYSTEMD/$unit"
done

ada_systemd=0
if [ -d /run/systemd/system ] && command -v systemctl >/dev/null 2>&1; then
    ada_systemd=1
    systemctl daemon-reload
else
    printf '    systemd tidak berjalan di mesin ini; unit ditulis tetapi tidak dinyalakan\n'
fi

# --- 6. Pendaftaran ------------------------------------------------------------------------------------

agen="$RUMAH/bin/coreerp-agent"
export COREERP_HOME="$RUMAH"

if [ -f "$RUMAH/agent/site.json" ]; then
    langkah 'Situs sudah terdaftar di server ini; pendaftaran dilewati'
elif [ "$moda" = online ]; then
    "$agen" enroll --admin-url "$alamat_admin" --token "$token"
else
    langkah 'Mendaftarkan situs dari paket pendaftaran'
    "$agen" import-package "$paket"
fi

if [ "$moda" = online ]; then
    if [ "$ada_systemd" -eq 1 ]; then
        systemctl enable --now coreerp-agent.timer >/dev/null
        printf '    timer agen menyala\n'
    fi
else
    rilis_bundle="$(jq -r '.rilis // ""' "$bundle/manifest.json" 2>/dev/null || true)"
    rilis_terpasang="$(jq -r '.release // ""' "$RUMAH/agent/state.json" 2>/dev/null || true)"

    if [ -n "$rilis_bundle" ] && [ "$rilis_bundle" = "$rilis_terpasang" ]; then
        langkah "Rilis $rilis_bundle sudah terpasang; bundle tidak dipasang ulang"
    else
        "$agen" install-bundle "$bundle"
    fi
fi

# --- Selesai -------------------------------------------------------------------------------------------

printf '\nPemasangan agen selesai.\n'

if [ -n "$kata_sandi_provider" ]; then
    # Dicetak sekali, di sini saja. Ia juga tersimpan di .env karena Core membacanya saat seeding; ganti
    # kata sandinya sesudah masuk pertama kali.
    printf '\n'
    printf '  +--------------------------------------------------------------------+\n'
    printf '  | Admin provider CoreERP. Catat sekarang; ia tidak dicetak lagi.     |\n'
    printf '  |   email     : %-52s |\n' "$email_provider"
    printf '  |   kata sandi: %-52s |\n' "$kata_sandi_provider"
    printf '  +--------------------------------------------------------------------+\n'
fi

printf '\nLangkah berikutnya:\n'

if [ "$moda" = online ]; then
    printf '  1. Di admin.erp, buka halaman situs ini dan minta "Perbarui" ke rilis pertama. Agen\n'
    printf '     mengambilnya pada kunjungan berikutnya di dalam jendela pembaruan.\n'
    printf '  2. Sesudah rilis pertama terpasang, jalankan di terminal server ini:\n'
    printf '       coreerp-agent bootstrap-tenant --admin-name "NAMA ADMIN" --admin-email EMAIL\n'
else
    printf '  1. Jalankan di terminal server ini:\n'
    printf '       coreerp-agent bootstrap-tenant --admin-name "NAMA ADMIN" --admin-email EMAIL\n'
    printf '  2. coreerp-agent write-report, lalu bawa berkas laporannya ke admin.erp.\n'
    printf '  3. Sesudah admin.erp menerimanya: coreerp-agent confirm-enrollment\n'
fi
