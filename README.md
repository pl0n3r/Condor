# Condor App — Snapshot operativo · Commercial Catalog V1 · V 0.1.53

> **Candidato:** Issue #266 · catálogo comercial SaaS versionado para planes, verticales, capabilities y add-ons.

Condor continúa en **construcción**. V0.1.53 incorpora el núcleo comercial global que alimentará entitlements, suscripciones y el futuro Plan Configurator sin mezclar precios con RBAC ni con el catálogo tenant de productos.

## Alcance

- `Plan`, `PlanVersion`, `Vertical`, `Capability` y `AddOn` viven en el nuevo subdominio global `CommercialCatalog`;
- las claves comerciales son estables y los labels visibles pueden evolucionar sin romper consumidores;
- `PlanVersion` conserva vigencia temporal, precio COP, límites y composición de verticales/capabilities/add-ons;
- el mismo plan puede operar en distintas verticales sin forks;
- Producción Lite es un add-on independiente de Negocio, no un permiso RBAC;
- el catálogo inicial reproduce las hipótesis aprobadas en #265 para Básico, Negocio, Pro y Enterprise;
- `CommercialCatalogReader` resuelve el catálogo vigente por fecha sin condicionales por nombre de plan;
- Doctrine persiste el modelo con una migración estrictamente aditiva y seed reproducible;
- el frontend no recibe precios duplicados ni reglas hardcodeadas en este slice.

## Calidad y aceptación

- Factory Acceptance de #266 fija AC-01..AC-08 mediante `tests/test_commercial_catalog.py`;
- PHPUnit cubre versionado temporal, independencia de verticales, claves estables y el reader;
- PHPStan nivel 8 y Rector siguen siendo gates obligatorios para PHP;
- la migración no borra, renombra ni contrae estructuras existentes;
- no cambia `datos.yml`, permisos, secretos ni datos personales.

## Seguridad y reversión

Este slice añade estructuras comerciales globales y datos canónicos nuevos. No ejecuta SQL manual ni cambios destructivos. La reversión del candidato se hace por revert del deploy antes de uso dependiente; cualquier rollback de esquema en producción queda sujeto al protocolo de migraciones y backup de Condor.

## Evidencia base

- Base exacta: `main@c797055cab0a53905acb6f3193bfc4b36e469115` · V0.1.52.
- CI Condor exact-main `36258571041`: success.
- Observer exact-main `36258570689`: success.
- Reserva #266: `6f30945f-15a1-4fb6-89ac-34eaf5dfd122`.
- Implementación funcional inicial: 15 archivos · +1058/−1 antes de este snapshot.
- Factory v1, Política/aceptación, CI Condor, Sonar y CodeRabbit deben cerrar sobre el HEAD exacto antes de merge.

## Continuidad

- **NOW:** #266 — Commercial Catalog V1.
- **NEXT:** #267 — Plan Configurator, dependiente del catálogo canónico.
- **EPIC:** #265 — monetización SaaS, entitlements, suscripciones, Control Center y métricas.
- Issue #1 sigue siendo el único Roadmap canónico.

## Fuentes de verdad

- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap: Issue #1
- Slice actual: #266
