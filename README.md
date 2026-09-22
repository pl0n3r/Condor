# Condor App — Snapshot operativo · candidato V 0.1.20

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** demostrar que Condor puede respaldar y restaurar su base de datos de forma verificable y bloquear dependencias con vulnerabilidades conocidas antes de integrar código.

<p align="center">
  <strong>Base:</strong> V 0.1.19 · main `363604ec` ·
  <strong>Candidato:</strong> V 0.1.20 ·
  <strong>Producción V 0.1.19:</strong> NO_OBSERVADO
</p>

## Estado de entrega

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.19 / EXACT-MAIN** | SHA `363604ec50941b175dbbc04dd473422ca4989edd` |
| Identidad de release base | ✅ **TAG + RELEASE** | `v0.1.19` anotado + GitHub Release publicado |
| Candidato actual | 🚧 **V 0.1.20 EN VALIDACIÓN** | Issue #169 / PR #170 |
| Backup/restauración | ✅ **DEMOSTRADO EN CI DE RAMA** | dump real → base descartable → esquema consultable |
| Auditorías | ✅ **GATES INDEPENDIENTES** | npm high + Composer lock |
| Producción | ⛔ **NO OBSERVADA PARA V 0.1.19** | merge/release metadata no equivalen a deploy |

## Qué incorpora V 0.1.20

- `scripts/backup-database.sh` compatible con MariaDB/MySQL y shared hosting;
- dumps gzip con nombres UTC + token único y publicación atómica;
- retención configurable con default 30 y validación fail-closed antes de crear/eliminar artefactos;
- parser compartido de `DATABASE_URL` con percent-decoding, IPv6 y option-file MySQL temporal `0600`;
- credenciales retiradas del entorno antes de ejecutar clientes de base de datos;
- selección portable de `mariadb-dump` / `mysqldump`, usando `--column-statistics=0` solo cuando el binario lo soporta;
- restore permitido únicamente sobre nombres de base descartables y con `CONDOR_ALLOW_DESTRUCTIVE_RESTORE=1`;
- validación post-restore de tablas críticas, migraciones y consulta representativa;
- regresiones para credenciales URL-encoded, retención inválida y guardas destructivas;
- jobs independientes `npm audit --audit-level=high` y `composer audit --locked --no-interaction`;
- agregador final de CI exige auditorías y backup/restauración cuando corresponda.

## Seguridad y operación

- ningún script ejecuta restore destructivo sobre una base que no cumpla el patrón descartable;
- el backup no usa contraseñas en argumentos ni `MYSQL_PWD`;
- temporales/credenciales se eliminan también ante fallos tempranos;
- la restauración se demuestra en MariaDB real dentro de CI;
- el script queda listo para cron, pero **este PR no activa cron en Hostinger**;
- no añade destino externo de backup ni cambia producción.

## Qué sigue

- cerrar CI, SonarQube y CodeRabbit sobre el SHA exacto final de V 0.1.20;
- squash merge y validación exact-main;
- comprobar creación automática de tag/Release `v0.1.20`;
- mantener deploy y validación productiva como estados separados;
- después desbloquear Slice 4 — Inventario (#171 / V 0.1.21).

> **Regla de estado:** backup demostrado en CI valida el mecanismo; no prueba que exista todavía un cron productivo ni un backup off-site.
