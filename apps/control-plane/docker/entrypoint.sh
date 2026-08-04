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
case "${CONTAINER_ROLE:-web}" in
    web)
        exec apache2-foreground
        ;;
    scheduler)
        # schedule:work ticks once a minute in-process. number-sequences:recover also guards itself with
        # onOneServer, which requires a shared cache store (database or redis) — never file or array.
        exec php artisan schedule:work --no-interaction
        ;;
    worker)
        exec php artisan queue:work --no-interaction --tries=3 --max-time=3600
        ;;
    *)
        echo "Unknown CONTAINER_ROLE: ${CONTAINER_ROLE}. Expected web, scheduler, or worker." >&2
        exit 1
        ;;
esac
