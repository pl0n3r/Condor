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

php bin/console cache:clear --env=prod --no-warmup
php bin/console cache:warmup --env=prod
