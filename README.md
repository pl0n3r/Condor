# Condor App — Snapshot operativo · candidato V 0.1.36

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** cerrar TANDA 1 evitando que el backup previo dependa del contenedor Symfony `prod` cacheado de la release anterior; el fallback PDO compila un contenedor efímero con semántica `prod` sin tocar la caché viva antes de migrar.

<p align="center">
  <strong>Base integrada en código:</strong> V 0.1.35 · main `8c9d819c99443bb92df5b60fc7d637db0a8dabea` ·
  <strong>Candidato:</strong> V 0.1.36 · PR #216 ·
  <strong>Incidente:</strong> #200
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.35 / MAIN** | SHA `8c9d819c99443bb92df5b60fc7d637db0a8dabea` |
| Exact-main V 0.1.35 | ✅ **VALIDADO EN CÓDIGO** | CI #953: 12/12 jobs success; release `v0.1.35` publicada |
| Observador V 0.1.35 | ⛔ **NO_OBSERVADO** | observer #25: cron `09:50:05Z`, `phase=backup`, `backup_client=pdo`, subcode 1 |
| Candidato actual | 🚧 **PR #216 / V 0.1.36** | fallback PDO usa caché Symfony efímera manteniendo `APP_ENV=prod` |
| Incidente actual | 🚧 **#200** | no cerrar hasta completar los cinco puntos de PRODUCCIÓN EN VERDE |

## Qué añade V 0.1.36

- conserva el comando `app:database:backup-pdo` y la conexión `Doctrine\DBAL\Connection` introducidos en V0.1.35;
- ejecuta el fallback con `APP_ENV=prod APP_DEBUG=0 CONDOR_EPHEMERAL_CACHE=1`, forzando un contenedor Symfony actual sin reutilizar `var/cache/prod`;
- `Kernel::getCacheDir()` solo cambia cuando el flag efímero está activo; web y demás comandos conservan la caché normal;
- un exit terminal PDO inesperado ya no hereda el `Access denied` del dump nativo anterior;
- mantiene snapshot consistente, streaming acotado, conteo por tabla, checksum y restore real en CI;
- no limpia ni calienta la caché `prod` antes de que D-054 complete backup/migración/recheck.

## Invariantes

- esquema pendiente → dry-run/allowlist → backup exitoso → migrate → recheck → cache;
- cualquier fallo de backup bloquea migración y caché;
- secretos permanecen fuera del probe, logs compartibles y línea de comandos;
- `construction` solo admite migraciones forward/expand-compatible;
- `live` conserva fail-closed;
- V 0.1.36 no contiene migraciones ni cambios de producto.

## Cierre de TANDA 1

No declarar producción verde hasta demostrar: `/health` 200 con V/SHA/schema exactos; home/login/storefront/centro de control sin 5xx; observador productivo aprobado; cero incidentes/[AUTO] abiertos; CI exact-main aprobado.

## Referencias

- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap canónico: Issue #1
- Incidente: Issue #200
