# Verificación lote 02 — Condor #1545 · D-030…D-040-S06

Fase `construccion`. Reserva V3: `3b252a37-e1f7-463e-8736-7d095a6e16d4`. Claims: `docs/verificacion/decisiones-02.md` y `tests/test_verificacion_decisiones_02.py`.

## Alcance y evidencia

20 decisiones contrastadas con especificación, matriz, fuente de código/configuración y PRs anteriores fusionados. Las suites Python de cobertura 04/05/06 y brechas 07 ejecutan PHP real y negativos con fixtures. Esas regresiones prueban **subcontratos locales**, no el cumplimiento integral, hosting o go-live. Las decisiones C son normas/producto, no funciones PHP.

| ID | Fuente | Testigo previo y PR MERGED | Veredicto parcial y brecha |
| --- | --- | --- | --- |
| D-030 | `ESPECIFICACIONES.md` | `tests/test_cobertura_condor_04.py` · [PR #1383 MERGED](https://github.com/pl0n3r/Condor/pull/1383) | **C normativa**: Decisión de producto; sin aceptación comercial integral. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |
| D-030-S01 | `ESPECIFICACIONES.md` | `tests/test_cobertura_condor_04.py` · [PR #1383 MERGED](https://github.com/pl0n3r/Condor/pull/1383) | **C normativa**: Validación de mercado no demostrable con fixtures. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |
| D-030-S02 | `ESPECIFICACIONES.md` | `tests/test_cobertura_condor_05.py` · [PR #1357 MERGED](https://github.com/pl0n3r/Condor/pull/1357) | **C normativa**: Los 17 dominios definidos no equivalen a implementación completa. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |
| D-030-S03 | `ESPECIFICACIONES.md` | `tests/test_cobertura_condor_05.py` · [PR #1357 MERGED](https://github.com/pl0n3r/Condor/pull/1357) | **C normativa**: Sin comprobación de coherencia en UI y persistencia. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |
| D-030-S04 | `src/Domain/Orders/OrderStateMachine.php` | `tests/test_cobertura_condor_05.py` · [PR #1357 MERGED](https://github.com/pl0n3r/Condor/pull/1357) | **A parcial**: Estados reales sin cobertura integral de stock y concurrencia. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |
| D-031 | `src/Http/Controller/TenantPublicController.php` | `tests/test_cobertura_condor_05.py` · [PR #1357 MERGED](https://github.com/pl0n3r/Condor/pull/1357) | **A parcial**: Faltan aislamientos tenant y membresías en todas las entidades/DB. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |
| D-032 | `src/Domain/Orders/OrderStateMachine.php` | `tests/test_cobertura_condor_05.py` · [PR #1357 MERGED](https://github.com/pl0n3r/Condor/pull/1357) | **A parcial**: Falta locking, ledger y reservas/consumos concurrentes. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |
| D-033 | `src/Http/Controller/TenantPublicController.php` | `tests/test_cobertura_condor_05.py` · [PR #1357 MERGED](https://github.com/pl0n3r/Condor/pull/1357) | **A parcial**: Faltan editor CMS, temas, navegador y dominios verificados. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |
| D-034 | `config/packages/security.yaml` | `tests/test_cobertura_condor_05.py` · [PR #1357 MERGED](https://github.com/pl0n3r/Condor/pull/1357) | **B integral**: Sin prueba integral de rutas, errores, UTC, permisos y auditoría. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |
| D-035 | `src/Domain/Workflow/WorkflowEngine.php` | `tests/test_cobertura_condor_05.py` · [PR #1357 MERGED](https://github.com/pl0n3r/Condor/pull/1357) | **A parcial**: Faltan post-commit, adaptadores y portabilidad real. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |
| D-036 | `.github/workflows/ci.yml` | `tests/test_cobertura_condor_05.py` · [PR #1357 MERGED](https://github.com/pl0n3r/Condor/pull/1357) | **B externa**: Faltan backups, accesibilidad y observabilidad real. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |
| D-037 | `config/packages/security.yaml` | `tests/test_cobertura_condor_05.py` · [PR #1357 MERGED](https://github.com/pl0n3r/Condor/pull/1357) | **A parcial**: Rutas protegidas no certifican DTO e idempotencia para móvil. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |
| D-039 | `src/Http/Controller/SecurityController.php` | `tests/test_brechas_condor_06.py` · [PR #1269 MERGED](https://github.com/pl0n3r/Condor/pull/1269) | **A parcial**: Falta observar login y footer público con misma versión. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |
| D-040 | `config/packages/security.yaml` | `tests/test_cobertura_condor_05.py` · [PR #1357 MERGED](https://github.com/pl0n3r/Condor/pull/1357) | **B integral**: Faltan 14 subcontratos y negativos transversales. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |
| D-040-S01 | `config/packages/security.yaml` | `tests/test_cobertura_condor_06.py` · [PR #1355 MERGED](https://github.com/pl0n3r/Condor/pull/1355) | **A parcial**: Faltan rehash, anti-enumeración y sesiones completas. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |
| D-040-S02 | `config/packages/framework.yaml` | `tests/test_cobertura_condor_06.py` · [PR #1355 MERGED](https://github.com/pl0n3r/Condor/pull/1355) | **B integral**: Faltan revocación, límite absoluto 12h y cookies de navegador. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |
| D-040-S03 | `templates/security/login.html.twig` | `tests/test_cobertura_condor_06.py` · [PR #1355 MERGED](https://github.com/pl0n3r/Condor/pull/1355) | **B integral**: Faltan todas las mutaciones React/AJAX con negativos. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |
| D-040-S04 | `src/Application/Storefront/PublicCatalogSearch.php` | `tests/test_cobertura_condor_06.py` · [PR #1355 MERGED](https://github.com/pl0n3r/Condor/pull/1355) | **B integral**: Falta comprobar SQL/DQL/allowlists en todos los endpoints. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |
| D-040-S05 | `src/Application/Storefront/PublicCatalogSearch.php` | `tests/test_cobertura_condor_06.py` · [PR #1355 MERGED](https://github.com/pl0n3r/Condor/pull/1355) | **A parcial**: Filtros de catálogo no prueban permisos/mass assignment de todas las APIs. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |
| D-040-S06 | `config/packages/rate_limiter.yaml` | `tests/test_cobertura_condor_06.py` · [PR #1355 MERGED](https://github.com/pl0n3r/Condor/pull/1355) | **B integral**: Sin evidencia bajo carga distribuida por identidad/IP. [#1613](https://github.com/pl0n3r/Condor/issues/1613) |

## Criterios de aceptación y límites

- **AC-01:** el test coteja las 20 filas exactas, headings normativos, anclas y PRs históricos. Estado integral `NO_VERIFICABLE`, sin tocar `docs/matriz-cobertura.md`; las brechas están en [#1613](https://github.com/pl0n3r/Condor/issues/1613).
- **AC-02:** `tests/test_verificacion_decisiones_02.py` ejecuta de verdad `CoberturaCondor04Tests`, `CoberturaCondor05Tests`, `CoberturaCondor06Tests` y `BrechasCondor07Tests`, con aserciones positivas/negativas; también rechaza duplicidad/ausencia/promoción falsa de filas y retiro de controles. Revertir el comportamiento probado debe romper los tests correspondientes.
- **AC-03:** `CI Factory v1 / Validar` terminal verde solo en el HEAD exacto de su PR, con CodeQL/SonarCloud y CodeRabbit FINAL según D-069/D-070. La ejecución de los nuevos tests en CI depende del contrato de [#1610](https://github.com/pl0n3r/Condor/issues/1610); verde sin correr la suite no es evidencia.
- Las 20 decisiones conservan veredicto integral **UNKNOWN**, pendientes de HTTP/DB/navegador y evidencias externas en #1613.

Fuera de alcance: producción, Hostinger, DNS, datos reales, secretos, pagos, go-live, SQL destructivo y cambios en matriz.
