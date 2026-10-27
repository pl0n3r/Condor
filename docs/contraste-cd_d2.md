# Contraste verificable D-019 a D-036 — Condor

Issue #1026 — 2026-10-27. Corte: main 0b3c1290b11a74acde5e6312ac720b82d247e61e. Fuente: ESPECIFICACIONES.md. Fase: construccion.

El censo histórico tiene 22 IDs para este grupo, incluyendo D-030-S01 a D-030-S04. Se comprobaron rutas del repo y PR #180 fusionado. CUBIERTO certifica solo la porción de código realmente probada, no todas las reglas, ni producción. NO_VERIFICABLE expresa evidencia insuficiente, nunca defecto inferido. SIN_COBERTURA requiere ausencia positivamente demostrada y un Issue ejecutable real enlazado. DESCARTADO exige razón formal explícita.

## Veredicto por cada ID

| ID | Estado | Código / fuente inspeccionada | Test real o pendiente | PR fusionado | Límite técnico concreto |
| --- | --- | --- | --- | --- | --- |
| D-019 | CUBIERTO | src/Application/Commerce/PricingService.php | tests/php/Application/Commerce/PricingServiceTest.php | PR #180 MERGED: https://github.com/pl0n3r/Condor/pull/180 | Solo resolución de precio efectivo; faltan pruebas de permisos y motivo de edición manual. |
| D-020 | NO_VERIFICABLE | frontend/admin/ | — | — | No existe evidencia integral de configuración progresiva, defaults y pantallas avanzadas. |
| D-021 | NO_VERIFICABLE | src/Domain/Commerce/ | — | — | Falta prueba combinada de estados de pedido, pago y cumplimiento con auditoría. |
| D-022 | NO_VERIFICABLE | src/Domain/Workflow/WorkflowEngine.php | tests/test_workflow_core.py | — | Hay evaluador con tenant y permisos; faltan todas las integraciones transversales. |
| D-023 | NO_VERIFICABLE | config/ | — | — | Stack PHP presente; falta prueba de portabilidad real de Hostinger a AWS. |
| D-024 | NO_VERIFICABLE | src/ | — | — | Módulos y Doctrine presentes; falta regresión integral ORM, media, REST y builds. |
| D-025 | NO_VERIFICABLE | src/Infrastructure/Tenancy/TenantResolver.php | tests/php/Infrastructure/Tenancy/TenantResolverTest.php | — | Hay resolución de dominio verificado; faltan permisos y aislamiento end-to-end. |
| D-026 | NO_VERIFICABLE | src/Application/Commercial/ | — | — | No se ha acreditado el modelo completo de mensualidad, módulos y pagos manuales. |
| D-027 | NO_VERIFICABLE | src/Application/Inventory/ChannelInventorySource.php | — | — | Selector presente; falta test de sedes, no-fallback, auditoría y precios por canal. |
| D-028 | NO_VERIFICABLE | src/Domain/ | — | — | Entidades parciales; falta test de autorización legal, cliente y roles por sede. |
| D-029 | CUBIERTO | src/Application/Commerce/PricingService.php | tests/php/Application/Commerce/PricingServiceTest.php | PR #180 MERGED: https://github.com/pl0n3r/Condor/pull/180 | Solo precio base con precedencia; no descuentos futuros ni UI configurable. |
| D-030 | NO_VERIFICABLE | ESPECIFICACIONES.md | — | — | Decisión paraguas de diseño; no existe un contrato integral demostrable. |
| D-030-S01 | NO_VERIFICABLE | ESPECIFICACIONES.md | — | — | Propuesta de valor comercial: validación del mercado no es test de código. |
| D-030-S02 | NO_VERIFICABLE | src/Application/ | — | — | Mapa conceptual de dominios, falta test de todas las dependencias entre áreas. |
| D-030-S03 | NO_VERIFICABLE | ESPECIFICACIONES.md | — | — | Vocabulario documentado, pero consistencia en APIs y vistas no demostrada. |
| D-030-S04 | NO_VERIFICABLE | src/Domain/Workflow/WorkflowDefinition.php | — | — | Workflows parciales; no se prueban invariantes globales frente a defaults. |
| D-031 | NO_VERIFICABLE | src/Infrastructure/Tenancy/TenantResolver.php | tests/php/Infrastructure/Tenancy/TenantResolverTest.php | — | No prueba ULID y aislamiento de todas las entidades ni migración compatible. |
| D-032 | NO_VERIFICABLE | src/Application/Inventory/InventoryService.php | — | — | Falta transacción idempotente completa de stock/reserva/consumo y pedido. |
| D-033 | NO_VERIFICABLE | frontend/ | — | — | No hay prueba conjunta de themes, Twig/SSR y contenido tenant por dominio. |
| D-034 | NO_VERIFICABLE | src/Infrastructure/Security/ | tests/php/Http/SecurityHttpSessionNegativesTest.php | — | Test de sesión no prueba todos los defaults CSRF, errores, UTC y auditoría. |
| D-035 | NO_VERIFICABLE | src/Domain/Workflow/WorkflowEngine.php | tests/test_workflow_core.py | — | Hay motor inicial; no se prueba adaptador, evento post-commit y cloud. |
| D-036 | NO_VERIFICABLE | .github/workflows/ci.yml | — | — | CI presente; faltan pruebas integrales de backup, observabilidad, SEO y accesibilidad. |

## Resumen y reversión

TOTAL 22. CUBIERTO 2; NO_VERIFICABLE 20; SIN_COBERTURA 0; DESCARTADO 0. Las dos coberturas ya existían en la matriz de base. No se declara nueva funcionalidad ni se crean Issues especulativos. El contraste no toca usuarios, DB, hosting, secretos, release ni operación real.

La matriz debe enlazar este documento en cada una de sus 22 filas manteniendo idénticos estados, IDs, 472 entradas y resúmenes. El CI exact-HEAD y la aceptación AC-04 lo validan. Rollback mediante squash-revert.