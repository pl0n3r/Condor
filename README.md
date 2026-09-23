# Condor App — Snapshot operativo · candidato V 0.1.26

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** restaurar convergencia operativa entre código y esquema en el entorno de construcción de Hostinger, eliminando la política que detectaba migraciones pendientes pero dejaba producción en HTTP 500.

<p align="center">
  <strong>Base integrada:</strong> V 0.1.25 · main `4c3e7c70a767db5d2aafb7614205cc96cac8c524` ·
  <strong>Candidato:</strong> V 0.1.26 ·
  <strong>Issue/PR:</strong> #185 / #186
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.25 / MAIN ACTUAL** | SHA `4c3e7c70a767db5d2aafb7614205cc96cac8c524` |
| Exact-main V 0.1.25 | ✅ **VALIDADO EN CÓDIGO** | CI, Backend/MariaDB, integración, Playwright, frontend, backup, gobierno, coordinación y SonarQube en success |
| Producción V 0.1.25 | ⛔ **NO_OBSERVADA** | observador post-push reportó HTTP 500; código desplegado y esquema reconciliado siguen siendo evidencias separadas |
| Candidato actual | 🚧 **V 0.1.26** | Issue #185 / PR #186 |
| CI/Sonar del HEAD final | ⏳ **EXACT-HEAD OBLIGATORIO** | validar después del bump y del cambio de post-deploy |
| CodeRabbit | ⏳ **EXACT-HEAD OBLIGATORIO** | revisión terminal sobre el mismo SHA antes de ready/merge |

## Qué incorpora V 0.1.26

- modo operativo explícito `CONDOR_PRODUCTION_STAGE=construction|live`;
- `construction` como etapa vigente mientras no existan usuarios finales ni datos reales a conservar;
- autorización durable para deploys, migraciones Doctrine versionadas forward/expand-compatible, provisioning técnico, caché y correcciones productivas durante construcción;
- `scripts/post-deploy.sh` puede reconciliar migraciones pendientes bajo el lock existente en modo construction;
- `CONDOR_AUTO_MIGRATE=0` permite volver a solo detección fail-closed sin dry-run, backup ni migrate;
- cada auto-migración válida exige **dry-run/allowlist → backup → migrate → verificación** antes de `cache:clear` y `cache:warmup`;
- si `DATABASE_URL` no viene exportada, el post-deploy la resuelve con Symfony dotenv exclusivamente para el proceso de backup, sin imprimirla;
- `live` restaura el fallo cerrado ante migraciones pendientes;
- historial de migraciones incoherente, conectividad fallida y timeouts siguen fallando cerrado;
- migraciones destructivas/contract, SQL destructivo, secretos, DNS/infra irreversible y borrados irreversibles continúan fuera de la automatización;
- regresión ejecutable que demuestra construction → migrate → verify → cache y live → fail closed.

## Invariantes operativas

- un solo post-deploy opera a la vez;
- toda mutación productiva queda asociada a una release/SHA;
- construcción no equivale a permiso para destrucción irreversible;
- ninguna caché se regenera si el esquema no quedó reconciliado;
- ninguna migración automática se ejecuta si el backup previo falla;
- el modo `live` debe establecerse antes del primer uso real con datos a conservar;
- código desplegado, esquema reconciliado y producción validada se reportan por separado;
- un HTTP 500 o `NO_OBSERVADO` activa diagnóstico y corrección, no una aceptación permanente del fallo.

## Archivos principales

- `AGENTES.md`
- `ESPECIFICACIONES.md`
- `scripts/post-deploy.sh`
- `tests/test_post_deploy.py`
- `config/version.php`

## Validación requerida

- pruebas del contrato construction/live;
- CI y SonarQube terminales sobre el SHA exacto final;
- CodeRabbit full review exact-head sin findings accionables;
- squash merge serial y validación exact-main;
- tag/release `v0.1.26`;
- observar Hostinger después del deploy;
- confirmar que el esquema quedó al día y repetir smoke público;
- registrar por separado deploy observado y `VALIDATED_IN_PRODUCTION`.

## Estado inmediato

- **V 0.1.26:** hotfix operativo prioritario #185/#186.
- **V 0.1.25:** integrada y validada en código; producción permanece `NO_OBSERVADO` por HTTP 500 hasta reconciliar esquema/runtime.
- **V 0.1.27:** pedido manual/e-commerce + reserva/liberación/consumo de stock en #183/#184; implementación avanzada y temporalmente serializada detrás de V 0.1.26.
- La planificación completa vive exclusivamente en el Roadmap canónico #1.

## Referencias

- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap canónico: Issue #1

> **Regla de estado:** durante construcción se permite reconciliar producción automáticamente de forma trazable; pasar a operación real exige cambiar explícitamente a `CONDOR_PRODUCTION_STAGE=live` y restaurar controles reforzados.
