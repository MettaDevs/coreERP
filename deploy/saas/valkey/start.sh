#!/bin/sh
set -eu
umask 077

for secret in "$VALKEY_CACHE_PASSWORD" "$VALKEY_SESSION_PASSWORD"; do
    case "$secret" in
        *[!a-zA-Z0-9]*|'') echo 'Valkey membutuhkan secret alfanumerik acak.' >&2; exit 1 ;;
    esac
    [ "${#secret}" -ge 32 ] || { echo 'Secret Valkey terlalu pendek.' >&2; exit 1; }
done
[ "$VALKEY_CACHE_PASSWORD" != "$VALKEY_SESSION_PASSWORD" ] || { echo 'Secret cache dan sesi harus berbeda.' >&2; exit 1; }
cache_hash=$(printf '%s' "$VALKEY_CACHE_PASSWORD" | sha256sum | cut -d' ' -f1)
session_hash=$(printf '%s' "$VALKEY_SESSION_PASSWORD" | sha256sum | cut -d' ' -f1)
commands='-@all +ping +select +get +mget +set +setex +psetex +setnx +del +unlink +exists +expire +pexpire +ttl +pttl +incr +incrby +decr +decrby +eval +evalsha +script|load +client|setname +client|setinfo +multi +exec +discard +watch +unwatch +info'
printf 'user default off\nuser cache on #%s ~* db=1 %s\nuser sessions on #%s ~* db=2 %s\n' \
    "$cache_hash" "$commands" "$session_hash" "$commands" > /data/users.acl
chown valkey:valkey /data/users.acl
exec /usr/local/bin/docker-entrypoint.sh valkey-server /etc/valkey/valkey.conf --maxmemory "${VALKEY_MAXMEMORY:-512mb}"
