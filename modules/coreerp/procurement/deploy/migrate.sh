#!/bin/sh
set -eu

psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" <<'SQL'
CREATE TABLE IF NOT EXISTS coreerp_module_migrations (
    migration VARCHAR(255) PRIMARY KEY,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
SQL

for file in /coreerp/migrations/*.sql; do
    [ -f "$file" ] || continue
    migration=$(basename "$file")
    case "$migration" in
        *[!A-Za-z0-9_.-]*) echo "Invalid migration filename" >&2; exit 1 ;;
    esac
    [ "$(psql -At -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v migration="$migration" -c "SELECT 1 FROM coreerp_module_migrations WHERE migration = :'migration'")" = "1" ] && continue

    {
        echo '\set ON_ERROR_STOP on'
        echo 'BEGIN;'
        cat "$file"
        echo "INSERT INTO coreerp_module_migrations (migration) VALUES (:'migration');"
        echo 'COMMIT;'
    } | psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v migration="$migration"
done
