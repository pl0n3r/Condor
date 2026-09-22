#!/usr/bin/env sh
# Restaura un dump únicamente en una base explícitamente descartable y
# comprueba que el esquema crítico quedó completo y consultable.
set -eu
umask 077

dump_file="${1:?Uso: verify-backup-restore.sh <dump.sql.gz>}"
if [ -z "${DATABASE_URL:-}" ]; then
  echo "verify-backup-restore.sh: falta DATABASE_URL en el entorno." >&2
  exit 1
fi
if [ ! -f "$dump_file" ]; then
  echo "verify-backup-restore.sh: no existe el archivo '$dump_file'." >&2
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
  echo "verify-backup-restore.sh: no se encontró un binario de PHP utilizable." >&2
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

case "$db" in
  condor_backup_restored|condor_restore_*|condor_*_restore_test|condor_*_restore_verify)
    ;;
  *)
    echo "verify-backup-restore.sh: '$db' no es un nombre permitido para una base descartable de restauración." >&2
    exit 1
    ;;
esac

if [ "${CONDOR_ALLOW_DESTRUCTIVE_RESTORE:-}" != "1" ]; then
  echo "verify-backup-restore.sh: se requiere CONDOR_ALLOW_DESTRUCTIVE_RESTORE=1 para recrear la base descartable." >&2
  exit 1
fi

client_bin=""
for candidate in mariadb mysql; do
  if command -v "$candidate" >/dev/null 2>&1; then
    client_bin="$candidate"
    break
  fi
done
if [ -z "$client_bin" ]; then
  echo "verify-backup-restore.sh: no se encontró mariadb ni mysql en PATH." >&2
  exit 1
fi

run_sql() {
  MYSQL_PWD="$pass" "$client_bin" --host="$host" --port="$port" --user="$user" "$@"
}

restore_tmp="${TMPDIR:-/tmp}/condor-restore-$$.sql"
cleanup() {
  rm -f -- "$restore_tmp"
}
trap cleanup 0 HUP INT TERM

# Guardas anteriores garantizan que solo una base de restauración
# descartable puede llegar a esta operación destructiva.
run_sql -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\`;"

gunzip -c "$dump_file" > "$restore_tmp"
run_sql "$db" < "$restore_tmp"

for required_table in condor_tenant condor_user doctrine_migration_versions; do
  exists="$(run_sql -N -e     "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$db' AND table_name='$required_table';")"
  if [ "$exists" != "1" ]; then
    echo "verify-backup-restore.sh: falta la tabla crítica '$required_table'." >&2
    exit 1
  fi
done

migration_count="$(run_sql -N "$db" -e "SELECT COUNT(*) FROM doctrine_migration_versions;")"
if [ "${migration_count:-0}" -lt 1 ]; then
  echo "verify-backup-restore.sh: la tabla de migraciones no contiene versiones aplicadas." >&2
  exit 1
fi

# Consulta representativa: obliga a MariaDB a resolver columnas reales de
# las dos entidades nucleares, aunque el backup no contenga filas de negocio.
run_sql -N "$db" -e   "SELECT COUNT(t.id), (SELECT COUNT(u.id) FROM condor_user u) FROM condor_tenant t;"   >/dev/null

table_count="$(run_sql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$db';")"

rm -f -- "$restore_tmp"
trap - 0 HUP INT TERM

echo "Restauración verificada: $table_count tabla(s), $migration_count migración(es) en '$db'."
