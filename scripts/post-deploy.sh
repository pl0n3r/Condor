#!/bin/sh
# Cron/post-deploy seguro para Hostinger shared hosting.
#
# Este script NO ejecuta migraciones productivas. AGENTES.md §10 exige
# autorización humana explícita para cualquier migración de producción.
# El cron solo comprueba que el esquema ya esté al día y, si lo está,
# regenera la caché bajo exclusión mutua. Si hay migraciones pendientes,
# falla cerrado antes de tocar caché y deja una señal accionable.
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

LOCK_FILE="var/post-deploy.lock"
LOCK_GUARD_FILE="var/post-deploy.lock.guard"
LOCK_MAX_AGE_SECONDS=21600
LOCK_INVALID_GRACE_MINUTES=5
LOCK_TOKEN="$$-$(date +%s)"
LOCK_GUARD_OWNED=0
LOCK_SKIP_STATUS=10
LOCK_ERROR_STATUS=11
SCHEMA_CHECK_TIMEOUT_SECONDS="${CONDOR_SCHEMA_CHECK_TIMEOUT_SECONDS:-60}"
case "$SCHEMA_CHECK_TIMEOUT_SECONDS" in
    ''|*[!0-9]*|0)
        echo "post-deploy.sh: CONDOR_SCHEMA_CHECK_TIMEOUT_SECONDS debe ser un entero entre 1 y 300." >&2
        exit 1
        ;;
esac
if [ "$SCHEMA_CHECK_TIMEOUT_SECONDS" -gt 300 ]; then
    echo "post-deploy.sh: CONDOR_SCHEMA_CHECK_TIMEOUT_SECONDS no puede superar 300." >&2
    exit 1
fi
SCHEMA_CHECK_LOG=""
SCHEMA_CHECK_TIMEOUT_MARKER=""
SCHEMA_CHECK_PID=""
SCHEMA_CHECK_WATCHDOG_PID=""

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
        wait "$SCHEMA_CHECK_WATCHDOG_PID" 2>/dev/null || true
        SCHEMA_CHECK_WATCHDOG_PID=""
    fi
}

cleanup_schema_check_log() {
    if [ -n "$SCHEMA_CHECK_TIMEOUT_MARKER" ]; then
        rm -f -- "$SCHEMA_CHECK_TIMEOUT_MARKER"
        SCHEMA_CHECK_TIMEOUT_MARKER=""
    fi
    if [ -n "$SCHEMA_CHECK_LOG" ]; then
        rm -f -- "$SCHEMA_CHECK_LOG"
        SCHEMA_CHECK_LOG=""
    fi
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
    fi

    guard_status=$?
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

trap 'cleanup_schema_check_process; cleanup_schema_check_watchdog; cleanup_schema_check_log; cleanup_lock; cleanup_guard' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

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

# D-044 / AGENTES.md §10: detectar deriva es automático; migrar producción no.
# Un esquema pendiente requiere autorización explícita y una operación separada.
SCHEMA_CHECK_LOG="$(mktemp "${TMPDIR:-/tmp}/condor-schema-check-XXXXXX.log")"
SCHEMA_CHECK_TIMEOUT_MARKER="${SCHEMA_CHECK_LOG}.timeout"
schema_check_status=0

"$PHP_BIN" bin/console doctrine:migrations:up-to-date --env=prod --no-interaction --fail-on-unregistered >"$SCHEMA_CHECK_LOG" 2>&1 &
SCHEMA_CHECK_PID=$!

(
    sleep "$SCHEMA_CHECK_TIMEOUT_SECONDS"
    if kill -0 "$SCHEMA_CHECK_PID" 2>/dev/null; then
        : >"$SCHEMA_CHECK_TIMEOUT_MARKER"
        kill -TERM "$SCHEMA_CHECK_PID" 2>/dev/null || true
        sleep 2
        kill -KILL "$SCHEMA_CHECK_PID" 2>/dev/null || true
    fi
) &
SCHEMA_CHECK_WATCHDOG_PID=$!

if wait "$SCHEMA_CHECK_PID"; then
    schema_check_status=0
else
    schema_check_status=$?
fi
SCHEMA_CHECK_PID=""
cleanup_schema_check_watchdog

if [ -f "$SCHEMA_CHECK_TIMEOUT_MARKER" ]; then
    cleanup_schema_check_log
    echo "post-deploy.sh: comprobación de esquema excedió ${SCHEMA_CHECK_TIMEOUT_SECONDS}s. Caché no modificada." >&2
    exit 2
fi

if [ "$schema_check_status" -eq 0 ]; then
    cleanup_schema_check_log
else
    if grep -Eiq 'sqlstate|connection|database|driver|server[[:space:]_-]*has[[:space:]_-]*gone[[:space:]_-]*away|timed?[[:space:]_-]*out' "$SCHEMA_CHECK_LOG"; then
        schema_diagnostic="Doctrine no pudo comprobar el esquema por un fallo de base de datos o conectividad."
    elif grep -Eiq 'not[[:space:]_-]*up[[:space:]_-]*to[[:space:]_-]*date|out[[:space:]_-]*of[[:space:]_-]*date|new[[:space:]_-]*migration|pending[[:space:]_-]*migration|previously[[:space:]_-]*executed[[:space:]_-]*migration' "$SCHEMA_CHECK_LOG"; then
        schema_diagnostic="Doctrine reporta migraciones pendientes o historial de migraciones no reconciliado."
    else
        schema_diagnostic="Doctrine no pudo comprobar el esquema; el fallo no pudo clasificarse de forma segura."
    fi

    cleanup_schema_check_log
    echo "post-deploy.sh: comprobación de esquema falló (código $schema_check_status). $schema_diagnostic Caché no modificada." >&2
    exit 2
fi

"$PHP_BIN" bin/console cache:clear --env=prod --no-warmup
"$PHP_BIN" bin/console cache:warmup --env=prod
