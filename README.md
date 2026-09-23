# Condor App — Snapshot operativo · candidato V 0.1.27

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** recuperar producción tras #186/D-054. El observador de V 0.1.26 agotó 20 minutos con HTTP 500 aun después de integrar la reconciliación automática de esquema.

<p align="center">
  <strong>Base integrada:</strong> V 0.1.26 · main `66882ed6035d2624f1369fa49668ffd8cb12c085` ·
  <strong>Candidato:</strong> V 0.1.27 ·
  <strong>Issue:</strong> #197
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.26 / MAIN** | SHA `66882ed6035d2624f1369fa49668ffd8cb12c085` |
| Exact-main V 0.1.26 | ✅ **VALIDADO EN CÓDIGO** | CI run 35927048211 en success |
| Observador V 0.1.26 | ⛔ **NO_OBSERVADO** | run 35927048132 agotó la ventana completa; producción siguió en HTTP 500 |
| Incidente actual | 🚧 **#197 / V 0.1.27** | crear y verificar backup → migración → recheck → smoke |
| CI/Sonar/CodeRabbit | ⏳ **PENDIENTE DEL HEAD FINAL** | se exige exact-head antes del merge |

## Qué corrige V 0.1.27

- preserva D-054: dry-run/allowlist aditivo → backup exitoso → migrate → recheck → caché;
- mantiene prohibidos SQL destructivo, migraciones contract y borrados irreversibles;
- endurece el backup para usuarios de base restringidos: cuando el cliente MySQL/MariaDB lo soporta, usa `--no-tablespaces` y evita requerir el privilegio global `PROCESS`;
- conserva `--single-transaction`, `--quick`, `--skip-lock-tables` y triggers;
- mantiene secretos en option-files temporales protegidos y nunca los imprime;
- valida por contrato que el fallback de compatibilidad permanezca presente.

## Invariantes operativas

- una sola corrida de post-deploy opera a la vez;
- ninguna migración se aplica si el backup previo falla;
- ninguna caché se regenera si el esquema no queda reconciliado;
- `construction` permite únicamente migraciones forward/expand-compatible;
- `live` conserva el fallo cerrado;
- código, deploy, esquema y validación productiva siguen siendo evidencias separadas.

## Validación requerida antes de cerrar #197

1. `/health` responde 200 con V 0.1.27, SHA exacto de `main` y `schema_up_to_date:true`.
2. `/`, `/admin/login`, `/marcela-arias-tienda`, `/adminpl0n3r` y `/adminpl0n3r/api/context` no responden 5xx.
3. El último observador automático registra estado funcional positivo.
4. No quedan Issues abiertos `tipo: incidente` ni `[AUTO]` de fallo productivo.
5. El último CI de `main` está verde.

## Referencias

- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap canónico: Issue #1
- Incidente: Issue #197
