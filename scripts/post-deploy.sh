#!/bin/sh
# Cron/post-deploy seguro para Hostinger shared hosting.
#
# En modo construction (D-053/D-054) este script puede reconciliar migraciones
# Doctrine versionadas pendientes bajo exclusión mutua, backup previo y luego
# regenerar la caché. CONDOR_AUTO_MIGRATE=0 conserva el modo solo-chequeo.
# En modo live el esquema pendiente siempre falla cerrado. Nunca convierte
# migraciones destructivas/contract en automáticas.
set -eu
umask 077

cd "$(dirname "$0")/.."

PHP_BIN=""
for candidate in \
    /opt/alt/php85/usr/bin/php \
    /opt/alt/php86/usr/bin/php \
    php85 \
    php
do
    if command -v "$candidate" >/dev/null 2>&1; then
        PHP_BIN="$candidate"
        break
    fi
done

if [ -z "$PHP_BIN" ]; then
    echo "post-deploy.sh: no se encontró un binario de PHP utilizable." >&2
    exit 1
fi

POST_DEPLOY_STATUS_FILE="var/runtime/post-deploy-status.json"
POST_DEPLOY_PHASE="bootstrap"
POST_DEPLOY_REASON="none"
POST_DEPLOY_SUBCODE=0
POST_DEPLOY_BACKUP_CLIENT="unknown"
POST_DEPLOY_VERSION="$("$PHP_BIN" -r '$config = require "config/version.php"; echo is_array($config) ? ($config["version"] ?? "unknown") : "unknown";' 2>/dev/null || true)"
case "$POST_DEPLOY_VERSION" in
    [0-9]*.[0-9]*.[0-9]*)
        ;;
    *)
        POST_DEPLOY_VERSION="unknown"
        ;;
esac

write_post_deploy_status() {
    phase="$1"
    result="$2"
    code="$3"
    status_dir="$(dirname "$POST_DEPLOY_STATUS_FILE")"
    mkdir -p -- "$status_dir" 2>/dev/null || return 0
    status_tmp="$(mktemp "$status_dir/.post-deploy-status-XXXXXX" 2>/dev/null || true)"
    [ -n "$status_tmp" ] || return 0
    updated_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf '{"version":"%s","phase":"%s","result":"%s","code":%s,"reason":"%s","subcode":%s,"backup_client":"%s","updated_at":"%s"}\n' \
        "$POST_DEPLOY_VERSION" "$phase" "$result" "$code" \
        "$POST_DEPLOY_REASON" "$POST_DEPLOY_SUBCODE" "$POST_DEPLOY_BACKUP_CLIENT" \
        "$updated_at" >"$status_tmp" || {
            rm -f -- "$status_tmp"
            return 0
        }
    chmod 0600 "$status_tmp" 2>/dev/null || true
    mv -f -- "$status_tmp" "$POST_DEPLOY_STATUS_FILE" 2>/dev/null || {
        rm -f -- "$status_tmp"
        return 0
    }
}

set_post_deploy_phase() {
    POST_DEPLOY_PHASE="$1"
    POST_DEPLOY_REASON="none"
    POST_DEPLOY_SUBCODE=0
    POST_DEPLOY_BACKUP_CLIENT="unknown"
    write_post_deploy_status "$POST_DEPLOY_PHASE" "running" 0
}

finalize_post_deploy_status() {
    exit_status="$1"
    if [ "$exit_status" -eq 0 ]; then
        if [ "$POST_DEPLOY_PHASE" = "complete" ]; then
            result="success"
        else
            result="skipped"
        fi
    else
        result="failure"
    fi
    write_post_deploy_status "$POST_DEPLOY_PHASE" "$result" "$exit_status"
}

set_post_deploy_phase "bootstrap"
trap 'exit_status=$?; finalize_post_deploy_status "$exit_status"; exit "$exit_status"' EXIT

LOCK_FILE="var/post-deploy.lock"
LOCK_GUARD_FILE="var/post-deploy.lock.guard"
LOCK_MAX_AGE_SECONDS=21600
LOCK_INVALID_GRACE_MINUTES=5
LOCK_TOKEN="$$-$(date +%s)"
LOCK_GUARD_OWNED=0
LOCK_SKIP_STATUS=10
LOCK_ERROR_STATUS=11
PRODUCTION_STAGE="${CONDOR_PRODUCTION_STAGE:-construction}"
case "$PRODUCTION_STAGE" in
    construction|live)
        ;;
    *)
        echo "post-deploy.sh: CONDOR_PRODUCTION_STAGE debe ser construction o live." >&2
        exit 1
        ;;
esac

AUTO_MIGRATE="${CONDOR_AUTO_MIGRATE:-1}"
case "$AUTO_MIGRATE" in
    0|1)
        ;;
    *)
        echo "post-deploy.sh: CONDOR_AUTO_MIGRATE debe ser 0 o 1." >&2
        exit 1
        ;;
esac

MIGRATION_TIMEOUT_SECONDS="${CONDOR_MIGRATION_TIMEOUT_SECONDS:-180}"
case "$MIGRATION_TIMEOUT_SECONDS" in
    ''|*[!0-9]*|0)
        echo "post-deploy.sh: CONDOR_MIGRATION_TIMEOUT_SECONDS debe ser un entero entre 1 y 900." >&2
        exit 1
        ;;
    *)
        # Entero positivo; el límite superior se valida a continuación.
        ;;
esac
if [ "$MIGRATION_TIMEOUT_SECONDS" -gt 900 ]; then
    echo "post-deploy.sh: CONDOR_MIGRATION_TIMEOUT_SECONDS no puede superar 900." >&2
    exit 1
fi

BACKUP_TIMEOUT_SECONDS="${CONDOR_BACKUP_TIMEOUT_SECONDS:-180}"
case "$BACKUP_TIMEOUT_SECONDS" in
    ''|*[!0-9]*|0)
        echo "post-deploy.sh: CONDOR_BACKUP_TIMEOUT_SECONDS debe ser un entero entre 1 y 900." >&2
        exit 1
        ;;
    *)
        ;;
esac
if [ "$BACKUP_TIMEOUT_SECONDS" -gt 900 ]; then
    echo "post-deploy.sh: CONDOR_BACKUP_TIMEOUT_SECONDS no puede superar 900." >&2
    exit 1
fi

SCHEMA_CHECK_TIMEOUT_SECONDS="${CONDOR_SCHEMA_CHECK_TIMEOUT_SECONDS:-60}"
case "$SCHEMA_CHECK_TIMEOUT_SECONDS" in
    ''|*[!0-9]*|0)
        echo "post-deploy.sh: CONDOR_SCHEMA_CHECK_TIMEOUT_SECONDS debe ser un entero entre 1 y 300." >&2
        exit 1
        ;;
    *)
        # Entero positivo; el límite superior se valida a continuación.
        ;;
esac
if [ "$SCHEMA_CHECK_TIMEOUT_SECONDS" -gt 300 ]; then
    echo "post-deploy.sh: CONDOR_SCHEMA_CHECK_TIMEOUT_SECONDS no puede superar 300." >&2
    exit 1
fi
SCHEMA_CHECK_LOG=""
SCHEMA_CHECK_TIMEOUT_MARKER=""
SCHEMA_CHECK_DONE_MARKER=""
SCHEMA_CHECK_PID=""
SCHEMA_CHECK_WATCHDOG_PID=""
MIGRATION_LOG=""
MIGRATION_SQL_FILE=""
MIGRATION_TIMEOUT_MARKER=""
MIGRATION_PID=""
MIGRATION_WATCHDOG_PID=""
BACKUP_LOG=""
BACKUP_TIMEOUT_MARKER=""
BACKUP_PID=""
BACKUP_WATCHDOG_PID=""

FLOCK_BIN=""
for candidate in /usr/bin/flock flock
do
    if command -v "$candidate" >/dev/null 2>&1; then
        FLOCK_BIN="$candidate"
        break
    fi
done

if [ -z "$FLOCK_BIN" ]; then
    echo "post-deploy.sh: no se encontró flock; no es seguro recuperar locks concurrentemente." >&2
    exit 1
fi

cleanup_guard() {
    if [ "$LOCK_GUARD_OWNED" -eq 1 ]; then
        "$FLOCK_BIN" -u 9 2>/dev/null || true
        exec 9>&-
    fi
    LOCK_GUARD_OWNED=0
}

cleanup_lock() {
    if [ -f "$LOCK_FILE" ] &&
        [ "$(sed -n '1p' "$LOCK_FILE" 2>/dev/null || true)" = "$LOCK_TOKEN" ]; then
        rm -f -- "$LOCK_FILE"
    fi
}

cleanup_schema_check_process() {
    if [ -n "$SCHEMA_CHECK_PID" ] && kill -0 "$SCHEMA_CHECK_PID" 2>/dev/null; then
        kill -TERM "$SCHEMA_CHECK_PID" 2>/dev/null || true
        sleep 1
        kill -KILL "$SCHEMA_CHECK_PID" 2>/dev/null || true
    fi
    SCHEMA_CHECK_PID=""
}

cleanup_schema_check_watchdog() {
    if [ -n "$SCHEMA_CHECK_WATCHDOG_PID" ]; then
        kill -TERM "$SCHEMA_CHECK_WATCHDOG_PID" 2>/dev/null || true
        SCHEMA_CHECK_WATCHDOG_PID=""
    fi
}

cleanup_schema_check_log() {
    if [ -n "$SCHEMA_CHECK_DONE_MARKER" ]; then
        rm -f -- "$SCHEMA_CHECK_DONE_MARKER"
        SCHEMA_CHECK_DONE_MARKER=""
    fi
    if [ -n "$SCHEMA_CHECK_TIMEOUT_MARKER" ]; then
        rm -f -- "$SCHEMA_CHECK_TIMEOUT_MARKER"
        SCHEMA_CHECK_TIMEOUT_MARKER=""
    fi
    if [ -n "$SCHEMA_CHECK_LOG" ]; then
        rm -f -- "$SCHEMA_CHECK_LOG"
        SCHEMA_CHECK_LOG=""
    fi
}

cleanup_migration_process() {
    if [ -n "$MIGRATION_PID" ] && kill -0 "$MIGRATION_PID" 2>/dev/null; then
        kill -TERM "$MIGRATION_PID" 2>/dev/null || true
        sleep 1
        kill -KILL "$MIGRATION_PID" 2>/dev/null || true
    fi
    MIGRATION_PID=""
}

cleanup_migration_watchdog() {
    if [ -n "$MIGRATION_WATCHDOG_PID" ]; then
        kill -TERM "$MIGRATION_WATCHDOG_PID" 2>/dev/null || true
        MIGRATION_WATCHDOG_PID=""
    fi
}

cleanup_migration_log() {
    if [ -n "$MIGRATION_TIMEOUT_MARKER" ]; then
        rm -f -- "$MIGRATION_TIMEOUT_MARKER"
        MIGRATION_TIMEOUT_MARKER=""
    fi
    if [ -n "$MIGRATION_SQL_FILE" ]; then
        rm -f -- "$MIGRATION_SQL_FILE"
        MIGRATION_SQL_FILE=""
    fi
    if [ -n "$MIGRATION_LOG" ]; then
        rm -f -- "$MIGRATION_LOG"
        MIGRATION_LOG=""
    fi
}

cleanup_backup_process() {
    if [ -n "$BACKUP_PID" ] && kill -0 "$BACKUP_PID" 2>/dev/null; then
        kill -TERM "$BACKUP_PID" 2>/dev/null || true
        sleep 1
        kill -KILL "$BACKUP_PID" 2>/dev/null || true
    fi
    BACKUP_PID=""
}

cleanup_backup_watchdog() {
    if [ -n "$BACKUP_WATCHDOG_PID" ]; then
        kill -TERM "$BACKUP_WATCHDOG_PID" 2>/dev/null || true
        BACKUP_WATCHDOG_PID=""
    fi
}

cleanup_backup_log() {
    if [ -n "$BACKUP_TIMEOUT_MARKER" ]; then
        rm -f -- "$BACKUP_TIMEOUT_MARKER"
        BACKUP_TIMEOUT_MARKER=""
    fi
    if [ -n "$BACKUP_LOG" ]; then
        rm -f -- "$BACKUP_LOG"
        BACKUP_LOG=""
    fi
}

print_log_tail() {
    log_file="$1"
    if [ -n "$log_file" ] && [ -s "$log_file" ]; then
        tail -n 30 -- "$log_file" >&2 || true
    fi
}

run_schema_check() {
    SCHEMA_CHECK_LOG="$(mktemp "${TMPDIR:-/tmp}/condor-schema-check-XXXXXX.log")"
    SCHEMA_CHECK_TIMEOUT_MARKER="${SCHEMA_CHECK_LOG}.timeout"
    SCHEMA_CHECK_DONE_MARKER="${SCHEMA_CHECK_LOG}.done"

    "$PHP_BIN" bin/console doctrine:migrations:up-to-date \
        --env=prod \
        --no-interaction \
        --fail-on-unregistered \
        >"$SCHEMA_CHECK_LOG" 2>&1 &
    SCHEMA_CHECK_PID=$!

    (
        elapsed=0
        while [ "$elapsed" -lt "$SCHEMA_CHECK_TIMEOUT_SECONDS" ]; do
            sleep 1
            if [ -f "$SCHEMA_CHECK_DONE_MARKER" ]; then
                exit 0
            fi
            elapsed=$((elapsed + 1))
        done

        if kill -0 "$SCHEMA_CHECK_PID" 2>/dev/null; then
            : >"$SCHEMA_CHECK_TIMEOUT_MARKER"
            kill -TERM "$SCHEMA_CHECK_PID" 2>/dev/null || true
            sleep 2
            kill -KILL "$SCHEMA_CHECK_PID" 2>/dev/null || true
        fi
    ) &
    SCHEMA_CHECK_WATCHDOG_PID=$!

    schema_status=0
    if wait "$SCHEMA_CHECK_PID"; then
        schema_status=0
    else
        schema_status=$?
    fi
    SCHEMA_CHECK_PID=""
    : >"$SCHEMA_CHECK_DONE_MARKER"
    wait "$SCHEMA_CHECK_WATCHDOG_PID" 2>/dev/null || true
    SCHEMA_CHECK_WATCHDOG_PID=""

    if [ -f "$SCHEMA_CHECK_TIMEOUT_MARKER" ]; then
        return 124
    fi

    return "$schema_status"
}

run_migration_command() {
    migration_mode="$1"
    cleanup_migration_process
    cleanup_migration_watchdog
    cleanup_migration_log

    MIGRATION_LOG="$(mktemp "${TMPDIR:-/tmp}/condor-migration-XXXXXX.log")"
    MIGRATION_TIMEOUT_MARKER="${MIGRATION_LOG}.timeout"

    if [ "$migration_mode" = "dry-run" ]; then
        MIGRATION_SQL_FILE="${MIGRATION_LOG}.sql"
        "$PHP_BIN" bin/console doctrine:migrations:migrate \
            --env=prod \
            --no-interaction \
            --allow-no-migration \
            --dry-run \
            --write-sql="$MIGRATION_SQL_FILE" \
            >"$MIGRATION_LOG" 2>&1 &
    else
        "$PHP_BIN" bin/console doctrine:migrations:migrate \
            --env=prod \
            --no-interaction \
            --allow-no-migration \
            >"$MIGRATION_LOG" 2>&1 &
    fi
    MIGRATION_PID=$!

    (
        elapsed=0
        while [ "$elapsed" -lt "$MIGRATION_TIMEOUT_SECONDS" ]; do
            sleep 1
            if ! kill -0 "$MIGRATION_PID" 2>/dev/null; then
                exit 0
            fi
            elapsed=$((elapsed + 1))
        done

        if kill -0 "$MIGRATION_PID" 2>/dev/null; then
            : >"$MIGRATION_TIMEOUT_MARKER"
            kill -TERM "$MIGRATION_PID" 2>/dev/null || true
            sleep 2
            kill -KILL "$MIGRATION_PID" 2>/dev/null || true
        fi
    ) &
    MIGRATION_WATCHDOG_PID=$!

    migration_status=0
    if wait "$MIGRATION_PID"; then
        migration_status=0
    else
        migration_status=$?
    fi
    MIGRATION_PID=""
    wait "$MIGRATION_WATCHDOG_PID" 2>/dev/null || true
    MIGRATION_WATCHDOG_PID=""

    if [ -f "$MIGRATION_TIMEOUT_MARKER" ]; then
        return 124
    fi

    return "$migration_status"
}

validate_construction_migrations() {
    set_post_deploy_phase "dry-run"
    validation_status=0
    if run_migration_command dry-run; then
        validation_status=0
    else
        validation_status=$?
    fi

    if [ "$validation_status" -eq 124 ]; then
        print_log_tail "$MIGRATION_LOG"
        cleanup_migration_log
        echo "post-deploy.sh: dry-run de migraciones excedió ${MIGRATION_TIMEOUT_SECONDS}s; caché no modificada." >&2
        return 2
    fi

    if [ "$validation_status" -ne 0 ]; then
        print_log_tail "$MIGRATION_LOG"
        cleanup_migration_log
        echo "post-deploy.sh: no fue posible validar las migraciones pendientes de construcción (código $validation_status); caché no modificada." >&2
        return 2
    fi

    if [ ! -s "$MIGRATION_SQL_FILE" ]; then
        print_log_tail "$MIGRATION_LOG"
        cleanup_migration_log
        echo "post-deploy.sh: Doctrine no produjo SQL para validar las migraciones pendientes; se falla cerrado." >&2
        return 2
    fi

    guard_status=0
    sed '/^[[:space:]]*--/d; /^[[:space:]]*$/d' "$MIGRATION_SQL_FILE" | awk '
        BEGIN { RS = ";"; count = 0 }
        {
            statement = $0
            gsub(/^[[:space:]]+|[[:space:]]+$/, "", statement)
            if (statement == "") next
            count++
            normalized = toupper(statement)

            if (normalized ~ /(^|[^A-Z0-9_])(DROP|TRUNCATE|RENAME|MODIFY|CHANGE)([^A-Z0-9_]|$)/ ||
                normalized ~ /^DELETE[[:space:]]+FROM([[:space:]]|$)/ ||
                normalized ~ /^UPDATE[[:space:]]/ ||
                normalized ~ /CREATE[[:space:]]+OR[[:space:]]+REPLACE/ ||
                normalized ~ /REPLACE[[:space:]]+INTO/ ||
                normalized ~ /FOREIGN_KEY_CHECKS[[:space:]]*=[[:space:]]*0/) {
                exit 2
            }

            if (normalized ~ /^CREATE[[:space:]]+(TABLE|INDEX|UNIQUE[[:space:]]+INDEX|VIEW)[[:space:]]/ ||
                normalized ~ /^ALTER[[:space:]]+TABLE[[:space:]].*[[:space:]]ADD[[:space:]]/ ||
                normalized ~ /^INSERT[[:space:]]+INTO[[:space:]]/ ||
                normalized ~ /^START[[:space:]]+TRANSACTION$/ ||
                normalized ~ /^COMMIT$/) {
                next
            }

            exit 3
        }
        END {
            if (count == 0) exit 4
        }
    ' || guard_status=$?

    if [ "$guard_status" -ne 0 ]; then
        cleanup_migration_log
        if [ "$guard_status" -eq 2 ]; then
            echo "post-deploy.sh: migración destructiva/contract detectada; requiere autorización explícita. Caché no modificada." >&2
        elif [ "$guard_status" -eq 4 ]; then
            echo "post-deploy.sh: Doctrine no produjo SQL validable para las migraciones pendientes; se falla cerrado." >&2
        else
            echo "post-deploy.sh: SQL de migración fuera del allowlist forward/expand-compatible; se falla cerrado." >&2
        fi
        return 2
    fi

    cleanup_migration_log
    return 0
}

resolve_database_url() {
    if [ -n "${DATABASE_URL:-}" ]; then
        printf '%s' "$DATABASE_URL"
        return 0
    fi

    APP_ENV=prod "$PHP_BIN" -r '
        require "vendor/autoload.php";
        $dotenv = new Symfony\Component\Dotenv\Dotenv();
        $dotenv->bootEnv(".env", "prod");
        $value = $_SERVER["DATABASE_URL"] ?? $_ENV["DATABASE_URL"] ?? getenv("DATABASE_URL");
        if (is_string($value) && $value !== "") {
            echo $value;
        }
    '
}

capture_backup_client() {
    backup_log="$1"
    detected_client="$(sed -n 's/^backup-database\.sh: cliente seleccionado: \(mariadb-dump\|mysqldump\)\.$/\1/p' "$backup_log" 2>/dev/null | tail -n 1)"
    case "$detected_client" in
        mariadb-dump|mysqldump)
            POST_DEPLOY_BACKUP_CLIENT="$detected_client"
            ;;
        *)
            POST_DEPLOY_BACKUP_CLIENT="unknown"
            ;;
    esac
}

classify_backup_failure() {
    backup_log="$1"
    backup_status="$2"
    capture_backup_client "$backup_log"
    POST_DEPLOY_SUBCODE="$backup_status"
    POST_DEPLOY_REASON="dump_failed_unknown"

    if grep -Eiq 'no se encontró mariadb-dump ni mysqldump' "$backup_log"; then
        POST_DEPLOY_REASON="client_missing"
    elif grep -Eiq 'parse-database-url\.php:|falta DATABASE_URL|option-file' "$backup_log"; then
        POST_DEPLOY_REASON="configuration"
    elif grep -Eiq 'no space left|disk quota|read-only file system|permission denied|mktemp:|cannot create|failed to create' "$backup_log"; then
        POST_DEPLOY_REASON="filesystem"
    elif grep -Eiq 'unknown (variable|option)|unrecognized option|unknown option' "$backup_log"; then
        POST_DEPLOY_REASON="unsupported_option"
    elif grep -Eiq '(PROCESS|RELOAD|FLUSH_TABLES|SUPER).*(privilege|required)|requires.*(PROCESS|RELOAD|FLUSH_TABLES|SUPER)' "$backup_log"; then
        POST_DEPLOY_REASON="server_privilege"
    elif grep -Eiq 'access denied|command denied to user|permission denied for user' "$backup_log"; then
        POST_DEPLOY_REASON="access_denied"
    elif grep -Eiq "can.t connect|cannot connect|connection refused|lost connection|server has gone away|unknown server host|timed?[[:space:]_-]*out" "$backup_log"; then
        POST_DEPLOY_REASON="connection"
    fi
}

run_pre_migration_backup() {
    set_post_deploy_phase "backup"
    database_url="$(resolve_database_url 2>/dev/null || true)"
    if [ -z "$database_url" ]; then
        POST_DEPLOY_REASON="database_url_missing"
        POST_DEPLOY_SUBCODE=2
        unset database_url
        echo "post-deploy.sh: no fue posible resolver DATABASE_URL para el backup previo; migración no ejecutada." >&2
        return 2
    fi

    cleanup_backup_process
    cleanup_backup_watchdog
    cleanup_backup_log
    BACKUP_LOG="$(mktemp "${TMPDIR:-/tmp}/condor-pre-migration-backup-XXXXXX.log")"
    BACKUP_TIMEOUT_MARKER="${BACKUP_LOG}.timeout"

    DATABASE_URL="$database_url" sh scripts/backup-database.sh >"$BACKUP_LOG" 2>&1 &
    BACKUP_PID=$!
    unset database_url

    (
        elapsed=0
        while [ "$elapsed" -lt "$BACKUP_TIMEOUT_SECONDS" ]; do
            sleep 1
            if ! kill -0 "$BACKUP_PID" 2>/dev/null; then
                exit 0
            fi
            elapsed=$((elapsed + 1))
        done

        if kill -0 "$BACKUP_PID" 2>/dev/null; then
            : >"$BACKUP_TIMEOUT_MARKER"
            kill -TERM "$BACKUP_PID" 2>/dev/null || true
            sleep 2
            kill -KILL "$BACKUP_PID" 2>/dev/null || true
        fi
    ) &
    BACKUP_WATCHDOG_PID=$!

    backup_status=0
    if wait "$BACKUP_PID"; then
        backup_status=0
    else
        backup_status=$?
    fi
    BACKUP_PID=""
    wait "$BACKUP_WATCHDOG_PID" 2>/dev/null || true
    BACKUP_WATCHDOG_PID=""

    if [ -f "$BACKUP_TIMEOUT_MARKER" ]; then
        capture_backup_client "$BACKUP_LOG"
        POST_DEPLOY_REASON="timeout"
        POST_DEPLOY_SUBCODE=124
        print_log_tail "$BACKUP_LOG"
        cleanup_backup_log
        echo "post-deploy.sh: backup previo a migración excedió ${BACKUP_TIMEOUT_SECONDS}s; migración y caché no modificadas." >&2
        return 2
    fi

    if [ "$backup_status" -ne 0 ]; then
        classify_backup_failure "$BACKUP_LOG" "$backup_status"
        print_log_tail "$BACKUP_LOG"
        cleanup_backup_log
        echo "post-deploy.sh: backup previo a migración falló (código $backup_status); migración y caché no modificadas." >&2
        return 2
    fi

    cleanup_backup_log
    return 0
}

run_construction_migrations() {
    validation_result=0
    if validate_construction_migrations; then
        validation_result=0
    else
        validation_result=$?
    fi
    if [ "$validation_result" -ne 0 ]; then
        return "$validation_result"
    fi

    backup_result=0
    if run_pre_migration_backup; then
        backup_result=0
    else
        backup_result=$?
    fi
    if [ "$backup_result" -ne 0 ]; then
        return "$backup_result"
    fi

    set_post_deploy_phase "migrate"
    migration_status=0
    if run_migration_command apply; then
        migration_status=0
    else
        migration_status=$?
    fi

    if [ "$migration_status" -eq 124 ]; then
        print_log_tail "$MIGRATION_LOG"
        cleanup_migration_log
        echo "post-deploy.sh: migración excedió ${MIGRATION_TIMEOUT_SECONDS}s; caché no modificada." >&2
        return 2
    fi

    if [ "$migration_status" -ne 0 ]; then
        print_log_tail "$MIGRATION_LOG"
        cleanup_migration_log
        echo "post-deploy.sh: Doctrine no pudo reconciliar las migraciones de construcción (código $migration_status); caché no modificada." >&2
        return 2
    fi
    cleanup_migration_log

    set_post_deploy_phase "recheck"
    recheck_status=0
    if run_schema_check; then
        recheck_status=0
    else
        recheck_status=$?
    fi

    if [ "$recheck_status" -eq 124 ]; then
        print_log_tail "$SCHEMA_CHECK_LOG"
        cleanup_schema_check_log
        echo "post-deploy.sh: recomprobación de esquema excedió ${SCHEMA_CHECK_TIMEOUT_SECONDS}s tras migrar; caché no modificada." >&2
        return 2
    fi

    if [ "$recheck_status" -ne 0 ]; then
        print_log_tail "$SCHEMA_CHECK_LOG"
        cleanup_schema_check_log
        echo "post-deploy.sh: la migración terminó pero el esquema no quedó reconciliado; caché no modificada." >&2
        return 2
    fi

    cleanup_schema_check_log
    return 0
}
crear_lock() {
    now="$(date +%s)"
    if (
        set -C
        printf '%s\n%s\n%s\n' "$LOCK_TOKEN" "$$" "$now" > "$LOCK_FILE"
    ) 2>/dev/null; then
        return 0
    fi
    return 1
}

acquire_guard() {
    # Mutex estable: este archivo nunca se renombra ni elimina. De este modo,
    # dos recuperadores no pueden inspeccionar un guard viejo y después mover
    # accidentalmente el guard nuevo creado por el otro proceso.
    lock_dir="$(dirname "$LOCK_GUARD_FILE")"
    if ! mkdir -p -- "$lock_dir" 2>/dev/null; then
        echo "post-deploy.sh: no fue posible preparar el directorio del lock." >&2
        return "$LOCK_ERROR_STATUS"
    fi
    if ! touch "$LOCK_GUARD_FILE" 2>/dev/null; then
        echo "post-deploy.sh: no fue posible preparar el archivo guard del lock." >&2
        return "$LOCK_ERROR_STATUS"
    fi
    exec 9>>"$LOCK_GUARD_FILE"

    if "$FLOCK_BIN" -n 9; then
        LOCK_GUARD_OWNED=1
        return 0
    else
        guard_status=$?
    fi

    exec 9>&-
    if [ "$guard_status" -eq 1 ]; then
        echo "post-deploy.sh: otra recuperación de lock sigue activa; se omite." >&2
        return "$LOCK_SKIP_STATUS"
    fi

    echo "post-deploy.sh: flock falló al adquirir el guard (código $guard_status)." >&2
    return "$LOCK_ERROR_STATUS"
}

recuperar_lock() {
    # acquire_lock() ya mantiene el flock estable. Si llegamos aquí con el
    # guard adquirido, ninguna corrida que respete este protocolo sigue activa;
    # LOCK_FILE es solo metadata recuperable de una corrida terminada.
    owner_token="$(sed -n '1p' "$LOCK_FILE" 2>/dev/null || true)"
    owner_pid="$(sed -n '2p' "$LOCK_FILE" 2>/dev/null || true)"
    created_at="$(sed -n '3p' "$LOCK_FILE" 2>/dev/null || true)"

    case "$owner_pid:$created_at" in
        *[!0-9:]*|:*|*:)
            if [ -z "$(find "$LOCK_FILE" -mmin +"$LOCK_INVALID_GRACE_MINUTES" -print -quit 2>/dev/null)" ]; then
                echo "post-deploy.sh: lock incompleto reciente; se omite esta corrida." >&2
                return "$LOCK_SKIP_STATUS"
            fi
            echo "post-deploy.sh: lock incompleto huérfano; se recupera bajo mutex." >&2
            ;;
        *)
            now="$(date +%s)"
            age=$((now - created_at))
            if [ "$age" -le "$LOCK_MAX_AGE_SECONDS" ]; then
                echo "post-deploy.sh: lock huérfano detectado; se recupera bajo mutex." >&2
            else
                echo "post-deploy.sh: lock huérfano y vencido; se recupera bajo mutex." >&2
            fi
            ;;
    esac

    current_token="$(sed -n '1p' "$LOCK_FILE" 2>/dev/null || true)"
    if [ "$current_token" != "$owner_token" ]; then
        echo "post-deploy.sh: el lock cambió mientras se inspeccionaba; se omite." >&2
        return "$LOCK_SKIP_STATUS"
    fi

    stale="$LOCK_FILE.stale.$LOCK_TOKEN"
    if ! mv "$LOCK_FILE" "$stale" 2>/dev/null; then
        echo "post-deploy.sh: el lock cambió concurrentemente; se omite." >&2
        return "$LOCK_SKIP_STATUS"
    fi
    rm -f -- "$stale"

    if ! crear_lock; then
        echo "post-deploy.sh: no fue posible publicar el lock bajo el mutex adquirido." >&2
        return "$LOCK_ERROR_STATUS"
    fi
}

acquire_lock() {
    # El flock es el mutex real y se conserva hasta EXIT. LOCK_FILE aporta
    # metadata diagnóstica, pero nunca decide por sí solo si el dueño está vivo.
    if acquire_guard; then
        :
    else
        return $?
    fi

    if crear_lock; then
        return 0
    fi

    if [ ! -e "$LOCK_FILE" ]; then
        echo "post-deploy.sh: no fue posible crear el archivo de lock." >&2
        return "$LOCK_ERROR_STATUS"
    fi

    recuperar_lock
}

trap 'exit_status=$?; finalize_post_deploy_status "$exit_status"; cleanup_schema_check_process; cleanup_schema_check_watchdog; cleanup_schema_check_log; cleanup_migration_process; cleanup_migration_watchdog; cleanup_migration_log; cleanup_backup_process; cleanup_backup_watchdog; cleanup_backup_log; cleanup_lock; cleanup_guard; exit "$exit_status"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

set_post_deploy_phase "lock"
lock_status=0
if acquire_lock; then
    :
else
    lock_status=$?
    if [ "$lock_status" -eq "$LOCK_SKIP_STATUS" ]; then
        exit 0
    fi
    echo "post-deploy.sh: no fue posible adquirir el lock de forma segura (código $lock_status)." >&2
    exit 3
fi

# D-053/D-054 / AGENTES.md §10: construction puede converger migraciones
# aditivas con backup previo; live y el opt-out detectan deriva y fallan cerrado.
set_post_deploy_phase "schema-check"
schema_check_status=0
if run_schema_check; then
    schema_check_status=0
else
    schema_check_status=$?
fi

if [ "$schema_check_status" -eq 124 ]; then
    print_log_tail "$SCHEMA_CHECK_LOG"
    cleanup_schema_check_log
    echo "post-deploy.sh: comprobación de esquema excedió ${SCHEMA_CHECK_TIMEOUT_SECONDS}s. Caché no modificada." >&2
    exit 2
fi

if [ "$schema_check_status" -eq 0 ]; then
    cleanup_schema_check_log
elif grep -Eiq 'previously[[:space:]_-]*executed|unregistered|not[[:space:]_-]*registered' "$SCHEMA_CHECK_LOG"; then
    print_log_tail "$SCHEMA_CHECK_LOG"
    cleanup_schema_check_log
    echo "post-deploy.sh: el historial de migraciones no coincide con el catálogo desplegado; se requiere reconciliación explícita. Caché no modificada." >&2
    exit 2
elif grep -Eiq 'not[[:space:]_-]*up[[:space:]_-]*to[[:space:]_-]*date|out[[:space:]_-]*of[[:space:]_-]*date|new[[:space:]_-]*migration|pending[[:space:]_-]*migration' "$SCHEMA_CHECK_LOG"; then
    cleanup_schema_check_log
    if [ "$PRODUCTION_STAGE" = "construction" ] && [ "$AUTO_MIGRATE" = "1" ]; then
        echo "post-deploy.sh: esquema pendiente en construction; validando migraciones versionadas." >&2
        if run_construction_migrations; then
            :
        else
            migration_result=$?
            exit "$migration_result"
        fi
    elif [ "$PRODUCTION_STAGE" = "construction" ]; then
        echo "post-deploy.sh: esquema pendiente en construction pero CONDOR_AUTO_MIGRATE=0; migración automática deshabilitada. Caché no modificada." >&2
        exit 2
    else
        echo "post-deploy.sh: esquema pendiente en live; migración automática deshabilitada. Caché no modificada." >&2
        exit 2
    fi
elif grep -Eiq 'sqlstate|connection|database|driver|server[[:space:]_-]*has[[:space:]_-]*gone[[:space:]_-]*away|timed?[[:space:]_-]*out' "$SCHEMA_CHECK_LOG"; then
    print_log_tail "$SCHEMA_CHECK_LOG"
    cleanup_schema_check_log
    echo "post-deploy.sh: Doctrine no pudo comprobar el esquema por base de datos o conectividad. Caché no modificada." >&2
    exit 2
else
    print_log_tail "$SCHEMA_CHECK_LOG"
    cleanup_schema_check_log
    echo "post-deploy.sh: Doctrine no pudo comprobar el esquema; fallo no clasificable. Caché no modificada." >&2
    exit 2
fi
set_post_deploy_phase "cache"
"$PHP_BIN" bin/console cache:clear --env=prod --no-warmup
"$PHP_BIN" bin/console cache:warmup --env=prod
set_post_deploy_phase "complete"
