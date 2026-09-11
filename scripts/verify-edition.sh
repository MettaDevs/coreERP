#!/usr/bin/env bash
#
# Membuktikan bahwa modul yang tidak dibeli tidak ada di dalam image edisi.
#
# Ini pemeriksaan yang membuat seluruh model lisensi berdiri. Klaimnya bukan "modul yang tidak
# dibeli dimatikan", melainkan "modul yang tidak dibeli **tidak ada** di server pelanggan" — dan
# klaim sekuat itu harus dibuktikan mesin pada image yang benar-benar dikirim, bukan dijanjikan
# di dokumen.
#
# Tiga jalur diperiksa, karena satu modul meninggalkan jejak di tiga tempat yang berbeda dan
# ketiganya bisa bocor sendiri-sendiri:
#
#   1. Berkas dan nama namespace di dalam image.
#   2. Tabel yang terbentuk ketika migration dijalankan ke database kosong.
#   3. Bundel JavaScript, yang dibangun dari folder UI modul lewat pola glob.
#
# Pemakaian:
#   scripts/verify-edition.sh <edisi> <image> [--anggap-tidak-dibeli <id modul>]
#
# `--anggap-tidak-dibeli` memperlakukan sebuah modul yang **memang dibeli** seolah tidak dibeli.
# Ia ada untuk satu tujuan: membuktikan pemeriksa ini bisa merah. Sebuah pemeriksa kebocoran
# yang belum pernah gagal tidak dapat dibedakan dari pemeriksa yang tidak memeriksa apa pun, dan
# yang kedua jauh lebih berbahaya karena ia mengakhiri pencarian.

set -euo pipefail

akar_repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

pakai() {
    echo "Pemakaian: scripts/verify-edition.sh <edisi> <image> [--anggap-tidak-dibeli <id modul>]" >&2
    exit 2
}

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

gagal() {
    echo >&2
    echo "KEBOCORAN EDISI: $*" >&2
    exit 1
}

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

if [ -z "${tidak_dibeli// /}" ]; then
    gagal "tidak ada satu pun modul yang terlarang untuk edisi ini, jadi ketiga pemeriksaan di bawah tidak dapat membuktikan apa pun. Tambahkan modul kedua ke repo, atau periksa edisi lain."
fi

# ---------------------------------------------------------------------------
# 1. Berkas dan nama namespace di dalam image.
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

echo "1/3 berkas dan namespace: bersih."

# ---------------------------------------------------------------------------
# 2. Tabel yang terbentuk dari migration.
# ---------------------------------------------------------------------------
#
# Migration Core saja tidak cukup: tabel modul dibuat `module:migrate`, bukan `migrate`, jadi
# database yang hanya dimigrasi Core tidak akan pernah punya tabel modul — dan pemeriksaan yang
# berdasar itu selalu hijau tanpa memeriksa apa pun. Karena itu setiap modul yang **ada di dalam
# image** ikut dimigrasikan lebih dulu; barulah daftar tabelnya berarti.

if [ -z "${DB_HOST:-}" ]; then
    echo "2/3 tabel: dilewati, DB_HOST tidak disetel." >&2
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

    echo "2/3 tabel: bersih."
fi

# ---------------------------------------------------------------------------
# 3. Bundel JavaScript.
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

echo "3/3 bundel: bersih."
echo
echo "Edisi \"$edisi\" bersih: tidak satu pun modul terlarang ditemukan pada ketiga jalur."
