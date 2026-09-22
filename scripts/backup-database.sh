#!/usr/bin/env sh
# Backups/restauración demostrada (roadmap Issue #1). Produce un dump
# comprimido y con timestamp, sin tocar nada de la base de datos real
# (solo lectura vía mysqldump). Pensado para correr por cron en el
# mismo hosting que ya ejecuta scripts/post-deploy.sh (Issue #144).
#
# Uso: DATABASE_URL="mysql://user:pass@host:port/db" scripts/backup-database.sh
# Requiere: mysqldump/mariadb-dump en PATH, DATABASE_URL exportada.
set -eu

if [ -z "${DATABASE_URL:-}" ]; then
  echo "backup-database.sh: falta DATABASE_URL en el entorno." >&2
  exit 1
fi

# Parseo mínimo de mysql://user:pass@host:port/db sin depender de PHP/python
# (el cron de Hostinger puede no tener el binario de PHP correcto en PATH,
# ver Issue #144 — el mismo problema que post-deploy.sh ya resuelve).
url="${DATABASE_URL#mysql://}"
url="${url%%\?*}"
userpass="${url%%@*}"
rest="${url#*@}"
user="${userpass%%:*}"
pass="${userpass#*:}"
hostport="${rest%%/*}"
db="${rest#*/}"
host="${hostport%%:*}"
port="${hostport#*:}"
[ "$port" = "$host" ] && port=3306

backup_dir="${BACKUP_DIR:-var/backups}"
mkdir -p "$backup_dir"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
out="$backup_dir/condor-${db}-${timestamp}.sql.gz"

dump_bin=""
for candidate in mariadb-dump mysqldump; do
  if command -v "$candidate" >/dev/null 2>&1; then
    dump_bin="$candidate"
    break
  fi
done

if [ -z "$dump_bin" ]; then
  echo "backup-database.sh: no se encontró mariadb-dump ni mysqldump en PATH." >&2
  exit 1
fi

"$dump_bin" \
  --host="$host" \
  --port="$port" \
  --user="$user" \
  ${pass:+--password="$pass"} \
  --single-transaction \
  --routines \
  --triggers \
  "$db" | gzip > "$out"

echo "Backup creado: $out"

# Retención: conserva solo los últimos N backups (default 14) para no
# agotar disco en shared hosting.
keep="${BACKUP_KEEP:-14}"
ls -1t "$backup_dir"/condor-"${db}"-*.sql.gz 2>/dev/null | tail -n +$((keep + 1)) | xargs -r rm -f
