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
mysql_login_file=""
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
  [ -z "$mysql_login_file" ] || rm -f -- "$mysql_login_file"
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
# Copia no exportada: solo la recibe el backup PDO de respaldo, nunca el entorno.
pdo_database_url="$DATABASE_URL"
unset DATABASE_URL

# Backup lógico por PDO con la cuenta de aplicación. Hostinger no concede los
# privilegios que exigen mariadb-dump/mysqldump; este camino solo usa SELECT y
# SHOW CREATE TABLE y verifica filas por tabla antes de dar el backup por bueno.
run_pdo_backup() {
  echo "backup-database.sh: cliente seleccionado: pdo." >&2
  : > "$raw_tmp"
  pdo_status=0
  DATABASE_URL="$pdo_database_url" "$PHP_BIN" "$script_dir/backup-database-pdo.php" "$raw_tmp" &
  dump_pid=$!
  if wait "$dump_pid"; then
    pdo_status=0
  else
    pdo_status=$?
  fi
  dump_pid=""
  if [ "$pdo_status" -ne 0 ]; then
    echo "backup-database.sh: el backup PDO falló (código $pdo_status)." >&2
    exit "$pdo_status"
  fi
}

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
if [ "${CONDOR_BACKUP_CLIENT:-auto}" = "pdo" ]; then
  dump_bin=""
elif [ -z "$dump_bin" ]; then
  echo "backup-database.sh: no se encontró mariadb-dump ni mysqldump en PATH; se usa PDO." >&2
fi

if [ -z "$dump_bin" ]; then
  run_pdo_backup
else
  # Condor no define triggers de base de datos. En shared hosting, pedirlos en el
  # dump puede exigir privilegios adicionales que la cuenta de aplicación no
  # necesita. El respaldo de D-054 conserva esquema+datos administrados por Condor
  # y evita metadata/objetos fuera de ese contrato.
  set -- --single-transaction --quick --skip-lock-tables --skip-triggers

  # Inspeccionar capacidades una sola vez evita asumir opciones entre clientes.
  dump_help="$("$dump_bin" --help 2>&1 || true)"

  # Usuarios de aplicación en hosting compartido no deben tener privilegios
  # globales como PROCESS. MySQL 8 puede exigirlo al inspeccionar tablespaces
  # salvo que el dump use --no-tablespaces. Activarlo solo si el cliente lo
  # soporta mantiene compatibilidad con MariaDB/MySQL sin relajar credenciales.
  if printf '%s\n' "$dump_help" | grep -q -- '--no-tablespaces'; then
    set -- --no-tablespaces "$@"
  fi

  dump_name="$(basename "$dump_bin")"
  echo "backup-database.sh: cliente seleccionado: $dump_name." >&2

  case "$dump_name" in
    mysqldump)
      # MySQL 8.0.32+ puede exigir RELOAD/FLUSH_TABLES con
      # --single-transaction cuando GTID está activo y set-gtid-purged=AUTO.
      # El backup de Condor no provisiona replicación: excluir esa metadata
      # evita privilegios globales innecesarios sin quitar esquema ni datos.
      if printf '%s\n' "$dump_help" | grep -q -- '--set-gtid-purged'; then
        set -- --set-gtid-purged=OFF "$@"
      fi
      if printf '%s\n' "$dump_help" | grep -q -- '--column-statistics'; then
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
  unset dump_help

  # El option-file temporal debe prevalecer sobre configuración externa.
  # --defaults-file, como primer argumento, evita los option-files normales.
  # Oracle MySQL conserva una excepción para .mylogin.cnf incluso con esa opción;
  # redirigir MYSQL_TEST_LOGIN_FILE a una ruta inexistente aísla solo mysqldump.
  if [ "$dump_name" = "mysqldump" ]; then
    mysql_login_file="${credentials_tmp}.login"
    rm -f -- "$mysql_login_file"
    MYSQL_TEST_LOGIN_FILE="$mysql_login_file"
    export MYSQL_TEST_LOGIN_FILE
  fi

  dump_status=0
  "$dump_bin" --defaults-file="$credentials_tmp" "$@" "$db" > "$raw_tmp" &
  dump_pid=$!
  if wait "$dump_pid"; then
    dump_status=0
  else
    dump_status=$?
  fi
  dump_pid=""
  if [ "$dump_name" = "mysqldump" ]; then
    unset MYSQL_TEST_LOGIN_FILE
  fi
  if [ "$dump_status" -ne 0 ]; then
    # Access denied, GTID o PROCESS en hosting compartido: el dump nativo no es
    # recuperable desde aquí, pero el backup PDO sí.
    echo "backup-database.sh: el dump falló (código $dump_status); se usa PDO." >&2
    run_pdo_backup
  fi
fi
unset pdo_database_url

gzip -c "$raw_tmp" > "$gzip_tmp"
mv -- "$gzip_tmp" "$out"
rm -f -- "$raw_tmp" "$credentials_tmp"
[ -z "$mysql_login_file" ] || rm -f -- "$mysql_login_file"
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
