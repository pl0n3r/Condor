# Condor App — Snapshot operativo · Commercial Catalog Persistence V 0.1.54

> **Candidato:** Issue #270 · persistencia Doctrine y migración del Commercial Catalog.

Condor continúa en **construcción**. V0.1.54 persiste el contrato de dominio integrado en #268 sin ampliar producto: Plan, PlanVersion y Vertical quedan mapeados en tablas comerciales globales, separados del catálogo tenant-scoped.

## Alcance
- atributos Doctrine sobre `App\Domain\Commercial`;
- tablas globales para Plan, Vertical, PlanVersion y relación N:M;
- claves únicas para identidad estable y plan+version;
- índice temporal por plan/vigencia;
- migración expand-only, sin seed ni datos comerciales;
- regresión SchemaTool↔schema migrado;
- aceptación Factory en `tests/test_commercial_catalog_persistence.py`.

## Seguridad, datos y reversión
No se agregan PII, proveedores ni formularios; `datos.yml` no cambia. El `up()` solo crea estructuras. En construcción aplica D-054: dry-run/allowlist, backup, migrate y recheck antes de considerar schema productivo. El `down()` no se ejecuta automáticamente en producción.

## Evidencia base
- `main@3dd1d61a79ca0507df55d56f0c30e370406106fb` · V0.1.53.
- Reserva #270: `f83096cc-5353-4503-9221-112fbc1645a6`.
- #269 permanece bloqueado hasta integrar este slice.

## Fuentes de verdad
- [AGENTES.md](./AGENTES.md)
- Roadmap: Issue #1
- Épico comercial: #265
- Commercial Catalog parent: #266
- Slice actual: #270
