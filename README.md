# Condor App — Snapshot operativo · candidato V 0.1.30

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** confirmar de forma sanitizada la causa exacta del backup fallido de D-054 en hosting compartido, sin exponer logs ni relajar seguridad.

<p align="center">
  <strong>Base desplegada:</strong> V 0.1.29 · main `fd0243b83a5fba1e16e1f027a42c395dd7f770be` ·
  <strong>Candidato:</strong> V 0.1.30 ·
  <strong>Incidente:</strong> #200
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.29 / MAIN** | SHA `fd0243b83a5fba1e16e1f027a42c395dd7f770be` |
| Exact-main V 0.1.29 | ✅ **VALIDADO EN CÓDIGO** | CI run 35942476302 (#918) en success |
| Observador V 0.1.29 | ⛔ **NO_OBSERVADO** | run 35942476298 (#19): HTTP 500; probe `phase=backup`, `result=failure`, `code=2`, actualizado 2026-09-24T01:40:04Z |
| Incidente actual | 🚧 **#200 / V 0.1.30** | confirmar clase de fallo y cliente de dump con enums seguros |
| CI/Sonar/CodeRabbit | ⏳ **PENDIENTE DEL HEAD FINAL** | exact-head obligatorio antes del merge |

## Qué añade V 0.1.30

- conserva D-054 sin cambiar su orden ni permitir SQL destructivo;
- registra en el status operacional únicamente `reason`, `subcode` y `backup_client` bajo allowlists cerradas;
- clasifica fallos de configuración, filesystem, opción no soportada, privilegio global, acceso, conexión, timeout o dump no clasificable;
- nunca publica stderr, hostname, usuario, contraseña, nombre de tabla/base ni SQL;
- el observer valida esos campos antes de mostrarlos en el Roadmap;
- una regresión de comportamiento demuestra que un error con identificadores sensibles termina expuesto solo como `reason=access_denied`, `subcode=2`, `backup_client=mariadb-dump`.

## Invariantes

- esquema pendiente → dry-run/allowlist → backup exitoso → migrate → recheck → cache;
- cualquier fallo de backup sigue bloqueando migración y caché;
- secretos permanecen fuera del probe;
- `construction` solo admite migraciones forward/expand-compatible;
- `live` conserva fail-closed.

## Cierre de TANDA 1

No declarar producción verde hasta demostrar los cinco puntos del plan: health 200 con V/SHA/schema exactos; home/login/storefront/centro de control sin 5xx; observador success; 0 incidentes/[AUTO]; CI exact-main success.

## Referencias

- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap canónico: Issue #1
- Incidente: Issue #200
