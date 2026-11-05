# Condor #1551 — trazabilidad verificable del README (29 filas)

**Corte:** 5-nov-2026 · issue #1551 · reserva V3 `58bf3431-e00c-4ed4-a763-f6b137bd047a`. **PR:** se asociará antes del merge. La matriz original marca `NO_VERIFICABLE`; este documento identifica evidencias, **no cambia su veredicto global**. Todos los tests son locales/sintéticos: no se usaron tokens, GitHub vivo, Hostinger, datos de clientes ni navegación remota. Entrega **sin tocar `docs/matriz-cobertura.md`**.

La columna de límite distingue:
- `CONTRATO_LOCAL`: rutas, versiones, imports o guards comprobables sobre checkout.
- `DOCUMENTAL`: fuentes de verdad/roles/roadmap, no resultado operacional.
- `HTTP_INTEGRAL_PENDIENTE`: hay controlador y tests parciales; falta HTTP+DB integral de esa ruta con dos tenants/permisos.
- `INTEGRACION_PENDIENTE`, `NAVEGADOR_PENDIENTE`: se comprueban dependencias/plantillas, no kernel/DB ni navegador.
- `EXTERNO_PENDIENTE`: enlaces dinámicos y CI/observación remotos requieren evidencia GitHub/navegador/deploy exacta.
- `OPERACION_PENDIENTE`: política de migración testable no equivale a backup, rollback ni autorización productiva.

| Fila auditada | Fuente principal | Prueba existente para contexto | Límite |
| --- | --- | --- | --- |
| `README-01-01` | `README.md` | `tests/test_readme_contract_adoption.py` | EXTERNO_PENDIENTE |
| `README-02-01` | `README.md` | `tests/test_readme_contract_adoption.py` | EXTERNO_PENDIENTE |
| `README-02-02` | `README.md` | `tests/test_readme_contract_adoption.py` | EXTERNO_PENDIENTE |
| `README-02-03` | `docs/modulos-operativos.md` | `tests/test_readme_contract_adoption.py` | DOCUMENTAL |
| `README-02-04` | `README.md` | `tests/test_readme_contract_adoption.py` | EXTERNO_PENDIENTE |
| `README-03-03` | `src/Http/Controller/OrderController.php` | `tests/test_cobertura_condor_28.py` | HTTP_INTEGRAL_PENDIENTE |
| `README-03-04` | `src/Http/Controller/CustomerController.php` | `tests/test_cobertura_condor_28.py` | HTTP_INTEGRAL_PENDIENTE |
| `README-03-05` | `src/Http/Controller/InventoryController.php` | `tests/test_cobertura_condor_28.py` | HTTP_INTEGRAL_PENDIENTE |
| `README-03-06` | `src/Http/Controller/PurchasingController.php` | `tests/test_cobertura_condor_28.py` | HTTP_INTEGRAL_PENDIENTE |
| `README-05-01` | `composer.json` | `tests/test_symfony_lts_dependency_guard.py` | INTEGRACION_PENDIENTE |
| `README-05-02` | `package.json` | `tests/test_ci_frontend_claims.py` | NAVEGADOR_PENDIENTE |
| `README-05-03` | `templates/tenant/index.html.twig` | `tests/test_tenant_public_http_contract.py` | NAVEGADOR_PENDIENTE |
| `README-05-04` | `AGENTES.md` | `tests/test_operational_release_gate.py` | EXTERNO_PENDIENTE |
| `README-05-05` | `.github/workflows/ci.yml` | `tests/test_rector_gate.py` | EXTERNO_PENDIENTE |
| `README-07-01` | `AGENTES.md` | `tests/test_ci_self_audit.py` | DOCUMENTAL |
| `README-07-02` | `src/Application/Identity/BranchAuthorization.php` | `tests/test_new_modules_tenant_isolation.py` | HTTP_INTEGRAL_PENDIENTE |
| `README-07-03` | `scripts/live-schema-policy.php` | `tests/test_live_migration_policy.py` | OPERACION_PENDIENTE |
| `README-07-04` | `.github/workflows/ci.yml` | `tests/test_operational_release_gate.py` | EXTERNO_PENDIENTE |
| `README-08-01` | `README.md` | `tests/test_readme_contract_adoption.py` | DOCUMENTAL |
| `README-08-02` | `ESPECIFICACIONES.md` | `tests/test_docs_modulos_operativos.py` | DOCUMENTAL |
| `README-08-03` | `decisiones.yml` | `tests/test_readme_contract_adoption.py` | DOCUMENTAL |
| `README-08-04` | `.github/workflows/ci.yml` | `tests/test_readme_contract_adoption.py` | DOCUMENTAL |
| `README-09-01` | `composer.lock` | `tests/test_readme_contract_adoption.py` | CONTRATO_LOCAL |
| `README-09-02` | `phpstan.neon.dist` | `tests/test_rector_gate.py` | CONTRATO_LOCAL |
| `README-10-01` | `README.md` | `tests/test_readme_contract_adoption.py` | DOCUMENTAL |
| `README-10-02` | `README.md` | `tests/test_readme_contract_adoption.py` | DOCUMENTAL |
| `README-10-03` | `README.md` | `tests/test_readme_contract_adoption.py` | DOCUMENTAL |
| `README-10-04` | `README.md` | `tests/test_readme_contract_adoption.py` | DOCUMENTAL |
| `README-10-05` | `README.md` | `tests/test_readme_contract_adoption.py` | DOCUMENTAL |

## Qué queda realmente probado y qué no

1. `tests/test_verificacion_verify_readme_claims.py` exige 29 IDs, la presencia de cada frase en su sección, la fuente versionada y el witness, comprueba los filtros GitHub NOW/NEXT/BLOCKED con un caso negativo de query manipulada, confirma controller+ruta+permisos+scope servidor, metadata PHP/Symfony/React/TS/Vite, CI y la frontera no-live, y ejecuta cinco métodos reales del contrato de README.
2. `README-03-03..06`: `tests/test_cobertura_condor_28.py` ejercita subcontratos de dominio, **no cada endpoint integrado**. El test nuevo confirma existencia de controlador/ruta y `authorizedBranchScope`; no atribuye cobertura HTTP/BD que no ejecuta.
3. `README-01-01`, `README-02-01..02/04`, `README-05-04..05` y `README-07-04`: requieren lectura/observación externa en entorno autorizado o checks GitHub de identidad concreta. Los enlaces de búsqueda sólo son filtros: **no conceden reserva, no resuelven dependencias y no son un segundo planificador**. `README-05-02..03` exigen build/frontend real y render Twig/accesibilidad; verificar existencia de archivos no demuestra UX real.
4. La fuente de verdad productiva sigue siendo un observer con recibo terminal y SHA, no un badge, un PR ni un CI verde. `README-07-03` es política para migraciones compatibles: los backups/rollback reales y cambios live requieren autorización y evidencia separadas. `README-10-01..05` registra asignación de responsabilidades, no interacción cross-repo validada.
5. Los tests específicos ya existentes incluyen `tests/test_readme_contract_adoption.py`, `tests/test_cobertura_condor_27.py` (algunos módulos sí tienen negativos HTTP), `tests/test_cobertura_condor_28.py` (dominio sin HTTP integral), `tests/test_new_modules_tenant_isolation.py`, `tests/test_live_migration_policy.py` y `tests/test_rector_gate.py`. Su presencia en tabla **no equivale a ejecutarlos todos** en este lote.
6. La suite agregada puede ejecutarse con `python3 tests/test_verificacion_verify_readme_claims.py -v`. El workflow CI actual ejecuta `test_ci_*.py` y contratos específicos; **no ejecuta esta nueva ruta de test automáticamente**. Las puertas exigidas para el PR siguen siendo `CI Factory v1 / Validar`, checks de Condor sobre **CI exact-SHA**, CodeQL, SonarCloud y CodeRabbit FINAL; un `success` de CI no demuestra por sí solo que esta suite adicional corrió.

**Decisión de auditoría:** ningún `EXTERNO_PENDIENTE`, `HTTP_INTEGRAL_PENDIENTE` o `DOCUMENTAL` se promueve a `CUBIERTO` por este PR. Una hoja hija de integración necesita claims adicionales y entorno/autoridad adecuados; no fingir pruebas ni cerrar brechas globales por inferencia.
