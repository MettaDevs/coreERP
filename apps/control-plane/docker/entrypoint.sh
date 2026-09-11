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
    chown -R www-data:www-data /repo/apps/control-plane/storage/app /repo/apps/control-plane/storage/framework /repo/apps/control-plane/storage/logs 2>/dev/null || true
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

bangun_cache

case "${CONTAINER_ROLE:-web}" in
    web)
        exec apache2-foreground
        ;;
    scheduler)
        # schedule:work ticks once a minute in-process. number-sequences:recover also guards itself with
        # onOneServer, which requires a shared cache store (database or redis) — never file or array.
        drop_to_www_data
        exec runuser -u www-data -- php artisan schedule:work --no-interaction
        ;;
    worker)
        # Same user as Apache's PHP. The worker writes report exports and layout copies into
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
