#!/bin/sh
# Cron/post-deploy seguro para Hostinger shared hosting.
#
# Este script NO ejecuta migraciones productivas. AGENTES.md §10 exige
# autorización humana explícita para cualquier migración de producción.
# El cron solo comprueba que el esquema ya esté al día y, si lo está,
# regenera la caché bajo exclusión mutua. Si hay migraciones pendientes,
# falla cerrado antes de tocar caché y deja una señal accionable.
set -eu

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
LOCK_GUARD_DIR="var/post-deploy.lock.guard"
LOCK_MAX_AGE_SECONDS=21600
LOCK_INVALID_GRACE_MINUTES=5
LOCK_GUARD_GRACE_MINUTES=1
LOCK_TOKEN="$$-$(date +%s)"
LOCK_GUARD_OWNED=0

cleanup_guard() {
    if [ "$LOCK_GUARD_OWNED" -eq 1 ] &&
        [ -d "$LOCK_GUARD_DIR" ] &&
        [ "$(sed -n '1p' "$LOCK_GUARD_DIR/owner" 2>/dev/null || true)" = "$LOCK_TOKEN" ]; then
        rm -f -- "$LOCK_GUARD_DIR/owner"
        rmdir "$LOCK_GUARD_DIR" 2>/dev/null || true
    fi
    LOCK_GUARD_OWNED=0
}

cleanup_lock() {
    if [ -f "$LOCK_FILE" ] &&
        [ "$(sed -n '1p' "$LOCK_FILE" 2>/dev/null || true)" = "$LOCK_TOKEN" ]; then
        rm -f -- "$LOCK_FILE"
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
    if mkdir "$LOCK_GUARD_DIR" 2>/dev/null; then
        printf '%s\n%s\n%s\n' "$LOCK_TOKEN" "$$" "$(date +%s)" > "$LOCK_GUARD_DIR/owner"
        LOCK_GUARD_OWNED=1
        return 0
    fi

    guard_pid="$(sed -n '2p' "$LOCK_GUARD_DIR/owner" 2>/dev/null || true)"
    case "$guard_pid" in
        ''|*[!0-9]*)
            if [ -z "$(find "$LOCK_GUARD_DIR" -mmin +"$LOCK_GUARD_GRACE_MINUTES" -print -quit 2>/dev/null)" ]; then
                echo "post-deploy.sh: mutex de recuperación incompleto reciente; se omite." >&2
                return 1
            fi
            ;;
        *)
            if kill -0 "$guard_pid" 2>/dev/null; then
                echo "post-deploy.sh: otra recuperación de lock sigue activa (pid $guard_pid); se omite." >&2
                return 1
            fi
            ;;
    esac

    stale_guard="$LOCK_GUARD_DIR.stale.$LOCK_TOKEN"
    if ! mv "$LOCK_GUARD_DIR" "$stale_guard" 2>/dev/null; then
        echo "post-deploy.sh: el mutex de recuperación cambió concurrentemente; se omite." >&2
        return 1
    fi
    rm -f -- "$stale_guard/owner"
    rmdir "$stale_guard" 2>/dev/null || {
        echo "post-deploy.sh: mutex huérfano contiene datos inesperados; se omite." >&2
        return 1
    }

    if ! mkdir "$LOCK_GUARD_DIR" 2>/dev/null; then
        echo "post-deploy.sh: otro proceso adquirió el mutex primero; se omite." >&2
        return 1
    fi
    printf '%s\n%s\n%s\n' "$LOCK_TOKEN" "$$" "$(date +%s)" > "$LOCK_GUARD_DIR/owner"
    LOCK_GUARD_OWNED=1
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

trap 'cleanup_guard; cleanup_lock' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

if ! acquire_lock; then
    exit 0
fi

# D-044 / AGENTES.md §10: detectar deriva es automático; migrar producción no.
# Un esquema pendiente requiere autorización explícita y una operación separada.
if ! "$PHP_BIN" bin/console doctrine:migrations:up-to-date --env=prod --no-interaction >/dev/null 2>&1; then
    echo "post-deploy.sh: hay migraciones pendientes; se requiere autorización explícita antes de migrar producción. Caché no modificada." >&2
    exit 2
fi

"$PHP_BIN" bin/console cache:clear --env=prod --no-warmup
"$PHP_BIN" bin/console cache:warmup --env=prod
