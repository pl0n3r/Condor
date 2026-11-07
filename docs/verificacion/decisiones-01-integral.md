# Condor #1608 — testigos integrales D-011 / D-023 / D-025

Esta hoja continúa #1544, cuyo primer lote deja tres decisiones como `UNKNOWN`. **Corte local en fase de construcción**, exclusivamente con hosts sintéticos `.example.test`, kernel Symfony, Doctrine y la base MariaDB descartable del runner CI. No se consultan DNS públicos, Hostinger ni AWS. El veredicto productivo externo permanece `UNKNOWN`; las pruebas locales aprobadas se denominan `LOCAL_PARTIAL` y no actualizan la matriz canónica.

## Ejecutar y reproducir

En el job **Pruebas de contrato e integración**, después de `composer install`, generar `APP_SECRET` efímero y aplicar migraciones sobre `mysql://root@127.0.0.1:3306/condor_ci_bridge_28`, ejecutar:

```bash
python3 -m unittest discover -s tests -p 'test_verificacion_decisiones_*.py' -v
```

El nuevo archivo `tests/test_verificacion_decisiones_01_integral.py` se descubre por el patrón ya presente en `.github/workflows/ci.yml`; invoca **PHP 8.5** y el PHPUnit/Symfony real desde un fixture temporal. Fallar, saltar, carecer de `vendor`, carecer de PHP, tener una respuesta inesperada o incumplir una invariante falla el test (sin skips aceptados). El fixture es descartable, no se incorpora como ruta fuente y usa el contenedor HTTP del kernel con MariaDB de CI, no un servicio remoto.

| Decisión | Evidencia que ejecuta | Resultado permitido | Límite no acreditado |
| --- | --- | --- | --- |
| `D-011` | `test_d011_d025_http_kernel_reales` ⇒ `testD011PublicBrandAndSeoThroughActualKernel`: seis GET de marketing en `www.condorapp.com.co`; verifica 200, `lang=es-CO`, nombre técnico `Condor` en `<title>`, nombre público `Condor App` en pie, enlace SEO canónico exacto por ruta y meta descripción no vacía. | `LOCAL_PARTIAL` tras CI verde | No cubre cada vista autenticada ni todos los posibles títulos de UI; requiere auditoría transversal de páginas no públicas si se exige universalidad |
| `D-023` | `test_d023_php_symfony_mariadb_contract`: comprueba restricciones Composer PHP 8.5/Symfony 7.4 LTS, DBAL Doctrine 4 + `DATABASE_URL` configurable y `utf8mb4`, bootstrap real del kernel en entorno `test`; las pruebas HTTP ejercitan Doctrine sobre MariaDB CI. | `LOCAL_PARTIAL` tras CI verde | **Hostinger→AWS** sin ejecución AWS, networking, almacenamiento S3/volúmenes, backups/restauración, TLS, compatibilidad de extensiones y cutover real: `UNKNOWN` |
| `D-025` | `test_d011_d025_http_kernel_reales` ⇒ `testD025HostSlugIsolationAndCanonicalThroughKernel`: tenants A/B persistidos en MariaDB de prueba; GET por `/slug` plataforma y por host verificado; canonical por dominio primario y fallback a `/slug`; negativos 404 ante slug inexistente, host desconocido, host no verificado, path ajeno en host A y página comercial en host A. | `LOCAL_PARTIAL` tras CI verde | DNS/TLS reales, registro operativo de dominios y exhaustividad de todos los endpoints multitenant siguen `UNKNOWN` |

## Qué se verifica y qué no

- El runner ejecuta el **router, subscriber de contexto, resolver de tenants, controladores, ORM y templates de verdad** usando `KernelBrowser`; no usa mocks para falsificar las respuestas HTTP ni asume que un string documental sea ejecución.
- Cada variable de empresa/dominio se genera con sufijo aleatorio sobre `.example.test`, sin correo real, claves, datos personales ni peticiones a terceros. El escenario no crea ni modifica dominios reales.
- `D-011` distingue marca **Condor App** del nombre técnico **Condor** sin exigir que ambos estén en cada título HTML. SEO se valida en las seis rutas públicas enumeradas, no se atribuye cobertura universal.
- `D-023` acredita un contrato de configuración/boot y arranque reproducible **solo en CI**, no viabilidad desplegada en AWS. Las diferencias Hostinger→AWS que impliquen storage compartido, rutas persistentes, cron y terminación TLS necesitan hoja específica con claims de implementación y smoke de destino, no la inferencia de un check verde.
- `D-025` garantiza la ruta negativa y aislamiento de la portada pública sintética, no asegura por sí sola el aislamiento de APIs administrativas fuera de este recorrido.
- `test_documentacion_diferencia_evidencia_local_de_live` comprueba que el informe conserva límites y enlaces a los tests; las regresiones de HTTP, versión y aislamiento fallan cuando cambian respuestas/clases reales.

## Alcance, CI y reversión

**Claims únicos:** `docs/verificacion/decisiones-01-integral.md` y `tests/test_verificacion_decisiones_01_integral.py`. No se modifica `docs/matriz-cobertura.md`, ni `docs/verificacion/decisiones-01.md`, ni CI, controladores o infraestructura. Gate: `Pruebas de contrato e integración` y `CI Factory v1 / Validar` en `success` para el HEAD exacto, además de branch protection, reserva V3 auténtica y chequeos de seguridad aplicables. Resultado local nunca es prueba de producción.

**Reversión:** revertir el squash de este PR elimina únicamente el informe complementario y la suite de verificación; no cambia la aplicación ni sus datos productivos.