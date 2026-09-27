# Condor App — Snapshot operativo · Plan Configurator B V 0.1.58

> **Candidato:** Issue #278 · quote autoritativo y trazable.

Condor continúa en construcción. V0.1.58 añade cotización server-side sobre el Commercial Catalog vigente: revalida plan/vertical/add-ons/cantidades, ignora totales enviados por cliente, persiste PlanVersion/composición exacta y deriva a propuesta cuando no existe precio cerrado.

## Alcance
- `PlanQuoteService` consume `PlanConfiguratorCatalogReader`;
- cantidades de usuarios/sedes/empresas se recalculan con límites y add-ons canónicos;
- add-ons incompatibles fallan cerrado;
- mensual calcula total cerrado; anual con extras queda en proposal hasta política comercial explícita;
- Enterprise/unpriced nunca inventa total;
- `Quote` persiste PlanVersion, vertical, cantidades, add-ons, vigencia y estado;
- tax policy/amount permanecen null mientras no exista política fiscal configurada;
- aceptación Factory: `tests/test_plan_quote.py`.

## Seguridad y reversión
No se confía en precio/total del frontend. No cambia RBAC, PII, secretos ni `datos.yml`. La migración es expand-only.

## Evidencia base
- `main@0852d3bf42513ddf21ba7cfabc571130c78e42ac` · V0.1.57 GREEN.
- Reserva #278: `b06dba8c-1c4a-40b2-b63e-d3420d913ce6`.
- #279 permanece bloqueado hasta integrar este quote autoritativo.
