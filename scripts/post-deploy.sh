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
# Este script deja el contenedor de producción sincronizado con el código
# recién desplegado en cada deploy, sin tocar datos ni esquema.

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

"$PHP_BIN" bin/console cache:clear --env=prod --no-warmup
"$PHP_BIN" bin/console cache:warmup --env=prod
