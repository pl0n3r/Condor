# Condor App — Snapshot operativo · Plan Configurator C V 0.1.61

> **Candidato:** Issue #284 · experiencia pública responsive.

Condor continúa en construcción. V0.1.61 monta la UI React pública del configurador sobre las APIs canónicas de V0.1.59, sin duplicar precios ni reglas comerciales en TypeScript.

## Alcance
- entry Vite independiente `configurator.js`, separado del bundle admin;
- flujo plan → vertical → escala → add-ons → ciclo → resumen;
- catálogo, opciones y preview consumidos desde APIs públicas canónicas;
- preview con debounce + AbortController;
- Legal renderiza únicamente capabilities recibidas del backend;
- proposal/Enterprise nunca muestra total ficticio;
- resumen sticky desktop y móvil, foco visible y estados loading/error/empty;
- ayuda “¿Necesito esto?” únicamente presentacional;
- CSS público dedicado `/configurator.css`;
- regresiones en `tests/test_plan_configurator_ui.py` y Playwright `configurator.spec.mjs`.

## Seguridad y reversión
El frontend no persiste cotizaciones ni acepta precio como autoridad. No añade PII, telemetría, checkout, trial ni CRM. Revertir el entry/Twig/CSS restaura el host público sin alterar catálogo o quotes.

## Evidencia base
- `main@f86585acd66d24bc3f9b0ca32e89b184ae57ced4` · V0.1.60 GREEN.
- Reserva #284: `ee5e1f73-2205-426d-8134-7339ff6702c8`.
- #280 permanece bloqueado hasta integrar y validar esta UI.
