#!/usr/bin/env sh
# Backup real de MariaDB/MySQL compatible con shared hosting.
set -eu
umask 077

if [ -z "${DATABASE_URL:-}" ]; then
  echo "backup-database.sh: falta DATABASE_URL en el entorno." >&2
  exit 1
fi

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
PHP_BIN=""
for candidate in /opt/alt/php85/usr/bin/php /opt/alt/php86/usr/bin/php php85 php; do
  if command -v "$candidate" >/dev/null 2>&1; then
    PHP_BIN="$candidate"
    break
  fi
done
if [ -z "$PHP_BIN" ]; then
  echo "backup-database.sh: no se encontró un binario de PHP utilizable." >&2
  exit 1
fi

parse_field() {
  "$PHP_BIN" "$script_dir/parse-database-url.php" "$1"
}

user="$(parse_field user)"
pass="$(parse_field password)"
host="$(parse_field host)"
port="$(parse_field port)"
db="$(parse_field database)"

backup_dir="${BACKUP_DIR:-var/backups}"
mkdir -p "$backup_dir"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
out="$backup_dir/condor-${db}-${timestamp}.sql.gz"
raw_tmp="$backup_dir/.condor-${db}-${timestamp}.sql.tmp"
gzip_tmp="$out.tmp"

cleanup() {
  rm -f -- "$raw_tmp" "$gzip_tmp"
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

set --   --host="$host"   --port="$port"   --user="$user"   --single-transaction   --routines   --triggers

case "$(basename "$dump_bin")" in
  mysqldump)
    set -- --column-statistics=0 "$@"
    ;;
  mariadb-dump)
    ;;
  *)
    echo "backup-database.sh: binario de dump no soportado: $dump_bin" >&2
    exit 1
    ;;
esac

MYSQL_PWD="$pass" "$dump_bin" "$@" "$db" > "$raw_tmp"

gzip -c "$raw_tmp" > "$gzip_tmp"
mv -- "$gzip_tmp" "$out"
rm -f -- "$raw_tmp"
trap - 0 HUP INT TERM

echo "Backup creado: $out"

# Retención por cantidad. El valor explícito sigue siendo configurable,
# pero el baseline seguro aumenta de 14 a 30 backups diarios.
keep="${BACKUP_KEEP:-30}"
case "$keep" in
  ''|*[!0-9]*)
    echo "backup-database.sh: BACKUP_KEEP debe ser un entero positivo." >&2
    exit 1
    ;;
esac
if [ "$keep" -lt 1 ]; then
  echo "backup-database.sh: BACKUP_KEEP debe ser mayor que cero." >&2
  exit 1
fi

LC_ALL=C find "$backup_dir" -maxdepth 1 -type f -name "condor-${db}-*.sql.gz" -print   | LC_ALL=C sort -r   | {
      count=0
      while IFS= read -r backup; do
        [ -n "$backup" ] || continue
        count=$((count + 1))
        if [ "$count" -gt "$keep" ]; then
          rm -f -- "$backup"
        fi
      done
    }
