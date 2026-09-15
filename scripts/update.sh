#!/usr/bin/env bash
#
# Memasang atau memutakhirkan satu edisi CoreERP dari sebuah bundle, di server pelanggan.
#
#   ./update.sh                     # memakai bundle di folder yang sama dengan skrip ini
#   ./update.sh /opt/coreerp/agent/releases/apotek-sejahtera-0.2.0
#
# Tiga bentuk folder diterima, dan ketiganya melewati pemeriksaan tanda tangan dan checksum yang sama:
#
# - **Bundle** dari `build-bundle.sh`, yang membawa `images.tar.gz`. Image dimuat dari arsip itu, tanpa
#   menarik apa pun dari registry.
# - **Berkas rilis v1**, yang diambil agen situs dari admin.erp: bentuk yang sama dikurangi
#   `images.tar.gz`. Image ditarik dari registry. Manifest menyebut image edisi lewat digest registry
#   (`ghcr.io/…@sha256:…`), dan id image yang ditarik tetap diperiksa terhadap `digest` di manifest —
#   pemeriksaan yang sama dengan jalur bundle. Rantainya tidak putus: tanda tangan menjamin
#   `SHA256SUMS`, `SHA256SUMS` menjamin `manifest.json`, manifest menyebut isi image.
# - **Berkas rilis v2** (`"versi": 2`, dari perakit Harbor). Skrip ini **tidak menarik apa pun** dan tidak
#   pernah mengenal host registry. Agen situs yang menarik image lewat digest dengan kredensial sekali pakai
#   dari admin.erp, lalu memberinya tag lokal `coreerp.local/*`; di sini hanya diperiksa bahwa setiap tag
#   lokal ada dan `RepoDigests`-nya memuat digest yang ditandatangani. Host dicocokkan sebagai awalan apa
#   pun: registry boleh pindah tanpa rilis berubah, sedangkan digest tidak dapat dipalsukan.
#
# Urutannya tetap, dan tidak boleh diacak:
#
#   periksa tanda tangan → periksa checksum → periksa lokasi cadangan → (v2: periksa image lokal) →
#   cadangkan database → muat atau tarik image → ganti container → migrasi → periksa kesehatan →
#   mundur bila gagal
#
# Pada v2 image diperiksa **sebelum** pencadangan, karena pencadangan sudah menyalakan `core-db` dengan compose
# rilis baru: tag pendamping yang hilang atau salah akan mengganti container database sebelum ada yang menolak.
#
# Tiga hal yang membedakannya dari skrip pemasangan biasa, dan ketiganya sengaja:
#
# 1. **Tanda tangan dan checksum adalah dua pemeriksaan yang berbeda**, dan keduanya wajib lulus.
#    Checksum menjaga dari berkas yang rusak saat disalin; tanda tangan menjaga dari berkas yang
#    diganti orang. Kunci publiknya dipasang sekali lewat jalur yang berbeda dari bundle-nya, dan
#    tidak pernah diambil dari dalam bundle.
#
# 2. **Mundur berarti memulihkan image DAN database.** Memulihkan image saja hanya sah bila setiap
#    migration kompatibel mundur, dan hari ini tidak ada aturan yang mewajibkannya maupun pemeriksa
#    yang menolak yang melanggarnya.
#
# 3. **Bila tidak ada versi yang terbukti pernah sehat, skrip ini berhenti** — bukan menebak versi
#    sebelumnya. Berhenti dengan keadaan yang jelas lebih baik daripada memutar mundur ke sesuatu
#    yang tidak diketahui pernah bekerja. Bentuk ini meniru deployment circuit breaker pada ECS;
#    sumbernya di `docs/todo/bundle-on-prem/README.md`.
#
# Yang **tidak** dijaga skrip ini, dan pantas disebut: versi baru yang menyala, lulus pemeriksaan
# kesehatan, tetapi menjawab salah. Pemeriksaan kesehatan melihat apakah aplikasinya hidup, bukan
# apakah jawabannya benar.
#
# Ongkos yang juga pantas disebut: **data yang ditulis setelah pembaruan dimulai akan hilang saat
# mundur**, karena database dipulihkan dari cadangan sebelum migrasi. Itu alasan pembaruan
# dijalankan pada jendela yang disepakati, bukan di tengah jam kerja.

set -euo pipefail

bundle="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"

RUMAH="${COREERP_HOME:-/opt/coreerp}"
KUNCI_PUBLIK="${COREERP_KUNCI_PUBLIK:-$RUMAH/kunci-rilis.pub}"
FOLDER_CADANGAN="${COREERP_FOLDER_CADANGAN:-$RUMAH/cadangan}"
FOLDER_KEADAAN="$RUMAH/keadaan"
BERKAS_VERSI_SEHAT="$FOLDER_KEADAAN/versi-sehat"
BERKAS_COMPOSE_SEHAT="$FOLDER_KEADAAN/compose-sehat.yaml"
BERKAS_ENV="${COREERP_ENV:-$RUMAH/.env}"
PROYEK="${COREERP_PROYEK:-coreerp}"
BATAS_SEHAT_DETIK="${COREERP_BATAS_SEHAT_DETIK:-180}"

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

for perintah in docker openssl sha256sum; do
    command -v "$perintah" >/dev/null 2>&1 \
        || gagal "Perintah \`$perintah\` tidak ada di PATH." "Ia dibutuhkan untuk memasang bundle."
done

docker compose version >/dev/null 2>&1 || gagal 'Docker Compose v2 dibutuhkan.'
docker info >/dev/null 2>&1 || gagal 'Docker tidak berjalan, atau pengguna ini tidak dapat mengaksesnya.'

# `images.tar.gz` tidak ada di daftar ini: ketiadaannya berarti berkas rilis, bukan bundle yang
# rusak. Lihat bagian atas berkas ini.
for berkas in manifest.json compose.yaml SHA256SUMS; do
    [ -f "$bundle/$berkas" ] || gagal \
        "Bundle tidak lengkap: $bundle/$berkas tidak ada." \
        "Folder yang diperiksa: $bundle"
done

membawa_image=0
[ ! -f "$bundle/images.tar.gz" ] || membawa_image=1

if [ "$membawa_image" -eq 1 ]; then
    command -v gzip >/dev/null 2>&1 \
        || gagal "Perintah \`gzip\` tidak ada di PATH." 'Ia dibutuhkan untuk memuat images.tar.gz.'
fi

[ -f "$BERKAS_ENV" ] || gagal \
    "Berkas setelan tidak ditemukan: $BERKAS_ENV" \
    'Salin contohnya dan isi nilai sungguhannya sebelum memasang.'

# --- 1. Tanda tangan -----------------------------------------------------------------------------
#
# Dijalankan **sebelum** checksum, karena checksum yang tidak ditandatangani dapat ikut diganti oleh
# orang yang sama yang mengganti isinya. Memeriksa checksum lebih dulu hanya membuktikan bundle
# konsisten dengan dirinya sendiri.

langkah 'Memeriksa tanda tangan'

[ -f "$KUNCI_PUBLIK" ] || gagal \
    "Kunci publik rilis tidak ditemukan: $KUNCI_PUBLIK" \
    '' \
    'Kunci ini dipasang sekali saat pemasangan pertama, dan sengaja TIDAK ikut di dalam bundle:' \
    'bundle yang membawa kunci pemverifikasinya sendiri tidak memverifikasi apa pun terhadap orang' \
    'yang mengganti keduanya sekaligus.' \
    '' \
    'Mintalah kuncinya lewat jalur yang berbeda dari bundle-nya, lalu simpan di jalur di atas.'

[ -f "$bundle/SHA256SUMS.sig" ] || gagal \
    'Bundle ini tidak ditandatangani.' \
    "Berkas $bundle/SHA256SUMS.sig tidak ada." \
    '' \
    'Bundle tanpa tanda tangan hanya untuk mencoba di mesin pembuatnya sendiri; ia tidak dipasang' \
    'di server pelanggan.'

if ! openssl dgst -sha256 -verify "$KUNCI_PUBLIK" \
    -signature "$bundle/SHA256SUMS.sig" "$bundle/SHA256SUMS" >/dev/null 2>&1; then
    gagal \
        'Tanda tangan bundle TIDAK sah.' \
        '' \
        'Bundle ini bukan bundle yang kami terbitkan, atau ia berubah setelah ditandatangani.' \
        'Jangan memasangnya. Hubungi penerbitnya.'
fi

printf '    tanda tangan sah\n'

# --- 2. Checksum ---------------------------------------------------------------------------------
#
# Pemeriksaan yang berbeda dari tanda tangan, dan bukan pengganti: tanda tangan membuktikan daftar
# checksum-nya asli, checksum membuktikan berkasnya cocok dengan daftar itu. Bundle yang tanda
# tangannya sah tetapi salinannya rusak akan lolos pemeriksaan pertama dan gagal di sini.

langkah 'Memeriksa checksum'

# `sha256sum --check` hanya memeriksa berkas yang **tercantum**. Sejak `images.tar.gz` boleh tidak ada,
# SHA256SUMS yang sah milik berkas rilis — yang memang tidak mencantumkan arsip image — dapat
# dipasangkan dengan `images.tar.gz` selundupan, dan arsip itu akan lolos tanpa diperiksa sama sekali.
# Image edisi masih tertangkap pemeriksaan digest di bawah; image pendamping tidak. Karena itu setiap
# berkas yang ada di folder wajib tercantum.
tercantum() {
    awk -v nama="$1" '{ n = $2; sub(/^\*/, "", n); if (n == nama) ada = 1 } END { exit !ada }' "$bundle/SHA256SUMS"
}

for berkas in manifest.json compose.yaml images.tar.gz update.sh; do
    if [ -f "$bundle/$berkas" ] && ! tercantum "$berkas"; then
        gagal \
            "Berkas $berkas tidak tercantum di SHA256SUMS." \
            '' \
            'Tanda tangan hanya menjamin berkas yang tercantum di daftar checksum. Berkas yang tidak' \
            'tercantum bukan bagian dari rilis yang kami terbitkan. Jangan memasang folder ini.'
    fi
done

if ! (cd "$bundle" && sha256sum --quiet --check SHA256SUMS); then
    gagal \
        'Checksum bundle TIDAK cocok.' \
        '' \
        'Tanda tangannya sah, jadi daftar checksum-nya asli — yang tidak cocok adalah berkasnya.' \
        'Hampir selalu ini berarti salinannya rusak. Salin ulang bundle-nya, jangan memasang yang ini.'
fi

printf '    seluruh berkas cocok\n'

# --- 3. Lokasi cadangan --------------------------------------------------------------------------
#
# Cadangan yang duduk di filesystem yang sama dengan data yang dicadangkannya tidak menolong pada
# kegagalan yang paling mungkin terjadi. Ia tetap berguna untuk mundur — itu memang perannya di
# sini — tetapi ia bukan cadangan bencana, dan skrip ini menolak berpura-pura sebaliknya.

langkah 'Memeriksa lokasi cadangan'

mkdir -p "$FOLDER_CADANGAN" "$FOLDER_KEADAAN"

# Jalur data database boleh disebut langsung lewat COREERP_TITIK_DATA_DB. Itu bukan sekadar seam
# untuk pengujian: penyebaran on-prem lazim mem-bind-mount data PostgreSQL ke disk tersendiri, dan
# pada bentuk itu volume Docker tidak memberi tahu apa pun tentang letaknya yang sebenarnya.
titik_data_db="${COREERP_TITIK_DATA_DB:-}"

if [ -z "$titik_data_db" ]; then
    titik_data_db="$(docker volume inspect "${PROYEK}_core-db-data" --format '{{.Mountpoint}}' 2>/dev/null || true)"
fi

if [ -n "$titik_data_db" ] && [ -d "$titik_data_db" ]; then
    perangkat_data="$(stat -c '%d' "$titik_data_db")"
    perangkat_cadangan="$(stat -c '%d' "$FOLDER_CADANGAN")"

    if [ "$perangkat_data" = "$perangkat_cadangan" ]; then
        gagal \
            'Folder cadangan berada di filesystem yang sama dengan data database.' \
            '' \
            "  data database : $titik_data_db" \
            "  cadangan      : $FOLDER_CADANGAN" \
            '' \
            'Satu disk yang rusak menghapus keduanya sekaligus. Arahkan COREERP_FOLDER_CADANGAN ke' \
            'penyimpanan lain, lalu jalankan lagi.'
    fi

    printf '    cadangan berada di filesystem yang berbeda\n'
elif [ -z "$titik_data_db" ] && [ ! -s "$BERKAS_VERSI_SEHAT" ]; then
    # Pemasangan pertama di server bersih: volume database belum pernah dibuat, jadi letaknya memang
    # belum ada untuk dibandingkan — dan belum ada data yang dapat hilang. Ditemukan saat skrip ini
    # pertama kali dijalankan agen di mesin kosong: pemeriksaan yang menolak di sini membuat
    # pemasangan pertama mustahil tanpa menyetel jalur secara manual.
    #
    # Pemeriksaannya ditunda, bukan dihapus. Pembaruan berikutnya — yang pertama kali benar-benar
    # mencadangkan — menemukan volumenya dan memeriksa seperti biasa.
    printf '    ditunda: pemasangan pertama, belum ada data database untuk dibandingkan\n'
elif [ "${COREERP_LEWATI_PERIKSA_CADANGAN:-0}" = '1' ]; then
    printf '    DILEWATI atas permintaan (COREERP_LEWATI_PERIKSA_CADANGAN=1)\n' >&2
else
    gagal \
        'Tidak dapat menentukan di mana data database benar-benar berada.' \
        '' \
        "Volume ${PROYEK}_core-db-data tidak dapat dibaca dari sini — lazim pada Docker Desktop atau" \
        'daemon Docker yang berjalan di mesin lain.' \
        '' \
        'Pemeriksaan ini tidak dilewati diam-diam: melaporkan lulus tanpa memeriksa lebih berbahaya' \
        'daripada tidak memeriksa sama sekali. Di mesin pengembangan, setel' \
        'COREERP_LEWATI_PERIKSA_CADANGAN=1 dengan sadar.'
fi

# --- Membaca manifest ----------------------------------------------------------------------------

nilai_manifest() {
    sed -n "s/.*\"$1\"[[:space:]]*:[[:space:]]*\"\\([^\"]*\\)\".*/\\1/p" "$bundle/manifest.json" | head -n 1
}

# Manifest v1 — bundle dan berkas rilis lama — tidak pernah menulis kunci `versi`. Kunci itu dicari sebagai
# kunci JSON (diikuti titik dua), bukan sebagai kata: nama pelanggan yang kebetulan memuatnya tidak boleh
# memindahkan bundle ke jalur yang menuntut jq.
versi_manifest=1

if grep -Eq '"versi"[[:space:]]*:' "$bundle/manifest.json"; then
    # jq hanya dituntut di sini. Bundle beli-putus tetap dipasang tanpanya; rilis v2 hanya sampai ke server lewat
    # agen situs, dan agen itu — beserta pasang.sh yang memasangnya — sudah menuntut jq.
    command -v jq >/dev/null 2>&1 || gagal \
        'Manifest rilis v2 dibaca dengan jq, dan jq tidak ada di PATH.' \
        'Agen situs yang menarik image rilis v2 memasangnya; di Ubuntu: apt-get install jq.'

    versi_manifest="$(jq -r 'if .versi == 2 then "2" else "" end' "$bundle/manifest.json" 2>/dev/null || true)"

    [ "$versi_manifest" = 2 ] || gagal \
        'manifest.json menyebut versi manifest yang tidak dikenal skrip ini.' \
        'Rilis dengan manifest yang lebih baru membawa update.sh-nya sendiri; jalankan yang itu.'
fi

if [ "$versi_manifest" = 2 ]; then
    edisi=''
    digest=''
    rilis="$(jq -r 'if (.rilis | type) == "string" and (.rilis | test("\\A(0|[1-9][0-9]*)(\\.(0|[1-9][0-9]*)){0,5}\\z"))
        then .rilis else "" end' "$bundle/manifest.json")"

    [ -n "$rilis" ] || gagal 'manifest.json v2 tidak menyebut nomor rilis yang sah.'

    # Satu baris per image: tag lokal, lalu `repositori@digest` tanpa host yang harus menjadi ujung salah satu
    # RepoDigests-nya. Aturan tag lokal sama persis dengan `tag_lokal_*` di coreerp-agent, yang memberi tag itu:
    # pendamping memakai 20 huruf pertama digestnya, bukan nomor rilis, supaya PostgreSQL tidak dibuat ulang
    # pada setiap pembaruan yang tidak mengubah image-nya.
    #
    # Bentuk setiap nilai diperiksa, walaupun manifest sudah lolos tanda tangan: nilainya menjadi argumen docker
    # dan dipisah dengan tab di bawah.
    daftar_image="$(jq -r '
        def repositori: type == "string" and test("\\A[a-z0-9]+([._/-][a-z0-9]+)*\\z");
        def digest: type == "string" and test("\\Asha256:[a-f0-9]{64}\\z");
        if (.image | repositori) and (.digest | digest) and (.pendamping | type) == "array"
            and all(.pendamping[]; (.nama | type == "string" and test("\\A[a-z0-9][a-z0-9._-]*\\z"))
                and (.image | repositori) and (.digest | digest))
        then
            "coreerp.local/core:\(.rilis)\t\(.image)@\(.digest)",
            (.pendamping[] | "coreerp.local/pendamping/\(.nama):\(.digest[7:27])\t\(.image)@\(.digest)")
        else error("bentuk manifest v2") end' "$bundle/manifest.json" 2>/dev/null)" \
        || gagal 'manifest.json v2 tidak berbentuk yang dibaca skrip ini: image, digest, atau pendamping tidak sah.'

    image="coreerp.local/core:$rilis"
else
    edisi="$(nilai_manifest edisi)"
    rilis="$(nilai_manifest rilis)"
    image="$(nilai_manifest image)"
    digest="$(nilai_manifest digest)"
fi

[ -n "$image" ] || gagal 'manifest.json tidak menyebut image.'

compose=(docker compose --project-name "$PROYEK" --env-file "$BERKAS_ENV" -f "$bundle/compose.yaml")

# Jalur mundur memakai compose milik versi yang **dituju**, bukan milik bundle yang baru saja
# ditolak. Ini ditemukan dengan menjalankannya, bukan dengan membacanya: bundle yang gagal karena
# compose-nya sendiri bermasalah akan membawa masalah itu ikut ke dalam pemulihannya, dan aplikasi
# tidak menyala kembali sesudah mundur — kegagalan kedua yang lahir dari kegagalan pertama.
compose_mundur=("${compose[@]}")

if [ -f "$BERKAS_COMPOSE_SEHAT" ]; then
    compose_mundur=(docker compose --project-name "$PROYEK" --env-file "$BERKAS_ENV" -f "$BERKAS_COMPOSE_SEHAT")
fi

versi_sehat=''
[ -f "$BERKAS_VERSI_SEHAT" ] && versi_sehat="$(cat "$BERKAS_VERSI_SEHAT")"

printf '\n'
printf 'Edisi        : %s\n' "${edisi:-(tidak ada; satu image untuk semua klien)}"
printf 'Rilis        : %s\n' "$rilis"
printf 'Image        : %s\n' "$image"
printf 'Versi sehat  : %s\n' "${versi_sehat:-(belum ada; ini pemasangan pertama)}"

# --- 3b. Image rilis v2 di mesin ini ----------------------------------------------------------------
#
# Hanya untuk v2, dan sebelum pencadangan: lihat bagian atas berkas ini. Tidak ada `docker pull` di jalur ini.
# Image yang hilang tidak ditarik dari mana pun — Compose pun tidak, karena setiap layanannya
# `pull_policy: never`.

# periksa_image_lokal TAG_LOKAL REPOSITORI@DIGEST
periksa_image_lokal() {
    local lokal="$1" sumber="$2" daftar baris

    if ! daftar="$(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$lokal" 2>/dev/null)"; then
        gagal \
            "Image $lokal tidak ada di mesin ini." \
            '' \
            'Skrip ini tidak menarik image rilis v2. Agen situs menariknya lewat digest, memeriksanya, lalu memberinya' \
            'tag lokal sebelum menjalankan skrip ini. Minta pembaruan ulang dari admin.erp.'
    fi

    # Dicocokkan ujungnya, dengan garis miring di depan: host registry sengaja tidak dikenal skrip ini, tetapi
    # repositori `lain/coreerp/core` yang kebetulan berakhiran sama tetap tidak boleh lolos sebagai `coreerp/core`.
    while IFS= read -r baris; do
        if [[ "$baris" == */"$sumber" ]]; then
            printf '    %s\n' "$lokal"
            return 0
        fi
    done <<< "$daftar"

    gagal \
        "Image $lokal bukan image yang disebut manifest." \
        "  manifest: $sumber" \
        '' \
        'Tidak ada RepoDigests image itu yang berakhiran digest yang ditandatangani: tag lokalnya menunjuk image' \
        'lain. Container tidak disentuh. Minta pembaruan ulang dari admin.erp supaya agen menarik dan memberi' \
        'tag ulang image yang benar.'
}

if [ "$versi_manifest" = 2 ]; then
    langkah 'Memeriksa image rilis di mesin ini'

    while IFS=$'\t' read -r lokal sumber; do
        periksa_image_lokal "$lokal" "$sumber"
    done <<< "$daftar_image"
fi

# --- 4. Cadangkan database -----------------------------------------------------------------------

berkas_cadangan=''

if [ -n "$versi_sehat" ]; then
    langkah 'Mencadangkan database'

    berkas_cadangan="$FOLDER_CADANGAN/core_erp-sebelum-$rilis-$(date -u +%Y%m%dT%H%M%SZ).dump"

    EDITION_IMAGE="$versi_sehat" "${compose[@]}" up -d --wait core-db >/dev/null

    if ! EDITION_IMAGE="$versi_sehat" "${compose[@]}" exec -T core-db \
        pg_dump -U core_erp -d core_erp -Fc > "$berkas_cadangan"; then
        rm -f "$berkas_cadangan"
        gagal 'Pencadangan database gagal; pembaruan dihentikan sebelum apa pun berubah.'
    fi

    printf '    %s (%s)\n' "$berkas_cadangan" "$(du -h "$berkas_cadangan" | cut -f1)"
else
    langkah 'Melewati pencadangan: belum ada versi terpasang'
fi

# --- 5. Muat atau tarik image --------------------------------------------------------------------
#
# Bukan untuk v2: image-nya sudah diperiksa di 3b, dan skrip ini tidak memuat maupun menariknya.

if [ "$versi_manifest" = 2 ]; then
    :
elif [ "$membawa_image" -eq 1 ]; then
    langkah 'Memuat image dari bundle'

    gzip -dc "$bundle/images.tar.gz" | docker load >/dev/null
else
    langkah 'Menarik image dari registry'

    # Daftar pendamping dibaca dari manifest yang sudah lolos tanda tangan dan checksum. Manifest dibaca
    # utuh sebagai satu baris dulu, karena alur rilis boleh menulis larik ini melintasi beberapa baris.
    pendamping="$(tr -d '\n\r' < "$bundle/manifest.json" \
        | sed -n 's/.*"image_pendamping"[[:space:]]*:[[:space:]]*\[\([^]]*\)\].*/\1/p' \
        | tr ',' '\n' | sed 's/^[[:space:]]*"//; s/"[[:space:]]*$//' | sed '/^$/d')"

    # Image edisi yang disebut lewat digest tidak dapat berubah isi, jadi yang sudah ada tidak ditarik
    # ulang — server yang internetnya putus-sambung tetap dapat mengulang pembaruan yang terhenti.
    # Yang disebut lewat tag selalu ditarik: tag dapat dipindahkan.
    if [[ "$image" == *@sha256:* ]] && docker image inspect "$image" >/dev/null 2>&1; then
        printf '    sudah ada: %s\n' "$image"
    else
        printf '    menarik:   %s\n' "$image"
        docker pull --quiet "$image" >/dev/null || gagal "Image $image gagal ditarik dari registry."
    fi

    # Image pendamping hanya ditarik bila belum ada. Menariknya ulang setiap pembaruan berarti
    # PostgreSQL dapat berganti isi di bawah tag yang sama tanpa rilis kita berubah; menyematkannya
    # lewat digest dicatat sebagai pekerjaan lanjutan di rancangan on-prem dikelola.
    while IFS= read -r satu; do
        [ -n "$satu" ] || continue

        if docker image inspect "$satu" >/dev/null 2>&1; then
            printf '    sudah ada: %s\n' "$satu"
        else
            printf '    menarik:   %s\n' "$satu"
            docker pull --quiet "$satu" >/dev/null || gagal "Image pendamping $satu gagal ditarik dari registry."
        fi
    done <<< "$pendamping"
fi

if [ "$versi_manifest" != 2 ]; then
    digest_terpasang="$(docker image inspect "$image" --format '{{.Id}}' 2>/dev/null || true)"

    [ -n "$digest_terpasang" ] || gagal "Image $image tidak ada sesudah dimuat atau ditarik."

    if [ -n "$digest" ] && [ "$digest" != "$digest_terpasang" ]; then
        gagal \
            'Image yang dimuat bukan image yang disebut manifest.' \
            "  manifest : $digest" \
            "  terpasang: $digest_terpasang" \
            '' \
            'Hampir selalu ini berarti sudah ada image lain bertag sama di mesin ini.'
    fi

    printf '    %s\n' "$image"
fi

# --- 6-8. Ganti container, migrasi, periksa kesehatan --------------------------------------------

mundur() {
    local sebab="$1"

    printf '\n' >&2
    printf 'Pembaruan gagal: %s\n' "$sebab" >&2

    if [ -z "$versi_sehat" ]; then
        gagal \
            'Tidak ada versi yang terbukti pernah sehat untuk dituju.' \
            '' \
            'Skrip ini berhenti alih-alih menebak. Container dibiarkan seperti adanya supaya' \
            'keadaannya dapat diperiksa; jangan menjalankan ulang sebelum sebab kegagalannya' \
            'diketahui.' \
            '' \
            "Log: docker compose --project-name $PROYEK logs core-app"
    fi

    printf 'Mundur ke %s...\n' "$versi_sehat" >&2

    EDITION_IMAGE="$versi_sehat" "${compose_mundur[@]}" up -d --wait core-db >/dev/null 2>&1 || true

    if [ -n "$berkas_cadangan" ] && [ -f "$berkas_cadangan" ]; then
        printf 'Memulihkan database dari cadangan...\n' >&2

        # Container aplikasi dihentikan lebih dulu. Selama mereka hidup, koneksinya menahan
        # database dan penghapusannya di bawah akan ditolak.
        EDITION_IMAGE="$versi_sehat" "${compose_mundur[@]}" stop \
            core-app core-worker core-scheduler >/dev/null 2>&1 || true

        # Database dibuang lalu dibuat ulang, **bukan** dipulihkan di atas yang lama.
        #
        # Ini ditemukan dengan menjalankannya, bukan dengan membacanya. `pg_restore --clean` hanya
        # membuang objek yang ada **di dalam dump**; tabel yang dibuat migrasi yang gagal lahir
        # sesudah cadangan diambil, jadi ia tidak pernah tersentuh dan tetap berdiri sesudah mundur.
        # Akibatnya skema hasil mundur bukan skema versi lama, melainkan campuran keduanya — dan
        # campuran itu tidak pernah diuji siapa pun.
        if ! EDITION_IMAGE="$versi_sehat" "${compose_mundur[@]}" exec -T core-db psql -U core_erp -d postgres -q \
            -c "select pg_terminate_backend(pid) from pg_stat_activity where datname = 'core_erp' and pid <> pg_backend_pid();" \
            -c 'drop database if exists core_erp;' \
            -c 'create database core_erp owner core_erp;' >/dev/null 2>&1; then
            gagal \
                'Gagal menyiapkan database kosong untuk pemulihan.' \
                '' \
                "Cadangannya ada dan utuh: $berkas_cadangan" \
                'Jangan menyalakan aplikasi. Hubungi penerbitnya dengan membawa berkas cadangan itu.'
        fi

        if ! EDITION_IMAGE="$versi_sehat" "${compose_mundur[@]}" exec -T core-db \
            pg_restore -U core_erp -d core_erp --no-owner < "$berkas_cadangan" >/dev/null 2>&1; then
            gagal \
                'Pemulihan database GAGAL sesudah pembaruan gagal.' \
                '' \
                "Cadangannya ada dan utuh: $berkas_cadangan" \
                'Jangan menyalakan aplikasi. Hubungi penerbitnya dengan membawa berkas cadangan itu.'
        fi

        printf 'Database dipulihkan.\n' >&2
    fi

    if ! EDITION_IMAGE="$versi_sehat" "${compose_mundur[@]}" up -d --remove-orphans \
        core-app core-worker core-scheduler core-renderer >/dev/null 2>&1; then
        gagal \
            "Sistem GAGAL dinyalakan kembali pada $versi_sehat sesudah pembaruan gagal." \
            '' \
            'Databasenya sudah dipulihkan, jadi datanya utuh; yang tidak menyala adalah' \
            'containernya.' \
            '' \
            "Log: docker compose --project-name $PROYEK logs core-app"
    fi

    gagal \
        "Pembaruan dibatalkan; sistem kembali ke $versi_sehat." \
        '' \
        'Data yang ditulis setelah pembaruan dimulai tidak ikut kembali — itu memang sifat' \
        'pemulihan dari cadangan.' \
        '' \
        "Log versi yang gagal: docker compose --project-name $PROYEK logs core-migrate core-app"
}

langkah 'Menjalankan migrasi'

if ! EDITION_IMAGE="$image" "${compose[@]}" up -d --wait core-db >/dev/null; then
    mundur 'database tidak menjadi sehat'
fi

if ! EDITION_IMAGE="$image" "${compose[@]}" up --exit-code-from core-migrate core-migrate; then
    mundur 'migrasi gagal'
fi

langkah 'Menyalakan aplikasi'

if ! EDITION_IMAGE="$image" "${compose[@]}" up -d --remove-orphans \
    core-app core-worker core-scheduler core-renderer >/dev/null; then
    mundur 'container aplikasi gagal dinyalakan'
fi

langkah 'Memeriksa kesehatan'

nama_app="$(EDITION_IMAGE="$image" "${compose[@]}" ps -q core-app)"

[ -n "$nama_app" ] || mundur 'container core-app tidak ditemukan sesudah dinyalakan'

batas="$(( $(date +%s) + BATAS_SEHAT_DETIK ))"
keadaan='starting'

while [ "$(date +%s)" -lt "$batas" ]; do
    keadaan="$(docker inspect --format '{{.State.Health.Status}}' "$nama_app" 2>/dev/null || echo 'tidak-ada')"

    case "$keadaan" in
        healthy) break ;;
        unhealthy) mundur 'pemeriksaan kesehatan menyatakan aplikasi tidak sehat' ;;
    esac

    sleep 5
done

[ "$keadaan" = 'healthy' ] || mundur "aplikasi tidak menjadi sehat dalam $BATAS_SEHAT_DETIK detik"

printf '    core-app sehat\n'

# --- 9. Catat versi sehat ------------------------------------------------------------------------
#
# Ditulis **sesudah** sehat, bukan sebelum. Versi yang dicatat sebelum terbukti sehat akan menjadi
# sasaran mundur pada pembaruan berikutnya — dan itu berarti mundur ke versi yang tidak pernah
# diketahui bekerja.

printf '%s' "$image" > "$BERKAS_VERSI_SEHAT"

# Compose-nya ikut disimpan, bukan hanya nama image-nya. Mundur menuntut keduanya: image yang
# terbukti sehat, dan berkas compose yang terbukti menyalakannya.
cp "$bundle/compose.yaml" "$BERKAS_COMPOSE_SEHAT"

printf '\n'
if [ -n "$edisi" ]; then
    printf 'Pembaruan selesai. Edisi "%s" rilis %s berjalan.\n' "$edisi" "$rilis"
else
    printf 'Pembaruan selesai. Rilis %s berjalan.\n' "$rilis"
fi

if [ -n "$berkas_cadangan" ]; then
    printf 'Cadangan sebelum pembaruan disimpan: %s\n' "$berkas_cadangan"
fi

printf '\nModule yang dilayani:\n'
EDITION_IMAGE="$image" "${compose[@]}" exec -T core-app php artisan module:list || true
