# Condor App — Snapshot operativo · candidato V 0.1.32

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** desbloquear D-054 en Hostinger usando un backup lógico PDO verificable cuando el cliente nativo no dispone de privilegios suficientes, sin relajar el fail-closed previo a migraciones.

<p align="center">
  <strong>Base integrada:</strong> V 0.1.31 · main `50bce23ca7e172aa87b46302acf4c911782c47c9` ·
  <strong>Candidato:</strong> V 0.1.32 ·
  <strong>Incidente:</strong> #200
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.31 / MAIN** | SHA `50bce23ca7e172aa87b46302acf4c911782c47c9` |
| Exact-main V 0.1.31 | ✅ **VALIDADO EN CÓDIGO** | CI run 35950457989 (#929) en success |
| Observador V 0.1.31 | ⛔ **NO_OBSERVADO** | run 35950457985 (#21): HTTP 500; `phase=backup`, `reason=access_denied`, `backup_client=mariadb-dump` |
| Candidato actual | 🚧 **PR #205 / V 0.1.32** | backup PDO + restauración demostrada; revalidación exact-head obligatoria tras hardening |
| Incidente actual | 🚧 **#200** | no cerrar hasta completar los cinco puntos de PRODUCCIÓN EN VERDE |

## Qué añade V 0.1.32

- conserva `mariadb-dump|mysqldump` como camino preferido y cae a PDO si el cliente nativo no puede completar el respaldo;
- el backup PDO usa la misma cuenta de aplicación, snapshot consistente, `SHOW CREATE TABLE` y lectura por lotes sin `LOCK TABLES`, `PROCESS` ni GTID;
- verifica por tabla que las filas volcadas coinciden con el conteo del mismo snapshot;
- escribe el SQL de forma exhaustiva aun ante `fwrite()` parcial y verifica SHA-256 contra los bytes persistidos;
- el proceso PHP de backup queda rastreado por el mismo cleanup que usa el watchdog, evitando hijos huérfanos ante timeout;
- telemetría, endpoint de estado y observador reconocen `pdo` como cliente seguro allowlisted;
- CI fuerza el camino PDO y demuestra restauración de esquema + datos centinela.

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
