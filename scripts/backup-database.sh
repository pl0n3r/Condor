#!/usr/bin/env sh
# Backup real de MariaDB/MySQL compatible con shared hosting.
set -eu
umask 077

if [ -z "${DATABASE_URL:-}" ]; then
  echo "backup-database.sh: falta DATABASE_URL en el entorno." >&2
  exit 1
fi

# La retención se valida antes de crear o eliminar cualquier artefacto.
keep="${BACKUP_KEEP:-30}"
case "$keep" in
  ''|*[!0-9]*)
    echo "backup-database.sh: BACKUP_KEEP debe ser un entero positivo." >&2
    exit 1
    ;;
  *)
    ;;
esac
if [ "$keep" -lt 1 ]; then
  echo "backup-database.sh: BACKUP_KEEP debe ser mayor que cero." >&2
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

db="$("$PHP_BIN" "$script_dir/parse-database-url.php" database)"
backup_dir="${BACKUP_DIR:-var/backups}"
mkdir -p "$backup_dir"

credentials_tmp="$(mktemp "${TMPDIR:-/tmp}/condor-mysql-XXXXXX.cnf")"
raw_tmp=""
gzip_tmp=""
dump_pid=""
cleanup() {
  if [ -n "$dump_pid" ] && kill -0 "$dump_pid" 2>/dev/null; then
    kill -TERM "$dump_pid" 2>/dev/null || true
    sleep 1
    kill -KILL "$dump_pid" 2>/dev/null || true
    wait "$dump_pid" 2>/dev/null || true
  fi
  dump_pid=""
  [ -z "$raw_tmp" ] || rm -f -- "$raw_tmp"
  [ -z "$gzip_tmp" ] || rm -f -- "$gzip_tmp"
  rm -f -- "$credentials_tmp"
}
handle_signal() {
  signal_status="$1"
  cleanup
  trap - 0 HUP INT TERM
  exit "$signal_status"
}
trap cleanup 0
trap 'handle_signal 129' HUP
trap 'handle_signal 130' INT
trap 'handle_signal 143' TERM

"$PHP_BIN" "$script_dir/parse-database-url.php" client-config "$credentials_tmp"
unset DATABASE_URL

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
raw_tmp="$(mktemp "$backup_dir/.condor-${db}-${timestamp}-XXXXXX.sql")"
gzip_tmp="$(mktemp "$backup_dir/.condor-${db}-${timestamp}-XXXXXX.sql.gz")"
token="${gzip_tmp##*-}"
token="${token%.sql.gz}"
out="$backup_dir/condor-${db}-${timestamp}-${token}.sql.gz"

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

set -- --single-transaction --quick --skip-lock-tables --triggers

case "$(basename "$dump_bin")" in
  mysqldump)
    if "$dump_bin" --help 2>&1 | grep -q -- '--column-statistics'; then
      set -- --column-statistics=0 "$@"
    fi
    ;;
  mariadb-dump)
    ;;
  *)
    echo "backup-database.sh: binario de dump no soportado: $dump_bin" >&2
    exit 1
    ;;
esac

dump_status=0
"$dump_bin" --defaults-extra-file="$credentials_tmp" "$@" "$db" > "$raw_tmp" &
dump_pid=$!
if wait "$dump_pid"; then
  dump_status=0
else
  dump_status=$?
fi
dump_pid=""
if [ "$dump_status" -ne 0 ]; then
  echo "backup-database.sh: el dump falló (código $dump_status)." >&2
  exit "$dump_status"
fi

gzip -c "$raw_tmp" > "$gzip_tmp"
mv -- "$gzip_tmp" "$out"
rm -f -- "$raw_tmp" "$credentials_tmp"
trap - 0 HUP INT TERM

echo "Backup creado: $out"

# Retención en dos capas:
# 1. ningún backup de los últimos 30 días se elimina por cantidad;
# 2. BACKUP_KEEP limita únicamente el histórico anterior a esa ventana.
# Los nombres UTC + token aleatorio mantienen orden lexicográfico estable.
LC_ALL=C find "$backup_dir" \
  -maxdepth 1 \
  -type f \
  -name "condor-${db}-*.sql.gz" \
  -mtime +30 \
  -print \
  | LC_ALL=C sort -r \
  | {
      historical_count=0
      while IFS= read -r backup_path; do
        [ -n "$backup_path" ] || continue
        historical_count=$((historical_count + 1))
        if [ "$historical_count" -gt "$keep" ]; then
          rm -f -- "$backup_path"
        fi
      done
    }
