# Condor App — Snapshot operativo · candidato V 0.1.37

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** corregir el observer automático para que una producción que todavía sirve el SHA anterior de la misma versión sea tratada como deploy pendiente durante la ventana acotada, sin declarar producción verde hasta observar el SHA exacto.

<p align="center">
  <strong>Base integrada en código:</strong> V 0.1.36 · main `5e157e64a41513956e277ddef1ed0160c48f3fd6` ·
  <strong>Candidato:</strong> V 0.1.37 · Issue #219
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.36 / MAIN** | SHA `5e157e64a41513956e277ddef1ed0160c48f3fd6`; privacidad como código de #217/#218 integrada |
| Exact-main V 0.1.36 | ✅ **VALIDADO EN CÓDIGO** | `Validar`, Backend/MariaDB, contratos, Playwright, frontend, backup/restore, Sonar y CodeQL en verde |
| Producción para `5e157e64…` | ⛔ **NO_OBSERVADO** | El observer detectó V0.1.36 con un SHA distinto al esperado; no se declaró deploy ni producción verde |
| Candidato actual | 🚧 **Issue #219 / V 0.1.37** | tolerar SHA anterior solo como deploy pendiente y mantener timeout fail-closed |
| Privacidad | ✅ **MECANISMO INTEGRADO** | `datos.yml`, documentos generados y gates de Factory; estado jurídico sigue separado de la evidencia técnica |

## Qué añade V 0.1.37

- clasifica una identidad con **misma versión + SHA distinto bien formado** como deploy pendiente durante la espera acotada;
- conserva `NO_OBSERVADO` si el SHA exacto no aparece antes del timeout;
- mantiene SHA malformado y versiones incompatibles como fallos de identidad;
- evita describir un `NO_OBSERVADO` válido como “uso incorrecto de argumentos”;
- no aumenta la ventana de deploy, no escribe en producción y no relaja ningún smoke check.

## Invariantes

- merge o CI verde no equivalen a producción verde;
- solo versión + SHA exactos permiten avanzar la identidad de release;
- un SHA anterior puede esperar, pero nunca se acepta como evidencia final;
- esquema, home, login, storefront, centro de control y assets siguen siendo gates independientes;
- secretos y PII permanecen fuera de logs, Issues y argumentos;
- la revisión jurídica de privacidad permanece separada de la documentación técnica.

## Privacidad como código

Condor mantiene `datos.yml` en fase `construccion` con datos del responsable como `[COMPLETAR POR EL DUEÑO]`. Bases, consentimientos y retenciones sin evidencia material permanecen `review_required`; los documentos generados no constituyen aprobación jurídica.

## Criterio para volver a PRODUCCIÓN EN VERDE

No declarar producción verde hasta demostrar, para el SHA vigente: `/health` 200 con versión/SHA/schema exactos; home/login/storefront/centro de control sin 5xx; observer aprobado; cero incidentes/[AUTO] abiertos; CI exact-main aprobado.

## Referencias

- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap canónico: Issue #1
- Incidente de observer: Issue #219
- Privacidad como código: Issue #217
