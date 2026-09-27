# Condor App — Snapshot operativo · Runtime Cache Hotfix V 0.1.60

> **Candidato:** Issue #286 · rutas nuevas 404 tras post-deploy V0.1.59.

Condor continúa en construcción. V0.1.60 corrige el aislamiento de cache Symfony entre releases en Hostinger shared hosting: el cache no efímero incorpora la versión canónica para que PHP-FPM cargue un matcher/router nuevo en cada release en vez de reutilizar el mismo path compilado.

## Alcance
- cache prod versionado como `var/cache/prod-v<version>`;
- la versión se lee de `config/version.php` y se normaliza a un componente filesystem-safe;
- `CONDOR_EPHEMERAL_CACHE=1` conserva el contrato previo de aislamiento por PID/perfil;
- no se borra ningún cache de releases anteriores;
- no se usa `opcache_reset()`, restart de PHP-FPM, SSH ni endpoint de mantenimiento;
- regresión ejecutable en `tests/test_versioned_runtime_cache.py`;
- aceptación productiva exige que las rutas #283 dejen de responder 404.

## Seguridad y reversión
No hay DB, PII, secretos ni permisos nuevos. El cambio solo altera la ubicación del cache compilado de Symfony para releases normales. Revertir restaura el path estándar; los caches versionados antiguos quedan inertes y no se eliminan durante este incidente.

## Evidencia base
- `main@dcd48c302433f1ba388042bc9781aa168db7b5fc` · V0.1.59.
- Health exact-SHA y schema estaban sanos, pero `/configurar-condor` y `/api/public/configurator/catalog` seguían 404 incluso después de post-deploy V0.1.59 `complete/success`.
- Reserva #286: `dd6198a4-22b0-440a-9746-f08605e7ed8c`.
- #284 permanece bloqueado hasta recuperar PRODUCCIÓN EN VERDE.
