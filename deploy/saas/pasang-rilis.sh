#!/usr/bin/env bash
#
# Memasang satu rilis ke SaaS dev di server pertama: image core dan konsol ditarik dari Harbor lewat digest yang
# dicatat perakit, lalu stack dinyalakan bertahap. Dijalankan user `deploy` dari /opt/coreerp yang sudah
# di-checkout ke commit rilis itu — alur GitHub `deploy-dev.yml` yang melakukannya:
#
#   git -C /opt/coreerp checkout --detach <commit rilis>
#   bash /opt/coreerp/deploy/saas/pasang-rilis.sh 0.3.0
#
# ## Kenapa dari rilis, bukan dari main
#
# Sejak 15 September 2026 tidak ada yang dibangun saat men-deploy. SaaS dev menjalankan byte yang persis sama
# dengan yang nanti dipasang agen di server klien: yang lolos di SaaS dev adalah yang dikirim. Dulu setiap
# merge ke `main` membangun image sendiri di server ini dari Dockerfile SaaS, sehingga yang diuji tim bukan
# yang diterima klien. Alurnya di docs/dev/29-alur-rilis-server-klien.md.
#
# ## Yang dijaga
#
# - Commit /opt/coreerp harus sama dengan commit rilis: compose dan migrasi datang dari commit yang sama dengan
#   image-nya.
# - Image ditarik lewat digest dengan robot pull-only milik SaaS dev, ke DOCKER_CONFIG sementara, dan RepoDigests
#   dicocokkan sebelum apa pun dinyalakan.
# - Penyalaan bertahap dengan `compose wait`, bukan `up --wait` sekaligus: container sekali-jalan memulangkan 1
#   pada `up --wait` walaupun berhasil, dan migrasi yang gagal pun memulangkan 1 — keduanya tidak terbedakan.

set -euo pipefail
shopt -s inherit_errexit

FOLDER_RILIS='/var/lib/coreerp-perakit/rilis'
BERKAS_ENV='/etc/coreerp/saas.env'
BERKAS_ROBOT='/etc/coreerp/saas-registry.env'
RUMAH='/opt/coreerp'

gagal() {
    printf '\nGAGAL: %s\n' "$1" >&2
    shift
    [ "$#" -eq 0 ] || printf '%s\n' "$@" >&2
    exit 1
}

langkah() {
    printf '\n==> %s\n' "$*"
}

rilis="${1:-}"
[[ $rilis =~ ^[0-9]+(\.[0-9]+){1,3}$ ]] || gagal "Nomor rilis tidak sah: '$rilis'" 'Pemakaian: bash deploy/saas/pasang-rilis.sh 0.3.0'
for perintah in docker jq git; do
    command -v "$perintah" >/dev/null || gagal "Perintah $perintah tidak ada."
done

catatan="$FOLDER_RILIS/$rilis/saas.json"
[ -r "$catatan" ] || gagal "Rilis $rilis belum dirakit di server ini ($catatan tidak ada)." \
    'Rakit dulu lewat alur GitHub "rilis", atau sudo /usr/local/sbin/coreerp-rilis.'

nilai() {
    jq -r --arg k "$1" '.[$k] // "" | tostring' "$catatan"
}
commit="$(nilai commit)"
registry="$(nilai registry)"
image_core="$(nilai core)"
image_konsol="$(nilai konsol)"
[[ $commit =~ ^[0-9a-f]{40}$ ]] || gagal "saas.json rilis $rilis tidak menyebut commit yang sah."
[[ $registry =~ ^[a-z0-9.-]+(:[0-9]+)?$ ]] || gagal "saas.json rilis $rilis tidak menyebut host registry yang sah."
for satu in "$image_core" "$image_konsol"; do
    [[ $satu =~ ^[a-z0-9]+(/[a-z0-9._-]+)+@sha256:[a-f0-9]{64}$ ]] || gagal "saas.json rilis $rilis menyebut image yang tidak sah: $satu"
done

langkah "Rilis $rilis"
kepala="$(git -C "$RUMAH" rev-parse HEAD)"
[ "$kepala" = "$commit" ] || gagal "$RUMAH ada di commit $kepala, bukan commit rilis $commit." \
    "Checkout commit rilis dulu: git -C $RUMAH checkout --detach $commit"
printf '    commit  %s — %s\n' "${commit:0:12}" "$(git -C "$RUMAH" log -1 --pretty=%s)"
printf '    core    %s\n    konsol  %s\n' "$image_core" "$image_konsol"

langkah 'Menarik image dari registry'
[ -r "$BERKAS_ROBOT" ] || gagal "$BERKAS_ROBOT tidak terbaca." \
    'Berkas itu dibuat deploy/registry/atur-harbor.sh (robot pull-only SaaS dev), root:coreerp 0640.'
pengguna="$(sed -n "s/^REGISTRY_USERNAME='\(.*\)'\$/\1/p" "$BERKAS_ROBOT" | tail -n 1)"
[ -n "$pengguna" ] || gagal "$BERKAS_ROBOT tidak memuat REGISTRY_USERNAME."

DOCKER_CONFIG="$(mktemp -d)"
export DOCKER_CONFIG
trap 'docker logout "$registry" >/dev/null 2>&1 || true; rm -rf "$DOCKER_CONFIG"' EXIT
# Kata sandi dibaca sed langsung ke stdin docker login, tidak pernah menjadi variabel atau argumen.
sed -n "s/^REGISTRY_PASSWORD='\(.*\)'\$/\1/p" "$BERKAS_ROBOT" | tail -n 1 | tr -d '\n' \
    | docker login "$registry" --username "$pengguna" --password-stdin >/dev/null 2>&1 \
    || gagal "Robot SaaS dev tidak dapat login ke $registry."

for satu in "$image_core" "$image_konsol"; do
    rujukan="$registry/$satu"
    docker pull --quiet "$rujukan" >/dev/null || gagal "Menarik $rujukan gagal."
    docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$rujukan" | grep -qxF -- "$rujukan" \
        || gagal "Image yang ditarik bukan $rujukan (RepoDigests tidak cocok)."
    printf '    %s\n' "$rujukan"
done
docker logout "$registry" >/dev/null 2>&1 || true

# Nilai di saas.env dikalahkan lingkungan proses: compose mendahulukan variabel shell atas --env-file. Image
# dirujuk lewat digest yang baru ditarik, jadi compose tidak pernah menariknya lagi.
export COREERP_IMAGE="$registry/$image_core"
export CONSOLE_IMAGE="$registry/$image_konsol"

compose() {
    docker compose --env-file "$BERKAS_ENV" \
        -f "$RUMAH/deploy/saas/compose.yaml" -f "$RUMAH/deploy/saas/compose.dokploy.yaml" "$@"
}

langkah 'Data'
compose --profile data up -d
compose --profile data wait core-minio-init

langkah 'Migrasi'
# `--force-recreate` supaya ia benar-benar berjalan setiap pemasangan: rilis yang image-nya sama dengan yang
# berjalan tetap harus memastikan setiap database lingkungan ada di skema yang berlaku.
compose --profile migrate up -d --force-recreate core-migrate
compose --profile migrate wait core-migrate

langkah 'Layanan'
compose --profile app --profile console --profile scheduler --profile worker \
    up -d --wait --wait-timeout 600

langkah 'Pemeriksaan'
for percobaan in $(seq 1 20); do
    if docker exec coreerp-saas-core-console-1 curl -sf -o /dev/null --max-time 10 http://core-app/up; then
        printf '    core menjawab /up (percobaan %s)\n' "$percobaan"
        break
    fi
    if [ "$percobaan" = 20 ]; then
        docker logs --tail 40 coreerp-saas-core-app-1 >&2 || true
        gagal 'core tidak pernah menjawab /up sesudah 20 percobaan.'
    fi
    sleep 3
done

keluar_galat="$(compose ps -a --format '{{.Service}} {{.State}} {{.ExitCode}}' | awk '$2 == "exited" && $3 != "0" { print $1 }')"
[ -z "$keluar_galat" ] || gagal "Layanan yang keluar dengan galat: $keluar_galat"
printf '    tidak ada layanan yang keluar dengan galat\n'

docker image prune -f --filter 'until=168h' >/dev/null || true
printf '\nSaaS dev menjalankan rilis %s (commit %s).\n' "$rilis" "${commit:0:12}"
