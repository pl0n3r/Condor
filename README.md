# Condor App — Snapshot operativo · Plan Configurator Public API V 0.1.59

> **Candidato:** Issue #283 · API pública canónica y preview no persistente.

Condor continúa en construcción. V0.1.59 expone el contrato público que consumirá la experiencia visual del configurador sin convertir el frontend en fuente de precios o compatibilidad.

## Alcance
- host público `/configurar-condor` preparado para el entry React del siguiente slice;
- catálogo y opciones públicas derivadas de Commercial Catalog + compatibilidad canónica;
- preview server-side de quote que ignora totales cliente y no persiste filas `Quote`;
- extras de escala derivados únicamente de cantidades y marcados no seleccionables;
- payload allowlisted y errores fail-closed;
- rate limit anónimo por IP sobre preview;
- Legal conserva filtrado canónico; Enterprise/proposal nunca inventa precio;
- aceptación Factory: `tests/test_plan_configurator_public_api.py`.

## Seguridad y reversión
No agrega PII, cookies nuevas, permisos, checkout ni telemetría. El preview no escribe base de datos. Las rutas pueden retirarse por revert sin migraciones ni pérdida de datos.

## Evidencia base
- `main@2e928ae157b29513fb16652af6140444a190a8da` · V0.1.58 GREEN.
- Reserva #283: `cbc2e643-d249-4e5d-9a9f-8f4e15111e78`.
- #284 permanece bloqueado hasta integrar y validar este contrato público.
