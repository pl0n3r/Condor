# Condor App — Snapshot operativo · Commercial Catalog V 0.1.53

> **Candidato:** Issue #268 · Plan, PlanVersion y Vertical versionados.

Condor continúa en **construcción**. V0.1.53 inicia el Commercial Catalog SaaS como bounded context separado del catálogo de productos de los tenants. Este slice fija identidad estable, historial de versiones, vigencia temporal y compatibilidad PlanVersion↔Vertical sin implementar todavía entitlements, add-ons o UI.

## Alcance
- nuevo `App\Domain\Commercial`, sin reutilizar `Domain\Catalog` de productos/SKU;
- Plan y Vertical con keys estables;
- PlanVersion con moneda, precios versionados, límites, vigencia half-open y modo cotizable;
- timeline fail-closed ante versiones solapadas;
- compatibilidad N:M entre PlanVersion y Vertical;
- migración expand-only para tablas, índices y foreign keys del slice;
- Enterprise conserva precio desconocido/cotizable como `null`, nunca como COP 0;
- aceptación ejecutable Factory mediante `tests/test_commercial_catalog_model.py`.

## Seguridad, datos y reversión
El candidato no agrega PII, proveedores externos ni formularios; `datos.yml` no cambia. La migración solo agrega estructuras y no ejecuta SQL destructivo. En fase construcción, cualquier despliegue mantiene D-054: dry-run/allowlist, backup previo, migrate y recheck. Revertir código no implica ejecutar automáticamente el `down()` destructivo en producción.

## Evidencia base
- `main@c797055cab0a53905acb6f3193bfc4b36e469115` · V0.1.52 · PRODUCCIÓN EN VERDE.
- Reserva #268: `e9c9fd84-1653-4d89-a2f6-cf05c6bef959`.
- #269 queda bloqueado detrás de este slice para Capability/AddOn, seed y reader.
- Factory v1, Política, Privacidad, Backend PHP/MariaDB, PHPStan, Rector y coordinación deben pasar sobre el HEAD exacto.

## Fuentes de verdad
- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap: Issue #1
- Épico comercial: #265
- Commercial Catalog parent: #266
- Slice actual: #268
