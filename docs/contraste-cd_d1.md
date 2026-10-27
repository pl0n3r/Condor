# Contraste de decisiones Condor D-001 a D-018 — #1025

> Fuente normativa: [`ESPECIFICACIONES.md`](../ESPECIFICACIONES.md), decisiones `### D-001`…`### D-018`. Base inspeccionada: `main@0b3c1290b11a74acde5e6312ac720b82d247e61e` (27-oct-2026). Solo contraste documental en fase `construccion`; **no** acredita live, tratamientos reales ni un despliegue.

## Reglas de veredicto

- `CUBIERTO` exige ruta de código, prueba existente y PR realmente fusionado para el **alcance acotado** indicado.
- `NO_VERIFICABLE` conserva pendiente una obligación del producto o del proceso que no tiene aquí evidencia terminal y completa; la mera existencia de un archivo no acredita resultado operativo.
- `DESCARTADO` significa que **se excluye del denominador de conductas funcionales el juicio editorial/de procedencia**, **no** que la decisión del dueño pierda vigencia. Razón particular obligatoria por fila.
- `SIN_COBERTURA` solo se usa si hay falla material constatada y hoja ejecutable creada. Este corte no inventa hojas por ausencia de evidencia. Ningún `NO_VERIFICABLE` es una aprobación implícita.

## Veredictos individuales

| ID | Veredicto | Evidencia observada, límite o razón |
| --- | --- | --- |
| `D-001` | `DESCARTADO` | Razón D-001: guía editorial de procedencia de prácticas BRVTAL, no una conducta verificable del producto; `ESPECIFICACIONES.md` excluye expresamente reutilización automática de módulos y datos. No se descarta la decisión, solo su clasificación como test funcional. |
| `D-002` | `DESCARTADO` | Razón D-002: separación editorial de Issue #1, `ROADMAP.md`, `ESPECIFICACIONES.md` y `AGENTES.md`; esas fuentes existen, pero no definen una transición observable del runtime. Reglas de gobernanza permanecen activas fuera del porcentaje de cobertura funcional. |
| `D-003` | `NO_VERIFICABLE` | Falta para D-003 una prueba ejecutable del fallback `es-CO` en rutas públicas y administración; `README.md` declara mercado colombiano pero una declaración de locale no demuestra renderización ni negociación real de idioma. |
| `D-004` | `DESCARTADO` | Razón D-004: herencia selectiva de prácticas en `AGENTES.md` y Factory es proceso de desarrollo, no feature de cliente; el documento local existe y delimita dominios, sin prueba funcional aplicable a una afirmación histórica de influencia. |
| `D-005` | `NO_VERIFICABLE` | Falta para D-005 probar identidad única por deploy observado, paridad y no reutilización de versión; `config/version.php` devuelve 0.1.195 y el contrato de release está documentado, pero un merge no acredita versión real desplegada. |
| `D-006` | `NO_VERIFICABLE` | Falta para D-006 correlación autenticada README-versión/SHA-resultado del despliegue; `README.md` describe candidato y gates, no se dispone aquí de un recibo de despliegue real ni regresión que lo confronte con el snapshot. |
| `D-007` | `DESCARTADO` | Razón D-007: `GLOSARIO.md` existe y cumple un propósito editorial de explicación a socios; calidad del lenguaje para negocio y mantenimiento semántico no se infieren de una aserción binaria de comportamiento del producto. |
| `D-008` | `DESCARTADO` | Razón D-008: política histórica append-only del Issue #1 es gobernanza de GitHub, no API/función del producto; el enlace `ROADMAP.md` no demuestra por sí solo integridad temporal de todo el historial. |
| `D-009` | `NO_VERIFICABLE` | Falta para D-009 test de todos los tipos de artefactos GitHub (Issues, PRs, Releases y milestones) y normalización de títulos `(V X.Y.Z)`; observar un título conforme no prueba aplicación universal ni rechazo fail-closed. |
| `D-010` | `DESCARTADO` | Razón D-010: separación de contenido cambiante del Issue #1 frente a `ESPECIFICACIONES.md` es una convención editorial; no debe figurar como falta de conducta de runtime ni interpretarse como derogación de la norma. |
| `D-011` | `NO_VERIFICABLE` | Falta para D-011 aceptación de marca `Condor App`, nombre corto Condor y dominio en todas las superficies públicas; `README.md` y `GLOSARIO.md` usan esas denominaciones, sin prueba transversal de UI y SEO publicada. |
| `D-012` | `NO_VERIFICABLE` | Falta para D-012 evidencia de contrato completo de coordinación, recuperación/expiración de lease y serialización; `.github/workflows/coordinacion-trabajo.yml` delega a Factory y un recibo V3 puntual no demuestra todos los caminos negativos. |
| `D-013` | `NO_VERIFICABLE` | Falta para D-013 probar trigger automático por cada check Sonar completado y comentario idempotente con detalles; `.github/workflows/sonar-annotation-relay.yml` contempla `workflow_dispatch`, pero no se acreditó un roundtrip terminal exact-HEAD completo. |
| `D-014` | `NO_VERIFICABLE` | Falta para D-014 enlazar la regresión de throughput con PR fusionado y evidencia post-CI sobre mismo tipo de evento; `.github/workflows/ci-throughput-telemetry.yml`, `scripts/ci_throughput_report.py` y `tests/test_ci_throughput_report.py` muestran implementación parcial, no todo el ciclo observador. |
| `D-015` | `NO_VERIFICABLE` | Falta para D-015 prueba que vincule la baseline entera con el contrato y PR fusionado, incluyendo Playwright Chromium, selectores accesibles y retención de artifacts. `.github/workflows/ci.yml` contiene gates parciales, pero no certifica todos esos enunciados. |
| `D-016` | `NO_VERIFICABLE` | Falta para D-016 verificación exact-HEAD del runner `ubuntu-24.04`, `channel: chrome`, ausencia de descarga Chromium y telemetría comparada; la configuración de CI por sí sola no prueba el paquete completo ni la mejora de segundos citada. |
| `D-017` | `CUBIERTO` | src/Http/Controller/CatalogController.php + tests/php/Http/CatalogControllerTest.php + [PR #152 MERGED](https://github.com/pl0n3r/Condor/pull/152) (merge `0a60bafad39ef1b6bbda4bb475a7a5a005b157d4`). Evidencia limitada al catálogo comercial inicial; no acredita digitalización integral, e-commerce completo o producción. |
| `D-018` | `NO_VERIFICABLE` | Falta para D-018 probar defaults y overrides por tenant, configuración de roles CRUD, sedes y negaciones cross-tenant; `AGENTES.md` impone invariantes, pero no sustituye un test de política configurable y denegación real. |

## Conciliación del corte

- Fuente controlada: 18 filas exactas `D-001` a `D-018` (no hay subapartados `D-001-Snn`…`D-018-Snn` en la matriz vigente); el supuesto de 43 era una estimación y no autoriza filas ficticias.
- Dictámenes: 1 `CUBIERTO` previamente validado, 6 `DESCARTADO` únicamente como **no evaluables por prueba funcional**, 11 `NO_VERIFICABLE` con brecha explícita; 0 `SIN_COBERTURA` y 0 `EN_CURSO`.
- `D-017` conserva evidencia de PR #152 realmente fusionado. No se agregaron tests de producto para simular coberturas ni se sustituyó un error por una exclusión editorial.
- **Segunda fase integrada en este PR:** #1026/PR #1075 ya fusionado, reserva ajena liberada y V3 #1025 renovada a `1697912d-bbe1-5f71-a332-dd718d9c8e27` con `docs/matriz-cobertura.md`. Se han reconciliado **18 filas** contra `main@18e3c79b784274bfc5bfda1f789a81a42fe4ebc5`; en 472 requisitos hay 23 `CUBIERTO`, 443 `NO_VERIFICABLE`, 6 `DESCARTADO`, 0 `SIN_COBERTURA` y 0 `EN_CURSO`. La aceptación AC-04 sigue exigiendo `Validar` final exact-HEAD, no presume CI ni producción.
- Reversión: los dos archivos de contraste se retiran; revertir el squash completo restaurará también las filas de matriz modificadas en la segunda fase. El resto de requisitos no se toca.
