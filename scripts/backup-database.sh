#!/usr/bin/env sh
# Backups/restauración demostrada (roadmap Issue #1). Produce un dump
# comprimido y con timestamp, sin tocar nada de la base de datos real
# (solo lectura vía mysqldump). Pensado para correr por cron en el
# mismo hosting que ya ejecuta scripts/post-deploy.sh (Issue #144).
#
# Uso: DATABASE_URL="mysql://user:pass@host:port/db" scripts/backup-database.sh
# Requiere: mysqldump/mariadb-dump en PATH, DATABASE_URL exportada.
set -eu
umask 077

if [ -z "${DATABASE_URL:-}" ]; then
  echo "backup-database.sh: falta DATABASE_URL en el entorno." >&2
  exit 1
fi

url="${DATABASE_URL#mysql://}"
url="${url%%\?*}"
userpass="${url%%@*}"
rest="${url#*@}"

case "$userpass" in
  *:*)
    user="${userpass%%:*}"
    pass="${userpass#*:}"
    ;;
  *)
    user="$userpass"
    pass=""
    ;;
esac

hostport="${rest%%/*}"
db="${rest#*/}"
host="${hostport%%:*}"
port="${hostport#*:}"
[ "$port" = "$host" ] && port=3306

case "$db" in
  ''|*[!A-Za-z0-9_]*)
    echo "backup-database.sh: nombre de base no válido." >&2
    exit 1
    ;;
  *)
    ;;
esac

backup_dir="${BACKUP_DIR:-var/backups}"
mkdir -p "$backup_dir"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
out="$backup_dir/condor-${db}-${timestamp}.sql.gz"
raw_tmp="$backup_dir/.condor-${db}-${timestamp}.sql.tmp"
gzip_tmp="$out.tmp"

cleanup() {
  rm -f "$raw_tmp" "$gzip_tmp"
}
trap cleanup 0 HUP INT TERM

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

case "$(basename "$dump_bin")" in
  mysqldump)
    compatibility_arg="--column-statistics=0"
    ;;
  mariadb-dump)
    compatibility_arg=""
    ;;
  *)
    echo "backup-database.sh: binario de dump no soportado: $dump_bin" >&2
    exit 1
    ;;
esac

MYSQL_PWD="$pass" "$dump_bin" \
  $compatibility_arg \
  --host="$host" \
  --port="$port" \
  --user="$user" \
  --single-transaction \
  --routines \
  --triggers \
  "$db" > "$raw_tmp"

gzip -c "$raw_tmp" > "$gzip_tmp"
mv "$gzip_tmp" "$out"
rm -f "$raw_tmp"
trap - 0 HUP INT TERM

echo "Backup creado: $out"

# Retención: conserva solo los últimos N backups (default 14) para no
# agotar disco en shared hosting.
keep="${BACKUP_KEEP:-14}"
ls -1t "$backup_dir"/condor-"${db}"-*.sql.gz 2>/dev/null | tail -n +$((keep + 1)) | xargs -r rm -f
