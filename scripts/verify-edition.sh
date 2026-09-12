#!/usr/bin/env bash
#
# Membuktikan bahwa modul yang tidak dibeli tidak ada di dalam image edisi.
#
# Ini pemeriksaan yang membuat seluruh model lisensi berdiri. Klaimnya bukan "modul yang tidak
# dibeli dimatikan", melainkan "modul yang tidak dibeli **tidak ada** di server pelanggan" — dan
# klaim sekuat itu harus dibuktikan mesin pada image yang benar-benar dikirim, bukan dijanjikan
# di dokumen.
#
# Empat jalur diperiksa. Tiga di antaranya menelusuri jejak satu modul, yang meninggalkan bekas
# di tiga tempat berbeda dan bisa bocor sendiri-sendiri; yang pertama tidak berurusan dengan
# modul sama sekali:
#
#   1. Folder aplikasi di dalam image: hanya `apps/core` yang boleh ada di sana.
#   2. Berkas dan nama namespace modul di dalam image.
#   3. Tabel yang terbentuk ketika migration dijalankan ke database kosong.
#   4. Bundel JavaScript, yang dibangun dari folder UI modul lewat pola glob.
#
# Jalur pertama berdiri di luar daftar modul dengan sengaja, karena yang dijaganya bukan lisensi
# melainkan bentuk image. Repo ini berisi lebih dari satu aplikasi — `provider-console`,
# `web-shell`, dan pusat admin — dan tidak satu pun dari mereka dijual, dipasang, atau boleh
# berjalan di server pelanggan; pusat admin bahkan memegang data seluruh pelanggan sekaligus.
# Karena tidak bergantung pada `$tidak_dibeli`, ia tetap berarti pada edisi yang membeli semua
# modul, ketika ketiga jalur di bawahnya tidak punya apa pun untuk dicari.
#
# Ia ditulis sebagai daftar-boleh, bukan daftar-larang atas nama `control-plane`: aplikasi kelima
# yang ditambahkan seseorang tahun depan tertangkap tanpa ada yang perlu ingat mendaftarkannya.
#
# Pemakaian:
#   scripts/verify-edition.sh <edisi> <image> [--anggap-tidak-dibeli <id modul>]
#   scripts/verify-edition.sh --buktikan-aplikasi-bisa-merah
#
# Dua bentuk itu ada untuk satu tujuan yang sama: membuktikan pemeriksa ini bisa merah. Sebuah
# pemeriksa kebocoran yang belum pernah gagal tidak dapat dibedakan dari pemeriksa yang tidak
# memeriksa apa pun, dan yang kedua jauh lebih berbahaya karena ia mengakhiri pencarian.
#
# `--anggap-tidak-dibeli` memperlakukan sebuah modul yang **memang dibeli** seolah tidak dibeli,
# sehingga ketiga jalur modul harus merah. `--buktikan-aplikasi-bisa-merah` membangun dua image
# sekali pakai berisi dua baris — satu hanya berisi `apps/core`, satu lagi ditambah
# `apps/control-plane` — lalu menuntut jalur pertama hijau pada yang pertama dan merah pada yang
# kedua. Image edisi tidak dibangun ulang untuk itu.

set -euo pipefail

akar_repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

pakai() {
    echo "Pemakaian: scripts/verify-edition.sh <edisi> <image> [--anggap-tidak-dibeli <id modul>]" >&2
    echo "           scripts/verify-edition.sh --buktikan-aplikasi-bisa-merah" >&2
    exit 2
}

gagal() {
    echo >&2
    echo "KEBOCORAN EDISI: $*" >&2
    exit 1
}

# ---------------------------------------------------------------------------
# Jalur 1: folder aplikasi di dalam image.
# ---------------------------------------------------------------------------
#
# Satu-satunya aplikasi yang dikirim ke pelanggan. Daftarnya ditulis sebagai yang **boleh**,
# bukan yang dilarang, supaya aplikasi yang belum ada hari ini ikut terjaga: yang bocor besok
# adalah yang belum sempat didaftarkan siapa pun.
#
# Menambah satu baris di sini berarti menyatakan aplikasi itu memang dijual dan memang berjalan
# di server pelanggan. Itu keputusan produk, bukan keputusan pembangunan.
APLIKASI_BOLEH=(core)

# Isi `/repo/apps` harus **persis** sama dengan daftar di atas, dan kedua arahnya diperiksa.
#
# Arah yang jelas: folder aplikasi yang tidak ada di daftar berarti sesuatu ikut terkirim. Arah
# yang mudah terlupakan: `core` yang justru tidak ada berarti pemindaian ini salah alamat —
# susunan image berubah, path-nya meleset, atau `docker run` menjalankan image lain — dan
# pemeriksaan yang tidak menemukan apa-apa akan melaporkan hijau persis seperti pemeriksaan yang
# tidak menemukan pelanggaran. Keduanya hanya dapat dibedakan kalau yang seharusnya ada dituntut
# ada.
#
# Yang dihitung folder, bukan berkas: sebuah aplikasi adalah pohon berkas, dan foldernya ada di
# image bahkan ketika isinya kosong.
periksa_aplikasi() {
    local image="$1" label="$2"

    local terbaca
    # Enumerasi dikerjakan di dalam container, seperti pemeriksaan lain di berkas ini:
    # mengekspor image ke runner lalu membongkarnya jauh lebih lambat dan menjawab hal yang sama.
    terbaca="$(docker run --rm --entrypoint sh "$image" -c '
        for folder in /repo/apps/*/; do
            if [ -d "$folder" ]; then
                folder="${folder%/}"
                printf "%s\n" "${folder##*/}"
            fi
        done
    ')" || gagal "isi /repo/apps tidak dapat dibaca dari image \"$image\""

    local aplikasi boleh
    local terlarang=() hilang=()

    while IFS= read -r aplikasi; do
        [ -n "$aplikasi" ] || continue
        local diizinkan=tidak
        for boleh in "${APLIKASI_BOLEH[@]}"; do
            if [ "$aplikasi" = "$boleh" ]; then
                diizinkan=ya
            fi
        done
        if [ "$diizinkan" = 'tidak' ]; then
            terlarang+=("$aplikasi")
        fi
    done <<< "$terbaca"

    for boleh in "${APLIKASI_BOLEH[@]}"; do
        if ! printf '%s\n' "$terbaca" | grep -qx "$boleh"; then
            hilang+=("$boleh")
        fi
    done

    if [ ${#hilang[@]} -gt 0 ]; then
        echo >&2
        echo "Yang terbaca di /repo/apps:" >&2
        printf '%s\n' "$terbaca" >&2
        gagal "aplikasi \"${hilang[*]}\" tidak ada di /repo/apps pada image \"$image\"; pemindaiannya salah alamat dan hasil hijaunya tidak berarti apa-apa"
    fi

    if [ ${#terlarang[@]} -gt 0 ]; then
        echo >&2
        echo "Folder aplikasi di dalam image:" >&2
        printf '%s\n' "$terbaca" >&2
        gagal "folder aplikasi \"${terlarang[*]}\" ikut ke dalam image edisi \"$label\"; hanya \"${APLIKASI_BOLEH[*]}\" yang dikirim ke pelanggan"
    fi
}

# Membuktikan bahwa `periksa_aplikasi` bisa merah — tanpa membangun ulang image edisi.
#
# Jalur merahnya dibuat dengan image sekali pakai berisi dua baris, pola yang sama dipakai
# `scripts/periksa-sisa-mesin.sh buktikan-merah`. Dua sasaran dibangun dari satu berkas, dan yang
# kedua menumpuk di atas yang pertama sehingga lapisannya dipakai ulang: satu image bersih yang
# hanya berisi `apps/core`, satu lagi ditambah `apps/control-plane`.
#
# Keduanya diperiksa, bukan hanya yang bocor. Pemeriksa yang selalu merah sama tidak berartinya
# dengan pemeriksa yang selalu hijau — bedanya ia tidak bertahan lama, karena orang pertama yang
# terhalang olehnya akan mematikannya.
buktikan_aplikasi_bisa_merah() {
    command -v docker >/dev/null 2>&1 || gagal 'perintah `docker` tidak ada di PATH'

    local sementara
    sementara="$(mktemp -d)"

    printf '%s\n' \
        'FROM alpine AS bersih' \
        'RUN mkdir -p /repo/apps/core' \
        'FROM bersih AS bocor' \
        'RUN mkdir -p /repo/apps/control-plane && echo "{}" > /repo/apps/control-plane/composer.json' \
        > "$sementara/Merah.Dockerfile"

    local bersih='coreerp-aplikasi:uji-bersih'
    local bocor='coreerp-aplikasi:uji-bocor'

    docker build --quiet --target bersih --file "$sementara/Merah.Dockerfile" --tag "$bersih" "$sementara" >/dev/null
    docker build --quiet --target bocor --file "$sementara/Merah.Dockerfile" --tag "$bocor" "$sementara" >/dev/null

    # Subshell, bukan pemanggilan biasa: `gagal` menutup proses dengan `exit`, dan `exit` di dalam
    # fungsi mengakhiri seluruh skrip — termasuk langkah pembersihan di bawah.
    local kode_bersih=0 keluaran_bersih=''
    keluaran_bersih="$( ( periksa_aplikasi "$bersih" uji-bersih ) 2>&1 )" || kode_bersih=$?

    local kode_bocor=0 keluaran_bocor=''
    keluaran_bocor="$( ( periksa_aplikasi "$bocor" uji-bocor ) 2>&1 )" || kode_bocor=$?

    docker image rm --force "$bersih" "$bocor" >/dev/null 2>&1 || true
    rm -rf "$sementara"

    if [ "$kode_bersih" -ne 0 ]; then
        printf '%s\n' "$keluaran_bersih" >&2
        gagal 'pemeriksa aplikasi merah pada image yang isinya hanya `apps/core`, yaitu pada image yang benar. Ia merah tanpa sebab.'
    fi

    if [ "$kode_bocor" -eq 0 ]; then
        gagal 'pemeriksa aplikasi hijau pada image yang memuat `apps/control-plane`. Ia tidak memeriksa apa pun.'
    fi

    printf '%s\n' "$keluaran_bocor"
    printf 'Pemeriksa aplikasi hijau pada image bersih, lalu gagal dengan kode keluar %d pada image yang memuat `apps/control-plane`.\n' "$kode_bocor"
}

if [ "${1:-}" = '--buktikan-aplikasi-bisa-merah' ]; then
    [ "$#" -eq 1 ] || pakai
    buktikan_aplikasi_bisa_merah
    exit 0
fi

[ "$#" -ge 2 ] || pakai

edisi="$1"
image="$2"
shift 2

anggap_tidak_dibeli=""

while [ "$#" -gt 0 ]; do
    case "$1" in
        --anggap-tidak-dibeli)
            [ "$#" -ge 2 ] || pakai
            anggap_tidak_dibeli="$2"
            shift 2
            ;;
        *)
            echo "Argumen tidak dikenal: $1" >&2
            pakai
            ;;
    esac
done

# ---------------------------------------------------------------------------
# Daftar modul: yang dibeli, dan yang tidak.
# ---------------------------------------------------------------------------
#
# Dihitung di sini, di luar image, dan sengaja begitu: kalau daftarnya dibaca dari dalam image,
# image yang bocor akan menghitung dirinya sendiri sebagai benar.

dibeli="$(cd "$akar_repo/apps/core" && php artisan edition:resolve "$edisi" --daftar)"

semua_modul=""
for folder in "$akar_repo"/modules/*/*/; do
    [ -f "$folder/app.yaml" ] || continue
    semua_modul="$semua_modul $(basename "$folder")"
done

[ -n "${semua_modul// /}" ] || gagal "tidak satu pun modul terbaca di $akar_repo/modules; pemindaiannya salah alamat dan hasil hijaunya tidak berarti apa-apa"

if [ -n "$anggap_tidak_dibeli" ]; then
    echo "Catatan: \"$anggap_tidak_dibeli\" diperlakukan seolah tidak dibeli, untuk membuktikan pemeriksa ini bisa merah."
    dibeli="$(printf '%s\n' "$dibeli" | grep -vx "$anggap_tidak_dibeli" || true)"
fi

tidak_dibeli=""
for modul in $semua_modul; do
    if printf '%s\n' "$dibeli" | grep -qx "$modul"; then
        continue
    fi
    tidak_dibeli="$tidak_dibeli $modul"
done

echo "Edisi   : $edisi"
echo "Image   : $image"
echo "Dibeli  :$(printf '%s' " $(printf '%s' "$dibeli" | tr '\n' ' ')")"
echo "Terlarang:$tidak_dibeli"

periksa_aplikasi "$image" "$edisi"

echo "1/4 aplikasi: bersih, tidak ada folder aplikasi di /repo/apps selain \"${APLIKASI_BOLEH[*]}\"."

if [ -z "${tidak_dibeli// /}" ]; then
    gagal "tidak ada satu pun modul yang terlarang untuk edisi ini, jadi ketiga pemeriksaan di bawah tidak dapat membuktikan apa pun. Tambahkan modul kedua ke repo, atau periksa edisi lain."
fi

# ---------------------------------------------------------------------------
# 2. Berkas dan nama namespace modul di dalam image.
# ---------------------------------------------------------------------------
#
# Yang dicari bukan hanya foldernya, tetapi juga namanya di metadata Composer dan pemetaan
# PSR-4. Nama bukan kode — tetapi klaimnya berbunyi "tidak ada", bukan "kodenya tidak ada", dan
# sebuah pemetaan PSR-4 yang tertinggal adalah bukti bahwa pemangkasannya terjadi setelah
# pemasangan, bukan sebelum.

for modul in $tidak_dibeli; do
    if docker run --rm --entrypoint sh "$image" -c "[ -e /repo/modules/*/$modul ] 2>/dev/null" 2>/dev/null; then
        gagal "folder modul \"$modul\" ada di dalam image edisi \"$edisi\""
    fi

    namespace="$(php -r '
        $berkas = $argv[1];
        if (! is_file($berkas)) { exit(0); }
        $isi = json_decode((string) file_get_contents($berkas), true);
        foreach (array_keys($isi["autoload"]["psr-4"] ?? []) as $awalan) {
            echo rtrim($awalan, "\\\\"), "\n";
            break;
        }
    ' "$akar_repo/modules/apperp/$modul/composer.json")"

    if [ -n "$namespace" ]; then
        # `grep -r` dijalankan di dalam container: mengekspor seluruh image ke runner lalu
        # menggeledahnya jauh lebih lambat, dan yang ditanyakan sama saja.
        if docker run --rm --entrypoint sh "$image" -c "grep -rlF '$namespace' /repo 2>/dev/null | head -5" | grep -q .; then
            echo >&2
            echo "Berkas yang menyebutnya:" >&2
            docker run --rm --entrypoint sh "$image" -c "grep -rlF '$namespace' /repo 2>/dev/null | head -20" >&2 || true
            gagal "namespace \"$namespace\" milik modul \"$modul\" masih disebut di dalam image edisi \"$edisi\""
        fi
    fi
done

echo "2/4 berkas dan namespace: bersih."

# ---------------------------------------------------------------------------
# 3. Tabel yang terbentuk dari migration.
# ---------------------------------------------------------------------------
#
# Migration Core saja tidak cukup: tabel modul dibuat `module:migrate`, bukan `migrate`, jadi
# database yang hanya dimigrasi Core tidak akan pernah punya tabel modul — dan pemeriksaan yang
# berdasar itu selalu hijau tanpa memeriksa apa pun. Karena itu setiap modul yang **ada di dalam
# image** ikut dimigrasikan lebih dulu; barulah daftar tabelnya berarti.

if [ -z "${DB_HOST:-}" ]; then
    echo "3/4 tabel: dilewati, DB_HOST tidak disetel." >&2
    echo "   Pemeriksaan ini butuh PostgreSQL kosong; di CI ia disediakan sebagai service container." >&2
else
    jalankan_artisan() {
        docker run --rm --network host \
            -e APP_ENV=production \
            -e APP_KEY="${APP_KEY:?APP_KEY wajib disetel untuk pemeriksaan tabel}" \
            -e DB_CONNECTION=pgsql \
            -e DB_HOST="$DB_HOST" \
            -e DB_PORT="${DB_PORT:-5432}" \
            -e DB_DATABASE="${DB_DATABASE:?}" \
            -e DB_USERNAME="${DB_USERNAME:?}" \
            -e DB_PASSWORD="${DB_PASSWORD:?}" \
            --entrypoint php "$image" artisan "$@"
    }

    jalankan_artisan migrate --force >/dev/null

    for modul in $(printf '%s\n' "$dibeli"); do
        [ -n "$modul" ] || continue
        jalankan_artisan module:migrate "$modul" >/dev/null
    done

    tabel="$(PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "$DB_USERNAME" -d "$DB_DATABASE" -At \
        -c "select tablename from pg_tables where schemaname = 'public'")"

    for modul in $tidak_dibeli; do
        awalan="$(php -r '
            $isi = @file_get_contents($argv[1]);
            if ($isi === false) { exit(0); }
            if (preg_match("/^table_prefix:\s*(\S+)/m", $isi, $cocok) === 1) { echo trim($cocok[1], "\"'"'"'"); }
        ' "$akar_repo/modules/apperp/$modul/app.yaml")"

        [ -n "$awalan" ] || continue

        if printf '%s\n' "$tabel" | grep -q "^$awalan"; then
            echo >&2
            printf '%s\n' "$tabel" | grep "^$awalan" >&2
            gagal "migration di dalam image edisi \"$edisi\" membuat tabel berawalan \"$awalan\", milik modul \"$modul\" yang tidak dibeli"
        fi
    done

    echo "3/4 tabel: bersih."
fi

# ---------------------------------------------------------------------------
# 4. Bundel JavaScript.
# ---------------------------------------------------------------------------
#
# Halaman modul masuk ke bundel lewat pola glob atas folder `modules/*/*/ui`, jadi yang
# menentukan adalah folder apa yang ada saat build berjalan. Pemeriksaan ini yang menagihnya:
# rute modul yang tidak dibeli di dalam bundel berarti foldernya masih ada waktu aset dibangun,
# walau ia sudah tidak ada di image akhir.
#
# **Yang dicari bentuk rute dan nama halamannya, bukan id modul telanjang, dan itu keputusan.**
# Id telanjang juga muncul di kode Core yang sah — `product-launcher.tsx` memetakan ikon per
# produk dengan id yang ditulis tangan — sehingga edisi Core-saja akan dinyatakan bocor karena
# berkas milik Core. Dua bentuk di bawah hanya bisa lahir dari kode modul: `"<id>::"` adalah
# awalan nama halaman Inertia milik modul, dan `"/<id>/"` adalah awalan rute layar maupun
# API-nya. Keduanya yang benar-benar dituntut task ini.
#
# Peta ikon yang menuliskan id produk di dalam kode Core tetap sebuah cacat yang akan menggigit
# pada modul kedua; ia dicatat sebagai pekerjaan tersendiri, bukan ditutup dengan melonggarkan
# pemeriksa ini.

for modul in $tidak_dibeli; do
    for bentuk in "$modul::" "/$modul/"; do
        if docker run --rm --entrypoint sh "$image" -c "grep -rlF '$bentuk' /repo/apps/core/public/build 2>/dev/null | head -5" | grep -q .; then
            echo >&2
            docker run --rm --entrypoint sh "$image" -c "grep -rlF '$bentuk' /repo/apps/core/public/build 2>/dev/null | head -20" >&2 || true
            gagal "bundel JavaScript pada image edisi \"$edisi\" memuat \"$bentuk\", milik modul \"$modul\" yang tidak dibeli"
        fi
    done
done

echo "4/4 bundel: bersih."
echo
echo "Edisi \"$edisi\" bersih: hanya \"${APLIKASI_BOLEH[*]}\" yang ada di /repo/apps, dan tidak satu pun modul terlarang ditemukan pada ketiga jalur modul."
