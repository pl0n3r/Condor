# Condor App — Snapshot operativo · Commercial Catalog V 0.1.53

> **Candidato:** Issue #268 · modelo de dominio Plan, PlanVersion y Vertical.

Condor continúa en **construcción**. V0.1.53 inicia el Commercial Catalog SaaS como bounded context separado del catálogo de productos de los tenants. Este slice fija identidad estable, historial de versiones, vigencia temporal y compatibilidad PlanVersion↔Vertical sin persistencia ni UI.

## Alcance
- nuevo `App\Domain\Commercial`, separado de `Domain\Catalog` de productos/SKU;
- Plan y Vertical con keys estables;
- PlanVersion con moneda, precio versionado, límites y vigencia half-open;
- timeline fail-closed ante versiones solapadas;
- compatibilidad entre una PlanVersion y múltiples verticales;
- Enterprise conserva precio desconocido/cotizable como `null`, nunca COP 0;
- aceptación ejecutable Factory mediante `tests/test_commercial_catalog_model.py`.

## Seguridad, datos y reversión
El candidato es dominio puro: no agrega PII, proveedores, DB, migraciones ni efectos externos. `datos.yml` no cambia. Revertir el PR elimina el modelo sin tocar producción.

## Evidencia base
- `main@c797055cab0a53905acb6f3193bfc4b36e469115` · V0.1.52 · PRODUCCIÓN EN VERDE.
- Reserva #268 iniciada como `e9c9fd84-1653-4d89-a2f6-cf05c6bef959`; contrato v2 reducido pendiente de renovación.
- #270 queda detrás de este slice para persistencia Doctrine/migración.
- #269 queda después de #270 para Capability/AddOn, seed y reader.

## Fuentes de verdad
- [AGENTES.md](./AGENTES.md)
- Roadmap: Issue #1
- Épico comercial: #265
- Commercial Catalog parent: #266
- Slice actual: #268
