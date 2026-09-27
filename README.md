# Condor App — Snapshot operativo · Commercial Catalog V1 V 0.1.55

> **Candidato:** Issue #269 · capabilities, add-ons, seed y reader del Commercial Catalog.

Condor continúa en **construcción**. V0.1.55 completa el catálogo comercial base iniciado en #268/#270 sin activar todavía entitlements, suscripciones ni UI.

## Alcance
- `Capability` y `AddOn` con claves comerciales estables, separados de RBAC;
- compatibilidad PlanVersion↔Capability/AddOn por relaciones persistentes;
- seed idempotente de Básico, Negocio, Pro y Enterprise con las hipótesis aprobadas en #265;
- verticales iniciales compartidas, incluido Legal/Abogados sin fork;
- Producción Lite como add-on de Negocio por COP 99.900/mes;
- reader vigente por fecha que consume el catálogo persistido y no conoce nombres de planes;
- migración expand-only para nuevas tablas y relaciones;
- aceptación Factory en `tests/test_commercial_catalog_seed.py`.

## Seguridad, datos y reversión
No se agregan PII ni proveedores; `datos.yml` no cambia. No hay enforcement comercial ni cambios de permisos. La migración solo crea estructuras y relaciones. En construcción sigue aplicando D-054 antes de cualquier migrate real.

## Evidencia base
- `main@5ca6081680581b235ca34223fc1c1fb50af6f597` · V0.1.54.
- Reserva #269: `5d560e99-073a-472c-80cd-07ab2fae550f`.
- #267 permanece dependiente del cierre del Commercial Catalog V1.

## Fuentes de verdad
- [AGENTES.md](./AGENTES.md)
- Roadmap: Issue #1
- Épico comercial: #265
- Commercial Catalog parent: #266
- Slice actual: #269
