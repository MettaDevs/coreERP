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
    chown -R www-data:www-data /var/www/html/storage/app /var/www/html/storage/framework /var/www/html/storage/logs 2>/dev/null || true
}

case "${CONTAINER_ROLE:-web}" in
    web)
        # Konten UI app disajikan same-origin di /apps-content/<placement>/<app>/, dari registry
        # placement — bukan dari nilai yang ditulis tangan. Config ini statis, jadi placement yang
        # dibuat setelah container hidup baru dilayani setelah restart. Bila registry belum bisa
        # dibaca (DB belum siap, migrasi belum jalan), core tetap naik dengan config kosong: shell
        # sendiri masih berfungsi, hanya iframe app-nya yang gagal dengan pesan yang jelas.
        php artisan app:render-proxy-config \
            --target=apache \
            --allow-empty \
            --output=/etc/apache2/conf-enabled/coreerp-apps-content.conf \
            || echo "Peringatan: config proxy /apps-content gagal dirender; app tidak akan termuat." >&2
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
