#!/usr/bin/env bash
# Private, local backup only. Never prints a credential or restores a database.
set -Eeuo pipefail
umask 077
[[ $EUID == 0 ]] || { printf 'Root required.\n' >&2; exit 64; }
exec 9>/run/lock/imagine-portal-db-backup.lock
flock -n 9 || exit 75
cd /srv/imagine/portal-development/infra/development/portal-db
base=/srv/imagine/backups/portal-db
install -d -m 0700 "$base"
temporary=$(mktemp -d "$base/.partial.XXXXXXXX")
trap 'logger -t imagine-portal-db-backup -- "Backup incomplete; inspect private partial directory"; exit 1' ERR
pg() {
    timeout 180s docker compose exec -T --user 82:82 postgres sh -ec \
        'export PGPASSWORD="$(cat /run/secrets/database-admin)"; exec "$@"' sh "$@"
}
pg pg_dump -h 127.0.0.1 -U postgres --format=custom portal_identity_dev > "$temporary/database.dump"
pg pg_dumpall -h 127.0.0.1 -U postgres --globals-only > "$temporary/globals.sql"
tar -czf "$temporary/secrets.tgz" .private
git -C /srv/imagine/portal-development rev-parse HEAD > "$temporary/source.commit"
date -u +%FT%TZ > "$temporary/completed_at"
pg pg_restore --list < "$temporary/database.dump" > "$temporary/archive.list"
(cd "$temporary"; sha256sum database.dump globals.sql secrets.tgz source.commit > SHA256SUMS)
touch "$temporary/COMPLETE"
final="$base/$(date -u +%Y%m%dT%H%M%SZ)-${temporary##*.}"
mv -T "$temporary" "$final"
trap - ERR
printf 'Backup complete: %s\n' "$final"
