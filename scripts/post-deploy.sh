#!/bin/sh
# Ejecutar como comando de post-deploy en la integración Git de Hostinger,
# desde la raíz del proyecto (mismo directorio que composer.json).
#
# Por qué existe: el despliegue por Git de Hostinger preserva var/cache/prod
# entre builds (coincide con .gitignore) en vez de regenerarlo. Si un deploy
# cambia servicios/dependencias del contenedor de Symfony, el contenedor
# compilado queda desincronizado con el código nuevo y produce un error
# fatal tan temprano en el arranque que ni el manejador de errores propio
# de Condor (ErrorIncidentSubscriber) llega a interceptarlo: el visitante
# ve la página genérica del hosting en vez del diagnóstico seguro.
#
# Este script sincroniza el runtime con el código desplegado: aplica únicamente
# migraciones forward compatibles con producción y luego recompila caché.
# Las migraciones destructivas siguen prohibidas por D-040/D-044.

set -eu

cd "$(dirname "$0")/.."

# El hosting compartido puede tener varias versiones de PHP instaladas con
# un "php" por defecto más viejo que el runtime real del sitio (visto en
# Hostinger: CLI por defecto en 8.2 mientras el sitio corre en 8.5). Se
# prueban binarios conocidos de PHP 8.5+ antes de caer al "php" del PATH.
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

# El cron de Hostinger ejecuta este script cada 5 minutos: se evita que dos
# corridas se solapen sobre el mismo esquema o la misma caché. El lock usa
# un token de propietario y puede recuperar un directorio huérfano después
# de 6 h sin permitir que una corrida vieja borre el lock de una nueva.
LOCK_DIR="var/post-deploy.lock"
LOCK_MAX_AGE_SECONDS=21600
LOCK_TOKEN="$$-$(date +%s)"

cleanup_lock() {
    if [ -f "$LOCK_DIR/token" ] &&
        [ "$(cat "$LOCK_DIR/token" 2>/dev/null || true)" = "$LOCK_TOKEN" ]; then
        rm -rf "$LOCK_DIR"
    fi
}

acquire_lock() {
    if mkdir "$LOCK_DIR" 2>/dev/null; then
        printf '%s\n' "$LOCK_TOKEN" > "$LOCK_DIR/token"
        printf '%s\n' "$(date +%s)" > "$LOCK_DIR/created_at"
        return 0
    fi

    created_at="$(cat "$LOCK_DIR/created_at" 2>/dev/null || true)"
    case "$created_at" in
        ''|*[!0-9]*)
            echo "post-deploy.sh: lock existente sin timestamp válido; se omite." >&2
            return 1
            ;;
        *)
            ;;
    esac

    now="$(date +%s)"
    age=$((now - created_at))
    if [ "$age" -le "$LOCK_MAX_AGE_SECONDS" ]; then
        echo "post-deploy.sh: otra corrida sigue en curso; se omite." >&2
        return 1
    fi

    stale_dir="$LOCK_DIR.stale.$LOCK_TOKEN"
    if ! mv "$LOCK_DIR" "$stale_dir" 2>/dev/null; then
        echo "post-deploy.sh: el lock cambió concurrentemente; se omite." >&2
        return 1
    fi
    rm -rf "$stale_dir"

    if ! mkdir "$LOCK_DIR" 2>/dev/null; then
        echo "post-deploy.sh: otro proceso recuperó el lock primero; se omite." >&2
        return 1
    fi
    printf '%s\n' "$LOCK_TOKEN" > "$LOCK_DIR/token"
    printf '%s\n' "$now" > "$LOCK_DIR/created_at"
}

if ! acquire_lock; then
    exit 0
fi
trap cleanup_lock EXIT INT TERM

# D-044: sin este paso el código nuevo llega sin su esquema (storefront
# V 0.1.13 respondía 500 en producción). Las migraciones de Condor son
# expand-compatible y las riesgosas abortan solas ante datos reales; sin
# migraciones pendientes es un no-op.
"$PHP_BIN" bin/console doctrine:migrations:migrate --env=prod --no-interaction --allow-no-migration

"$PHP_BIN" bin/console cache:clear --env=prod --no-warmup
"$PHP_BIN" bin/console cache:warmup --env=prod
