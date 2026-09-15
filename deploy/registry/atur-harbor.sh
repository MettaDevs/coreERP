#!/usr/bin/env bash
#
# Menyelaraskan isi Harbor dengan kontrak di docs/todo/registry-harbor lewat API: setelan sistem, project
# `coreerp`, aturan immutability, aturan retensi, jadwal GC, robot perakit, dan robot sistem admin.erp.
# Dijalankan sebagai root di server pertama, sesudah pasang.sh:
#
#   sudo bash deploy/registry/atur-harbor.sh
#
# Idempoten: setiap butir dibaca dulu dan hanya ditulis bila berbeda.
#
# API dipanggil dari dalam container harbor-core ke localhost, bukan lewat Traefik: jalur admin di internet
# dibatasi daftar IP, dan kata sandi admin tidak perlu meninggalkan mesin ini. Kata sandi dikirim ke curl
# lewat stdin, bukan argumen, supaya tidak terlihat di daftar proses.
#
# Setelan yang boleh diubah tanpa menyunting skrip, di /etc/coreerp/registry/setelan.env:
#   REGISTRY_SIMPAN_RILIS   berapa rilis terakhir yang disimpan retensi di luar tag terpasang-* (bawaan 10)
#   REGISTRY_TOKEN_MENIT    umur token registry dalam menit — batas atas jeda pencabutan robot (bawaan 5)

set -euo pipefail
shopt -s inherit_errexit

PROJECT='coreerp'
BERKAS_RAHASIA='/etc/coreerp/registry/rahasia.env'
BERKAS_SETELAN='/etc/coreerp/registry/setelan.env'
FOLDER_PERAKIT='/etc/coreerp/perakit'
BERKAS_ROBOT_PERAKIT="$FOLDER_PERAKIT/registry-robot.env"
NAMA_ROBOT_KONSOL='konsol'
BERKAS_ROBOT_KONSOL='/etc/coreerp/registry/robot-konsol.env'
HOST_REGISTRY='registry.erp.grenery.xyz'

# Tag rilis diawali angka (`0.1.0`, `0.2.0-rc1`). Tag `terpasang-*` sengaja tidak cocok: admin.erp harus
# dapat mencabutnya.
POLA_TAG_RILIS='[0-9]*'
# Minggu 03.00 WIB. Jadwal Harbor dibaca dalam UTC dan berformat enam kolom, detik lebih dulu.
CRON_GC='0 0 20 * * 6'

gagal() {
    printf '\nGAGAL: %s\n' "$1" >&2
    shift
    [ "$#" -eq 0 ] || printf '%s\n' "$@" >&2
    exit 1
}

langkah() {
    printf '\n==> %s\n' "$*"
}

nilai_dari() {
    [ -f "$1" ] || return 0
    sed -n "s/^$2=//p" "$1" | tail -n 1
}

[ "$(id -u)" -eq 0 ] || gagal 'Jalankan sebagai root.'
command -v jq >/dev/null || gagal 'Perintah jq tidak ada.'
[ "$(docker inspect harbor-core --format '{{.State.Health.Status}}' 2>/dev/null)" = 'healthy' ] \
    || gagal 'harbor-core tidak berjalan sehat. Jalankan pasang.sh lebih dulu.'

kata_sandi_admin="$(nilai_dari "$BERKAS_RAHASIA" HARBOR_ADMIN_PASSWORD)"
[ -n "$kata_sandi_admin" ] || gagal "Kata sandi admin tidak ada di $BERKAS_RAHASIA."
simpan_rilis="$(nilai_dari "$BERKAS_SETELAN" REGISTRY_SIMPAN_RILIS)"
simpan_rilis="${simpan_rilis:-10}"
token_menit="$(nilai_dari "$BERKAS_SETELAN" REGISTRY_TOKEN_MENIT)"
token_menit="${token_menit:-5}"
[[ $simpan_rilis =~ ^[1-9][0-9]*$ ]] || gagal "REGISTRY_SIMPAN_RILIS bukan bilangan positif: $simpan_rilis"
[[ $token_menit =~ ^[1-9][0-9]*$ ]] || gagal "REGISTRY_TOKEN_MENIT bukan bilangan positif: $token_menit"

jawaban=''
# api METODE JALUR [JSON] — isi jawaban di $jawaban; pulang gagal bila status di luar 2xx.
api() {
    local metode="$1" jalur="$2" isi="${3:-}" keluaran status
    local argumen=(-sS -K - -X "$metode" -H 'Content-Type: application/json' -w '\n%{http_code}')
    [ -z "$isi" ] || argumen+=(--data-binary "$isi")
    keluaran="$(printf 'user = "admin:%s"\n' "$kata_sandi_admin" \
        | docker exec -i harbor-core curl "${argumen[@]}" "http://localhost:8080/api/v2.0$jalur")"
    status="${keluaran##*$'\n'}"
    jawaban="${keluaran%$'\n'*}"
    case "$status" in
        2??) return 0 ;;
        *) printf '%s %s dijawab %s: %s\n' "$metode" "$jalur" "$status" "$jawaban" >&2; return 1 ;;
    esac
}

langkah 'Setelan sistem'
api GET /configurations
# Tanpa pendaftaran mandiri, hanya admin yang membuat project, dan token registry berumur pendek.
# Token yang sudah terbit untuk robot yang kemudian dihapus tetap berlaku sampai umurnya habis — lihat
# SPIKE.md — jadi umur ini adalah jeda pencabutan terpanjang.
setelan_ingin="$(jq -cn --argjson menit "$token_menit" \
    '{self_registration: false, project_creation_restriction: "adminonly", token_expiration: $menit}')"
setelan_beda="$(jq -cn --argjson ingin "$setelan_ingin" --argjson sekarang "$jawaban" \
    '$ingin | with_entries(select(.value != $sekarang[.key].value))')"
if [ "$setelan_beda" = '{}' ]; then
    printf '    tidak berubah\n'
else
    api PUT /configurations "$setelan_beda"
    printf '    diperbarui: %s\n' "$setelan_beda"
fi

langkah "Project $PROJECT"
api GET "/projects?name=$PROJECT&page_size=100"
id_project="$(printf '%s' "$jawaban" | jq -r --arg n "$PROJECT" '.[]? | select(.name == $n) | .project_id')"
if [ -z "$id_project" ]; then
    api POST /projects "$(jq -cn --arg n "$PROJECT" '{project_name: $n, metadata: {public: "false"}, storage_limit: -1}')"
    api GET "/projects?name=$PROJECT&page_size=100"
    id_project="$(printf '%s' "$jawaban" | jq -r --arg n "$PROJECT" '.[] | select(.name == $n) | .project_id')"
    printf '    dibuat, id %s\n' "$id_project"
else
    printf '    sudah ada, id %s\n' "$id_project"
fi
api GET "/projects/$id_project"
[ "$(printf '%s' "$jawaban" | jq -r '.metadata.public')" = 'false' ] \
    || gagal "Project $PROJECT publik. Ia harus privat; ubah lewat UI lalu ulangi."

# Project `library` dibuat Harbor saat pemasangan dan publik. Kosong, tetapi publik berarti siapa pun dapat
# menarik apa pun yang kelak didorong ke sana tanpa sengaja.
api GET '/projects?name=library&page_size=100'
id_library="$(printf '%s' "$jawaban" | jq -r '.[]? | select(.name == "library") | .project_id')"
if [ -n "$id_library" ]; then
    api GET "/projects/$id_library"
    if [ "$(printf '%s' "$jawaban" | jq -r '.metadata.public')" != 'false' ]; then
        api PUT "/projects/$id_library" '{"metadata":{"public":"false"}}'
        printf '    project library dijadikan privat\n'
    fi
fi

langkah 'Immutability tag rilis'
api GET "/projects/$id_project/immutabletagrules"
ada_aturan="$(printf '%s' "$jawaban" | jq -r --arg p "$POLA_TAG_RILIS" \
    '[.[]? | select(.disabled != true) | select(.tag_selectors[0].pattern == $p and .tag_selectors[0].decoration == "matches")] | length')"
if [ "$ada_aturan" -gt 0 ]; then
    printf '    sudah ada untuk pola %s\n' "$POLA_TAG_RILIS"
else
    api POST "/projects/$id_project/immutabletagrules" "$(jq -cn --arg p "$POLA_TAG_RILIS" '{
        disabled: false, action: "immutable", template: "immutable_template",
        tag_selectors: [{kind: "doublestar", decoration: "matches", pattern: $p}],
        scope_selectors: {repository: [{kind: "doublestar", decoration: "repoMatches", pattern: "**"}]}
    }')"
    printf '    dibuat untuk pola %s\n' "$POLA_TAG_RILIS"
fi

langkah 'Retensi'
# Dua aturan digabung "or": artifact bertag terpasang-* selalu disimpan, dan N rilis terakhir yang didorong
# disimpan. Artifact tanpa tag ikut dipilih aturan kedua (`untagged: true`) — PRD melarang membuang artifact
# tanpa tag sebelum terbukti setiap artifact yang dipakai bertag.
#
# Sengaja TANPA jadwal. Tag terpasang-* dipasang admin.erp (CP-05), dan selama itu belum ada, retensi yang
# berjalan sendiri dapat membuang rilis yang terpasang di klien. Jalankan pratinjau dari UI; jadwal dinyalakan
# bersama CP-05.
aturan_retensi="$(jq -cn --argjson k "$simpan_rilis" --argjson id "$id_project" '{
    algorithm: "or",
    rules: [
        {disabled: false, action: "retain", template: "always", params: {},
         tag_selectors: [{kind: "doublestar", decoration: "matches", pattern: "terpasang-*", extras: "{\"untagged\":false}"}],
         scope_selectors: {repository: [{kind: "doublestar", decoration: "repoMatches", pattern: "**"}]}},
        {disabled: false, action: "retain", template: "latestPushedK", params: {latestPushedK: $k},
         tag_selectors: [{kind: "doublestar", decoration: "matches", pattern: "**", extras: "{\"untagged\":true}"}],
         scope_selectors: {repository: [{kind: "doublestar", decoration: "repoMatches", pattern: "**"}]}}
    ],
    trigger: {kind: "Schedule", settings: {cron: ""}, references: {}},
    scope: {level: "project", ref: $id}
}')"
api GET "/projects/$id_project"
id_retensi="$(printf '%s' "$jawaban" | jq -r '.metadata.retention_id // empty')"
if [ -z "$id_retensi" ]; then
    api POST /retentions "$aturan_retensi"
    printf '    dibuat: simpan terpasang-* dan %s rilis terakhir, tanpa jadwal\n' "$simpan_rilis"
else
    api GET "/retentions/$id_retensi"
    # Harbor membuang `disabled: false` dan `params: {}` dari jawabannya; keduanya disamakan sebelum dibandingkan.
    bentuk() {
        jq -cS '{algorithm, cron: .trigger.settings.cron, rules: [.rules[]
            | {disabled: (.disabled // false), params: (.params // {}), action, template, tag_selectors, scope_selectors}]}'
    }
    if [ "$(printf '%s' "$jawaban" | bentuk)" = "$(printf '%s' "$aturan_retensi" | bentuk)" ]; then
        printf '    tidak berubah\n'
    else
        api PUT "/retentions/$id_retensi" "$(printf '%s' "$aturan_retensi" | jq -c --argjson id "$id_retensi" '. + {id: $id}')"
        printf '    diperbarui: simpan terpasang-* dan %s rilis terakhir, tanpa jadwal\n' "$simpan_rilis"
    fi
fi

langkah 'Jadwal GC'
# Mingguan, tanpa menghapus artifact tanpa tag — alasannya sama dengan retensi di atas. GC Harbor tidak
# memblok push dan pull, dan tidak menyapu lapisan yang diunggah dalam dua jam terakhir.
jadwal_gc="$(jq -cn --arg cron "$CRON_GC" \
    '{schedule: {type: "Custom", cron: $cron}, parameters: {delete_untagged: false, workers: 1, dry_run: false}}')"
api GET /system/gc/schedule
cron_sekarang="$(printf '%s' "${jawaban:-null}" | jq -r '.schedule.cron // empty' 2>/dev/null || true)"
# job_parameters dikembalikan sebagai string JSON di dalam JSON. `//` tidak dipakai untuk nilainya karena
# jq menganggap false sama dengan kosong.
hapus_tanpa_tag="$(printf '%s' "${jawaban:-null}" | jq -r '.job_parameters // "{}" | fromjson | .delete_untagged | tostring' 2>/dev/null || true)"
if [ "$cron_sekarang" = "$CRON_GC" ] && [ "$hapus_tanpa_tag" = 'false' ]; then
    printf '    tidak berubah\n'
elif [ -z "$cron_sekarang" ]; then
    api POST /system/gc/schedule "$jadwal_gc"
    printf '    dibuat: %s (UTC)\n' "$CRON_GC"
else
    api PUT /system/gc/schedule "$jadwal_gc"
    printf '    diperbarui: %s (UTC)\n' "$CRON_GC"
fi

langkah 'Robot perakit'
# Push dan pull di project coreerp, tanpa tenggat, diputar lewat RUNBOOK.md. Rahasianya hanya ditulis ke
# berkas root di mesin perakit — server ini — dan tidak pernah dicetak.
nama_robot="$PROJECT+perakit"
api GET "/robots?q=$(jq -rn --arg q "Level=project,ProjectID=$id_project" '$q | @uri')&page_size=100"
id_robot="$(printf '%s' "$jawaban" | jq -r --arg n "$nama_robot" '.[]? | select(.name | endswith($n)) | .id')"
# tulis_rahasia_robot BERKAS NAMA RAHASIA — berkas root 0600 di folder 0700. Nilainya bertanda kutip tunggal
# karena nama robot memuat `$`.
tulis_rahasia_robot() {
    local berkas="$1" nama="$2" rahasia="$3"
    install -d -m 0700 -o root -g root "$(dirname "$berkas")"
    (
        umask 077
        printf "REGISTRY_HOST='%s'\nREGISTRY_USERNAME='%s'\nREGISTRY_PASSWORD='%s'\n" \
            "$HOST_REGISTRY" "$nama" "$rahasia" > "$berkas"
    )
}
if [ -n "$id_robot" ] && [ -f "$BERKAS_ROBOT_PERAKIT" ]; then
    printf '    sudah ada, rahasia di %s\n' "$BERKAS_ROBOT_PERAKIT"
elif [ -n "$id_robot" ]; then
    # Robot ada tetapi rahasianya hilang dari mesin ini: putar rahasianya alih-alih membuat robot kedua.
    api PATCH "/robots/$id_robot" '{"secret":""}'
    rahasia="$(printf '%s' "$jawaban" | jq -r '.secret // empty')"
    [ -n "$rahasia" ] || gagal 'Harbor tidak mengembalikan rahasia baru untuk robot perakit.'
    api GET "/robots/$id_robot"
    tulis_rahasia_robot "$BERKAS_ROBOT_PERAKIT" "$(printf '%s' "$jawaban" | jq -r '.name')" "$rahasia"
    printf '    rahasia diputar, ditulis ke %s\n' "$BERKAS_ROBOT_PERAKIT"
else
    api POST /robots "$(jq -cn --arg p "$PROJECT" '{
        name: "perakit", level: "project", duration: -1, disable: false,
        description: "Perakit rilis di server pertama: push dan pull image rilis serta image pendamping.",
        permissions: [{kind: "project", namespace: $p, access: [
            {resource: "repository", action: "push"},
            {resource: "repository", action: "pull"}
        ]}]
    }')"
    rahasia="$(printf '%s' "$jawaban" | jq -r '.secret // empty')"
    [ -n "$rahasia" ] || gagal 'Harbor tidak mengembalikan rahasia robot perakit.'
    tulis_rahasia_robot "$BERKAS_ROBOT_PERAKIT" "$(printf '%s' "$jawaban" | jq -r '.name')" "$rahasia"
    printf '    dibuat, rahasia di %s\n' "$BERKAS_ROBOT_PERAKIT"
fi

langkah 'Robot sistem admin.erp'
# Dipakai admin.erp untuk membuat dan menghapus robot situs per operasi (CP-01, CP-02). Izinnya diukur di
# Harbor v2.15.2, bukan ditebak: robot create/delete/list/read dan repository pull di project coreerp.
# Pull wajib ada karena Harbor menolak robot membuat robot yang izinnya lebih luas dari miliknya, dan tanpa
# push robot ini tidak dapat melahirkan robot yang dapat mendorong image. Harbor tidak mengenal robot:update,
# jadi admin.erp mengganti robot situs dengan menghapus lalu membuat.
#
# Rahasianya ditulis ke berkas root 0600 di sini, lalu dipasang ke konsol dengan
#   sudo cat /etc/coreerp/registry/robot-konsol.env | docker exec -i <container konsol> php artisan registry:robot-sistem
# yang menyimpannya terenkripsi di database konsol sesudah memeriksanya ke Harbor.
api GET "/robots?q=$(jq -rn '"Level=system" | @uri')&page_size=100"
id_robot_konsol="$(printf '%s' "$jawaban" | jq -r --arg n "robot\$$NAMA_ROBOT_KONSOL" '.[]? | select(.name == $n) | .id')"
if [ -n "$id_robot_konsol" ] && [ -f "$BERKAS_ROBOT_KONSOL" ]; then
    printf '    sudah ada, rahasia di %s\n' "$BERKAS_ROBOT_KONSOL"
elif [ -n "$id_robot_konsol" ]; then
    api PATCH "/robots/$id_robot_konsol" '{"secret":""}'
    rahasia="$(printf '%s' "$jawaban" | jq -r '.secret // empty')"
    [ -n "$rahasia" ] || gagal 'Harbor tidak mengembalikan rahasia baru untuk robot sistem admin.erp.'
    tulis_rahasia_robot "$BERKAS_ROBOT_KONSOL" "robot\$$NAMA_ROBOT_KONSOL" "$rahasia"
    printf '    rahasia diputar, ditulis ke %s — pasang ulang ke konsol\n' "$BERKAS_ROBOT_KONSOL"
else
    api POST /robots "$(jq -cn --arg n "$NAMA_ROBOT_KONSOL" --arg p "$PROJECT" '{
        name: $n, level: "system", duration: -1, disable: false,
        description: "admin.erp: membuat dan menghapus robot pull-only per operasi situs.",
        permissions: [{kind: "project", namespace: $p, access: [
            {resource: "robot", action: "create"},
            {resource: "robot", action: "delete"},
            {resource: "robot", action: "list"},
            {resource: "robot", action: "read"},
            {resource: "repository", action: "pull"}
        ]}]
    }')"
    rahasia="$(printf '%s' "$jawaban" | jq -r '.secret // empty')"
    [ -n "$rahasia" ] || gagal 'Harbor tidak mengembalikan rahasia robot sistem admin.erp.'
    tulis_rahasia_robot "$BERKAS_ROBOT_KONSOL" "$(printf '%s' "$jawaban" | jq -r '.name')" "$rahasia"
    printf '    dibuat, rahasia di %s — pasang ke konsol\n' "$BERKAS_ROBOT_KONSOL"
fi

printf '\nHarbor selaras dengan kontrak.\n'
