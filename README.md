# Condor App — Snapshot operativo · Plan Configurator F V 0.1.64

> **Candidato:** Issue #292 · instrumentación frontend privacy-safe del funnel.

Condor continúa en construcción. V0.1.64 conecta el navegador al endpoint de telemetría privacy-safe entregado en V0.1.63 mediante un script estático best-effort cargado antes del bundle React, sin convertir el frontend en autoridad comercial ni introducir tracking persistente.

## Alcance
- script estático `/configurator-telemetry.js` cargado antes de `/build/configurator.js`;
- preserva `originalFetch` y observa únicamente APIs canónicas de options/quote;
- eventos documentados: `start`, `plan_selected`, `plan_changed`, `vertical`, `addon`, `abandonment`, `completion` y `proposal`;
- contexto emitido limitado a `event/plan/vertical/cycle/addon/step`;
- abandono por `navigator.sendBeacon` en `pagehide`/visibility hidden;
- cualquier fallo de telemetría se absorbe y siempre se devuelve la promesa funcional original;
- no se capturan ni envían cantidades, precios, texto libre, correo, cookies, storage, user-agent, fingerprint o identificadores de sesión;
- React y `frontend/configurator/main.tsx` permanecen sin lógica de telemetría ni cambios comerciales;
- regresiones estáticas AC-01..AC-03 y wrapper macro ejecutable de #289.

## Privacidad, seguridad y reversión
El script no crea cookies, storage ni identificadores cross-session. Solo deriva claves técnicas ya usadas por las APIs canónicas y el backend vuelve a validar todo el payload. `datos.yml` permanece intacto porque no aparece un tratamiento personal nuevo.

La telemetría es estrictamente best-effort: usa el fetch original para enviar eventos, no intercepta su propia telemetría y nunca cambia errores, respuestas ni tiempos contractuales del flujo catálogo/opciones/quote.

Reversión: retirar el script del Twig y eliminar `public/configurator-telemetry.js`; el configurador React continúa operando sin dependencia de telemetría.

## Evidencia base
- `main@268dd438ab895072b5d16aaefc8cd8539b0f5cfe` · V0.1.63 GREEN.
- Reserva #292: `6612f7d8-311e-4480-ab1e-ef36ad22dd14`.
- Backend privacy-safe #291 integrado y validado.
- #289 queda listo para cierre cuando este slice se integre y sus contratos macro pasen.
