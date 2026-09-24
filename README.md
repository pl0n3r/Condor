# Condor App — Snapshot operativo · candidato V 0.1.28

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** recuperar producción con evidencia diagnóstica suficiente para cerrar el HTTP 500 persistente tras D-054.

<p align="center">
  <strong>Base integrada:</strong> V 0.1.27 · main `8f288bf8eb61ba1cd75ab2d4a82c469c3d9c3ebe` ·
  <strong>Candidato:</strong> V 0.1.28 ·
  <strong>Incidente:</strong> #200
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.27 / MAIN** | SHA `8f288bf8eb61ba1cd75ab2d4a82c469c3d9c3ebe` |
| Exact-main V 0.1.27 | ✅ **VALIDADO EN CÓDIGO** | CI run 35933783128 (#911) en success |
| Observador V 0.1.27 | ⛔ **NO_OBSERVADO** | run 35933783129 (#17) agotó 1200 s y terminó failure por HTTP 500 |
| Incidente actual | 🚧 **#200 / V 0.1.28** | diagnosticar fase real del cron y reconciliar el esquema sin SQL destructivo |
| CI/Sonar/CodeRabbit | ⏳ **PENDIENTE DEL HEAD FINAL** | se exige exact-head antes del merge |

## Qué corrige V 0.1.28

- preserva D-054: schema-check → dry-run/allowlist forward → backup obligatorio → migrate → recheck → caché;
- mantiene prohibidos SQL destructivo, migraciones contract y borrados irreversibles;
- endurece el backup para hosting compartido: `--no-tablespaces` cuando el cliente lo soporta y `--skip-triggers` porque Condor no administra triggers SQL;
- conserva `--single-transaction`, `--quick` y `--skip-lock-tables`;
- persiste una señal sanitizada por fase del post-deploy, sin SQL, logs ni credenciales;
- expone `/post-deploy-status.php` fuera del kernel de Symfony para diagnosticar el cron aun si Doctrine/Symfony no arrancan;
- el observador añade esa señal al diagnóstico cuando `/health` permanece en un fallo transitorio;
- los fallos tempranos del post-deploy terminan el probe como `failure`, nunca quedan falsamente en `running`.

## Invariantes operativas

- una sola corrida de post-deploy opera a la vez;
- ninguna migración se aplica si el backup previo falla;
- ninguna caché se regenera si el esquema no queda reconciliado;
- `construction` permite únicamente migraciones forward/expand-compatible;
- `live` conserva el fallo cerrado;
- el probe público solo expone versión, SHA, fase, resultado, código y timestamp acotados;
- código, deploy, esquema y validación productiva siguen siendo evidencias separadas.

## Validación requerida antes de cerrar #200

1. `/health` responde 200 con V 0.1.28, SHA exacto de `main` y `schema_up_to_date:true`.
2. `/`, `/admin/login`, `/marcela-arias-tienda`, `/adminpl0n3r` y `/adminpl0n3r/api/context` no responden 5xx.
3. El último observador automático registra un resultado funcional positivo y el workflow termina `success`.
4. No quedan Issues abiertos `tipo: incidente` ni `[AUTO]` de fallo productivo.
5. El último CI de `main` está en `success`.

## Referencias

- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap canónico: Issue #1
- Incidente: Issue #200
