# Condor App — Snapshot operativo · candidato V 0.1.34

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** cerrar TANDA 1 eliminando la divergencia entre la conexión Doctrine que sí alcanza a inspeccionar el esquema y los caminos de backup que reconstruían la DSN por separado.

<p align="center">
  <strong>Base integrada en código:</strong> V 0.1.33 · main `b58ebc2d0041c63c8615f93e7ae48b1aff93d193` ·
  <strong>Candidato:</strong> V 0.1.34 · PR #214 ·
  <strong>Incidente:</strong> #200
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.33 / MAIN** | SHA `b58ebc2d0041c63c8615f93e7ae48b1aff93d193` |
| Exact-main V 0.1.33 | ✅ **VALIDADO EN CÓDIGO** | CI #943 / run `35971886157` en success; release `v0.1.33` publicada |
| Observador V 0.1.33 | ⛔ **NO_OBSERVADO** | run `35971886104`: V/SHA exactos; cron termina en `phase=backup`, `reason=pdo_failure`, subcode 31 |
| Candidato actual | 🚧 **PR #214 / V 0.1.34** | parser DSN compartido con paridad DBAL + conexión PDO construida por Doctrine + soporte de `unix_socket`/charset |
| Incidente actual | 🚧 **#200** | no cerrar hasta completar los cinco puntos de PRODUCCIÓN EN VERDE |

## Qué añade V 0.1.34

- centraliza la interpretación de `DATABASE_URL` en `DatabaseDsn`, usando `Doctrine\DBAL\Tools\DsnParser`;
- hace que el backup PDO cree la conexión con `DriverManager` y obtenga su conexión PDO nativa, en vez de reconstruir un DSN manual;
- conserva parámetros que el backup anterior descartaba, especialmente `unix_socket` y `charset`;
- hace que el option-file del dump nativo consuma la misma interpretación y propague el socket cuando exista;
- mantiene errores sanitizados: ninguna excepción de conexión, URL, host, usuario o contraseña se publica;
- añade regresiones para credenciales codificadas, esquema MariaDB y parámetros de conexión adicionales.

## Invariantes

- esquema pendiente → dry-run/allowlist → backup exitoso → migrate → recheck → cache;
- cualquier fallo de backup bloquea migración y caché;
- secretos permanecen fuera del probe, logs compartibles y línea de comandos;
- `construction` solo admite migraciones forward/expand-compatible;
- `live` conserva fail-closed;
- V 0.1.34 no contiene migraciones ni cambios de producto.

## Cierre de TANDA 1

No declarar producción verde hasta demostrar: `/health` 200 con V/SHA/schema exactos; home/login/storefront/centro de control sin 5xx; observador productivo aprobado; cero incidentes/[AUTO] abiertos; CI exact-main aprobado.

## Referencias

- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap canónico: Issue #1
- Incidente: Issue #200
