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
LOCK_TOKEN="$-$(date +%s)"
LOCK_GUARD_OWNED=0
SCHEMA_CHECK_LOG=""

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

cleanup_schema_check_log() {
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
    exec 9>"$LOCK_GUARD_FILE"
    if ! "$FLOCK_BIN" -n 9; then
        echo "post-deploy.sh: otra recuperación de lock sigue activa; se omite." >&2
        exec 9>&-
        return 1
    fi

    LOCK_GUARD_OWNED=1
    return 0
}

recuperar_lock() {
    if ! acquire_guard; then
        cleanup_guard
        return 1
    fi

    owner_token="$(sed -n '1p' "$LOCK_FILE" 2>/dev/null || true)"
    owner_pid="$(sed -n '2p' "$LOCK_FILE" 2>/dev/null || true)"
    created_at="$(sed -n '3p' "$LOCK_FILE" 2>/dev/null || true)"

    case "$owner_pid:$created_at" in
        *[!0-9:]*|:*|*:)
            if [ -z "$(find "$LOCK_FILE" -mmin +"$LOCK_INVALID_GRACE_MINUTES" -print -quit 2>/dev/null)" ]; then
                echo "post-deploy.sh: lock incompleto reciente; se omite esta corrida." >&2
                cleanup_guard
                return 1
            fi
            ;;
        *)
            if kill -0 "$owner_pid" 2>/dev/null; then
                echo "post-deploy.sh: otra corrida sigue activa (pid $owner_pid); se omite." >&2
                cleanup_guard
                return 1
            fi

            now="$(date +%s)"
            age=$((now - created_at))
            if [ "$age" -le "$LOCK_MAX_AGE_SECONDS" ]; then
                echo "post-deploy.sh: lock huérfano detectado; se recupera de forma segura." >&2
            else
                echo "post-deploy.sh: lock huérfano y vencido; se recupera." >&2
            fi
            ;;
    esac

    stale="$LOCK_FILE.stale.$LOCK_TOKEN"
    if ! mv "$LOCK_FILE" "$stale" 2>/dev/null; then
        echo "post-deploy.sh: el lock cambió concurrentemente; se omite." >&2
        cleanup_guard
        return 1
    fi
    rm -f -- "$stale"

    if ! crear_lock; then
        echo "post-deploy.sh: otro proceso adquirió el lock primero; se omite." >&2
        cleanup_guard
        return 1
    fi

    cleanup_guard
}

acquire_lock() {
    if crear_lock; then
        return 0
    fi
    recuperar_lock
}

trap 'cleanup_schema_check_log; cleanup_guard; cleanup_lock' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

if ! acquire_lock; then
    exit 0
fi

# D-044 / AGENTES.md §10: detectar deriva es automático; migrar producción no.
# Un esquema pendiente requiere autorización explícita y una operación separada.
SCHEMA_CHECK_LOG="$(mktemp "${TMPDIR:-/tmp}/condor-schema-check-XXXXXX.log")"
schema_check_status=0
if "$PHP_BIN" bin/console doctrine:migrations:up-to-date --env=prod --no-interaction >"$SCHEMA_CHECK_LOG" 2>&1; then
    cleanup_schema_check_log
else
    schema_check_status=$?

    if grep -Eiq 'sqlstate|connection|database|driver|server[[:space:]_-]*has[[:space:]_-]*gone[[:space:]_-]*away|timed?[[:space:]_-]*out' "$SCHEMA_CHECK_LOG"; then
        schema_diagnostic="Doctrine no pudo comprobar el esquema por un fallo de base de datos o conectividad."
    elif grep -Eiq 'not[[:space:]_-]*up[[:space:]_-]*to[[:space:]_-]*date|new[[:space:]_-]*migration|pending[[:space:]_-]*migration|previously[[:space:]_-]*executed[[:space:]_-]*migration' "$SCHEMA_CHECK_LOG"; then
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
