#!/usr/bin/env bash
#
# Merakit satu rilis CoreERP untuk server klien: membangun image, mengujinya, mendorongnya ke Harbor
# bersama image pendamping, lalu menulis dan menandatangani manifest rilis v2. Dijalankan sebagai root di
# server pertama:
#
#   sudo bash deploy/perakit/rakit.sh --rilis 0.2.0 [--ref origin/main]
#
# Hasilnya di /var/lib/coreerp-perakit/rilis/<rilis>/: manifest.json, compose.yaml, update.sh, SHA256SUMS,
# dan SHA256SUMS.sig — bentuk berkas rilis yang sama dengan v1, sehingga pemeriksaan tanda tangan di
# admin.erp dan agen tidak berubah. Mendaftarkannya ke admin.erp adalah langkah terpisah (CP-04).
#
# Urutannya mengikuti PRD registry Harbor:
#
#   1. sumber diambil pada commit yang disebut, di salinan yang dibersihkan dari sisa putaran sebelumnya;
#   2. image dibangun dengan cache Docker mesin ini dan diuji uji-image.sh;
#   3. image dan pendamping didorong lewat robot perakit; pendamping disalin lewat digest, linux/amd64;
#   4. manifest ditulis SESUDAH push, karena digest manifest baru diketahui setelah registry menerimanya;
#   5. manifest ditandatangani kunci rilis dan tanda tangannya diperiksa dengan kunci publik yang dipasang.
#
# Tiga hal yang sengaja:
#
# - **Nomor rilis diberikan operator**, bukan dibaca dari editions/*.yaml: satu image dipakai semua klien,
#   sedangkan berkas edisi menyimpan nomor per pelanggan. Tag bernomor rilis immutable di Harbor, jadi nomor
#   yang sudah dipakai ditolak di langkah pertama, bukan setelah build sepuluh menit.
# - **Cache build wajib.** Tanpa cache, lapisan basis dianggap baru setiap rilis dan setiap rilis menambah
#   ±112 MB di Harbor alih-alih ±11 MB lapisan kode; immutability membuatnya tidak pernah terhapus.
# - **Rahasia tidak pernah dicetak.** Kredensial robot hanya ada di DOCKER_CONFIG sementara 0700 yang
#   dihapus saat skrip selesai, termasuk saat gagal.

set -euo pipefail
shopt -s inherit_errexit

HOST_REGISTRY='registry.erp.grenery.xyz'
REPO_IMAGE='coreerp/core'
REPO_PENDAMPING='coreerp/pendamping'
PLATFORM='linux/amd64'
CRANE='gcr.io/go-containerregistry/crane:v0.22.1'
REPO_SUMBER="${PERAKIT_REPO:-https://github.com/MettaDevs/coreERP.git}"
RUMAH='/var/lib/coreerp-perakit'
FOLDER_SUMBER="$RUMAH/sumber"
FOLDER_RILIS="$RUMAH/rilis"
BERKAS_ROBOT='/etc/coreerp/perakit/registry-robot.env'
KUNCI_PRIVAT='/etc/coreerp/perakit/kunci-rilis-privat.pem'
KUNCI_PUBLIK='/etc/coreerp/kunci/rilis-publik.pem'
# Harus sama dengan CRON_GC di deploy/registry/atur-harbor.sh: Sabtu 20.00 UTC.
GC_HARI_UTC=6
GC_JAM_UTC=20

gagal() {
    printf '\nGAGAL: %s\n' "$1" >&2
    shift
    [ "$#" -eq 0 ] || printf '%s\n' "$@" >&2
    exit 1
}

langkah() {
    printf '\n==> %s\n' "$*"
}

rilis=''
ref='origin/main'
while [ "$#" -gt 0 ]; do
    case "$1" in
        --rilis) [ "$#" -ge 2 ] || gagal '--rilis butuh nilai'; rilis="$2"; shift 2 ;;
        --ref) [ "$#" -ge 2 ] || gagal '--ref butuh nilai'; ref="$2"; shift 2 ;;
        *) gagal "pilihan tidak dikenal: $1" 'Pemakaian: sudo bash deploy/perakit/rakit.sh --rilis 0.2.0 [--ref origin/main]' ;;
    esac
done

langkah 'Memeriksa mesin dan masukan'
[ "$(id -u)" -eq 0 ] || gagal 'Jalankan sebagai root: kunci rilis dan kredensial robot hanya terbaca root.'
for perintah in docker git jq openssl sha256sum; do
    command -v "$perintah" >/dev/null || gagal "Perintah $perintah tidak ada."
done
# Pola yang sama dengan ReleaseRegistry di admin.erp. Tag rilis diawali angka, jadi aturan immutability
# Harbor (`[0-9]*`) selalu melindunginya.
[[ $rilis =~ ^[0-9]+(\.[0-9]+){1,3}$ ]] || gagal "Nomor rilis tidak sah: '$rilis'" 'Bentuknya angka bertitik, misalnya 0.2.0.'
for berkas in "$BERKAS_ROBOT" "$KUNCI_PRIVAT" "$KUNCI_PUBLIK"; do
    [ -f "$berkas" ] || gagal "Berkas $berkas tidak ada."
done
[ "$(stat -c '%a %U' "$KUNCI_PRIVAT")" = '600 root' ] || gagal "$KUNCI_PRIVAT harus 0600 milik root."
[ "$(openssl pkey -in "$KUNCI_PRIVAT" -pubout 2>/dev/null | sha256sum)" = "$(openssl pkey -pubin -in "$KUNCI_PUBLIK" 2>/dev/null | sha256sum)" ] \
    || gagal "Kunci privat rilis tidak berpasangan dengan $KUNCI_PUBLIK." \
        'Tanda tangan yang dibuat akan ditolak admin.erp dan agen.'
[ ! -e "$FOLDER_RILIS/$rilis" ] || gagal "Rilis $rilis sudah pernah dirakit di $FOLDER_RILIS/$rilis."

# GC Harbor tidak menyapu lapisan yang diunggah dalam dua jam terakhir, jadi push saat GC tidak merusak
# apa pun. Push tetap ditolak di sekitar jadwalnya supaya kegagalan GC tidak pernah dapat dituduhkan pada
# push yang kebetulan berjalan bersamaan (PK-04).
hari_utc="$(date -u +%u)"
jam_utc="$(date -u +%H)"
if [ "$((10#$hari_utc % 7))" -eq "$((GC_HARI_UTC % 7))" ] && [ "$((10#$jam_utc))" -ge $((GC_JAM_UTC - 1)) ] && [ "$((10#$jam_utc))" -le $((GC_JAM_UTC + 1)) ]; then
    gagal "GC Harbor terjadwal Sabtu ${GC_JAM_UTC}.00 UTC; perakit tidak push pukul $((GC_JAM_UTC - 1)).00–$((GC_JAM_UTC + 1)).59 UTC hari itu."
fi

kerja="$(mktemp -d)"
chmod 0700 "$kerja"
bereskan() {
    rm -rf "$kerja"
}
trap bereskan EXIT

# Kredensial robot dibaca sebagai data. Nilainya bertanda kutip tunggal karena nama robot memuat `$`.
nilai_robot() {
    sed -n "s/^$1='\(.*\)'\$/\1/p" "$BERKAS_ROBOT" | tail -n 1
}
nama_robot="$(nilai_robot REGISTRY_USERNAME)"
rahasia_robot="$(nilai_robot REGISTRY_PASSWORD)"
[ -n "$nama_robot" ] && [ -n "$rahasia_robot" ] || gagal "$BERKAS_ROBOT tidak memuat kredensial robot."

export DOCKER_CONFIG="$kerja/docker"
mkdir -p "$DOCKER_CONFIG"
printf '%s' "$rahasia_robot" | docker login "$HOST_REGISTRY" -u "$nama_robot" --password-stdin >/dev/null 2>&1 \
    || gagal "Robot perakit tidak dapat login ke $HOST_REGISTRY." 'Putar rahasianya lewat deploy/registry/RUNBOOK.md.'
unset rahasia_robot

# crane PERINTAH... — memakai DOCKER_CONFIG yang sama, jadi kredensial robot tidak pernah menjadi argumen.
# Dijalankan sebagai root: image crane berjalan sebagai pengguna non-root, sedangkan config.json 0600.
crane() {
    docker run --rm -i -u "$(id -u):$(id -g)" -e DOCKER_CONFIG=/cfg -v "$DOCKER_CONFIG:/cfg:ro" "$CRANE" "$@"
}

# Tiga jawaban, bukan dua. Pada putaran pertama skrip ini, crane tidak dapat membaca kredensialnya, dan
# pemeriksaan "ada atau tidak" yang hanya melihat kode keluar menganggap tag belum ada — lalu push jalan
# terus. Hanya "tidak ditemukan" yang berarti tag belum ada — Harbor menjawabnya NOT_FOUND, registry lain
# MANIFEST_UNKNOWN atau NAME_UNKNOWN — dan kegagalan lain menghentikan skrip.
#
# Dipanggil lewat `$(...)`, bukan `< <(...)`: `gagal` di dalam substitusi proses tidak menghentikan skrip,
# dan pemanggil akan membaca jawaban kosong sebagai "tidak ada".
keadaan_tag() {
    local keluaran
    if keluaran="$(crane manifest "$1" 2>&1)"; then
        printf 'ada\n'
    elif printf '%s' "$keluaran" | grep -qE 'NOT_FOUND|MANIFEST_UNKNOWN|NAME_UNKNOWN'; then
        printf 'tidak-ada\n'
    else
        gagal "Tidak dapat memeriksa tag $1 di Harbor:" "$keluaran"
    fi
}
printf '    rilis %s, ref %s\n' "$rilis" "$ref"

langkah 'Sumber'
mkdir -p "$RUMAH"
if [ ! -d "$FOLDER_SUMBER/.git" ]; then
    git clone --quiet "$REPO_SUMBER" "$FOLDER_SUMBER"
fi
git -C "$FOLDER_SUMBER" fetch --quiet --prune origin
commit="$(git -C "$FOLDER_SUMBER" rev-parse --verify "$ref^{commit}")" || gagal "Ref $ref tidak ditemukan di $REPO_SUMBER."
git -C "$FOLDER_SUMBER" checkout --quiet --force --detach "$commit"
# Berkas tak terlacak dari putaran sebelumnya — vendor, node_modules, storage — tidak boleh masuk konteks
# pembangunan. .dockerignore menjaga sebagian, tetapi image dibangun dari pohon yang persis sama dengan commit.
git -C "$FOLDER_SUMBER" clean --quiet -ffdx
printf '    commit %s\n' "$commit"

tujuan="$HOST_REGISTRY/$REPO_IMAGE:$rilis"
image_lokal="coreerp-perakit/core:$rilis"
ada_tag="$(keadaan_tag "$tujuan")"
if [ "$ada_tag" = 'ada' ]; then
    revisi_tag="$(crane config "$tujuan" | jq -r '.config.Labels["org.opencontainers.image.revision"] // ""')"
    [ "$revisi_tag" = "$commit" ] || gagal "Tag $REPO_IMAGE:$rilis sudah ada di Harbor dari commit ${revisi_tag:-tak dikenal}." \
        'Tag rilis immutable; pakai nomor rilis berikutnya.'
fi

if [ "$ada_tag" = 'ada' ]; then
    # Putaran sebelumnya sudah mendorong image dari commit yang sama lalu berhenti sebelum manifest ditulis.
    # Image itu sudah lolos uji-image.sh sebelum didorong; membangunnya ulang hanya menghasilkan digest lain
    # yang tidak dapat didorong ke tag immutable yang sama.
    langkah "Build dan push dilewati: $REPO_IMAGE:$rilis sudah ada dari commit yang sama"
else
    langkah 'Build'
    docker build --quiet --platform "$PLATFORM" \
        -f "$FOLDER_SUMBER/deploy/perakit/Dockerfile" \
        --label "org.opencontainers.image.revision=$commit" \
        --label "org.opencontainers.image.version=$rilis" \
        -t "$image_lokal" "$FOLDER_SUMBER" > "$kerja/build.id" \
        || gagal 'docker build gagal.'
    printf '    %s\n' "$(cat "$kerja/build.id")"

    langkah 'Uji image'
    bash "$FOLDER_SUMBER/deploy/perakit/uji-image.sh" "$image_lokal"

    langkah 'Push image rilis'
    docker tag "$image_lokal" "$tujuan"
    docker push --quiet "$tujuan" >/dev/null || gagal "docker push $tujuan gagal."
    docker rmi "$tujuan" >/dev/null
fi

langkah 'Digest image rilis'
# Digest dibaca dari registry, bukan dari `docker image inspect`: `.Id` adalah digest config pada store
# klasik dan digest manifest pada store containerd, sedangkan manifest v2 mencatat keduanya terpisah.
digest="$(crane digest "$tujuan")"
manifest_image="$(crane manifest "$HOST_REGISTRY/$REPO_IMAGE@$digest")"
config_digest="$(printf '%s' "$manifest_image" | jq -r '.config.digest')"
[[ $digest == sha256:* ]] && [[ $config_digest == sha256:* ]] || gagal 'Digest image rilis tidak terbaca dari Harbor.'
[ "$(printf '%s' "$manifest_image" | sha256sum | cut -d' ' -f1)" = "${digest#sha256:}" ] \
    || gagal 'Manifest yang dibaca tidak cocok dengan digestnya.'
printf '    %s@%s\n' "$REPO_IMAGE" "$digest"

langkah 'Image pendamping'
# Diambil dari baris `image:` harfiah di compose rilis, seperti yang dilakukan build-release-files.sh untuk
# v1. Image aplikasi memakai variabel, jadi tidak ikut terbaca di sini.
compose_rilis="$FOLDER_SUMBER/deploy/compose.edition.yaml"
mapfile -t sumber_pendamping < <(sed -n 's/^[[:space:]]*image:[[:space:]]*\([^$[:space:]][^[:space:]]*\)[[:space:]]*$/\1/p' "$compose_rilis" | tr -d "'\"" | sort -u)
[ "${#sumber_pendamping[@]}" -gt 0 ] || gagal "Tidak ada image pendamping terbaca di $compose_rilis."
pendamping_json='[]'
for sumber in "${sumber_pendamping[@]}"; do
    nama="${sumber%%[:@]*}"
    nama="${nama##*/}"
    [[ $nama =~ ^[a-z0-9][a-z0-9._-]*$ ]] || gagal "Nama pendamping tidak sah dari $sumber."
    tujuan_pendamping="$HOST_REGISTRY/$REPO_PENDAMPING/$nama:$rilis"
    if [ "$(keadaan_tag "$tujuan_pendamping")" = 'ada' ]; then
        # Putaran sebelumnya untuk rilis ini sudah menyalinnya. Tagnya immutable, jadi digest yang sudah ada
        # itulah yang menjadi bagian rilis — walaupun tag sumbernya di Docker Hub sudah bergerak sejak itu.
        digest_pendamping="$(crane digest "$tujuan_pendamping")"
    else
        # Salinan platform linux/amd64 saja, lewat digest yang dikunci saat ini juga. Tag sumber dapat
        # bergerak di Docker Hub; yang dicatat dan ditarik klien adalah digest yang disalin di sini.
        digest_sumber="$(crane digest --platform "$PLATFORM" "$sumber")"
        # Rujukan `repo:tag@digest` tidak diterima semua alat; tag dibuang dan digest yang menentukan isinya.
        repo_sumber="${sumber%@*}"
        case "${repo_sumber##*/}" in *:*) repo_sumber="${repo_sumber%:*}" ;; esac
        crane copy --platform "$PLATFORM" "$repo_sumber@$digest_sumber" "$tujuan_pendamping" >/dev/null 2>&1 \
            || gagal "Menyalin $sumber ke $tujuan_pendamping gagal."
        digest_pendamping="$(crane digest "$tujuan_pendamping")"
        [ "$digest_pendamping" = "$digest_sumber" ] \
            || gagal "Digest $nama di Harbor ($digest_pendamping) tidak sama dengan sumbernya ($digest_sumber)."
    fi
    pendamping_json="$(jq -c --arg nama "$nama" --arg image "$REPO_PENDAMPING/$nama" --arg digest "$digest_pendamping" \
        '. + [{nama: $nama, image: $image, digest: $digest}]' <<< "$pendamping_json")"
    printf '    %s ← %s (%s)\n' "$REPO_PENDAMPING/$nama" "$sumber" "$digest_pendamping"
done

langkah 'Manifest v2 dan tanda tangan'
folder_baru="$kerja/rilis"
mkdir -p "$folder_baru"
# Tanpa host registry dan tanpa edisi: registry dapat pindah tanpa menyentuh rilis yang sudah ditandatangani,
# dan satu image dipakai semua klien.
jq -n --arg rilis "$rilis" --arg commit "$commit" --arg image "$REPO_IMAGE" \
    --arg digest "$digest" --arg config_digest "$config_digest" --argjson pendamping "$pendamping_json" \
    --arg dibangun_pada "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    '{versi: 2, rilis: $rilis, commit: $commit, image: $image, digest: $digest, config_digest: $config_digest,
      pendamping: $pendamping, dibangun_pada: $dibangun_pada}' > "$folder_baru/manifest.json"
cp "$compose_rilis" "$folder_baru/compose.yaml"
cp "$FOLDER_SUMBER/scripts/update.sh" "$folder_baru/update.sh"
(cd "$folder_baru" && sha256sum compose.yaml manifest.json update.sh > SHA256SUMS)
openssl dgst -sha256 -sign "$KUNCI_PRIVAT" -out "$folder_baru/SHA256SUMS.sig" "$folder_baru/SHA256SUMS"
openssl dgst -sha256 -verify "$KUNCI_PUBLIK" -signature "$folder_baru/SHA256SUMS.sig" "$folder_baru/SHA256SUMS" >/dev/null \
    || gagal 'Tanda tangan yang baru dibuat tidak lolos pemeriksaan kunci publik rilis.'

mkdir -p "$FOLDER_RILIS"
chmod 0755 "$RUMAH" "$FOLDER_RILIS"
chmod 0644 "$folder_baru"/*
chmod 0755 "$folder_baru"
mv "$folder_baru" "$FOLDER_RILIS/$rilis"
docker rmi "$image_lokal" >/dev/null 2>&1 || true

printf '\nRilis %s dirakit.\n' "$rilis"
printf '  image     %s/%s@%s\n' "$HOST_REGISTRY" "$REPO_IMAGE" "$digest"
printf '  berkas    %s\n' "$FOLDER_RILIS/$rilis"
