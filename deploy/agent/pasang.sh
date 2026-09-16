#!/usr/bin/env bash
#
# Memasang agen situs CoreERP di server Ubuntu milik klien. Dijalankan sekali, sebagai root, lewat perintah
# yang disalin dari halaman lingkungan di admin.erp:
#
#   curl -fsSL https://admin.erp.contoh/pasang.sh | sudo bash -s -- --token <token>
#
#   Pilihan:
#     --app-port PORT          port host aplikasi, ditulis ke .env (bawaan dari env.template)
#     --app-bind ALAMAT        alamat IPv4 tempat port itu diikat, ditulis ke .env (bawaan dari env.template)
#     --proxy-luar             tanpa proxy HTTPS agen, untuk server yang sudah punya reverse proxy sendiri
#     --kunci-lisensi          lisensi yang habis atau hilang mengunci pengguna tenant (bawaan: tidak)
#
#   Tanpa --kunci-lisensi, `.env` ditulis dengan COREERP_LICENSE_REQUIRED=false: lisensi tetap diterbitkan,
#   diperpanjang, dan dilaporkan, tetapi tidak pernah menutup aplikasi. Itu bawaan yang diputuskan pemilik
#   produk supaya gangguan penerbitan lisensi tidak mematikan klinik. Penguncian dinyalakan per server
#   sesudah penerbitannya terbukti berjalan di sana — saat memasang, atau dengan menyunting baris itu di
#   .env dan menunggu pembaruan berikutnya.
#
#   Bawaannya, agen memasang proxy HTTPS sendiri (core-proxy, profil `proxy` di compose.edition.yaml) di port
#   80 dan 443, dengan sertifikat Let's Encrypt untuk alamat tenant dari admin.erp. Port itu diperiksa bebas
#   pada pemasangan pertama. --proxy-luar melewati pemeriksaan itu dan tidak menyalakan profilnya; reverse
#   proxy server itu yang diarahkan ke port aplikasi. Keduanya tersimpan di .env, jadi berlaku juga untuk
#   setiap pembaruan sesudahnya.
#
#   COREERP_PROYEK dan COREERP_FOLDER_CADANGAN yang disebut saat memasang ditulis ke agent/agent.env,
#   supaya timer dan perintah yang dijalankan tangan sesudahnya memakai nilai yang sama.
#
#   COREERP_PASANG_BATAS_DETIK (bawaan 5400) dan COREERP_PASANG_JEDA_DETIK (bawaan 15) mengatur berapa lama
#   skrip ini menunggu operasi install dari admin.erp, dan jeda antarputaran agen selama menunggu.
#
# Yang dikerjakan, berurutan: memeriksa mesin → memasang paket yang belum ada → mengambil agen, update.sh,
# dan kunci publik rilis dari admin.erp → membuat .env dengan rahasia yang lahir di sini → memasang unit
# systemd → mendaftarkan situs → menjalankan agen sampai operasi install selesai → menyalakan timer.
#
# Empat hal yang sengaja:
#
# 1. **Rahasia aplikasi lahir di server ini** — APP_KEY, kata sandi database, kata sandi admin provider
#    — dan tidak pernah dikirim ke admin.erp. Kata sandi admin provider dicetak sekali di terminal ini.
#
# 2. **Seluruh berkas datang dari admin.erp yang menyajikan skrip ini**, dan alamatnya ditanam admin.erp ke
#    skrip saat disajikan. Repo privat, dan server klien tidak dapat menjangkau GitHub. Kunci publik rilis ikut
#    dari sana karena tidak ada jalur kedua yang independen, lalu dipaku: kunci yang berbeda dari yang sudah
#    terpasang ditolak. Yang dipercaya adalah satu jendela — pemasangan pertama, saat teknisi di lokasi —
#    bukan admin.erp pada setiap pembaruan sesudahnya.
#
# 3. **Rilis pertama tetap operasi admin.erp.** Skrip ini tidak memasang rilis sendiri. Ia menjalankan putaran
#    agen sampai operasi `install` — rilis, pembaruan pertama, tenant, dan admin pertamanya — selesai, supaya
#    pemasangan pertama pun tercatat dengan langkah, hasil, dan jejak audit, sementara orang yang memasang
#    masih melihat terminalnya.
#
# 4. **Tidak ada yang dijalankan sebelum seluruh skrip terbaca.** Skrip ini dialirkan `curl` ke `bash`; sambungan
#    yang putus di tengah unduhan membuat bash menjalankan separuh skrip yang sempat diterima. Seluruh isinya
#    karena itu ada di dalam `utama`, yang baru dipanggil di baris terakhir: skrip yang terpotong tidak pernah
#    sampai ke pemanggilan itu.

set -euo pipefail
shopt -s inherit_errexit

RUMAH="${COREERP_HOME:-/opt/coreerp}"
FOLDER_SYSTEMD="${COREERP_SYSTEMD_DIR:-/etc/systemd/system}"
FOLDER_PERINTAH="${COREERP_BIN_DIR:-/usr/local/bin}"
BERKAS_ENV="$RUMAH/.env"
BERKAS_SETELAN_AGEN="$RUMAH/agent/agent.env"
BERKAS_KEADAAN="$RUMAH/agent/state.json"
LOG_PUTARAN="$RUMAH/agent/log/pasang.log"

# Diganti admin.erp dengan alamatnya sendiri saat menyajikan skrip ini. Isiannya sengaja hanya muncul di
# baris ini: penyaji mengganti setiap kemunculannya di seluruh berkas, termasuk kemunculan di dalam
# pemeriksaan yang menolak isian yang belum diganti.
ALAMAT_ADMIN='@@COREERP_ADMIN_URL@@'

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
        '  curl -fsSL <alamat admin.erp>/pasang.sh | sudo bash -s -- --token TOKEN' \
        'Pilihan: --app-port PORT  --app-bind ALAMAT  --proxy-luar  --kunci-lisensi'
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

PROTOKOL=''

# Alamat yang ditanam admin.erp diperiksa dengan aturan yang sama dengan agen: HTTPS, kecuali ke mesin ini
# sendiri, dan tanpa path. Berkas agen dan kunci rilis diambil dari alamat ini; tanpa TLS, siapa pun di
# jaringan klinik dapat menggantinya di jalan, dan kunci rilis yang diganti di sini dipaku selamanya.
#
# Bagian host dibatasi pada huruf nama host dan port. `http://127.0.0.1:x@host-lain` lolos dari pemotongan
# `host:port` yang polos, sementara curl membacanya sebagai nama pengguna untuk host lain.
periksa_alamat_admin() {
    local sisa host

    case "$ALAMAT_ADMIN" in
        https://*) PROTOKOL='=https' ;;
        http://*) PROTOKOL='=http' ;;
        *) gagal "Alamat admin.erp di skrip ini tidak dikenali: $ALAMAT_ADMIN" ;;
    esac

    sisa="${ALAMAT_ADMIN#*://}"

    case "$sisa" in
        */*) gagal "Alamat admin.erp di skrip ini tidak boleh memuat path: $ALAMAT_ADMIN" ;;
    esac

    [[ "$sisa" =~ ^[A-Za-z0-9.-]+(:[0-9]{1,5})?$ ]] \
        || gagal "Alamat admin.erp di skrip ini bukan nama host yang sah: $ALAMAT_ADMIN"

    host="${sisa%:*}"

    if [ "$PROTOKOL" = '=http' ]; then
        case "$host" in
            127.0.0.1 | localhost) ;;
            *) gagal "Alamat admin.erp harus HTTPS: $ALAMAT_ADMIN" \
                'Berkas agen dan kunci publik rilis diambil dari alamat ini; tanpa TLS keduanya dapat diganti di jaringan.' ;;
        esac
    fi
}

# ambil NAMA TUJUAN — satu berkas dari `<admin.erp>/agen/NAMA`.
#
# Tanpa --location: admin.erp menyajikan berkasnya sendiri, dan jawaban selain 200 — termasuk pengalihan,
# yang tidak digagalkan --fail — bukan berkas agen.
ambil() {
    local nama="$1" tujuan="$2" kode

    kode="$(curl --fail --silent --show-error --proto "$PROTOKOL" --tlsv1.2 \
        --connect-timeout 15 --max-time 120 \
        --output "$tujuan" --write-out '%{http_code}' "$ALAMAT_ADMIN/agen/$nama")" \
        || gagal "Gagal mengambil agen/$nama dari $ALAMAT_ADMIN."

    [ "$kode" = 200 ] || gagal "admin.erp menjawab HTTP $kode untuk agen/$nama; berkasnya tidak dipakai."
    [ -s "$tujuan" ] || gagal "agen/$nama dari admin.erp kosong; tidak ada yang dipasang."
}

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

# --- Menunggu operasi install --------------------------------------------------------------------------
#
# Yang dibaca hanya state.json milik agen: operasi yang sedang berjalan beserta langkahnya, dan hasil install
# terakhir. Keluaran agen sendiri — keluaran update.sh dan Core — ditulis ke log, bukan ke terminal.

keadaan() {
    jq -r "$1" "$BERKAS_KEADAAN" 2>/dev/null || true
}

TAMPIL_LANGKAH=''

cetak_langkah_pemasangan() {
    local baris

    [ "$(keadaan '.current_operation.operation // ""')" = install ] || return 0

    # Rilisnya baru dicatat agen sesudah parameternya lolos pemeriksaan; install yang ditolak tidak pernah
    # tampil sebagai sedang dipasang.
    [ -n "$(keadaan '.current_operation.release // ""')" ] || return 0

    baris="Memasang rilis $(keadaan '.current_operation.release') — langkah: $(keadaan '.current_operation.step // "memulai"')"

    if [ "$baris" != "$TAMPIL_LANGKAH" ]; then
        TAMPIL_LANGKAH="$baris"
        printf '    %s\n' "$baris"
    fi
}

HASIL_PASANG=''

# pemasangan_selesai ID_SEBELUMNYA — pulang 0 dan mengisi HASIL_PASANG bila operasi install yang berbeda dari
# ID_SEBELUMNYA sudah ditutup. Hasil lama di server yang dipasang ulang bukan hasil pemasangan ini.
pemasangan_selesai() {
    local id hasil

    id="$(keadaan '.last_install.id // ""')"
    hasil="$(keadaan '.last_install.result // ""')"

    [ -n "$id" ] && [ "$id" != "$1" ] || return 1

    case "$hasil" in
        succeeded | failed) HASIL_PASANG="$hasil"; return 0 ;;
    esac

    return 1
}

# tunggu_pemasangan AGEN — mengisi HASIL_PASANG: succeeded, failed, atau habis.
#
# Putaran agen dijalankan di latar belakang supaya langkahnya dapat dibaca selagi ia bekerja: `run --now` baru
# pulang sesudah seluruh operasinya selesai.
#
# Batas waktu yang habis di tengah putaran **tidak** membunuh agen. Putaran itu mungkin sedang menjalankan
# migrasi, dan update.sh yang dihentikan di sana meninggalkan database setengah bermigrasi. Ia dibiarkan
# selesai; timer yang dinyalakan sesudahnya melewati putarannya sendiri selama kunci putaran masih dipegang.
tunggu_pemasangan() {
    local agen="$1" batas_detik jeda_detik batas sebelumnya pid status tampil_tunggu=0 tampil_galat=0

    batas_detik="${COREERP_PASANG_BATAS_DETIK:-5400}"
    [[ "$batas_detik" =~ ^[0-9]{1,6}$ ]] || batas_detik=5400
    jeda_detik="${COREERP_PASANG_JEDA_DETIK:-15}"
    [[ "$jeda_detik" =~ ^[0-9]{1,4}$ ]] || jeda_detik=15

    batas="$(( $(date +%s) + batas_detik ))"
    sebelumnya="$(keadaan '.last_install.id // ""')"
    HASIL_PASANG=habis

    (umask 077 && : >> "$LOG_PUTARAN")

    while :; do
        "$agen" run --now >> "$LOG_PUTARAN" 2>&1 &
        pid=$!
        status=''

        while :; do
            cetak_langkah_pemasangan

            if ! kill -0 "$pid" 2>/dev/null; then
                status=0
                wait "$pid" || status=$?
                break
            fi

            [ "$(date +%s)" -lt "$batas" ] || break
            sleep 1
        done

        if [ -z "$status" ]; then
            printf '    agen masih bekerja di latar belakang; hasilnya tampil di admin.erp\n'
            return 0
        fi

        if pemasangan_selesai "$sebelumnya"; then
            return 0
        fi

        if [ "$status" -ne 0 ]; then
            # Sekali saja sampai putaran berhasil lagi. Sebabnya di log, bukan di sini: keluaran agen memuat
            # keluaran update.sh dan Core, dan terminal ini hanya mencetak kalimat yang disusun skrip ini.
            [ "$tampil_galat" -eq 1 ] \
                || printf '    putaran agen gagal (kode %s); dicoba lagi tiap %s detik. Log: %s\n' "$status" "$jeda_detik" "$LOG_PUTARAN"
            tampil_galat=1
        else
            tampil_galat=0

            if [ "$tampil_tunggu" -eq 0 ]; then
                printf '    Server tersambung ke admin.erp. Menunggu admin.erp memberi rilis…\n'
                tampil_tunggu=1
            fi
        fi

        # Diperiksa juga di sini, di antara putaran, supaya batas yang habis saat menunggu tidak memulai putaran
        # baru yang langsung ditinggalkan.
        [ "$(date +%s)" -lt "$batas" ] || return 0
        sleep "$jeda_detik"
    done
}

utama() {
    token=''
    port_aplikasi=''
    alamat_ikat=''
    proxy_luar=0
    kunci_lisensi=false

    # Diperiksa sebelum argumen: salinan dari repo yang dijalankan dengan pilihan lama (`--admin-url`) lebih
    # tertolong oleh kalimat ini daripada oleh "argumen tidak dikenal".
    case "$ALAMAT_ADMIN" in
        @@*@@)
            gagal 'Skrip pasang ini belum memuat alamat admin.erp.' \
                '' \
                'Unduh pasang.sh dari admin.erp, bukan dari repo: salin perintah pasang dari halaman lingkungan' \
                'di admin.erp. admin.erp menanam alamatnya sendiri ke skrip yang disajikannya.'
            ;;
    esac

    while [ "$#" -gt 0 ]; do
        case "$1" in
            --token | --app-port | --app-bind)
                [ "$#" -ge 2 ] || pemakaian "$1 menuntut satu nilai."
                case "$1" in
                    --token) token="$2" ;;
                    --app-port) port_aplikasi="$2" ;;
                    --app-bind) alamat_ikat="$2" ;;
                esac
                shift 2
                ;;
            --proxy-luar) proxy_luar=1; shift ;;
            --kunci-lisensi) kunci_lisensi=true; shift ;;
            *) pemakaian "Argumen tidak dikenal: $1" ;;
        esac
    done

    periksa_alamat_admin

    [ -n "$token" ] || pemakaian 'Skrip pasang menuntut --token.'

    # --- 1. Mesin --------------------------------------------------------------------------------------

    langkah 'Memeriksa mesin'

    [ "$(id -u)" -eq 0 ] || gagal 'Skrip pasang harus dijalankan sebagai root.' 'Ia memasang paket, unit systemd, dan folder di /opt.'

    [ -r /etc/os-release ] || gagal 'Sistem operasi tidak dapat dikenali: /etc/os-release tidak ada.'
    # shellcheck disable=SC1091 # berkas milik sistem operasi, bukan bagian repo
    . /etc/os-release
    [ "${ID:-}" = ubuntu ] || gagal \
        "Skrip pasang hanya mendukung Ubuntu; mesin ini ${PRETTY_NAME:-tidak dikenal}." \
        'Nama paket Docker dan Compose yang dipasangnya milik Ubuntu.'

    printf '    %s, root\n' "${PRETTY_NAME:-Ubuntu}"

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

    # --- 2. Paket --------------------------------------------------------------------------------------
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

    # --- 3. Berkas agen --------------------------------------------------------------------------------

    langkah "Mengambil berkas agen dari $ALAMAT_ADMIN"

    bahan="$(mktemp -d)"
    trap 'rm -rf "$bahan"' EXIT

    for nama in coreerp-agent coreerp-agent.service coreerp-agent.timer env.template update.sh kunci-rilis.pub; do
        ambil "$nama" "$bahan/$nama"
    done

    # Kunci privat ditolak walaupun OpenSSL membacanya sebagai kunci publik yang sah: berkas gabungan kunci
    # publik dan kunci privat terbaca dari blok pertamanya. Berkas ini dipasang 0644, jadi kunci privat rilis
    # yang ikut di dalamnya terbaca setiap pengguna server klien — dan dengannya rilis apa pun dapat
    # ditandatangani.
    if grep -q 'PRIVATE KEY' "$bahan/kunci-rilis.pub"; then
        gagal 'Berkas kunci publik rilis dari admin.erp memuat kunci PRIVAT; tidak ada yang dipasang.' \
            '' \
            'Setelan kunci rilis di admin.erp menunjuk berkas yang salah. Perbaiki di sana dan jangan pakai' \
            'kunci itu lagi: pemegang berkas ini dapat menandatangani rilis.'
    fi

    openssl pkey -pubin -in "$bahan/kunci-rilis.pub" -noout 2>/dev/null \
        || gagal 'Kunci publik rilis dari admin.erp bukan kunci publik PEM yang sah.'

    # Kunci rilis yang sudah terpasang tidak diganti diam-diam. Ia akar kepercayaan setiap pembaruan
    # sesudahnya; mengganti kunci adalah keputusan yang disadari, bukan efek samping memasang ulang — dan
    # admin.erp yang dibobol tidak boleh dapat menggantinya lewat skrip ini.
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
    # container pertama dinyalakan — jauh dari orang yang sedang memasang dan dapat memilih port lain dalam
    # sepuluh detik.
    port_efektif="${port_aplikasi:-$(sed -n 's/^CORE_APP_PORT=//p' "$bahan/env.template" | tail -n 1)}"

    if [ ! -e "$BERKAS_ENV" ]; then
        port_sah "$port_efektif" || gagal "env.template menyebut CORE_APP_PORT yang tidak sah: ${port_efektif:-(kosong)}"

        if port_didengar "$port_efektif"; then
            gagal \
                "Port $port_efektif sudah didengar layanan lain di server ini." \
                '' \
                'CoreERP tidak dapat menyala di port yang sama. Pilih port yang bebas, lalu jalankan lagi dengan' \
                'pilihan yang sama ditambah --app-port:' \
                '  ... | sudo bash -s -- --token TOKEN --app-port PORT' \
                '' \
                'Reverse proxy di depan CoreERP kemudian diarahkan ke port itu.'
        fi

        # Proxy HTTPS agen mendengar 80 dan 443 di semua alamat. Server yang sudah memakai salah satunya hampir
        # selalu punya reverse proxy sendiri — Dokploy, Traefik, nginx — dan tanpa pemeriksaan ini tabrakannya
        # baru ketahuan di langkah terakhir pemasangan: sesudah rilis ditarik dan database bermigrasi, saat
        # update.sh menyalakan proxy, dan pemasangan pertama yang gagal di sana tidak punya versi untuk dituju.
        if [ "$proxy_luar" -eq 0 ]; then
            terpakai=()
            for satu in 80 443; do
                if port_didengar "$satu"; then
                    terpakai+=("$satu")
                fi
            done

            if [ "${#terpakai[@]}" -gt 0 ]; then
                ikat_efektif="${alamat_ikat:-$(sed -n 's/^CORE_APP_BIND=//p' "$bahan/env.template" | tail -n 1)}"
                daftar_terpakai="${terpakai[*]}"

                gagal \
                    "Port ${daftar_terpakai/ / dan } sudah didengar layanan lain di server ini." \
                    '' \
                    'Proxy HTTPS yang dipasang agen membutuhkan port 80 dan 443: peramban datang lewat keduanya, dan' \
                    "sertifikat Let's Encrypt untuk alamat tenant diambil lewat keduanya." \
                    '' \
                    'Bila server ini sudah punya reverse proxy (misalnya Dokploy, Traefik, atau nginx), jalankan lagi' \
                    'dengan pilihan yang sama ditambah --proxy-luar:' \
                    '  ... | sudo bash -s -- --token TOKEN --proxy-luar' \
                    '' \
                    "Sesudah itu arahkan host tenant di reverse proxy itu ke ${ikat_efektif:-127.0.0.1}:$port_efektif. Reverse proxy yang" \
                    'berjalan di dalam Docker tidak menjangkau loopback host: sebut alamat gateway bridge Docker lewat' \
                    '--app-bind (lihat: docker network inspect bridge), lalu arahkan ke alamat itu.'
            fi
        fi
    fi

    # Folder dan proyek compose milik stack lain. Diperiksa sebelum satu berkas pun ditulis, dan hanya bila folder
    # ini belum pernah dipasang agen — pemasangan ulang di atas pemasangan agen memang sah.
    #
    # Ditemukan di server uji kedua: `/opt/coreerp` di sana berisi stack CoreERP lama yang sedang berjalan, dengan
    # `.env`-nya sendiri dan volume `coreerp_core-db-data`. Tanpa penjaga ini perintah pasang bawaan membiarkan
    # `.env` itu (skrip ini tidak pernah menimpa `.env`), lalu update.sh menjalankan proyek compose `coreerp` —
    # nama yang sama — sehingga container stack lama diambil alih dan database-nya dipakai. Yang rusak adalah
    # layanan yang sedang melayani orang lain, dan kerusakannya baru terlihat sesudah terjadi.
    if [ ! -e "$RUMAH/kunci-rilis.pub" ] && [ ! -e "$RUMAH/agent/site.json" ]; then
        proyek_pasang="${COREERP_PROYEK:-coreerp}"
        saran_terpisah="  curl -fsSL $ALAMAT_ADMIN/pasang.sh | sudo COREERP_HOME=/opt/coreerp-situs COREERP_PROYEK=coreerp-situs bash -s -- --token TOKEN"

        if [ -e "$BERKAS_ENV" ]; then
            gagal \
                "$BERKAS_ENV sudah ada, tetapi $RUMAH bukan pemasangan agen CoreERP." \
                '' \
                'Skrip ini tidak menimpa .env, jadi memasang di sini menyalakan CoreERP dengan setelan milik stack lain.' \
                'Pasang ke folder dan proyek compose tersendiri, dengan token yang sama:' \
                "$saran_terpisah"
        fi

        if docker compose ls --all --format json 2>/dev/null | jq -e --arg p "$proyek_pasang" 'any(.[]?; .Name == $p)' >/dev/null 2>&1 \
            || docker volume ls --quiet --filter "label=com.docker.compose.project=$proyek_pasang" 2>/dev/null | grep -q .; then
            gagal \
                "Proyek compose \"$proyek_pasang\" sudah dipakai stack lain di server ini (container atau volume)." \
                '' \
                'Memasang dengan nama proyek yang sama mengambil alih container-nya dan memakai volume database-nya.' \
                'Pasang ke folder dan proyek compose tersendiri, dengan token yang sama:' \
                "$saran_terpisah"
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
    install -d -m 0700 "$RUMAH/agent" "$RUMAH/agent/releases" "$RUMAH/agent/log" "$RUMAH/keadaan"
    install -d -m 0755 "$RUMAH/agent/license"
    install -d -m 0700 "${COREERP_FOLDER_CADANGAN:-$RUMAH/cadangan}"

    install -m 0755 "$bahan/coreerp-agent" "$RUMAH/bin/coreerp-agent"
    install -m 0755 "$bahan/update.sh" "$RUMAH/update.sh"
    install -m 0644 "$bahan/kunci-rilis.pub" "$RUMAH/kunci-rilis.pub"

    # Pembungkus di PATH yang membawa COREERP_HOME, supaya perintah yang dijalankan tangan berlaku apa adanya
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

    # --- 4. Setelan aplikasi ---------------------------------------------------------------------------

    langkah 'Menyiapkan setelan aplikasi'

    kata_sandi_provider=''
    email_provider='provider@coreerp.local'

    if [ -e "$BERKAS_ENV" ]; then
        # Tidak pernah ditimpa. APP_KEY baru membuat seluruh data terenkripsi tidak terbaca lagi, dan kata
        # sandi database baru tidak cocok dengan database yang sudah dibuat dengan yang lama.
        printf '    %s sudah ada; tidak ditimpa\n' "$BERKAS_ENV"
        if [ -n "$port_aplikasi$alamat_ikat" ] || [ "$proxy_luar" -eq 1 ]; then
            printf '    --app-port, --app-bind, dan --proxy-luar hanya berlaku pada pemasangan pertama; ubah .env itu dengan tangan\n'
        fi
    else
        # Sementara. Operasi install dari admin.erp menulis alamat tenant (`app_url`) ke APP_URL dan
        # COREERP_APP_HOST sebelum rilis pertama menyala. Nama mesin bukan alamat yang dibuka pengguna — di server
        # uji ia domain pribadi pemilik mesin — dan admin.erp lama yang tidak mengirim `app_url` meninggalkan nilai
        # ini apa adanya.
        app_url="https://$(hostname -f 2>/dev/null || cat /proc/sys/kernel/hostname)"
        kunci_aplikasi="base64:$(openssl rand -base64 32)"
        kata_sandi_db="$(acak_alfanumerik 32)"
        kata_sandi_provider="$(acak_alfanumerik 24)"

        # Profil compose disimpan di .env, bukan diingat skrip ini: update.sh dan agen menjalankan compose dengan
        # --env-file yang sama pada setiap pembaruan, dan Compose membaca COMPOSE_PROFILES dari sana.
        profil_compose=proxy
        [ "$proxy_luar" -eq 0 ] || profil_compose=''

        (
            umask 077
            while IFS= read -r baris || [ -n "$baris" ]; do
                baris="${baris//@@APP_URL@@/"$app_url"}"
                baris="${baris//@@CORE_APP_KEY@@/"$kunci_aplikasi"}"
                baris="${baris//@@CORE_DB_PASSWORD@@/"$kata_sandi_db"}"
                baris="${baris//@@COREERP_PROVIDER_EMAIL@@/"$email_provider"}"
                baris="${baris//@@COREERP_PROVIDER_PASSWORD@@/"$kata_sandi_provider"}"
                baris="${baris//@@COREERP_LICENSE_DIR@@/"$RUMAH/agent/license"}"
                baris="${baris//@@COREERP_LICENSE_REQUIRED@@/"$kunci_lisensi"}"
                baris="${baris//@@COMPOSE_PROFILES@@/"$profil_compose"}"

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
        # diperiksa bebas di atas harus port yang benar-benar ditulis, dan server yang tidak dipasang dengan
        # --proxy-luar harus benar-benar mendapat proxy.
        for satu in "CORE_APP_PORT=$port_efektif" ${alamat_ikat:+"CORE_APP_BIND=$alamat_ikat"} \
            ${profil_compose:+"COMPOSE_PROFILES=$profil_compose"}; do
            if ! grep -qxF "$satu" "$BERKAS_ENV.baru"; then
                rm -f "$BERKAS_ENV.baru"
                gagal "env.template tidak memuat baris ${satu%%=*}; .env tidak dibuat."
            fi
        done

        chmod 0600 "$BERKAS_ENV.baru"
        mv -f "$BERKAS_ENV.baru" "$BERKAS_ENV"
        printf '    %s dibuat (hanya dapat dibaca root)\n' "$BERKAS_ENV"
        printf '    aplikasi didengar di %s:%s\n' "$(sed -n 's/^CORE_APP_BIND=//p' "$BERKAS_ENV" | tail -n 1)" "$port_efektif"

        if [ "$proxy_luar" -eq 1 ]; then
            printf '    tanpa proxy HTTPS agen (--proxy-luar): arahkan reverse proxy server ini ke alamat di atas\n'
        else
            printf '    proxy HTTPS agen di port 80 dan 443; alamatnya diberikan admin.erp saat pemasangan\n'
        fi

        # Disebut apa adanya pada kedua nilai. Penguncian yang menyala diam-diam adalah kejutan pada hari
        # lisensinya gagal terbit; penguncian yang mati diam-diam membuat orang mengira aplikasinya terlindungi.
        if [ "$kunci_lisensi" = true ]; then
            printf '    lisensi mengunci (--kunci-lisensi): lisensi yang habis atau hilang menutup aplikasi bagi pengguna tenant\n'
        else
            printf '    lisensi tidak mengunci: lisensi tetap diterbitkan dan dilaporkan, tetapi tidak pernah menutup aplikasi\n'
        fi
    fi

    # --- 5. systemd ------------------------------------------------------------------------------------

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

    # --- 6. Pendaftaran --------------------------------------------------------------------------------

    agen="$RUMAH/bin/coreerp-agent"
    export COREERP_HOME="$RUMAH"

    if [ -f "$RUMAH/agent/site.json" ]; then
        langkah 'Situs sudah terdaftar di server ini; pendaftaran dilewati'
    else
        "$agen" enroll --admin-url "$ALAMAT_ADMIN" --token "$token"
    fi

    if [ -n "$kata_sandi_provider" ]; then
        # Dicetak sekali, di sini, sebelum menunggu pemasangan: menunggu dapat dihentikan dengan Ctrl-C atau
        # habis waktunya, dan kata sandi yang hanya dicetak di akhir ikut hilang. Ia juga tersimpan di .env
        # karena Core membacanya saat seeding; ganti kata sandinya sesudah masuk pertama kali.
        printf '\n'
        printf '  +--------------------------------------------------------------------+\n'
        printf '  | Admin provider CoreERP. Catat sekarang; ia tidak dicetak lagi.     |\n'
        printf '  |   email     : %-52s |\n' "$email_provider"
        printf '  |   kata sandi: %-52s |\n' "$kata_sandi_provider"
        printf '  +--------------------------------------------------------------------+\n'
    fi

    # --- 7. Pemasangan dari admin.erp ------------------------------------------------------------------

    if [ "$(keadaan '.last_install.result // ""')" = succeeded ]; then
        langkah 'Pemasangan dari admin.erp sudah selesai sebelumnya di server ini'
        HASIL_PASANG=succeeded
    else
        langkah 'Menunggu pemasangan dari admin.erp'
        [ -z "$(keadaan '.last_install.id // ""')" ] \
            || printf '    pemasangan sebelumnya gagal; minta "Coba lagi" di halaman lingkungan di admin.erp\n'
        tunggu_pemasangan "$agen"
    fi

    # Timer baru dinyalakan sesudah menunggu: selama skrip ini menjalankan putaran, putaran timer hanya akan
    # menunggu kunci putaran yang sama.
    if [ "$ada_systemd" -eq 1 ]; then
        systemctl enable --now coreerp-agent.timer >/dev/null
        printf '    timer agen menyala\n'
    fi

    case "$HASIL_PASANG" in
        succeeded)
            printf '\nSelesai. Buka admin.erp untuk melihat server ini.\n'
            printf '  %s\n' "$ALAMAT_ADMIN"
            exit 0
            ;;
        failed)
            printf '\nPemasangan GAGAL pada langkah: %s\n' "$(keadaan '.last_install.step // "tidak diketahui"')"
            printf 'Sebab:\n'
            keadaan '.last_install.message // "tanpa keterangan"' | sed 's/^/  /'
            printf '\nPerbaiki sebabnya, lalu minta "Coba lagi" di halaman lingkungan di admin.erp; timer agen\n'
            printf 'mengambilnya tanpa perlu menjalankan skrip ini lagi. Log agen: %s\n' "$LOG_PUTARAN"
            exit 1
            ;;
        *)
            printf '\nBatas waktu menunggu habis sebelum pemasangan selesai.\n'
            printf 'Agen tetap mengambil operasi dari admin.erp lewat timer; ikuti progresnya di halaman\n'
            printf 'lingkungan di admin.erp. Log agen: %s\n' "$LOG_PUTARAN"
            exit 1
            ;;
    esac
} # akhir utama

utama "$@"
