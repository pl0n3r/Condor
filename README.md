# Condor App — Snapshot operativo · Plan Configurator E V 0.1.63

> **Candidato:** Issue #291 · backend de telemetría privacy-safe.

Condor continúa en construcción. V0.1.63 añade el contrato backend del funnel del configurador sin introducir tracking identificable: endpoint público cerrado, limiter independiente y señal funcional agregable reutilizando la infraestructura existente.

## Alcance
- endpoint `POST /api/public/configurator/events`;
- tipo funcional `configurator_funnel` sin tabla ni migración nueva;
- eventos documentados: start, plan selected/changed, vertical, add-on, abandonment, completion y proposal;
- contexto allowlisted exclusivamente a `event/plan/vertical/cycle/addon/step`;
- plan, vertical y add-on validados contra el catálogo comercial canónico;
- `tenant_id = null` y ausencia de correo, nombre, IP, sesión, user-agent, cantidades, cookies, fingerprint e identificadores cross-session;
- limiter `plan_configurator_events` independiente del limiter de quote;
- `configurator_funnel` incorporado al reporte funcional agregado;
- contrato de finalidad, explotación y retención documentado en `docs/telemetria-configurador.md`;
- regresiones runtime y wrapper Factory para privacidad + independencia de rate limits.

## Privacidad, seguridad y reversión
La IP solo puede intervenir transitoriamente como clave del limiter y no se persiste como señal funcional. El endpoint reconstruye el contexto server-side y rechaza campos extra o claves comerciales inexistentes. `datos.yml` permanece intacto porque #291 no incorpora tratamiento de dato personal.

La explotación operativa usa la ventana móvil de 30 días del reporte funcional. La tabla existente no tiene purga automática; este slice no ejecuta borrados destructivos y documenta esa limitación antes de una política de retención definitiva.

Reversión: retirar controller, limiter y tipo `configurator_funnel`; no hay cambios de Commercial Catalog, Quote ni migraciones.

## Evidencia base
- `main@fc2d5365401af69396a87f2e6bf4e416119d6ee5` · V0.1.62 GREEN.
- Reserva #291: `10c40351-5466-4e70-a215-fcb5e9268592`.
- #292 permanece fuera de alcance y conectará la instrumentación de navegador cuando #291 esté integrado.
- #280 permanece como parent hasta cerrar también la instrumentación frontend.
