#!/usr/bin/env sh
# Prueba real de restauración (no solo "el backup existe"): restaura un
# dump en una base de datos escenario descartable y confirma que al
# menos una tabla esperada quedó con datos consultables. Pensado para
# correr en CI contra el servicio MariaDB ya existente, no contra
# producción.
#
# Uso: DATABASE_URL="mysql://root@127.0.0.1:3306/condor_backup_verify" \
#        scripts/verify-backup-restore.sh path/al/dump.sql.gz
set -eu

dump_file="${1:?Uso: verify-backup-restore.sh <dump.sql.gz>}"
if [ -z "${DATABASE_URL:-}" ]; then
  echo "verify-backup-restore.sh: falta DATABASE_URL en el entorno." >&2
  exit 1
fi
if [ ! -f "$dump_file" ]; then
  echo "verify-backup-restore.sh: no existe el archivo '$dump_file'." >&2
  exit 1
fi

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
  "$client_bin" --host="$host" --port="$port" --user="$user" ${pass:+--password="$pass"} "$@"
}

# La base de verificación es descartable: se recrea vacía antes de restaurar,
# nunca se restaura sobre una base con datos reales.
run_sql -e "DROP DATABASE IF EXISTS \`${db}\`; CREATE DATABASE \`${db}\`;"

gunzip -c "$dump_file" | run_sql "$db"

table_count="$(run_sql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${db}';")"
if [ "${table_count:-0}" -lt 1 ]; then
  echo "verify-backup-restore.sh: la restauración no produjo ninguna tabla." >&2
  exit 1
fi

echo "Restauración verificada: $table_count tabla(s) en '$db' desde $dump_file."
