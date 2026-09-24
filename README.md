# Condor App — Snapshot operativo · candidato V 0.1.35

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** cerrar TANDA 1 haciendo que el fallback PDO del backup use la misma conexión Doctrine activa que los comandos Symfony, sin crear una conexión paralela desde `DATABASE_URL`.

<p align="center">
  <strong>Base integrada en código:</strong> V 0.1.34 · main `8a4b5a9e8a7835f8df46c2e27788919667f8120a` ·
  <strong>Candidato:</strong> V 0.1.35 · PR #215 ·
  <strong>Incidente:</strong> #200
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.34 / MAIN** | SHA `8a4b5a9e8a7835f8df46c2e27788919667f8120a` |
| Exact-main V 0.1.34 | ✅ **VALIDADO EN CÓDIGO** | CI #948: 12/12 jobs success; release `v0.1.34` publicada |
| Observador V 0.1.34 | ⛔ **NO_OBSERVADO** | observer #24: cron `09:15:05Z`, `phase=backup`, `reason=pdo_failure`, subcode 31 |
| Candidato actual | 🚧 **PR #215 / V 0.1.35** | fallback PDO entra por Symfony y reutiliza `Doctrine\DBAL\Connection` |
| Incidente actual | 🚧 **#200** | no cerrar hasta completar los cinco puntos de PRODUCCIÓN EN VERDE |

## Qué añade V 0.1.35

- registra `app:database:backup-pdo` como comando Symfony autoconfigurado;
- inyecta `Doctrine\DBAL\Connection` y obtiene el PDO nativo con `getNativeConnection()`, evitando `DriverManager` y un segundo armado de conexión;
- mantiene el dump nativo como primera opción; solo el fallback PDO cambia de camino;
- conserva snapshot consistente, streaming no bufferizado únicamente para filas, conteo por tabla y checksum SHA-256;
- conserva códigos de etapa 31..38 y mensajes sanitizados sin publicar excepciones del driver;
- reutiliza el gate CI existente que fuerza `CONDOR_BACKUP_CLIENT=pdo` y restaura esquema + datos reales.

## Invariantes

- esquema pendiente → dry-run/allowlist → backup exitoso → migrate → recheck → cache;
- cualquier fallo de backup bloquea migración y caché;
- secretos permanecen fuera del probe, logs compartibles y línea de comandos;
- `construction` solo admite migraciones forward/expand-compatible;
- `live` conserva fail-closed;
- V 0.1.35 no contiene migraciones ni cambios de producto.

## Cierre de TANDA 1

No declarar producción verde hasta demostrar: `/health` 200 con V/SHA/schema exactos; home/login/storefront/centro de control sin 5xx; observador productivo aprobado; cero incidentes/[AUTO] abiertos; CI exact-main aprobado.

## Referencias

- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap canónico: Issue #1
- Incidente: Issue #200
