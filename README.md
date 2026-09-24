# Condor App — Snapshot operativo · candidato V 0.1.29

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** desbloquear el backup previo obligatorio de D-054 en hosting compartido y reconciliar el esquema sin SQL destructivo.

<p align="center">
  <strong>Base integrada:</strong> V 0.1.28 · main `6f2de74a2f67f74a5242ed8289d237acca577e4d` ·
  <strong>Candidato:</strong> V 0.1.29 ·
  <strong>Incidente:</strong> #200
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.28 / MAIN** | SHA `6f2de74a2f67f74a5242ed8289d237acca577e4d` |
| Exact-main V 0.1.28 | ✅ **VALIDADO EN CÓDIGO** | CI run 35939812755 (#915) en success |
| Observador V 0.1.28 | ⛔ **NO_OBSERVADO** | run 35939812691 (#18): HTTP 500; probe exacto `phase=backup`, `result=failure`, `code=2` |
| Incidente actual | 🚧 **#200 / V 0.1.29** | eliminar requisito GTID/RELOAD de `mysqldump` sin relajar credenciales ni omitir esquema/datos |
| CI/Sonar/CodeRabbit | ⏳ **PENDIENTE DEL HEAD FINAL** | se exige exact-head antes del merge |

## Qué corrige V 0.1.29

- preserva D-054: schema-check → dry-run/allowlist forward → backup obligatorio → migrate → recheck → caché;
- mantiene prohibidos SQL destructivo, migraciones contract y borrados irreversibles;
- conserva `--single-transaction`, `--quick`, `--skip-lock-tables`, `--skip-triggers` y `--no-tablespaces` cuando corresponde;
- para `mysqldump`, si el cliente anuncia `--set-gtid-purged`, usa `--set-gtid-purged=OFF`: Condor no provisiona replicación y así evita requerir `RELOAD/FLUSH_TABLES` por metadata GTID en MySQL moderno;
- MariaDB nunca recibe la opción específica de MySQL;
- las opciones se habilitan únicamente cuando el binario las anuncia;
- secretos siguen en option-files temporales `0600` y nunca se imprimen.

## Invariantes operativas

- una sola corrida de post-deploy opera a la vez;
- ninguna migración se aplica si el backup previo falla;
- ninguna caché se regenera si el esquema no queda reconciliado;
- `construction` permite únicamente migraciones forward/expand-compatible;
- `live` conserva el fallo cerrado;
- código, deploy, esquema y validación productiva siguen siendo evidencias separadas.

## Validación requerida antes de cerrar #200

1. `/health` responde 200 con V 0.1.29, SHA exacto de `main` y `schema_up_to_date:true`.
2. `/`, `/admin/login`, `/marcela-arias-tienda`, `/adminpl0n3r` y `/adminpl0n3r/api/context` no responden 5xx.
3. El último observador automático registra resultado funcional positivo y workflow `success`.
4. No quedan Issues abiertos `tipo: incidente` ni `[AUTO]` de fallo productivo.
5. El último CI de `main` está en `success`.

## Referencias

- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap canónico: Issue #1
- Incidente: Issue #200
