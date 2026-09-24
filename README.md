# Condor App — Snapshot operativo · candidato V 0.1.31

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** corregir el `access_denied` confirmado de `mariadb-dump` aislando el option-file temporal de configuraciones externas del hosting, sin ampliar privilegios ni exponer secretos.

<p align="center">
  <strong>Base integrada:</strong> V 0.1.30 · main `32006c9c5cf1189c882aa8c1eb116373003b5c8e` ·
  <strong>Candidato:</strong> V 0.1.31 ·
  <strong>Incidente:</strong> #200
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.30 / MAIN** | SHA `32006c9c5cf1189c882aa8c1eb116373003b5c8e` |
| Exact-main V 0.1.30 | ✅ **VALIDADO EN CÓDIGO** | CI run 35946593975 (#926) en success |
| Observador V 0.1.30 | ⛔ **NO_OBSERVADO** | run 35946593943 (#20): HTTP 500; `phase=backup`, `reason=access_denied`, `subcode=2`, `backup_client=mariadb-dump` |
| Incidente actual | 🚧 **#200 / V 0.1.31** | usar option-file exclusivo para impedir overrides posteriores de `~/.my.cnf` |
| CI/Sonar/CodeRabbit | ⏳ **PENDIENTE DEL HEAD FINAL** | exact-head obligatorio antes del merge |

## Qué añade V 0.1.31

- conserva D-054 sin cambiar su orden ni permitir SQL destructivo;
- reemplaza `--defaults-extra-file` por `--defaults-file` como primer argumento del dump;
- evita que opciones globales o `~/.my.cnf` cargadas después puedan sobrescribir las credenciales temporales derivadas de `DATABASE_URL`;
- mantiene el password fuera de la línea de comandos y el option-file en modo `0600`;
- añade regresión que exige `--defaults-file` como primer argumento y prohíbe `--defaults-extra-file`;
- conserva el diagnóstico sanitizado `reason/subcode/backup_client` para validar producción sin exponer stderr ni secretos.

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
