# Condor App — Snapshot operativo · Plan Configurator A V 0.1.57

> **Candidato:** Issue #277 · compatibilidad canónica Vertical↔Capability y read model del configurador.

Condor continúa en **construcción**. V0.1.57 abre el Plan Configurator sin duplicar precios ni reglas en React: persiste relevancia por vertical con claves estables y prioridad explícita, y deriva las opciones configurables desde el Commercial Catalog vigente.

## Alcance
- relación persistida expand-only `VerticalCapability` con key estable, prioridad y unicidad Vertical↔Capability;
- seed idempotente de relevancia para commerce, textile, manufacturing, professional-services y legal;
- `PlanConfiguratorCatalogReader` server-side: PlanVersion vigente + vertical + límites + capabilities relevantes + add-ons permitidos;
- capabilities finales = intersección entre PlanVersion y relevancia del vertical;
- add-ons provienen exclusivamente de PlanVersion;
- Legal prioriza contacts/cases/documents/deadlines y excluye inventory/manufacturing incluso en Pro/Enterprise;
- vertical desconocido o incompatible falla cerrado;
- aceptación Factory en `tests/test_plan_configurator_catalog.py`.

## Seguridad, datos y reversión
No cambia RBAC, PII, secretos ni `datos.yml`. La migración solo agrega tabla/índices/FKs y el seed es idempotente. Revertir código elimina el read model; la tabla puede quedar sin uso hasta una reversión de esquema planificada, sin borrado automático de datos productivos.

## Evidencia base
- `main@372e12d447900ea392b69d76b0bd2cd2188ca245` · V0.1.56.
- CI exact-main `36298321922`: **SUCCESS**.
- Production observer `36298321578`: **SUCCESS**.
- Reserva #277: `3dc7d909-90c0-45a8-89b9-c75dd974cfaf`.
- #278 permanece dependiente de integrar este read model antes del quote autoritativo.

## Fuentes de verdad
- [AGENTES.md](./AGENTES.md)
- Roadmap: Issue #1
- Épico comercial: #265
- Plan Configurator: #267
- Slice actual: #277
