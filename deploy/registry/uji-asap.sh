#!/usr/bin/env bash
#
# Uji asap registry: membuktikan dari luar — lewat Traefik, dengan alat yang bukan docker — bahwa registry
# melayani kontraknya. Dijalankan sebagai root di server pertama sesudah pasang.sh dan atur-harbor.sh:
#
#   sudo bash deploy/registry/uji-asap.sh
#
# Keluar nol hanya bila seluruh syarat ini terpenuhi, dan merah pada syarat pertama yang dilanggar:
#
#   1. project coreerp privat dan punya aturan immutability;
#   2. tanpa kredensial, artifact tidak dapat dibaca;
#   3. robot push dapat push, dan digest yang dicatat sesudah push sama dengan yang ditarik lewat digest;
#   4. robot pull saja dapat pull lewat digest;
#   5. robot pull saja tidak dapat push;
#   6. tag rilis tidak dapat didorong ulang dengan isi lain;
#   7. robot yang dihapus tidak dapat login lagi.
#
# Syarat 2–7 diuji di project sekali pakai `uji-asap-<waktu>` yang menyalin aturan immutability coreerp,
# bukan di coreerp sendiri. Artifact bertag rilis immutable tidak dapat dihapus selama aturannya aktif, dan
# membersihkannya dari coreerp berarti mematikan perlindungan seluruh rilis — walau sebentar. Di project
# sekali pakai, aturannya boleh dimatikan saat bersih-bersih.
#
# Project, robot, dan artifact uji dibuang saat skrip selesai, termasuk saat gagal di tengah. Rahasia robot
# hanya ada di folder sementara 0700 dan tidak pernah dicetak.

set -euo pipefail
shopt -s inherit_errexit

HOST_REGISTRY='registry.erp.grenery.xyz'
PROJECT_KONTRAK='coreerp'
CRANE='gcr.io/go-containerregistry/crane:v0.22.1'
BERKAS_RAHASIA='/etc/coreerp/registry/rahasia.env'

gagal() {
    printf '\nMERAH: %s\n' "$1" >&2
    shift
    [ "$#" -eq 0 ] || printf '%s\n' "$@" >&2
    exit 1
}

lulus() {
    printf '  lulus  %s\n' "$*"
}

[ "$(id -u)" -eq 0 ] || gagal 'Jalankan sebagai root.'
command -v jq >/dev/null || gagal 'Perintah jq tidak ada.'
kata_sandi_admin="$(sed -n 's/^HARBOR_ADMIN_PASSWORD=//p' "$BERKAS_RAHASIA" | tail -n 1)"
[ -n "$kata_sandi_admin" ] || gagal "Kata sandi admin tidak ada di $BERKAS_RAHASIA."

akhiran="$(date +%s)"
project="uji-asap-$akhiran"
repo="$project/uji"
kerja="$(mktemp -d)"
chmod 0700 "$kerja"
jawaban=''
id_project=''

api() {
    local metode="$1" jalur="$2" isi="${3:-}" keluaran status
    local argumen=(-sS -K - -X "$metode" -H 'Content-Type: application/json' -w '\n%{http_code}')
    [ -z "$isi" ] || argumen+=(--data-binary "$isi")
    keluaran="$(printf 'user = "admin:%s"\n' "$kata_sandi_admin" \
        | docker exec -i harbor-core curl "${argumen[@]}" "http://localhost:8080/api/v2.0$jalur")"
    status="${keluaran##*$'\n'}"
    jawaban="${keluaran%$'\n'*}"
    [[ $status == 2?? ]]
}

bereskan() {
    set +e
    if [ -n "$id_project" ]; then
        api GET "/projects/$id_project/immutabletagrules"
        while IFS= read -r aturan; do
            [ -n "$aturan" ] || continue
            api PUT "/projects/$id_project/immutabletagrules/$(printf '%s' "$aturan" | jq -r .id)" \
                "$(printf '%s' "$aturan" | jq -c '.disabled = true')"
        done <<< "$(printf '%s' "$jawaban" | jq -c '.[]?')"
        api GET "/projects/$project/repositories?page_size=100"
        while IFS= read -r nama; do
            [ -z "$nama" ] || api DELETE "/projects/$project/repositories/${nama#"$project"/}"
        done <<< "$(printf '%s' "$jawaban" | jq -r '.[]?.name')"
        api GET "/robots?q=$(jq -rn --arg q "Level=project,ProjectID=$id_project" '$q | @uri')&page_size=100"
        while IFS= read -r id; do
            [ -z "$id" ] || api DELETE "/robots/$id"
        done <<< "$(printf '%s' "$jawaban" | jq -r '.[]?.id')"
        api DELETE "/projects/$id_project" || printf 'Project %s tidak terhapus: %s\n' "$project" "$jawaban" >&2
    fi
    rm -rf "$kerja"
}
trap bereskan EXIT

# crane SIAPA PERINTAH... — setiap identitas punya DOCKER_CONFIG sendiri di folder kerja.
crane() {
    local siapa="$1"
    shift
    mkdir -p "$kerja/cfg-$siapa" "$kerja/data"
    docker run --rm -i -u "$(id -u):$(id -g)" \
        -e DOCKER_CONFIG=/cfg -v "$kerja/cfg-$siapa:/cfg" -v "$kerja/data:/data" -w /data \
        "$CRANE" "$@"
}

# robot_baru SIAPA AKSI_JSON — membuat robot di project uji dan login crane sebagai SIAPA. Pulang id robot.
robot_baru() {
    local siapa="$1" aksi="$2" rahasia nama
    api POST /robots "$(jq -cn --arg n "$siapa" --arg p "$project" --argjson a "$aksi" '{
        name: $n, level: "project", duration: 1, disable: false,
        description: "uji-asap.sh, dibuang di akhir putaran",
        permissions: [{kind: "project", namespace: $p, access: $a}]}')" \
        || gagal "Robot $siapa tidak dapat dibuat: $jawaban"
    rahasia="$(printf '%s' "$jawaban" | jq -r .secret)"
    nama="$(printf '%s' "$jawaban" | jq -r .name)"
    printf '%s' "$rahasia" | crane "$siapa" auth login "$HOST_REGISTRY" -u "$nama" --password-stdin >/dev/null 2>&1 \
        || gagal "crane auth login untuk robot $siapa gagal."
    printf '%s' "$jawaban" | jq -r .id
}

lapisan_acak() {
    head -c 1048576 /dev/urandom > "$kerja/data/acak.bin"
    tar -C "$kerja/data" -cf "$kerja/data/$1" acak.bin
}

printf 'Uji asap %s\n' "$HOST_REGISTRY"
docker pull -q "$CRANE" >/dev/null

api GET "/projects?name=$PROJECT_KONTRAK&page_size=100" || gagal "Daftar project tidak terbaca: $jawaban"
id_kontrak="$(printf '%s' "$jawaban" | jq -r --arg n "$PROJECT_KONTRAK" '.[]? | select(.name == $n) | .project_id')"
[ -n "$id_kontrak" ] || gagal "Project $PROJECT_KONTRAK tidak ada. Jalankan atur-harbor.sh."
api GET "/projects/$id_kontrak"
[ "$(printf '%s' "$jawaban" | jq -r .metadata.public)" = 'false' ] || gagal "Project $PROJECT_KONTRAK publik."
api GET "/projects/$id_kontrak/immutabletagrules"
aturan_kontrak="$(printf '%s' "$jawaban" | jq -c '[.[]? | select(.disabled != true)
    | {disabled, action, template, tag_selectors, scope_selectors}]')"
[ "$(printf '%s' "$aturan_kontrak" | jq length)" -gt 0 ] || gagal "Project $PROJECT_KONTRAK tidak punya aturan immutability aktif."
lulus "project $PROJECT_KONTRAK privat dan punya aturan immutability"

api POST /projects "$(jq -cn --arg n "$project" '{project_name: $n, metadata: {public: "false"}, storage_limit: -1}')" \
    || gagal "Project uji tidak dapat dibuat: $jawaban"
api GET "/projects?name=$project"
id_project="$(printf '%s' "$jawaban" | jq -r --arg n "$project" '.[] | select(.name == $n) | .project_id')"
while IFS= read -r aturan; do
    api POST "/projects/$id_project/immutabletagrules" "$aturan" || gagal "Aturan immutability tidak tersalin: $jawaban"
done <<< "$(printf '%s' "$aturan_kontrak" | jq -c '.[]')"

robot_baru dorong '[{"resource":"repository","action":"push"},{"resource":"repository","action":"pull"}]' >/dev/null
id_robot_tarik="$(robot_baru tarik '[{"resource":"repository","action":"pull"}]')"

lapisan_acak lapisan.tar
tag_uji='uji'
crane dorong append -f lapisan.tar -t "$HOST_REGISTRY/$repo:$tag_uji" >/dev/null 2>&1 || gagal 'Robot push tidak dapat push.'
digest_uji="$(crane dorong digest "$HOST_REGISTRY/$repo:$tag_uji")"
[[ $digest_uji == sha256:* ]] || gagal "Digest sesudah push tidak terbaca: $digest_uji"
lulus 'robot push dapat push'

if crane anonim manifest "$HOST_REGISTRY/$repo@$digest_uji" >/dev/null 2>&1; then
    gagal 'Manifest dapat dibaca tanpa kredensial.'
fi
lulus 'tanpa kredensial ditolak'

# Format OCI, bukan tarball docker: tarball docker menyusun ulang manifest dan digestnya dapat berbeda dari
# yang ada di registry, sedangkan layout OCI menyimpan manifest apa adanya.
crane tarik pull --format=oci "$HOST_REGISTRY/$repo@$digest_uji" tarikan >/dev/null 2>&1 \
    || gagal 'Robot pull saja tidak dapat pull lewat digest.'
digest_tarikan="$(jq -r '.manifests[0].digest' "$kerja/data/tarikan/index.json")"
[ "$digest_tarikan" = "$digest_uji" ] \
    || gagal "Digest yang ditarik ($digest_tarikan) tidak sama dengan yang didorong ($digest_uji)."
lulus "robot pull saja dapat pull lewat digest; digest sama dengan sesudah push"

if crane tarik tag "$HOST_REGISTRY/$repo@$digest_uji" coba-tulis >/dev/null 2>&1; then
    gagal 'Robot pull saja dapat menulis tag.'
fi
lulus 'robot pull saja tidak dapat push'

tag_rilis='0.0.0-uji-asap'
crane dorong tag "$HOST_REGISTRY/$repo@$digest_uji" "$tag_rilis" >/dev/null 2>&1 \
    || gagal "Tag rilis $tag_rilis tidak dapat dibuat untuk pertama kali."
lapisan_acak lapisan2.tar
if crane dorong append -f lapisan2.tar -t "$HOST_REGISTRY/$repo:$tag_rilis" >/dev/null 2>&1; then
    gagal "Tag rilis $tag_rilis dapat ditimpa. Aturan immutability $PROJECT_KONTRAK tidak melindungi tag rilis."
fi
lulus 'tag rilis tidak dapat ditimpa'

api DELETE "/robots/$id_robot_tarik" || gagal "Robot pull saja tidak dapat dihapus: $jawaban"
if crane tarik manifest "$HOST_REGISTRY/$repo@$digest_uji" >/dev/null 2>&1; then
    gagal 'Robot yang sudah dihapus masih dapat membaca manifest dengan kredensialnya.'
fi
lulus 'robot yang dihapus tidak dapat login lagi'

printf '\nHijau: seluruh syarat terpenuhi.\n'
