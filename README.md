# Condor App — Snapshot operativo · candidato V 0.1.33

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** cerrar TANDA 1 corrigiendo el fallo productivo del backup PDO: metadata bufferizada, streaming acotado y diagnóstico por etapa sin heredar errores del dump nativo.

<p align="center">
  <strong>Base desplegada en código:</strong> V 0.1.32 · main `f38eb6cab2f29b2dc7b41639b5c74b1428cb552f` ·
  <strong>Candidato:</strong> V 0.1.33 ·
  <strong>Incidente:</strong> #200
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.32 / MAIN** | SHA `f38eb6cab2f29b2dc7b41639b5c74b1428cb552f` |
| Exact-main V 0.1.32 | ✅ **VALIDADO EN CÓDIGO** | CI #933 en success; release `v0.1.32` publicada |
| Observador V 0.1.32 | ⛔ **NO_OBSERVADO** | run 35959253158 (#22): V/SHA exactos, pero `phase=backup`, cliente final `pdo`; el `access_denied` estaba contaminado por el dump nativo previo |
| Candidato actual | 🚧 **PR #213 / V 0.1.33** | buffering PDO corregido + diagnóstico terminal por etapas 31–38 |
| Incidente actual | 🚧 **#200** | no cerrar hasta completar los cinco puntos de PRODUCCIÓN EN VERDE |

## Qué añade V 0.1.33

- mantiene las consultas PDO de metadata bufferizadas y usa modo no bufferizado únicamente durante el streaming de filas;
- cierra el cursor de filas antes de restaurar el buffering normal, evitando estados incompatibles del driver entre consultas;
- convierte fallos PDO en códigos de etapa sanitizados `31..38` sin exponer SQL, tabla, host, usuario ni credenciales;
- cuando el dump nativo falla antes del fallback, el diagnóstico terminal del PDO ya no hereda su `Access denied`;
- conserva snapshot consistente, conteo por tabla, checksum SHA-256, timeout y restauración demostrada en CI.

## Invariantes

- esquema pendiente → dry-run/allowlist → backup exitoso → migrate → recheck → cache;
- cualquier fallo de backup bloquea migración y caché;
- secretos permanecen fuera del probe y de la línea de comandos;
- `construction` solo admite migraciones forward/expand-compatible;
- `live` conserva fail-closed.

## Cierre de TANDA 1

No declarar producción verde hasta demostrar: `/health` 200 con V/SHA/schema exactos; home/login/storefront/centro de control sin 5xx; observador productivo aprobado; cero incidentes/[AUTO] abiertos; CI exact-main aprobado.

## Referencias

- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap canónico: Issue #1
- Incidente: Issue #200
