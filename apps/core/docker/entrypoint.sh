#!/bin/sh
set -e

# One image, three roles. The deployment decides which one a container plays; nothing about the build differs.
#
#   web       serves HTTP. Scale this freely: no counter or pool state lives in instance memory.
#   scheduler runs Laravel's scheduler. Exactly ONE replica per cluster.
#   worker    processes queued jobs.
#
# Without a scheduler container, number-sequences:recover never runs and expired continuous reservations are never
# moved to reconciliation_pending, so their pool numbers stay reserved forever.
# Ownership of storage/app follows www-data on every start. The directory is a shared volume
# between roles, so anything an earlier root-run process left behind would otherwise block
# www-data from writing next to it.
drop_to_www_data() {
    mkdir -p /repo/apps/core/storage/logs /repo/apps/core/storage/framework/views /repo/apps/core/storage/framework/sessions /repo/apps/core/storage/framework/cache 2>/dev/null || true
    chown -R www-data:www-data /repo/apps/core/storage /repo/apps/core/bootstrap/cache 2>/dev/null || true
    chmod -R 775 /repo/apps/core/storage /repo/apps/core/bootstrap/cache 2>/dev/null || true
}

# Setelan dan rute di-cache saat container naik, bukan saat image dibangun.
#
# Bedanya menentukan: `config:cache` membekukan nilai env ke dalam berkas hasilnya, dan satu image
# dipakai banyak deployment dengan env yang berbeda. Membangunnya saat build berarti mengirim
# setelan milik mesin pembangun ke server pelanggan.
#
# Ketiga peran membayar bootstrap yang sama — web, scheduler, dan worker semuanya memuat Laravel —
# jadi keduanya dibangun di sini, sebelum peran dipilih. Sampai 10 September 2026 tidak ada satu
# pun langkah penyebaran yang memanggil keduanya, jadi setiap permintaan membaca dan menggabungkan
# ulang seluruh berkas `config/` lalu mendaftarkan ulang ratusan rute. Itu tidak pernah terlihat
# sebagai kesalahan, hanya sebagai latensi.
#
# Gagal dengan peringatan, bukan dengan berhenti: tanpa cache aplikasi tetap benar, hanya lebih
# lambat. Sebuah container yang menolak naik karena cache-nya gagal menukar kelambatan dengan mati.
bangun_cache() {
    php artisan config:cache \
        || echo "Peringatan: config:cache gagal; setiap permintaan akan membaca ulang seluruh berkas config." >&2
    php artisan route:cache \
        || echo "Peringatan: route:cache gagal; setiap permintaan akan mendaftarkan ulang seluruh rute." >&2
}

# Berkas keadaan server Octane ditaruh di /tmp milik container, bukan `storage/logs` yang dipakai bersama
# peran lain dan replika web lain lewat volume, supaya tidak saling menimpa. Diekspor sebelum config
# di-cache, karena `config/octane.php` membacanya lewat env().
export OCTANE_STATE_FILE="${OCTANE_STATE_FILE:-/tmp/octane-server-state.json}"

bangun_cache

case "${CONTAINER_ROLE:-web}" in
    web)
        # FrankenPHP dalam mode worker lewat Octane: Laravel di-boot sekali per worker, bukan per
        # permintaan. Port tetap 80 supaya proxy dan healthcheck di depan container tidak berubah;
        # binari frankenphp membawa CAP_NET_BIND_SERVICE, jadi www-data boleh membukanya.
        #
        # Worker bawaan `auto`: FrankenPHP memakai dua kali jumlah CPU yang ia lihat, termasuk batas CPU
        # container (2 CPU → 4 worker). Jangan dinaikkan tanpa mengukur: 3 Oktober 2026, endpoint aset,
        # 2 CPU, 350 pengguna serentak, bergantian dua putaran — `auto` lulus (p95 320 dan 376 ms),
        # 8 worker (p95 1,6–1,8 dtk) dan 16 worker (p95 0,55–0,8 dtk) gagal. Worker tambahan hanya
        # berebut CPU yang sama. Setelah OCTANE_MAX_REQUESTS permintaan worker diganti baru, supaya
        # kebocoran memori yang belum ketahuan tidak menumpuk tanpa batas.
        #
        # `variables_order=EGPCS` mengikuti contoh dokumentasi Octane: php.ini-production (image
        # on-prem) memakai GPCS, yang mengosongkan $_ENV untuk proses yang menyalakan server.
        #
        # FRANKENPHP_WORKER=0 hanya untuk pengembangan dengan kode di-mount (`start.ps1 -HotReload` di
        # erp-dev): FrankenPHP tanpa mode worker, Laravel di-boot ulang tiap permintaan seperti Apache,
        # jadi suntingan PHP terbaca di permintaan berikutnya. Mode worker tidak bisa dipakai di sana.
        # Worker yang menyala sebelum suntingan lalu menganggur memegang kelas yang dimuatnya saat boot,
        # dan `--max-requests=1` baru mengganti worker sesudah ia melayani satu permintaan — diukur
        # 3 Oktober 2026, ±15% permintaan pada detik-detik pertama sesudah suntingan masih menjawab isi
        # lama. Watcher FrankenPHP (`--watch`) juga tidak menolong: ia bersandar pada inotify, dan
        # perubahan dari Windows tidak pernah sampai lewat bind mount Docker Desktop.
        drop_to_www_data
        if [ "${FRANKENPHP_WORKER:-1}" = '0' ]; then
            exec runuser -u www-data -- frankenphp php-server --listen :80 --root /repo/apps/core/public
        fi
        exec runuser -u www-data -- php -d variables_order=EGPCS artisan octane:frankenphp --no-interaction \
            --host=0.0.0.0 --port=80 --admin-port=2019 \
            --workers="${OCTANE_WORKERS:-auto}" --max-requests="${OCTANE_MAX_REQUESTS:-500}" \
            --log-level="${OCTANE_LOG_LEVEL:-warn}"
        ;;
    scheduler)
        # schedule:work ticks once a minute in-process. number-sequences:recover also guards itself with
        # onOneServer, which requires a shared cache store (database or redis) — never file or array.
        drop_to_www_data
        exec runuser -u www-data -- php artisan schedule:work --no-interaction
        ;;
    worker)
        # Same user as the web role's PHP. The worker writes report exports and layout copies into
        # storage/app, which the web role must then read and serve; a root-owned file there is
        # invisible to www-data and the download answers 410 for a file that exists.
        drop_to_www_data
        exec runuser -u www-data -- php artisan queue:work --no-interaction --tries=3 --max-time=3600
        ;;
    *)
        echo "Unknown CONTAINER_ROLE: ${CONTAINER_ROLE}. Expected web, scheduler, or worker." >&2
        exit 1
        ;;
esac
