# GEST M11 — órdenes, responsables, tiempos y productividad

Fase de construcción. Corte de lectura verificable (issue #1576, hija #1536).
Este primer slice es **solo lectura** y requiere tenant/sede, permiso
inventory.view, add-on production-lite y fuente de inventario activa.

| Fila | Estado acreditable | Evidencia y límite |
| --- | --- | --- |
| GEST-M11-S04 Órdenes | Parcial | GET /api/v1/branches/{branchId}/production/operations-summary, máximo 50 órdenes, estados draft/completed; no equivale a orden de trabajo integral |
| GEST-M11-S05 Responsables | BRECHA | ProductionOrder no persiste responsable; responsable_alias=NULL, sin identidad inventada |
| GEST-M11-S06 Tiempos | Parcial | elapsed_lifecycle_minutes mide creación→cierre, **no horas trabajadas** |
| GEST-M11-S07 Productividad | BRECHA | completion_percent mide avance de unidades en la muestra, **no productividad de trabajadores**; productividad laboral UNKNOWN |

El panel OperationsSummaryPanel.tsx presenta loading/empty/error/
permission-denied y estados explícitos, pero no se monta en navegación hasta
un cambio autorizado sobre AdminApp.tsx. No se crean asignaciones, se
inventan trabajadores ni se habilitan escrituras. Las pruebas del controlador
comprueban autenticación, tenant ajeno, permiso, GET sin filtros y POST
rechazado (CSRF no aplicable a una API sin mutaciones). La integración de
asignaciones, tiempo laboral y productividad auténtica continúa pendiente;
NO elevar docs/matriz-cobertura.md a CUBIERTO. Reversión: revertir el squash.
