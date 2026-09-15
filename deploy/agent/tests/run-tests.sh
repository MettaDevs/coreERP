#!/usr/bin/env bash
#
# Menguji agen situs, pasang.sh, dan jalur tanpa images.tar.gz di update.sh, di container Ubuntu bersih.
#
#   docker run --rm -v "$PWD":/repo -w /repo ubuntu:24.04 bash deploy/agent/tests/run-tests.sh
#
# Tanpa Docker sungguhan dan tanpa admin.erp sungguhan:
#
# - `docker` di PATH diganti `tests/shim/docker`, yang mencatat setiap pemanggilan;
# - update.sh diganti `tests/fake-update.sh` lewat COREERP_UPDATE_SCRIPT, kecuali pada pengujian
#   update.sh sendiri;
# - admin.erp diganti `tests/fake-admin.py`, yang memeriksa tanda tangan dan skema dari kontraknya;
# - `tests/klien-bertanda.py` menandatangani permintaan secara terpisah dari agen, supaya agen dan
#   admin.erp tiruan tidak dapat lulus bersama karena salah dengan cara yang sama;
# - pasang.sh mengambil berkas agen dari repo ini lewat COREERP_REPO_DIR, bukan dari GitHub.
#
# Kunci rilis dan kunci lisensi dibuat baru pada setiap putaran dan hilang bersama folder kerjanya.
# Tidak ada kunci yang disimpan di repo.
#
# Pengujian berurutan dan berbagi keadaan: situs yang didaftarkan pengujian pertama dipakai pengujian
# sesudahnya, dan nomor rilis naik mengikuti urutan. Satu kegagalan dapat menyeret yang sesudahnya;
# baca kegagalan pertama lebih dulu.
#
#   COREERP_AGENT_BIN=<berkas>      menguji salinan agen, update.sh, atau pasang.sh yang lain. Dipakai
#   COREERP_UPDATE_SH_BIN=<berkas>  untuk membuktikan pengujian ini memang merah ketika penjaga yang
#   COREERP_PASANG_BIN=<berkas>     diujinya dicabut dari salinan itu.

set -euo pipefail
shopt -s inherit_errexit

akar="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
folder_uji="$akar/deploy/agent/tests"
AGEN="${COREERP_AGENT_BIN:-$akar/deploy/agent/coreerp-agent}"
PASANG="${COREERP_PASANG_BIN:-$akar/deploy/agent/pasang.sh}"
TEMPLAT_ENV="$akar/deploy/agent/env.template"
UPDATE_SH="${COREERP_UPDATE_SH_BIN:-$akar/scripts/update.sh}"
KONTRAK_YAML="$akar/apps/control-plane/contracts/openapi-agent.yaml"
COMPOSE_EDISI="$akar/deploy/compose.edition.yaml"

SKRIP_BASH=(
    "$akar/deploy/agent/coreerp-agent"
    "$akar/deploy/agent/pasang.sh"
    "$akar/scripts/update.sh"
    "$folder_uji/run-tests.sh"
    "$folder_uji/fake-update.sh"
    "$folder_uji/shim/docker"
)

# --- Persiapan -----------------------------------------------------------------------------------------

siapkan_paket() {
    local kurang=()

    command -v jq >/dev/null 2>&1 || kurang+=(jq)
    command -v curl >/dev/null 2>&1 || kurang+=(curl)
    command -v openssl >/dev/null 2>&1 || kurang+=(openssl)
    command -v python3 >/dev/null 2>&1 || kurang+=(python3)
    command -v flock >/dev/null 2>&1 || kurang+=(util-linux)
    command -v shellcheck >/dev/null 2>&1 || kurang+=(shellcheck)
    python3 -c 'import yaml' >/dev/null 2>&1 || kurang+=(python3-yaml)

    [ "${#kurang[@]}" -gt 0 ] || return 0

    if [ "$(id -u)" -ne 0 ]; then
        printf 'Paket berikut dibutuhkan pengujian dan belum ada: %s\n' "${kurang[*]}" >&2
        exit 1
    fi

    printf 'Memasang paket uji: %s\n' "${kurang[*]}"
    apt-get update -qq >/dev/null
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends \
        ca-certificates "${kurang[@]}" >/dev/null
}

siapkan_paket

KERJA="$(mktemp -d)"
PID_ADMIN=''

bersihkan() {
    [ -z "$PID_ADMIN" ] || kill "$PID_ADMIN" 2>/dev/null || true
    rm -rf "$KERJA"
}
trap bersihkan EXIT

mkdir -p "$KERJA/bin" "$KERJA/kunci" "$KERJA/rilis" "$KERJA/log"
install -m 0755 "$folder_uji/shim/docker" "$KERJA/bin/docker"
export PATH="$KERJA/bin:$PATH"

export FAKE_DOCKER_LOG="$KERJA/docker.log"
export FAKE_DOCKER_LOG_LENGKAP="$KERJA/docker-lengkap.log"
export FAKE_DOCKER_JSON_LOG="$KERJA/docker.jsonl"
export FAKE_DOCKER_ROOT="$KERJA"
export FAKE_UPDATE_JEJAK="$KERJA/update.jejak"
: > "$FAKE_DOCKER_LOG"
: > "$FAKE_DOCKER_JSON_LOG"

buat_kunci_uji() {
    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$KERJA/kunci/$1.key" 2>/dev/null
    openssl pkey -in "$KERJA/kunci/$1.key" -pubout -out "$KERJA/kunci/$1.pub" 2>/dev/null
}

for nama in rilis rilis-palsu lisensi lisensi-palsu asing; do
    buat_kunci_uji "$nama"
done

python3 -c '
import json, sys, yaml
with open(sys.argv[1]) as masuk, open(sys.argv[2], "w") as keluar:
    json.dump(yaml.safe_load(masuk), keluar, default=str)
' "$KONTRAK_YAML" "$KERJA/kontrak.json"

python3 "$folder_uji/fake-admin.py" \
    --contract "$KERJA/kontrak.json" \
    --releases "$KERJA/rilis" \
    --license-public-key "$KERJA/kunci/lisensi.pub" \
    --port-file "$KERJA/port" 2> "$KERJA/log/fake-admin.log" &
PID_ADMIN=$!

for _ in $(seq 1 100); do
    [ ! -f "$KERJA/port" ] || break
    sleep 0.1
done
[ -f "$KERJA/port" ] || { printf 'admin.erp tiruan tidak menyala\n' >&2; cat "$KERJA/log/fake-admin.log" >&2; exit 1; }

ADMIN="http://127.0.0.1:$(cat "$KERJA/port")"

export COREERP_HOME="$KERJA/rumah"
export COREERP_FOLDER_CADANGAN="$KERJA/cadangan"
export COREERP_UPDATE_SCRIPT="$folder_uji/fake-update.sh"
mkdir -p "$COREERP_HOME"
cp "$KERJA/kunci/rilis.pub" "$COREERP_HOME/kunci-rilis.pub"

TOKEN='token-pendaftaran-0123456789abcdef012345'
TENANT_ID='01JTENANTUJI00000000000000'
TENANT_NAMA='Apotek Sejahtera Uji'

# --- Pembantu ------------------------------------------------------------------------------------------

lulus=0
gagal_uji=0
dilewati=0
nama_gagal=()

# Setiap pengujian berjalan di subshell dengan `set -e` sendiri, dan TIDAK dipanggil di dalam `if` atau
# `||`: bash mematikan `set -e` untuk seluruh isi fungsi yang dipanggil dari konteks itu, dan asersi
# yang gagal di tengah pengujian lalu lewat tanpa suara.
uji() {
    local nama="$1" fungsi="$2" log status

    log="$KERJA/log/$fungsi.log"

    set +e
    (
        set -e
        "$fungsi"
    ) > "$log" 2>&1
    status=$?
    set -e

    case "$status" in
        0)
            printf 'LULUS     %s\n' "$nama"
            lulus=$((lulus + 1))
            ;;
        77)
            printf 'DILEWATI  %s (%s)\n' "$nama" "$(tail -n 1 "$log")"
            dilewati=$((dilewati + 1))
            ;;
        *)
            printf 'GAGAL     %s\n' "$nama"
            tail -n 40 "$log" | sed 's/^/          | /'
            gagal_uji=$((gagal_uji + 1))
            nama_gagal+=("$nama")
            ;;
    esac
}

pastikan() {
    local keterangan="$1"
    shift

    if ! "$@"; then
        printf 'tidak terpenuhi: %s\n' "$keterangan"
        return 1
    fi
}

harus_gagal() {
    local keterangan="$1"
    shift

    if "$@"; then
        printf 'tidak terpenuhi: %s (perintah berhasil, padahal harus gagal)\n' "$keterangan"
        return 1
    fi
}

sama() {
    if [ "$2" != "$3" ]; then
        printf 'tidak terpenuhi: %s\n  diharapkan: %s\n  didapat   : %s\n' "$1" "$3" "$2"
        return 1
    fi
}

memuat() {
    case "$2" in
        *"$3"*) return 0 ;;
    esac

    printf 'tidak terpenuhi: %s\n  dicari   : %s\n  di dalam : %s\n' "$1" "$3" "$2"
    return 1
}

cocok_pola() {
    [[ "$1" =~ $2 ]]
}

agen() {
    bash "$AGEN" "$@"
}

klien() {
    python3 "$folder_uji/klien-bertanda.py" --url "$ADMIN" "$@" > "$KERJA/klien.out"
    head -n 1 "$KERJA/klien.out"
}

admin_keadaan() {
    curl -fsS "$ADMIN/_test/state"
}

admin_post() {
    curl -fsS -X POST -H 'Content-Type: application/json' --data-binary "$2" "$ADMIN$1"
}

jumlah_permintaan() {
    admin_keadaan | jq '.requests | length'
}

# Status HTTP permintaan ke JALUR sejak permintaan ke-N, digabung koma.
status_sejak() {
    admin_keadaan | jq -r --argjson n "$1" --arg jalur "$2" \
        '[.requests[$n:][] | select(.path == $jalur) | .status] | map(tostring) | join(",")'
}

situs() {
    jq -r .site_id "$COREERP_HOME/agent/site.json"
}

antre() {
    admin_post /_test/operations "$1" | jq -r .id
}

antre_upgrade() {
    antre "$(jq -cn --arg s "$(situs)" --arg e "$1" --arg r "$2" \
        '{site_id: $s, operation: "upgrade", parameters: {edition: $e, release: $r}}')"
}

operasi() {
    admin_keadaan | jq -c --arg id "$1" '.operations[] | select(.id == $id)'
}

laporan_terakhir() {
    admin_keadaan | jq -c '.reports[-1].isi'
}

validasi() {
    admin_post "/_test/validate/$1" "$2" | jq -c .errors
}

keadaan_agen() {
    jq -r "$1" "$COREERP_HOME/agent/state.json"
}

# tulis_berkas_rilis FOLDER EDISI RILIS [KUNCI] [--dengan-image] — berkas rilis bertanda tangan.
tulis_berkas_rilis() {
    local folder="$1" edisi="$2" rilis="$3" kunci="${4:-$KERJA/kunci/rilis.key}" dengan_image="${5:-}" digest

    mkdir -p "$folder"
    digest="$(printf '%s-%s' "$edisi" "$rilis" | sha256sum | cut -c1-64)"

    # Manifest ditulis melintasi beberapa baris dengan sengaja: update.sh harus membaca larik
    # image_pendamping dari bentuk itu juga.
    jq -n --arg e "$edisi" --arg r "$rilis" --arg d "$digest" '{
        edisi: $e,
        pelanggan: "Apotek Uji",
        rilis: $r,
        image: ("ghcr.io/mettadevs/edisi-" + $e + "@sha256:" + $d),
        digest: ("sha256:" + $d),
        image_pendamping: ["postgres:16-alpine", "gotenberg/gotenberg:8"],
        module: [],
        dibangun_pada: "2026-09-14T00:00:00Z"
    }' > "$folder/manifest.json"

    printf 'name: coreerp\n' > "$folder/compose.yaml"
    [ -f "$folder/update.sh" ] || printf '#!/usr/bin/env bash\nexit 0\n' > "$folder/update.sh"

    local daftar=(manifest.json compose.yaml update.sh)

    if [ "$dengan_image" = --dengan-image ]; then
        printf 'arsip image tiruan' | gzip > "$folder/images.tar.gz"
        daftar+=(images.tar.gz)
    fi

    (cd "$folder" && sha256sum "${daftar[@]}" > SHA256SUMS)
    openssl dgst -sha256 -sign "$kunci" -out "$folder/SHA256SUMS.sig" "$folder/SHA256SUMS"
}

# buat_rilis EDISI NAMA_FOLDER [RILIS_DI_MANIFEST] [KUNCI] — rilis yang disajikan admin.erp tiruan.
buat_rilis() {
    tulis_berkas_rilis "$KERJA/rilis/$1/$2" "$1" "${3:-$2}" "${4:-$KERJA/kunci/rilis.key}"
}

APPS_LISENSI='["human-resources","management-aset"]'

# buat_lisensi BERKAS SITUS BERLAKU_SAMPAI KUNCI [VERSI] [APPS] — lisensi format versi 2 bertanda tangan
# KUNCI; mencetak {license, signature}.
#
# VERSI dan APPS disisipkan harfiah sebagai JSON, dan SITUS kosong menghilangkan site_id, supaya bentuk
# yang salah dapat ditandatangani dengan kunci yang sah. Tanpa itu penolakan bentuk tidak dapat dibedakan
# dari penolakan tanda tangan.
buat_lisensi() {
    local berkas="$1" situs="$2" berlaku="$3" kunci="$4" versi="${5:-2}" apps="${6:-$APPS_LISENSI}" bidang_situs=''

    [ -z "$situs" ] || bidang_situs="\"site_id\":\"$situs\","

    printf '{"version":%s,"tenant_id":"%s",%s"apps":%s,"valid_until":"%s","issued_at":"2026-09-15T08:00:00Z"}' \
        "$versi" "$TENANT_ID" "$bidang_situs" "$apps" "$berlaku" > "$berkas"
    jq -cn --rawfile l "$berkas" --arg t "$(openssl dgst -sha256 -sign "$kunci" "$berkas" | base64 -w0)" \
        '{license: $l, signature: $t}'
}

# token_baru NAMA — mendaftarkan satu token pendaftaran sekali pakai di admin.erp tiruan dan mencetaknya.
token_baru() {
    local token="token-$1-0123456789abcdef0123456789abcdef"

    admin_post /_test/tokens "$(jq -cn --arg t "$token" '{token: $t}')" >/dev/null
    printf '%s' "$token"
}

variabel_compose() {
    grep -oE '\$\{[A-Za-z_][A-Za-z0-9_]*' "$COMPOSE_EDISI" | cut -c3- | sort -u
}

# --- Pengujian: statis ---------------------------------------------------------------------------------

uji_sintaks() {
    local satu

    for satu in "${SKRIP_BASH[@]}"; do
        bash -n "$satu"
        harus_gagal "$satu tidak memuat CR (akhir baris Windows)" grep -q $'\r' "$satu"
    done

    python3 -c '
import ast, sys
for berkas in sys.argv[1:]:
    with open(berkas) as f:
        ast.parse(f.read(), berkas)
' "$folder_uji/fake-admin.py" "$folder_uji/klien-bertanda.py"
}

uji_shellcheck() {
    if ! command -v shellcheck >/dev/null 2>&1; then
        printf 'shellcheck tidak tersedia\n'
        exit 77
    fi

    shellcheck --version | sed -n 's/^version: /shellcheck /p'
    shellcheck --external-sources "${SKRIP_BASH[@]}"
}

uji_templat_dan_compose() {
    local nama kurang=()

    while IFS= read -r nama; do
        [ "$nama" != EDITION_IMAGE ] || continue
        grep -q "^$nama=" "$TEMPLAT_ENV" || kurang+=("$nama")
    done < <(variabel_compose)

    sama 'setiap variabel compose ada di env.template' "${kurang[*]}" ''
    pastikan 'env.template menyebut COREERP_LICENSE_DIR' grep -q '^COREERP_LICENSE_DIR=' "$TEMPLAT_ENV"

    # Bawaan compose menjaga pemasangan beli-putus; on-prem dikelola mengikat ke loopback lewat templatnya.
    sama 'env.template mengikat port aplikasi ke loopback' "$(grep '^CORE_APP_BIND=' "$TEMPLAT_ENV")" 'CORE_APP_BIND=127.0.0.1'

    # On-prem dikelola mengunci; bawaan compose tidak, supaya beli-putus dan SaaS tidak pernah terkunci.
    sama 'env.template mewajibkan lisensi' "$(grep 'COREERP_LICENSE_REQUIRED' "$TEMPLAT_ENV" | grep -v '^#')" 'COREERP_LICENSE_REQUIRED=true'

    # shellcheck disable=SC2016 # ${COREERP_LICENSE_DIR:-...} dan ${CORE_APP_BIND:-...} adalah teks harfiah yang dicari di compose
    python3 -c '
import sys, yaml
with open(sys.argv[1]) as f:
    compose = yaml.safe_load(f)
lingkungan = compose["x-edition-environment"]
assert lingkungan["COREERP_LICENSE_PATH"] == "/run/coreerp-license/license.json", "COREERP_LICENSE_PATH"
assert lingkungan["COREERP_LICENSE_PUBLIC_KEY_PATH"] == "/run/coreerp-license/license-public.pem", "COREERP_LICENSE_PUBLIC_KEY_PATH"
wajib = "${COREERP_LICENSE_REQUIRED:-false}"
assert lingkungan["COREERP_LICENSE_REQUIRED"] == wajib, "COREERP_LICENSE_REQUIRED di anchor: %r" % lingkungan.get("COREERP_LICENSE_REQUIRED")
mount = "${COREERP_LICENSE_DIR:-/opt/coreerp/agent/license}:/run/coreerp-license:ro"
for layanan in ("core-app", "core-worker", "core-scheduler"):
    assert mount in compose["services"][layanan]["volumes"], layanan + " tidak me-mount folder lisensi"
# Setiap layanan yang membaca setelan lisensi: yang me-mount foldernya meneruskan nilai dari .env; yang tidak
# me-mount-nya tidak boleh mewajibkan lisensi, karena lisensi yang tidak terlihat terbaca hilang dan mengunci.
tanpa_mount = []
for nama, layanan in compose["services"].items():
    env = layanan.get("environment") or {}
    if "COREERP_LICENSE_PATH" not in env:
        continue
    if mount in (layanan.get("volumes") or []):
        assert env["COREERP_LICENSE_REQUIRED"] == wajib, "%s: COREERP_LICENSE_REQUIRED %r" % (nama, env["COREERP_LICENSE_REQUIRED"])
    else:
        assert env["COREERP_LICENSE_REQUIRED"] == "false", "%s tanpa folder lisensi: COREERP_LICENSE_REQUIRED %r" % (nama, env["COREERP_LICENSE_REQUIRED"])
        tanpa_mount.append(nama)
assert tanpa_mount == ["core-migrate"], "layanan tanpa folder lisensi: %r" % tanpa_mount
port = compose["services"]["core-app"]["ports"]
assert port == ["${CORE_APP_BIND:-0.0.0.0}:${CORE_APP_PORT:-8000}:80"], "port core-app: %r" % port
# Layanan lain yang menerbitkan port lolos dari CORE_APP_BIND dan terbuka ke semua alamat.
penerbit = sorted(nama for nama, layanan in compose["services"].items() if "ports" in layanan)
assert penerbit == ["core-app"], "layanan yang menerbitkan port: %r" % penerbit
' "$COMPOSE_EDISI"

    # build-bundle.sh membaca image pendamping secara harfiah dari berkas compose; perubahan berkas itu
    # tidak boleh menambah atau menghilangkan satu pun.
    sama 'image pendamping yang dibaca build-bundle.sh' \
        "$(grep -oE '^[[:space:]]*image:[[:space:]]*[^$[:space:]][^[:space:]]*' "$COMPOSE_EDISI" \
            | sed 's/^[[:space:]]*image:[[:space:]]*//' | sort -u | paste -sd,)" \
        'gotenberg/gotenberg:8,postgres:16-alpine'
}

# --- Pengujian: agen online ----------------------------------------------------------------------------

uji_01_enroll() {
    local berkas_situs kunci_sebelum id

    admin_post /_test/tokens "$(jq -cn --arg t "$TOKEN" --arg i "$TENANT_ID" --arg n "$TENANT_NAMA" \
        '{token: $t, tenant_id: $i, tenant_name: $n}')" >/dev/null

    agen enroll --admin-url "$ADMIN" --token "$TOKEN"

    berkas_situs="$COREERP_HOME/agent/site.json"
    pastikan 'site.json ditulis' test -f "$berkas_situs"
    id="$(jq -r .site_id "$berkas_situs")"

    sama 'kunci site.json' "$(jq -c 'keys' "$berkas_situs")" \
        '["admin_url","interval_seconds","site_id","tenant_id","tenant_name","update_window"]'
    sama 'alamat admin' "$(jq -r .admin_url "$berkas_situs")" "$ADMIN"
    sama 'tenant_id' "$(jq -r .tenant_id "$berkas_situs")" "$TENANT_ID"
    sama 'tenant_name' "$(jq -r .tenant_name "$berkas_situs")" "$TENANT_NAMA"
    sama 'interval' "$(jq -r .interval_seconds "$berkas_situs")" 60
    sama 'jendela pembaruan' "$(jq -c .update_window "$berkas_situs")" '{"start":"01:00","end":"04:00","timezone":"Asia/Jakarta"}'

    sama 'mode kunci privat situs' "$(stat -c %a "$COREERP_HOME/agent/site-key.pem")" 600
    pastikan 'kunci RSA 3072 bit' grep -q '3072 bit' <(openssl pkey -in "$COREERP_HOME/agent/site-key.pem" -noout -text)
    pastikan 'kunci publik situs cocok dengan kunci privatnya' \
        cmp -s <(openssl pkey -in "$COREERP_HOME/agent/site-key.pem" -pubout) "$COREERP_HOME/agent/site-public.pem"
    sama 'admin.erp menyimpan kunci publik situs' \
        "$(admin_keadaan | jq -r --arg id "$id" '.sites[$id].public_key')" "$(cat "$COREERP_HOME/agent/site-public.pem")"
    pastikan 'kunci publik lisensi dipasang' \
        cmp -s "$COREERP_HOME/agent/license/license-public.pem" "$KERJA/kunci/lisensi.pub"

    # Token yang sudah dipakai ditolak, dan tidak meninggalkan kunci setengah jadi.
    if env COREERP_HOME="$KERJA/rumah-token-bekas" bash "$AGEN" enroll --admin-url "$ADMIN" --token "$TOKEN" \
        > "$KERJA/log/enroll-kedua.log" 2>&1; then
        printf 'token yang sudah dipakai diterima\n'
        return 1
    fi
    memuat 'penolakan token bekas' "$(cat "$KERJA/log/enroll-kedua.log")" 'HTTP 401'
    pastikan 'tidak ada kunci tertinggal sesudah pendaftaran ditolak' test ! -e "$KERJA/rumah-token-bekas/agent/site-key.pem"

    # Mendaftar ulang di server yang sudah terdaftar tidak menimpa kunci situs.
    kunci_sebelum="$(sha256sum < "$COREERP_HOME/agent/site-key.pem")"
    harus_gagal 'enroll ulang ditolak' agen enroll --admin-url "$ADMIN" --token 'token-lain-0123456789abcdef0123456789'
    sama 'kunci situs tidak tertimpa' "$(sha256sum < "$COREERP_HOME/agent/site-key.pem")" "$kunci_sebelum"

    # Alamat HTTP ke mesin lain ditolak sebelum apa pun dikirim.
    harus_gagal 'alamat HTTP ke host lain ditolak' \
        env COREERP_HOME="$KERJA/rumah-http" bash "$AGEN" enroll --admin-url http://admin.erp.contoh --token "$TOKEN"
}

uji_02_laporan() {
    local sebelum laporan kunci_kontrak

    sebelum="$(admin_keadaan | jq '.reports | length')"
    agen run --now
    sama 'satu laporan diterima' "$(admin_keadaan | jq '.reports | length')" "$((sebelum + 1))"

    laporan="$(laporan_terakhir)"
    kunci_kontrak="$(jq -c '.components.schemas.Report.properties | keys' "$KERJA/kontrak.json")"

    # shellcheck disable=SC2016 # $izin adalah variabel jq
    pastikan 'laporan hanya memuat kunci Report di kontrak' \
        jq -e --argjson izin "$kunci_kontrak" 'keys - $izin == []' <<< "$laporan"
    sama 'laporan sesuai skema Report' "$(validasi Report "$laporan")" '[]'
    sama 'kontainer hanya membawa service, state, health' \
        "$(jq -c '[.containers[] | keys] | unique' <<< "$laporan")" '[["health","service","state"]]'
    sama 'isi kontainer' "$(jq -c '.containers' <<< "$laporan")" \
        '[{"service":"core-app","state":"running","health":"healthy"},{"service":"core-db","state":"running","health":null}]'
    pastikan 'sisa disk data terbaca' jq -e '.disk.data_free_bytes | type == "number"' <<< "$laporan"
    sama 'belum ada rilis terpasang' "$(jq -c '[.edition, .release, .last_operation]' <<< "$laporan")" '[null,null,null]'
    sama 'interval dari admin.erp tersimpan' "$(jq -r .interval_seconds "$COREERP_HOME/agent/site.json")" 60

    # Tanpa --now, putaran yang datang sebelum interval habis tidak menghubungi admin.erp.
    sebelum="$(jumlah_permintaan)"
    agen run
    sama 'putaran sebelum waktunya tidak mengirim apa pun' "$(jumlah_permintaan)" "$sebelum"

    # Merah lebih dulu: admin.erp tiruan benar-benar menolak kunci di luar skema. Tanpa bukti ini, "laporan
    # diterima" di atas tidak membuktikan apa pun tentang daftar tertutup.
    jq -c '. + {log_aplikasi: "Pasien A dirawat"}' <<< "$laporan" > "$KERJA/laporan-tambahan.json"
    sama 'kunci di luar skema ditolak 422' \
        "$(klien --key "$COREERP_HOME/agent/site-key.pem" --site "$(situs)" --method POST \
            --path /api/agent/v1/report --body "$KERJA/laporan-tambahan.json")" 422
    memuat 'alasan penolakan menyebut kuncinya' "$(cat "$KERJA/klien.out")" 'log_aplikasi'
}

uji_03_kunci_lain() {
    local sebelum

    jq -c . <<< "$(laporan_terakhir)" > "$KERJA/laporan-klien.json"

    sama 'klien terpisah dengan kunci situs diterima' \
        "$(klien --key "$COREERP_HOME/agent/site-key.pem" --site "$(situs)" --method POST \
            --path /api/agent/v1/report --body "$KERJA/laporan-klien.json")" 200

    sama 'kunci lain ditolak 401' \
        "$(klien --key "$KERJA/kunci/asing.key" --site "$(situs)" --method POST \
            --path /api/agent/v1/report --body "$KERJA/laporan-klien.json")" 401
    sama 'penolakan tanpa rincian' "$(tail -n +2 "$KERJA/klien.out")" '{"error": "signature_invalid"}'

    sama 'created di luar 300 detik ditolak 401' \
        "$(klien --key "$COREERP_HOME/agent/site-key.pem" --site "$(situs)" --method POST --created-offset -400 \
            --path /api/agent/v1/report --body "$KERJA/laporan-klien.json")" 401

    sama 'digest yang tidak cocok ditolak 401' \
        "$(klien --key "$COREERP_HOME/agent/site-key.pem" --site "$(situs)" --method POST --digest-palsu \
            --path /api/agent/v1/report --body "$KERJA/laporan-klien.json")" 401

    # Agen yang memegang kunci lain.
    rm -rf "$KERJA/rumah-asing"
    cp -a "$COREERP_HOME" "$KERJA/rumah-asing"
    cp "$KERJA/kunci/asing.key" "$KERJA/rumah-asing/agent/site-key.pem"

    sebelum="$(jumlah_permintaan)"
    harus_gagal 'agen dengan kunci lain gagal' env COREERP_HOME="$KERJA/rumah-asing" bash "$AGEN" run --now
    sama 'laporan agen dengan kunci lain dijawab 401' "$(status_sejak "$sebelum" /api/agent/v1/report)" 401
    sama 'agen berhenti sebelum menanyakan operasi' "$(status_sejak "$sebelum" /api/agent/v1/operations/claim)" ''
}

uji_04_upgrade() {
    local id op rilis_folder

    buat_rilis apotek-uji 0.2.0
    id="$(antre_upgrade apotek-uji 0.2.0)"
    : > "$FAKE_UPDATE_JEJAK"

    agen run --now

    op="$(operasi "$id")"
    rilis_folder="$COREERP_HOME/agent/releases/apotek-uji-0.2.0"

    sama 'status operasi' "$(jq -r .status <<< "$op")" succeeded
    sama 'setiap langkah ==> dilaporkan berurutan' \
        "$(jq -c '[.langkah[] | select(.status == "running") | .step]' <<< "$op")" \
        '["Mengunduh dan memeriksa rilis 0.2.0","Memeriksa tanda tangan","Menjalankan migrasi","Memeriksa kesehatan"]'
    sama 'langkah terakhir' "$(jq -c '.langkah[-1] | [.status, .step]' <<< "$op")" '["succeeded","Rilis 0.2.0 terpasang"]'

    sama 'update.sh dijalankan atas folder rilis yang terverifikasi' "$(cat "$FAKE_UPDATE_JEJAK")" \
        "mulai $rilis_folder"$'\n'"selesai $rilis_folder"
    pastikan 'berkas rilis tersimpan' test -f "$rilis_folder/SHA256SUMS.sig"

    sama 'state.json: edisi dan rilis' "$(keadaan_agen '[.edition, .release] | join(" ")')" 'apotek-uji 0.2.0'
    sama 'state.json: image' "$(keadaan_agen .image)" "$(jq -r .image "$rilis_folder/manifest.json")"
    sama 'state.json: digest' "$(keadaan_agen .digest)" "$(jq -r .digest "$rilis_folder/manifest.json")"

    sama 'laporan sesudah operasi menyebut rilis baru' "$(laporan_terakhir | jq -r '[.edition, .release] | join(" ")')" 'apotek-uji 0.2.0'
    sama 'laporan menyebut operasi terakhir' "$(laporan_terakhir | jq -c .last_operation)" \
        "{\"id\":\"$id\",\"result\":\"succeeded\",\"step\":\"Rilis 0.2.0 terpasang\"}"
}

uji_05_rilis_palsu() {
    local id op

    buat_rilis apotek-uji 0.3.0 0.3.0 "$KERJA/kunci/rilis-palsu.key"
    id="$(antre_upgrade apotek-uji 0.3.0)"
    : > "$FAKE_UPDATE_JEJAK"

    agen run --now

    op="$(operasi "$id")"
    sama 'rilis bertanda tangan kunci lain gagal' "$(jq -r .status <<< "$op")" failed
    memuat 'sebabnya tanda tangan' "$(jq -r .failure_message <<< "$op")" 'tanda tangan SHA256SUMS TIDAK sah'
    sama 'update.sh tidak pernah dijalankan' "$(cat "$FAKE_UPDATE_JEJAK")" ''
    pastikan 'berkas rilis palsu tidak disimpan' test ! -e "$COREERP_HOME/agent/releases/apotek-uji-0.3.0"
    sama 'rilis terpasang tidak berubah' "$(keadaan_agen .release)" 0.2.0

    # Tanda tangan sah, tetapi berkasnya berubah sesudah ditandatangani.
    buat_rilis apotek-uji 0.3.1
    printf 'services: {}\n' >> "$KERJA/rilis/apotek-uji/0.3.1/compose.yaml"
    id="$(antre_upgrade apotek-uji 0.3.1)"

    agen run --now

    op="$(operasi "$id")"
    sama 'berkas yang berubah gagal' "$(jq -r .status <<< "$op")" failed
    memuat 'sebabnya checksum' "$(jq -r .failure_message <<< "$op")" 'checksum'
    sama 'update.sh tidak pernah dijalankan' "$(cat "$FAKE_UPDATE_JEJAK")" ''
}

uji_06_tolak_mundur() {
    local id op rilis sebelum

    for rilis in 0.2.0 0.2 0.1.0; do
        id="$(antre_upgrade apotek-uji "$rilis")"
        : > "$FAKE_UPDATE_JEJAK"
        sebelum="$(jumlah_permintaan)"

        agen run --now

        op="$(operasi "$id")"
        sama "rilis $rilis ditolak" "$(jq -r .status <<< "$op")" failed
        memuat "sebab penolakan $rilis" "$(jq -r .failure_message <<< "$op")" 'tidak lebih baru dari rilis terpasang 0.2.0'
        sama "rilis $rilis tidak diunduh" \
            "$(admin_keadaan | jq --argjson n "$sebelum" '[.requests[$n:][] | select(.method == "GET")] | length')" 0
        sama 'update.sh tidak pernah dijalankan' "$(cat "$FAKE_UPDATE_JEJAK")" ''
    done

    # Berkas rilis lama yang sah disajikan di bawah nomor rilis baru. Nomor yang menentukan adalah yang
    # ditandatangani, bukan yang diminta.
    buat_rilis apotek-uji 0.9.0 0.1.0
    id="$(antre_upgrade apotek-uji 0.9.0)"
    : > "$FAKE_UPDATE_JEJAK"

    agen run --now

    op="$(operasi "$id")"
    sama 'rilis lama berlabel baru ditolak' "$(jq -r .status <<< "$op")" failed
    memuat 'sebabnya nomor di manifest' "$(jq -r .failure_message <<< "$op")" 'manifest yang ditandatangani menyebut apotek-uji 0.1.0'
    sama 'update.sh tidak pernah dijalankan' "$(cat "$FAKE_UPDATE_JEJAK")" ''

    # Rilis sah yang lebih baru dari yang terpasang, tetapi bukan yang diminta. Penolakan mundur tidak
    # menangkapnya; hanya pencocokan nomor yang diminta dengan nomor yang ditandatangani yang menangkap.
    buat_rilis apotek-uji 0.9.9 0.9.8
    id="$(antre_upgrade apotek-uji 0.9.9)"
    : > "$FAKE_UPDATE_JEJAK"

    agen run --now

    op="$(operasi "$id")"
    sama 'rilis yang bukan diminta ditolak' "$(jq -r .status <<< "$op")" failed
    memuat 'sebabnya nomor di manifest' "$(jq -r .failure_message <<< "$op")" 'manifest yang ditandatangani menyebut apotek-uji 0.9.8'
    sama 'update.sh tidak pernah dijalankan' "$(cat "$FAKE_UPDATE_JEJAK")" ''

    buat_rilis klinik-lain 1.0.0
    id="$(antre_upgrade klinik-lain 1.0.0)"

    agen run --now

    op="$(operasi "$id")"
    sama 'edisi lain ditolak' "$(jq -r .status <<< "$op")" failed
    memuat 'sebabnya edisi' "$(jq -r .failure_message <<< "$op")" 'berbeda dari edisi terpasang apotek-uji'
    sama 'rilis terpasang tidak berubah' "$(keadaan_agen .release)" 0.2.0
}

uji_07_update_gagal() {
    local id op pesan

    buat_rilis apotek-uji 0.4.0
    id="$(antre_upgrade apotek-uji 0.4.0)"

    env FAKE_UPDATE_EXIT=1 bash "$AGEN" run --now

    op="$(operasi "$id")"
    pesan="$(jq -r .failure_message <<< "$op")"

    sama 'status operasi' "$(jq -r .status <<< "$op")" failed
    sama 'langkah tempat berhenti' "$(jq -r '.langkah[-1].step' <<< "$op")" 'Menjalankan migrasi'
    memuat 'pesan memuat galat migrasi' "$pesan" 'SQLSTATE[42703]'
    memuat 'pesan memuat baris GAGAL' "$pesan" 'GAGAL: migrasi gagal (tiruan)'
    pastikan 'pesan paling banyak 20 baris' test "$(printf '%s\n' "$pesan" | wc -l)" -le 20
    sama 'rilis terpasang tidak berubah' "$(keadaan_agen .release)" 0.2.0
    sama 'operasi terakhir tercatat gagal' "$(keadaan_agen '.last_operation | [.result, .step] | join(" / ")')" \
        'failed / Menjalankan migrasi'
}

uji_08_409() {
    local id op

    buat_rilis apotek-uji 0.5.0
    id="$(antre "$(jq -cn --arg s "$(situs)" \
        '{site_id: $s, operation: "upgrade", parameters: {edition: "apotek-uji", release: "0.5.0"}, lepas_setelah: 2}')")"
    : > "$FAKE_UPDATE_JEJAK"

    if env FAKE_UPDATE_BARIS=4000 bash "$AGEN" run --now > "$KERJA/log/agen-409.log" 2>&1; then
        printf 'agen keluar nol padahal operasinya dilepas admin.erp\n'
        return 1
    fi

    op="$(operasi "$id")"

    sama 'lapor langkah berhenti sesudah 409' "$(jq -r .percobaan_langkah <<< "$op")" 3
    sama 'langkah yang diterima sebelum 409' "$(jq -c '[.langkah[].step]' <<< "$op")" \
        '["Mengunduh dan memeriksa rilis 0.5.0","Memeriksa tanda tangan"]'
    sama 'hasil akhir tidak dilaporkan' "$(jq -c '[.langkah[].status] | unique' <<< "$op")" '["running"]'
    sama 'update.sh tidak dibunuh dan berjalan sampai selesai' "$(tail -n 1 "$FAKE_UPDATE_JEJAK")" \
        "selesai $COREERP_HOME/agent/releases/apotek-uji-0.5.0"
    sama 'state.json menyebut rilis yang benar-benar terpasang' "$(keadaan_agen .release)" 0.5.0
    memuat 'agen menjelaskan penghentiannya' "$(cat "$KERJA/log/agen-409.log")" 'tidak lagi dipegang agen ini'
}

uji_09_lisensi_operasi() {
    local s id op parameter berkas_lisensi="$COREERP_HOME/agent/license/license.json"

    s="$(situs)"

    parameter="$(buat_lisensi "$KERJA/lisensi-palsu.json" "$s" 2027-09-30 "$KERJA/kunci/lisensi-palsu.key")"
    id="$(antre "$(jq -cn --arg s "$s" --argjson p "$parameter" '{site_id: $s, operation: "install_license", parameters: $p}')")"
    agen run --now
    op="$(operasi "$id")"
    sama 'lisensi bertanda tangan salah gagal' "$(jq -r .status <<< "$op")" failed
    memuat 'sebabnya tanda tangan' "$(jq -r .failure_message <<< "$op")" 'tanda tangan lisensi TIDAK sah'
    pastikan 'lisensi palsu tidak dipasang' test ! -e "$berkas_lisensi"

    parameter="$(buat_lisensi "$KERJA/lisensi-situs-lain.json" 01JSITUSLAIN00000000000000 2027-09-30 "$KERJA/kunci/lisensi.key")"
    id="$(antre "$(jq -cn --arg s "$s" --argjson p "$parameter" '{site_id: $s, operation: "install_license", parameters: $p}')")"
    agen run --now
    op="$(operasi "$id")"
    sama 'lisensi situs lain gagal' "$(jq -r .status <<< "$op")" failed
    memuat 'sebabnya situs' "$(jq -r .failure_message <<< "$op")" 'bukan situs ini'
    pastikan 'lisensi situs lain tidak dipasang' test ! -e "$berkas_lisensi"

    # Operasi dan jawaban laporan memakai pemeriksaan yang sama; format lama tidak lolos lewat jalur operasi.
    parameter="$(buat_lisensi "$KERJA/lisensi-versi-1.json" "$s" 2027-09-30 "$KERJA/kunci/lisensi.key" 1)"
    id="$(antre "$(jq -cn --arg s "$s" --argjson p "$parameter" '{site_id: $s, operation: "install_license", parameters: $p}')")"
    agen run --now
    op="$(operasi "$id")"
    sama 'lisensi versi 1 gagal' "$(jq -r .status <<< "$op")" failed
    memuat 'sebabnya versi' "$(jq -r .failure_message <<< "$op")" 'bukan format versi 2'
    pastikan 'lisensi versi 1 tidak dipasang' test ! -e "$berkas_lisensi"

    parameter="$(buat_lisensi "$KERJA/lisensi-baik.json" "$s" 2027-09-30 "$KERJA/kunci/lisensi.key")"
    id="$(antre "$(jq -cn --arg s "$s" --argjson p "$parameter" '{site_id: $s, operation: "install_license", parameters: $p}')")"
    agen run --now
    op="$(operasi "$id")"
    sama 'lisensi sah terpasang' "$(jq -r .status <<< "$op")" succeeded
    pastikan 'license.json persis byte yang ditandatangani' cmp -s "$berkas_lisensi" "$KERJA/lisensi-baik.json"
    sama 'license.json.sig satu baris tanpa akhir baris' "$(wc -l < "$berkas_lisensi.sig")" 0
    base64 -d "$berkas_lisensi.sig" > "$KERJA/lisensi-terpasang.sig"
    pastikan 'tanda tangan terpasang sah' \
        openssl dgst -sha256 -verify "$KERJA/kunci/lisensi.pub" -signature "$KERJA/lisensi-terpasang.sig" "$berkas_lisensi"
    sama 'lisensi dapat dibaca container Core' "$(stat -c %a "$berkas_lisensi")" 644
    sama 'laporan menyebut habis lisensi' "$(laporan_terakhir | jq -r .license_expires_at)" 2027-09-30
}

uji_09b_lisensi_laporan() {
    local s folder="$COREERP_HOME/agent/license" sidik_awal sebelum log lisensi

    s="$(situs)"

    sidik() {
        cat "$folder/license.json" "$folder/license.json.sig" | sha256sum
    }

    # putaran NAMA — satu `run --now` tanpa operasi; keluarannya di $log. Interval di site.json dikacaukan
    # lebih dulu: interval dari jawaban laporan tetap harus tersimpan, apa pun nasib lisensinya.
    putaran() {
        log="$KERJA/log/lisensi-laporan-$1.log"
        jq -c '.interval_seconds = 999' "$COREERP_HOME/agent/site.json" > "$KERJA/site-kacau.json"
        cat "$KERJA/site-kacau.json" > "$COREERP_HOME/agent/site.json"
        sebelum="$(jumlah_permintaan)"

        if ! agen run --now > "$log" 2>&1; then
            cat "$log"
            printf '%s: putaran gagal, padahal lisensi di jawaban laporan tidak boleh menggagalkannya\n' "$1"
            return 1
        fi

        sama "$1: interval dari jawaban laporan tersimpan" "$(jq -r .interval_seconds "$COREERP_HOME/agent/site.json")" 60
        sama "$1: putaran berlanjut menanyakan operasi" "$(status_sejak "$sebelum" /api/agent/v1/operations/claim)" 204
    }

    # titip ISI — {license, signature} yang disertakan admin.erp tiruan di jawaban laporan berikutnya.
    titip() {
        admin_post "/_test/sites/$s/lisensi-laporan" "$1" >/dev/null
    }

    dijawab() {
        sama "$1: jawaban laporan membawa lisensi" "$(admin_keadaan | jq -r '.reports[-1].lisensi_dijawab')" "$2"
    }

    # tolak_laporan KETERANGAN POTONGAN_ALASAN SITUS BERLAKU_SAMPAI KUNCI [VERSI] [APPS]
    tolak_laporan() {
        local keterangan="$1" potongan="$2"
        shift 2

        titip "$(buat_lisensi "$KERJA/lisensi-laporan.json" "$@")"
        putaran "$keterangan"
        dijawab "$keterangan" true
        memuat "$keterangan: penolakan dicatat" "$(cat "$log")" 'Lisensi dari admin.erp DITOLAK dan tidak dipasang'
        memuat "$keterangan: sebabnya" "$(cat "$log")" "$potongan"
        sama "$keterangan: lisensi terpasang tidak berubah" "$(sidik)" "$sidik_awal"
        sama "$keterangan: tidak ada berkas setengah jadi" "$(find "$folder" -name '.*' | wc -l)" 0
    }

    pastikan 'lisensi dari pengujian 09 terpasang' test -f "$folder/license.json"
    sidik_awal="$(sidik)"

    # Jawaban tanpa lisensi: tidak ada yang disentuh, tidak ada yang dicatat.
    putaran 'tanpa lisensi'
    dijawab 'tanpa lisensi' false
    sama 'tanpa lisensi: lisensi terpasang tidak berubah' "$(sidik)" "$sidik_awal"
    harus_gagal 'tanpa lisensi: tidak ada catatan lisensi' grep -q 'Lisensi dari admin.erp' "$log"

    local kunci="$KERJA/kunci/lisensi.key"

    tolak_laporan 'tanda tangan kunci lain' 'tanda tangan lisensi TIDAK sah' "$s" 2027-10-15 "$KERJA/kunci/lisensi-palsu.key"
    tolak_laporan 'situs lain' 'untuk situs 01JSITUSLAIN00000000000000, bukan situs ini' 01JSITUSLAIN00000000000000 2027-10-15 "$kunci"
    tolak_laporan 'tanpa site_id' 'tidak menyebut situs' '' 2027-10-15 "$kunci"
    tolak_laporan 'versi 1' 'bukan format versi 2' "$s" 2027-10-15 "$kunci" 1
    tolak_laporan 'versi berupa teks' 'bukan format versi 2' "$s" 2027-10-15 "$kunci" '"2"'
    tolak_laporan 'apps bukan larik' 'bukan larik id app' "$s" 2027-10-15 "$kunci" 2 '"human-resources"'
    tolak_laporan 'apps null' 'bukan larik id app' "$s" 2027-10-15 "$kunci" 2 null
    # Objek lolos `all(.apps[]; ...)` — jq menjelajahi nilai-nilainya — jadi hanya pemeriksaan tipe yang menolaknya.
    tolak_laporan 'apps berupa objek' 'bukan larik id app' "$s" 2027-10-15 "$kunci" 2 '{"hr":"human-resources"}'
    tolak_laporan 'id app bukan teks' 'bukan larik id app' "$s" 2027-10-15 "$kunci" 2 '["human-resources",7]'
    tolak_laporan 'id app berhuruf besar' 'bukan larik id app' "$s" 2027-10-15 "$kunci" 2 '["Human-Resources"]'
    tolak_laporan 'id app diawali minus' 'bukan larik id app' "$s" 2027-10-15 "$kunci" 2 '["-hr"]'
    tolak_laporan 'id app berakhir baris baru' 'bukan larik id app' "$s" 2027-10-15 "$kunci" 2 '["human-resources\n"]'
    tolak_laporan 'valid_until berjam' 'bukan tanggal berbentuk YYYY-MM-DD' "$s" 2027-10-15T00:00:00Z "$kunci"
    tolak_laporan 'valid_until berakhir baris baru' 'bukan tanggal berbentuk YYYY-MM-DD' "$s" '2027-10-15\n' "$kunci"
    tolak_laporan 'valid_until tidak ada di kalender' 'bukan tanggal kalender: 2027-02-30' "$s" 2027-02-30 "$kunci"

    # apps kosong sah: tenant yang hanya memakai Core.
    lisensi="$(buat_lisensi "$KERJA/lisensi-hanya-core.json" "$s" 2027-10-01 "$kunci" 2 '[]')"
    titip "$lisensi"
    putaran 'apps kosong'
    dijawab 'apps kosong' true
    memuat 'apps kosong: terpasang' "$(cat "$log")" 'Lisensi dari admin.erp terpasang, berlaku sampai 2027-10-01.'
    pastikan 'apps kosong: license.json persis' cmp -s "$folder/license.json" "$KERJA/lisensi-hanya-core.json"

    lisensi="$(buat_lisensi "$KERJA/lisensi-perpanjangan.json" "$s" 2027-10-15 "$kunci")"
    titip "$lisensi"
    putaran 'perpanjangan'
    dijawab 'perpanjangan' true
    memuat 'perpanjangan: terpasang' "$(cat "$log")" 'Lisensi dari admin.erp terpasang, berlaku sampai 2027-10-15.'
    pastikan 'license.json persis byte yang ditandatangani' cmp -s "$folder/license.json" "$KERJA/lisensi-perpanjangan.json"
    sama 'license.json.sig persis signature dari jawaban laporan' "$(cat "$folder/license.json.sig")" "$(jq -r .signature <<< "$lisensi")"
    sama 'license.json.sig satu baris tanpa akhir baris' "$(wc -l < "$folder/license.json.sig")" 0
    base64 -d "$folder/license.json.sig" > "$KERJA/lisensi-perpanjangan.sig"
    pastikan 'tanda tangan terpasang sah' \
        openssl dgst -sha256 -verify "$KERJA/kunci/lisensi.pub" -signature "$KERJA/lisensi-perpanjangan.sig" "$folder/license.json"
    sama 'mode berkas lisensi' "$(stat -c %a "$folder/license.json" "$folder/license.json.sig" | paste -sd' ')" '644 644'
    sama 'tidak ada berkas sementara di folder lisensi' "$(find "$folder" -name '.*' | wc -l)" 0

    # Laporan berikutnya menyebut tanggal baru — itu yang membuat admin.erp berhenti menyertakan lisensi.
    putaran 'sesudah perpanjangan'
    dijawab 'sesudah perpanjangan' false
    sama 'laporan sesudahnya menyebut tanggal lisensi baru' "$(laporan_terakhir | jq -r .license_expires_at)" 2027-10-15
}

uji_09c_lisensi_diwajibkan() {
    local berkas_env="$COREERP_HOME/.env" baris

    # wajib KETERANGAN DIHARAPKAN — license_required di laporan yang diterima admin.erp tiruan sesudah
    # satu putaran. `jq -c`, bukan `-r`: null dan false harus dapat dibedakan.
    wajib() {
        agen run --now
        sama "$1" "$(laporan_terakhir | jq -c .license_required)" "$2"
    }

    rm -rf "$berkas_env"
    wajib '.env tidak ada: null' null

    mkdir "$berkas_env"
    wajib '.env yang tidak dapat dibaca sebagai berkas: null' null
    rmdir "$berkas_env"

    # .env hasil pasang.sh dari env.template.
    cp "$TEMPLAT_ENV" "$berkas_env"
    wajib '.env dari env.template: true' true

    printf 'APP_ENV=production\n' > "$berkas_env"
    wajib 'kunci tidak disebut: false' false

    printf 'COREERP_LICENSE_REQUIRED=false\n' > "$berkas_env"
    wajib 'false tertulis: false' false

    printf 'APP_ENV=production\nCOREERP_LICENSE_REQUIRED=true\n' > "$berkas_env"
    wajib 'true persis: true' true

    # Compose memakai baris terakhir. `=false` yang ditambahkan di bawah `=true` mematikan kuncinya, dalam
    # bentuk apa pun barisnya ditulis.
    printf 'COREERP_LICENSE_REQUIRED=true\nAPP_ENV=production\nCOREERP_LICENSE_REQUIRED=false\n' > "$berkas_env"
    wajib 'false di bawah true: false' false
    printf 'COREERP_LICENSE_REQUIRED=true\n  export COREERP_LICENSE_REQUIRED = false\n' > "$berkas_env"
    wajib 'export false di bawah true: false' false
    printf 'COREERP_LICENSE_REQUIRED=false\nCOREERP_LICENSE_REQUIRED=true\n' > "$berkas_env"
    wajib 'true di bawah false: true' true

    for baris in 'COREERP_LICENSE_REQUIRED="true"' 'COREERP_LICENSE_REQUIRED=true # wajib' 'COREERP_LICENSE_REQUIRED=TRUE' \
        'COREERP_LICENSE_REQUIRED=1' ' COREERP_LICENSE_REQUIRED=true' $'COREERP_LICENSE_REQUIRED=true\r' \
        '#COREERP_LICENSE_REQUIRED=true' 'COREERP_LICENSE_REQUIRED_LAMA=true'; do
        printf '%s\n' "$baris" > "$berkas_env"
        wajib "bukan bentuk persis ($(printf '%q' "$baris")): false" false
    done

    rm -f "$berkas_env"
}

uji_10_putar_kunci() {
    local s id op lama baru sebelum

    s="$(situs)"
    rm -rf "$KERJA/rumah-kunci-lama"
    cp -a "$COREERP_HOME" "$KERJA/rumah-kunci-lama"
    lama="$(cat "$COREERP_HOME/agent/site-public.pem")"

    id="$(antre "$(jq -cn --arg s "$s" '{site_id: $s, operation: "rotate_key", parameters: {}}')")"
    agen run --now

    op="$(operasi "$id")"
    baru="$(cat "$COREERP_HOME/agent/site-public.pem")"

    # Hasil akhir yang diterima admin.erp tiruan sesudah kuncinya diganti hanya mungkin bertanda tangan
    # kunci baru.
    sama 'rotasi selesai' "$(jq -c '[.status, .langkah[-1].step]' <<< "$op")" '["succeeded","Kunci situs diganti"]'
    harus_gagal 'kunci publik berganti' test "$baru" = "$lama"
    sama 'admin.erp memegang kunci baru' "$(admin_keadaan | jq -r --arg s "$s" '.sites[$s].public_key')" "$baru"
    pastikan 'kunci privat baru cocok dengan kunci publik baru' \
        cmp -s <(openssl pkey -in "$COREERP_HOME/agent/site-key.pem" -pubout) "$COREERP_HOME/agent/site-public.pem"
    sama 'mode kunci privat baru' "$(stat -c %a "$COREERP_HOME/agent/site-key.pem")" 600
    sama 'tidak ada berkas kunci sementara tertinggal' \
        "$(find "$COREERP_HOME/agent" -maxdepth 1 -name 'site-*.baru' -o -maxdepth 1 -name '*.tertunda' | wc -l)" 0

    sebelum="$(jumlah_permintaan)"
    harus_gagal 'agen dengan kunci lama gagal' env COREERP_HOME="$KERJA/rumah-kunci-lama" bash "$AGEN" run --now
    sama 'kunci lama ditolak' "$(status_sejak "$sebelum" /api/agent/v1/report)" 401

    sebelum="$(jumlah_permintaan)"
    agen run --now
    sama 'kunci baru diterima' "$(status_sejak "$sebelum" /api/agent/v1/report)" 200
}

uji_10b_rotasi_jawaban_hilang() {
    local s id sidik_lama sebelum folder="$COREERP_HOME/agent"

    s="$(situs)"
    id="$(antre "$(jq -cn --arg s "$s" '{site_id: $s, operation: "rotate_key", parameters: {}}')")"
    admin_post "/_test/sites/$s/putus-ganti-kunci" '{}' >/dev/null
    sidik_lama="$(sha256sum < "$folder/site-key.pem")"
    sebelum="$(jumlah_permintaan)"

    if agen run --now > "$KERJA/log/rotasi-putus.log" 2>&1; then
        printf 'run keluar nol padahal jawaban ganti kunci tidak pernah sampai\n'
        return 1
    fi

    memuat 'kunci baru disimpan sebagai tertunda' "$(cat "$KERJA/log/rotasi-putus.log")" 'kunci baru disimpan sebagai tertunda'
    sama 'permintaan ganti kunci tidak dijawab' "$(status_sejak "$sebelum" /api/agent/v1/key)" 0

    # Laporan sesudah operasi di putaran yang sama: kunci lama ditolak, kunci tertunda dicoba dan diterima.
    sama 'laporan: sebelum operasi, dengan kunci lama, dengan kunci tertunda' \
        "$(status_sejak "$sebelum" /api/agent/v1/report)" '200,401,200'
    pastikan 'kunci tertunda sudah menjadi kunci situs' test ! -e "$folder/site-key.pem.tertunda"
    harus_gagal 'kunci lama sudah dibuang' test "$(sha256sum < "$folder/site-key.pem")" = "$sidik_lama"
    sama 'mode kunci situs' "$(stat -c %a "$folder/site-key.pem")" 600
    sama 'kunci situs cocok dengan yang dipegang admin.erp' \
        "$(openssl pkey -in "$folder/site-key.pem" -pubout)" \
        "$(admin_keadaan | jq -r --arg s "$s" '.sites[$s].public_key')"
    pastikan 'kunci publik situs diturunkan ulang' \
        cmp -s <(openssl pkey -in "$folder/site-key.pem" -pubout) "$folder/site-public.pem"

    # Operasi yang tidak pernah ditutup dilepas lewat lease-nya, seperti di admin.erp sungguhan.
    admin_post "/_test/operations/$id/expire" '{}' >/dev/null

    sebelum="$(jumlah_permintaan)"
    agen run --now
    sama 'putaran berikutnya langsung diterima' "$(status_sejak "$sebelum" /api/agent/v1/report)" 200
}

# --- Pengujian: kunci putaran, cadangan, operasi asing, tenant ----------------------------------------

uji_12_flock() {
    local id pid sebelum sesudah keluaran

    buat_rilis apotek-uji 0.6.0
    id="$(antre_upgrade apotek-uji 0.6.0)"
    : > "$FAKE_UPDATE_JEJAK"

    # Detak 1 detik supaya perpanjangan lease selama langkah yang diam dapat dilihat dalam pengujian ini.
    env FAKE_UPDATE_JEDA=6 COREERP_AGENT_DETAK_DETIK=1 bash "$AGEN" run --now > "$KERJA/log/putaran-pertama.log" 2>&1 &
    pid=$!

    for _ in $(seq 1 150); do
        ! grep -q '^mulai' "$FAKE_UPDATE_JEJAK" || break
        sleep 0.1
    done
    pastikan 'putaran pertama sedang menjalankan update.sh' grep -q '^mulai' "$FAKE_UPDATE_JEJAK"

    sebelum="$(admin_keadaan | jq -c '[(.reports | length), ([.requests[] | select(.path == "/api/agent/v1/operations/claim")] | length)]')"
    keluaran="$(agen run --now 2>&1)"
    sesudah="$(admin_keadaan | jq -c '[(.reports | length), ([.requests[] | select(.path == "/api/agent/v1/operations/claim")] | length)]')"

    sama 'putaran kedua tidak melapor dan tidak mengambil operasi' "$sesudah" "$sebelum"
    memuat 'putaran kedua menjelaskan dirinya' "$keluaran" 'masih berjalan'
    pastikan 'putaran pertama masih berjalan ketika yang kedua selesai' kill -0 "$pid"

    wait "$pid"
    sama 'operasi putaran pertama selesai' "$(operasi "$id" | jq -r .status)" succeeded

    # Enam detik tanpa keluaran di tengah migrasi: langkah yang sedang berjalan dilaporkan ulang untuk
    # memperpanjang lease, bukan dibiarkan habis.
    pastikan 'langkah yang diam dilaporkan ulang untuk memperpanjang lease' test \
        "$(operasi "$id" | jq '[.langkah[] | select(.step == "Menjalankan migrasi")] | length')" -ge 3
}

uji_13_cadangan() {
    local id op berkas diharapkan

    mkdir -p "$COREERP_HOME/keadaan"
    printf 'ghcr.io/mettadevs/edisi-apotek-uji@sha256:%064d' 0 > "$COREERP_HOME/keadaan/versi-sehat"
    printf 'name: coreerp\n' > "$COREERP_HOME/keadaan/compose-sehat.yaml"
    : > "$COREERP_HOME/.env"
    : > "$FAKE_DOCKER_JSON_LOG"

    id="$(antre "$(jq -cn --arg s "$(situs)" '{site_id: $s, operation: "backup", parameters: {}}')")"
    agen run --now

    op="$(operasi "$id")"
    sama 'cadangan selesai' "$(jq -r .status <<< "$op")" succeeded

    berkas="$(find "$COREERP_FOLDER_CADANGAN" -name 'terjadwal-*.dump')"
    sama 'satu berkas cadangan' "$(printf '%s\n' "$berkas" | grep -c .)" 1
    sama 'isi cadangan dari pg_dump' "$(cat "$berkas")" PGDMP-tiruan
    sama 'cadangan hanya dapat dibaca root' "$(stat -c %a "$berkas")" 600
    sama 'state.json mencatat cadangan' "$(keadaan_agen '.last_backup | [.result, .size_bytes] | map(tostring) | join(" ")')" 'succeeded 12'
    sama 'laporan membawa cadangan terakhir' "$(laporan_terakhir | jq -c '.last_backup | [.result, .size_bytes]')" '["succeeded",12]'

    diharapkan="$(jq -cn --arg env "$COREERP_HOME/.env" --arg c "$COREERP_HOME/keadaan/compose-sehat.yaml" \
        --arg i "$(cat "$COREERP_HOME/keadaan/versi-sehat")" \
        '[$i, ["compose","--project-name","coreerp","--env-file",$env,"-f",$c,"exec","-T","core-db","pg_dump","-U","core_erp","-d","core_erp","-Fc"]]')"
    sama 'pg_dump lewat compose dan image yang terbukti sehat' \
        "$(jq -c 'select(.args | index("pg_dump")) | [.edition_image, .args]' "$FAKE_DOCKER_JSON_LOG")" "$diharapkan"

    id="$(antre "$(jq -cn --arg s "$(situs)" '{site_id: $s, operation: "backup", parameters: {}}')")"
    env FAKE_DOCKER_PGDUMP_GAGAL=1 bash "$AGEN" run --now

    op="$(operasi "$id")"
    sama 'cadangan yang gagal dilaporkan gagal' "$(jq -r .status <<< "$op")" failed
    memuat 'sebabnya dari pg_dump' "$(jq -r .failure_message <<< "$op")" 'koneksi ditolak'
    sama 'tidak ada dump setengah jadi' "$(find "$COREERP_FOLDER_CADANGAN" -name '*.sebagian' | wc -l)" 0
    sama 'state.json mencatat cadangan gagal' "$(keadaan_agen .last_backup.result)" failed
}

uji_14_operasi_asing() {
    local id op

    id="$(antre "$(jq -cn --arg s "$(situs)" \
        '{site_id: $s, operation: "run_shell", parameters: {command: "cat /etc/shadow"}, tanpa_validasi: true}')")"
    : > "$FAKE_DOCKER_LOG"

    agen run --now

    op="$(operasi "$id")"
    sama 'operasi di luar daftar tertutup ditolak' "$(jq -c '[.status, .failure_message]' <<< "$op")" '["failed","operasi tidak dikenal"]'
    sama 'docker tidak dipanggil selain membaca keadaan' "$(grep -cv -e '^compose --project-name coreerp ps' -e '^info' "$FAKE_DOCKER_LOG" || true)" 0
}

uji_15_bootstrap_tenant() {
    local keluaran diharapkan rumah_tanpa_rilis="$KERJA/rumah-tanpa-rilis"

    # Situs terdaftar yang belum pernah memasang rilis: tidak ada compose dan image yang terbukti sehat.
    rm -rf "$rumah_tanpa_rilis"
    cp -a "$COREERP_HOME" "$rumah_tanpa_rilis"
    rm -rf "$rumah_tanpa_rilis/keadaan"

    if env COREERP_HOME="$rumah_tanpa_rilis" bash "$AGEN" bootstrap-tenant --admin-name 'A' --admin-email a@contoh.test \
        > "$KERJA/log/bootstrap-tanpa-rilis.log" 2>&1; then
        printf 'bootstrap-tenant berjalan tanpa rilis terpasang\n'
        return 1
    fi
    memuat 'penolakan tanpa rilis' "$(cat "$KERJA/log/bootstrap-tanpa-rilis.log")" 'Belum ada rilis yang terpasang'

    : > "$FAKE_DOCKER_JSON_LOG"
    keluaran="$(env FAKE_KATA_SANDI=Sementara-XyZ-789 bash "$AGEN" bootstrap-tenant \
        --admin-name 'Dr. Budi Santoso' --admin-email budi@apotek.test 2>&1)"

    memuat 'kata sandi sementara diteruskan ke terminal' "$keluaran" 'Kata sandi sementara: Sementara-XyZ-789'

    diharapkan="$(jq -cn --arg env "$COREERP_HOME/.env" --arg c "$COREERP_HOME/keadaan/compose-sehat.yaml" \
        --arg t "$TENANT_ID" --arg n "$TENANT_NAMA" --arg i "$(cat "$COREERP_HOME/keadaan/versi-sehat")" \
        '[$i, ["compose","--project-name","coreerp","--env-file",$env,"-f",$c,"exec","-T","core-app","php","artisan",
          "tenant:bootstrap-site",("--tenant-id=" + $t),("--name=" + $n),"--admin-name=Dr. Budi Santoso","--admin-email=budi@apotek.test"]]')"
    sama 'argumen yang diteruskan ke tenant:bootstrap-site' \
        "$(jq -c 'select(.args | index("tenant:bootstrap-site")) | [.edition_image, .args]' "$FAKE_DOCKER_JSON_LOG")" "$diharapkan"

    harus_gagal 'kata sandi tidak tertulis di berkas mana pun di bawah COREERP_HOME' \
        grep -rq 'Sementara-XyZ-789' "$COREERP_HOME"

    # Log docker yang lengkap sejak awal putaran uji: seluruh `run` di pengujian sebelumnya tidak pernah
    # memanggilnya, hanya perintah manual di atas.
    sama 'dipanggil tepat sekali, oleh perintah manual' \
        "$(grep -c 'tenant:bootstrap-site' "$KERJA/docker-lengkap.log")" 1
}

# --- Pengujian: update.sh dan pasang.sh ----------------------------------------------------------------

uji_16_update_sh_tanpa_arsip_image() {
    local rumah="$KERJA/rumah-update" folder="$KERJA/berkas-update/rilis" bundle="$KERJA/berkas-update/bundle" image

    mkdir -p "$rumah"
    cp "$KERJA/kunci/rilis.pub" "$rumah/kunci-rilis.pub"
    : > "$rumah/.env"
    mkdir -p "$folder"
    cp "$UPDATE_SH" "$folder/update.sh"
    tulis_berkas_rilis "$folder" apotek-uji 0.7.0

    image="$(jq -r .image "$folder/manifest.json")"
    local digest
    digest="$(jq -r .digest "$folder/manifest.json")"

    # jalankan_update ID_IMAGE_YANG_DIJAWAB_DOCKER FOLDER
    jalankan_update() {
        env COREERP_HOME="$rumah" COREERP_FOLDER_CADANGAN="$rumah/cadangan" COREERP_LEWATI_PERIKSA_CADANGAN=1 \
            FAKE_DOCKER_IMAGE_ADA='postgres:16-alpine' FAKE_DOCKER_IMAGE_ID="$1" bash "$UPDATE_SH" "$2"
    }

    : > "$FAKE_DOCKER_LOG"
    jalankan_update "$digest" "$folder" > "$KERJA/log/update-registry.log" 2>&1 \
        || { cat "$KERJA/log/update-registry.log"; return 1; }

    memuat 'langkah tarik image' "$(cat "$KERJA/log/update-registry.log")" '==> Menarik image dari registry'
    pastikan 'image edisi ditarik lewat digest' grep -qxF "pull --quiet $image" "$FAKE_DOCKER_LOG"
    pastikan 'pendamping yang belum ada ditarik' grep -qxF 'pull --quiet gotenberg/gotenberg:8' "$FAKE_DOCKER_LOG"
    harus_gagal 'pendamping yang sudah ada tidak ditarik' grep -q 'pull --quiet postgres:16-alpine' "$FAKE_DOCKER_LOG"
    harus_gagal 'tidak ada docker load' grep -qx load "$FAKE_DOCKER_LOG"
    sama 'versi sehat dicatat' "$(cat "$rumah/keadaan/versi-sehat")" "$image"

    # Id image yang ditarik tidak cocok dengan manifest: pemeriksaan jalur bundle tetap berlaku.
    : > "$FAKE_DOCKER_LOG"
    if jalankan_update sha256:lain "$folder" > "$KERJA/log/update-digest.log" 2>&1; then
        printf 'update.sh menerima image dengan id yang berbeda dari manifest\n'
        return 1
    fi
    memuat 'penolakan digest' "$(cat "$KERJA/log/update-digest.log")" 'bukan image yang disebut manifest'

    # Arsip image yang diselipkan ke samping berkas rilis, tidak tercantum di SHA256SUMS.
    printf 'bukan arsip image kami' | gzip > "$folder/images.tar.gz"
    : > "$FAKE_DOCKER_LOG"
    if jalankan_update "$digest" "$folder" > "$KERJA/log/update-selundupan.log" 2>&1; then
        printf 'update.sh memuat images.tar.gz yang tidak tercantum di SHA256SUMS\n'
        return 1
    fi
    memuat 'penolakan arsip selundupan' "$(cat "$KERJA/log/update-selundupan.log")" 'images.tar.gz tidak tercantum di SHA256SUMS'
    harus_gagal 'arsip selundupan tidak dimuat' grep -qx load "$FAKE_DOCKER_LOG"
    rm -f "$folder/images.tar.gz"

    # Bundle lengkap tetap memuat dari arsip, tanpa menarik apa pun.
    mkdir -p "$bundle"
    cp "$UPDATE_SH" "$bundle/update.sh"
    tulis_berkas_rilis "$bundle" apotek-uji 0.7.1 "$KERJA/kunci/rilis.key" --dengan-image
    : > "$FAKE_DOCKER_LOG"
    jalankan_update "$(jq -r .digest "$bundle/manifest.json")" "$bundle" \
        > "$KERJA/log/update-bundle.log" 2>&1 || { cat "$KERJA/log/update-bundle.log"; return 1; }
    memuat 'langkah muat image' "$(cat "$KERJA/log/update-bundle.log")" '==> Memuat image dari bundle'
    pastikan 'arsip image dimuat' grep -qx load "$FAKE_DOCKER_LOG"
    harus_gagal 'bundle lengkap tidak menarik apa pun' grep -q '^pull' "$FAKE_DOCKER_LOG"
}

uji_17_pasang() {
    local rumah="$KERJA/rumah-pasang" folder_bin="$KERJA/bin-pasang" folder_systemd="$KERJA/systemd"
    local token keluaran keluaran_ulang nilai kata_sandi sidik_env situs_id nama kurang=()

    token="$(token_baru pasang)"

    # Kunci rilis lewat --release-key: `deploy/agent/kunci-rilis.pub` belum ada di repo.
    pasang() {
        env COREERP_HOME="$rumah" COREERP_SYSTEMD_DIR="$folder_systemd" COREERP_BIN_DIR="$folder_bin" \
            COREERP_FOLDER_CADANGAN="$rumah/cadangan" COREERP_REPO_DIR="$akar" \
            bash "$PASANG" --admin-url "$ADMIN" --token "$token" --release-key "$KERJA/kunci/rilis.pub"
    }

    keluaran="$(pasang 2>&1)" || { printf '%s\n' "$keluaran"; return 1; }

    sama 'mode .env' "$(stat -c %a "$rumah/.env")" 600
    harus_gagal '.env tidak menyisakan isian' grep -q '@@[A-Z_]*@@' "$rumah/.env"

    while IFS= read -r nama; do
        [ "$nama" != EDITION_IMAGE ] || continue
        grep -q "^$nama=" "$rumah/.env" || kurang+=("$nama")
    done < <(variabel_compose)
    sama 'setiap variabel compose ada di .env' "${kurang[*]}" ''

    nilai="$(sed -n 's/^CORE_APP_KEY=//p' "$rumah/.env")"
    pastikan 'APP_KEY berawalan base64:' cocok_pola "$nilai" '^base64:'
    sama 'APP_KEY 32 bita' "$(printf '%s' "${nilai#base64:}" | base64 -d | wc -c)" 32
    pastikan 'kata sandi database 32 huruf dan angka' cocok_pola "$(sed -n 's/^CORE_DB_PASSWORD=//p' "$rumah/.env")" '^[A-Za-z0-9]{32}$'

    kata_sandi="$(sed -n 's/^COREERP_PROVIDER_PASSWORD=//p' "$rumah/.env")"
    pastikan 'kata sandi provider 24 huruf dan angka' cocok_pola "$kata_sandi" '^[A-Za-z0-9]{24}$'
    sama 'kata sandi provider dicetak tepat sekali' "$(grep -c -- "$kata_sandi" <<< "$keluaran")" 1
    sama 'folder lisensi' "$(sed -n 's/^COREERP_LICENSE_DIR=//p' "$rumah/.env")" "$rumah/agent/license"
    sama 'port dan alamat ikat bawaan dari env.template' \
        "$(grep -E '^CORE_APP_(PORT|BIND)=' "$rumah/.env" | sort | paste -sd' ')" 'CORE_APP_BIND=127.0.0.1 CORE_APP_PORT=8000'
    sama 'agent.env menyimpan folder cadangan yang disebut saat memasang' \
        "$(grep -v '^#' "$rumah/agent/agent.env")" "COREERP_FOLDER_CADANGAN=$rumah/cadangan"
    sama 'mode agent.env' "$(stat -c %a "$rumah/agent/agent.env")" 600

    pastikan 'unit service memakai COREERP_HOME' grep -qxF "ExecStart=$rumah/bin/coreerp-agent run" "$folder_systemd/coreerp-agent.service"
    pastikan 'unit timer terpasang' grep -q '^OnUnitActiveSec=60s' "$folder_systemd/coreerp-agent.timer"
    sama 'pembungkus perintah' "$("$folder_bin/coreerp-agent" --version)" 'coreerp-agent 0.1.0'
    pastikan 'kunci rilis dari --release-key' cmp -s "$rumah/kunci-rilis.pub" "$KERJA/kunci/rilis.pub"

    situs_id="$(jq -r .site_id "$rumah/agent/site.json")"
    sama 'situs terdaftar dengan kunci yang dipegang admin.erp' \
        "$(admin_keadaan | jq -r --arg s "$situs_id" '.sites[$s].public_key')" "$(cat "$rumah/agent/site-public.pem")"
    sama 'token pendaftaran terpakai' "$(admin_keadaan | jq -r --arg t "$token" '.tokens[$t].dipakai')" true
    sama 'rilis pertama tidak dipasang skrip pasang' "$(jq -r '.release // ""' "$rumah/agent/state.json")" ''
    memuat 'langkah berikutnya meminta rilis pertama dari admin.erp' "$keluaran" 'minta "Perbarui" ke rilis pertama'
    memuat 'langkah berikutnya menyebut bootstrap-tenant' "$keluaran" 'coreerp-agent bootstrap-tenant'

    # Dijalankan ulang: .env tidak ditimpa, kata sandi tidak dicetak lagi, dan token yang sudah terpakai
    # tidak dikirim lagi — pendaftaran ulang dengan token itu akan ditolak admin.erp.
    sidik_env="$(cat "$rumah/.env" "$rumah/agent/agent.env" "$rumah/agent/site-key.pem" | sha256sum)"
    keluaran_ulang="$(pasang 2>&1)" || { printf '%s\n' "$keluaran_ulang"; return 1; }
    sama '.env, agent.env, dan kunci situs tidak ditimpa' \
        "$(cat "$rumah/.env" "$rumah/agent/agent.env" "$rumah/agent/site-key.pem" | sha256sum)" "$sidik_env"
    harus_gagal 'kata sandi tidak dicetak ulang' grep -q -- "$kata_sandi" <<< "$keluaran_ulang"
    memuat 'pemasangan ulang menjelaskan .env' "$keluaran_ulang" 'sudah ada; tidak ditimpa'
    memuat 'pemasangan ulang melewati pendaftaran' "$keluaran_ulang" 'pendaftaran dilewati'

    harus_gagal 'tanpa --token ditolak' env COREERP_HOME="$KERJA/rumah-tanpa-token" COREERP_REPO_DIR="$akar" \
        bash "$PASANG" --admin-url "$ADMIN"
}

# dengarkan PORT — soket TCP sungguhan yang mendengarkan PORT di semua alamat IPv4, sampai subshell
# pengujian yang memanggilnya selesai. pasang.sh membacanya dari /proc seperti di server klien.
dengarkan() {
    local siap="$KERJA/dengar-$1.siap"

    rm -f "$siap"
    python3 -c '
import socket, sys, time
s = socket.socket()
s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
s.bind(("0.0.0.0", int(sys.argv[1])))
s.listen()
open(sys.argv[2], "w").close()
time.sleep(600)
' "$1" "$siap" >/dev/null 2>&1 &
    PENDENGAR+=("$!")
    # shellcheck disable=SC2064 # daftar PID memang dibekukan saat trap dipasang
    trap "kill ${PENDENGAR[*]} 2>/dev/null || true" EXIT

    for _ in $(seq 1 100); do
        [ ! -f "$siap" ] || return 0
        sleep 0.1
    done

    printf 'soket uji di port %s tidak menyala\n' "$1"
    return 1
}

PENDENGAR=()

uji_18_pasang_port_dan_setelan() {
    local rumah="$KERJA/rumah-port" folder_bin="$KERJA/bin-port" folder_systemd="$KERJA/systemd-port"
    local cadangan="$KERJA/cadangan-port" token keluaran sidik diharapkan

    token="$(token_baru port)"

    # pasang_port PROYEK [pilihan pasang.sh...] — keluaran di $KERJA/log/pasang-port.log
    pasang_port() {
        local proyek="$1"
        shift
        env COREERP_HOME="$rumah" COREERP_SYSTEMD_DIR="$folder_systemd" COREERP_BIN_DIR="$folder_bin" \
            COREERP_PROYEK="$proyek" COREERP_FOLDER_CADANGAN="$cadangan" COREERP_REPO_DIR="$akar" \
            bash "$PASANG" --admin-url "$ADMIN" --token "$token" --release-key "$KERJA/kunci/rilis.pub" "$@" \
            > "$KERJA/log/pasang-port.log" 2>&1
    }

    # Port bawaan dari env.template sudah didengar layanan lain: ditolak sebelum satu berkas pun ditulis.
    dengarkan 8000
    harus_gagal 'pemasangan pertama di port bawaan yang terpakai ditolak' pasang_port coreerp-situs
    memuat 'penolakan menyebut port bawaan' "$(cat "$KERJA/log/pasang-port.log")" 'Port 8000 sudah didengar'
    memuat 'penolakan menyebut cara memilih port lain' "$(cat "$KERJA/log/pasang-port.log")" '--app-port PORT'
    pastikan 'tidak ada yang ditulis ke COREERP_HOME' test ! -e "$rumah"
    pastikan 'unit systemd tidak ditulis' test ! -e "$folder_systemd"

    # Port yang disebut lewat --app-port juga diperiksa.
    dengarkan 18080
    harus_gagal 'port pilihan yang terpakai ditolak' pasang_port coreerp-situs --app-port 18080
    memuat 'penolakan menyebut port pilihan' "$(cat "$KERJA/log/pasang-port.log")" 'Port 18080 sudah didengar'
    pastikan 'tidak ada yang ditulis ke COREERP_HOME' test ! -e "$rumah"

    harus_gagal 'port di luar rentang ditolak' pasang_port coreerp-situs --app-port 70000
    memuat 'penolakan port tidak sah' "$(cat "$KERJA/log/pasang-port.log")" '--app-port tidak sah: 70000'
    harus_gagal 'alamat ikat yang bukan IPv4 ditolak' pasang_port coreerp-situs --app-port 18081 --app-bind 10.0.0
    memuat 'penolakan alamat tidak sah' "$(cat "$KERJA/log/pasang-port.log")" '--app-bind tidak sah: 10.0.0'
    harus_gagal 'oktet di atas 255 ditolak' pasang_port coreerp-situs --app-port 18081 --app-bind 10.0.0.256
    pastikan 'tidak ada yang ditulis ke COREERP_HOME' test ! -e "$rumah"

    # Nilai yang akan ditolak agen saat membaca agent.env tidak ditulis ke sana.
    harus_gagal 'nilai berspasi untuk agent.env ditolak' pasang_port 'coreerp situs' --app-port 18081
    memuat 'penolakan menyebut agent.env' "$(cat "$KERJA/log/pasang-port.log")" 'COREERP_PROYEK tidak dapat ditulis ke agent.env'
    pastikan 'tidak ada yang ditulis ke COREERP_HOME' test ! -e "$rumah"

    # Port bebas dan alamat ikat pilihan: keduanya sampai ke .env, proyek dan folder cadangan ke agent.env.
    pasang_port coreerp-situs --app-port 18081 --app-bind 172.17.0.1 || { cat "$KERJA/log/pasang-port.log"; return 1; }
    keluaran="$(cat "$KERJA/log/pasang-port.log")"

    sama '.env memakai port dan alamat ikat pilihan' \
        "$(grep -E '^CORE_APP_(PORT|BIND)=' "$rumah/.env" | sort | paste -sd' ')" 'CORE_APP_BIND=172.17.0.1 CORE_APP_PORT=18081'
    memuat 'alamat aplikasi dicetak untuk reverse proxy' "$keluaran" 'aplikasi didengar di 172.17.0.1:18081'
    sama 'agent.env menyimpan proyek dan folder cadangan' "$(grep -v '^#' "$rumah/agent/agent.env")" \
        "COREERP_PROYEK=coreerp-situs"$'\n'"COREERP_FOLDER_CADANGAN=$cadangan"
    sama 'mode agent.env' "$(stat -c %a "$rumah/agent/agent.env")" 600

    # Dijalankan ulang sementara port pilihannya didengar — di server sungguhan oleh CoreERP sendiri.
    dengarkan 18081
    sidik="$(cat "$rumah/.env" "$rumah/agent/agent.env" | sha256sum)"
    pasang_port coreerp-situs || { cat "$KERJA/log/pasang-port.log"; return 1; }
    sama 'pemasangan ulang tidak memeriksa port lagi dan tidak menimpa apa pun' \
        "$(cat "$rumah/.env" "$rumah/agent/agent.env" | sha256sum)" "$sidik"

    # Proyek lain disebut sesudah agent.env berdiri: ditolak, bukan diam-diam berbeda dari timer.
    harus_gagal 'proyek yang berbeda dari agent.env ditolak' pasang_port coreerp-lain
    memuat 'penolakan menyebut kedua nilai' "$(cat "$KERJA/log/pasang-port.log")" 'menyebut COREERP_PROYEK yang berbeda'
    memuat 'penolakan menyebut nilai yang diminta' "$(cat "$KERJA/log/pasang-port.log")" 'diminta  : coreerp-lain'
    sama 'agent.env tidak berubah' "$(cat "$rumah/.env" "$rumah/agent/agent.env" | sha256sum)" "$sidik"

    # Perintah manual lewat pembungkus di PATH — tanpa COREERP_PROYEK di lingkungannya — memakai proyek
    # yang disebut saat memasang.
    mkdir -p "$rumah/keadaan"
    printf 'ghcr.io/mettadevs/edisi-apotek-uji@sha256:%064d' 8 > "$rumah/keadaan/versi-sehat"
    printf 'name: coreerp\n' > "$rumah/keadaan/compose-sehat.yaml"
    : > "$FAKE_DOCKER_JSON_LOG"

    env -u COREERP_HOME -u COREERP_FOLDER_CADANGAN "$folder_bin/coreerp-agent" bootstrap-tenant \
        --admin-name 'Admin Port' --admin-email admin@port.test >/dev/null

    diharapkan="$(jq -cn --arg env "$rumah/.env" '["compose","--project-name","coreerp-situs","--env-file",$env]')"
    sama 'bootstrap-tenant lewat pembungkus memakai proyek dari agent.env' \
        "$(jq -c 'select(.args | index("tenant:bootstrap-site")) | .args[0:5]' "$FAKE_DOCKER_JSON_LOG")" "$diharapkan"
}

uji_19_agent_env() {
    local rumah="$KERJA/rumah-setelan" rumah_upgrade="$KERJA/rumah-setelan-upgrade" rumah_tolak="$KERJA/rumah-setelan-tolak"
    local kunci_kode kunci dikecualikan id

    rm -rf "$rumah" "$rumah_upgrade" "$rumah_tolak"
    mkdir -p "$rumah_tolak/agent"

    # tolak KETERANGAN BARIS POTONGAN_PESAN [BERKAS_YANG_TIDAK_BOLEH_LAHIR]
    tolak() {
        local status=0

        printf '%s\n' "$2" > "$rumah_tolak/agent/agent.env"
        env COREERP_HOME="$rumah_tolak" bash "$AGEN" --version > "$KERJA/log/setelan-tolak.log" 2>&1 || status=$?

        # Diperiksa sebelum penolakannya: pembacaan yang menjalankan barisnya lalu menolak tetap cacat.
        [ -z "${4:-}" ] || pastikan "$1: barisnya tidak dijalankan" test ! -e "$4"

        if [ "$status" -eq 0 ]; then
            printf 'agent.env diterima padahal harus ditolak: %s\n' "$1"
            return 1
        fi

        memuat "$1" "$(cat "$KERJA/log/setelan-tolak.log")" "$3"
    }

    # Berkasnya tidak pernah dijalankan sebagai skrip.
    tolak 'substitusi perintah di nilai ditolak' "COREERP_PROYEK=\$(touch $KERJA/tersentuh-nilai)" \
        'nilai COREERP_PROYEK memuat huruf yang tidak diterima' "$KERJA/tersentuh-nilai"
    tolak 'baris perintah ditolak' "touch $KERJA/tersentuh-baris" 'bukan KUNCI=nilai' "$KERJA/tersentuh-baris"
    tolak 'nilai berkutip ditolak' 'COREERP_PROYEK="coreerp situs"' 'nilai COREERP_PROYEK memuat huruf yang tidak diterima'
    tolak 'bentuk export ditolak' 'export COREERP_PROYEK=coreerp-situs' 'bukan KUNCI=nilai'
    tolak 'variabel di luar COREERP_* ditolak' 'PATH=/tmp' 'PATH bukan setelan yang dibaca agen'

    # Nilai baris yang ditolak tidak dicetak: keluaran agen masuk journald.
    tolak 'rahasia yang keliru ditaruh ditolak' 'CORE_DB_PASSWORD=RahasiaJanganTercetak' 'CORE_DB_PASSWORD bukan setelan'
    harus_gagal 'nilainya tidak tercetak' grep -q RahasiaJanganTercetak "$KERJA/log/setelan-tolak.log"

    # Daftar yang diterima diturunkan dari kode: setiap COREERP_* yang dibaca agen atau update.sh diterima,
    # kecuali yang sengaja dikecualikan — dan yang dikecualikan memang masih dibaca kode.
    dikecualikan=(COREERP_HOME COREERP_UPDATE_SCRIPT COREERP_LEWATI_PERIKSA_CADANGAN)
    kunci_kode="$(grep -ohE '\$\{COREERP_[A-Z0-9_]+' "$AGEN" "$UPDATE_SH" | cut -c3- | sort -u)"

    for kunci in "${dikecualikan[@]}"; do
        pastikan "$kunci masih dibaca kode" grep -qxF "$kunci" <<< "$kunci_kode"
        tolak "$kunci ditolak" "$kunci=/tmp/lain" "$kunci bukan setelan yang dibaca agen"
    done

    grep -vxF "${dikecualikan[@]/#/-e}" <<< "$kunci_kode" | sed 's/$/=nilai-uji/' > "$rumah_tolak/agent/agent.env"
    pastikan 'setiap setelan lain yang dibaca kode diterima' env COREERP_HOME="$rumah_tolak" bash "$AGEN" --version

    cp -a "$COREERP_HOME" "$rumah"

    proyek_bootstrap() {
        : > "$FAKE_DOCKER_JSON_LOG"
        "$@" bootstrap-tenant --admin-name 'Admin Setelan' --admin-email admin@setelan.test >/dev/null
        jq -r 'select(.args | index("tenant:bootstrap-site")) | .args[2]' "$FAKE_DOCKER_JSON_LOG"
    }

    # Komentar, baris kosong, dan kunci yang disebut dua kali: yang terakhir berlaku, sama dengan systemd.
    printf '%s\n' '# setelan uji' '' '  ; komentar systemd' 'COREERP_PROYEK=coreerp-lama' 'COREERP_PROYEK=coreerp-situs' \
        > "$rumah/agent/agent.env"

    sama 'perintah manual memakai COREERP_PROYEK dari agent.env' \
        "$(proyek_bootstrap env COREERP_HOME="$rumah" bash "$AGEN")" coreerp-situs
    sama 'lingkungan perintah menang atas agent.env' \
        "$(proyek_bootstrap env COREERP_HOME="$rumah" COREERP_PROYEK=coreerp-terminal bash "$AGEN")" coreerp-terminal

    # Setelan dari agent.env diekspor sampai ke update.sh yang dijalankan `run` dari terminal, tanpa
    # EnvironmentFile milik systemd.
    cp -a "$COREERP_HOME" "$rumah_upgrade"
    printf 'COREERP_PROYEK=coreerp-situs\nCOREERP_FOLDER_CADANGAN=%s\n' "$KERJA/cadangan-disk-kedua" \
        > "$rumah_upgrade/agent/agent.env"
    buat_rilis apotek-uji 1.0.0
    id="$(antre_upgrade apotek-uji 1.0.0)"
    rm -f "$KERJA/lingkungan-update"

    env -u COREERP_FOLDER_CADANGAN COREERP_HOME="$rumah_upgrade" FAKE_UPDATE_LINGKUNGAN="$KERJA/lingkungan-update" \
        bash "$AGEN" run --now > "$KERJA/log/setelan-upgrade.log" 2>&1 \
        || { cat "$KERJA/log/setelan-upgrade.log"; return 1; }
    sama 'pembaruan dari terminal selesai' "$(operasi "$id" | jq -r .status)" succeeded
    sama 'update.sh menerima proyek dan folder cadangan dari agent.env' "$(cat "$KERJA/lingkungan-update")" \
        "COREERP_PROYEK=coreerp-situs"$'\n'"COREERP_FOLDER_CADANGAN=$KERJA/cadangan-disk-kedua"
}

# --- Jalankan ------------------------------------------------------------------------------------------

printf 'Agen yang diuji: %s\n\n' "$AGEN"

uji 'sintaks bash dan Python, tanpa CR' uji_sintaks
uji 'shellcheck' uji_shellcheck
uji 'env.template dan compose.edition.yaml' uji_templat_dan_compose
uji '01 enroll menulis site.json dan kunci; admin.erp menyimpan kunci publik' uji_01_enroll
uji '02 laporan diterima dan hanya memuat kunci kontrak' uji_02_laporan
uji '03 tanda tangan dengan kunci lain ditolak 401' uji_03_kunci_lain
uji '04 upgrade: setiap langkah dilaporkan, state.json mencatat rilis' uji_04_upgrade
uji '05 upgrade dengan tanda tangan atau checksum salah ditolak sebelum update.sh' uji_05_rilis_palsu
uji '06 upgrade ke rilis yang sama, lebih rendah, atau edisi lain ditolak' uji_06_tolak_mundur
uji '07 update.sh gagal: failed dengan failure_message' uji_07_update_gagal
uji '08 409 menghentikan laporan langkah tanpa membunuh update.sh' uji_08_409
uji '09 install_license: tanda tangan salah, situs lain, dan versi 1 ditolak; versi 2 yang sah terpasang' uji_09_lisensi_operasi
uji '09b lisensi di jawaban laporan: dipasang lewat pemeriksaan yang sama; yang ditolak tidak mengubah apa pun' uji_09b_lisensi_laporan
uji '09c license_required dari .env: true hanya bentuk persis di baris terakhir, null bila tidak terbaca' uji_09c_lisensi_diwajibkan
uji '10 rotate_key: kunci lama ditolak, kunci baru diterima' uji_10_putar_kunci
uji '10b rotate_key yang jawabannya hilang dipulihkan dengan kunci tertunda' uji_10b_rotasi_jawaban_hilang
uji '12 dua run bersamaan: yang kedua keluar tanpa bekerja; lease diperpanjang' uji_12_flock
uji '13 backup: pg_dump lewat compose sehat, gagal dilaporkan gagal' uji_13_cadangan
uji '14 operasi di luar daftar tertutup ditolak' uji_14_operasi_asing
uji '15 bootstrap-tenant: argumen diteruskan, kata sandi tidak disimpan' uji_15_bootstrap_tenant
uji '16 update.sh tanpa images.tar.gz menarik image dan tetap memeriksa digest' uji_16_update_sh_tanpa_arsip_image
uji '17 pasang.sh: .env, unit, pendaftaran ke admin.erp, pemasangan ulang' uji_17_pasang
uji '18 pasang.sh: port terpakai ditolak di pemasangan pertama; port, alamat ikat, dan agent.env ditulis' uji_18_pasang_port_dan_setelan
uji '19 agent.env dibaca agen sendiri: isi di luar daftar ditolak, lingkungan menang, diteruskan ke update.sh' uji_19_agent_env

printf '\n%d lulus, %d gagal, %d dilewati\n' "$lulus" "$gagal_uji" "$dilewati"

if [ "$gagal_uji" -gt 0 ]; then
    printf 'Yang gagal:\n'
    printf '  - %s\n' "${nama_gagal[@]}"
    exit 1
fi
