# Condor App — Snapshot operativo · candidato V 0.1.21

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** cerrar la deriva código/esquema observada tras V 0.1.20 y hacer que la observación automática espere de forma acotada el deploy real de Hostinger antes de reportar un falso NO_OBSERVADO.

<p align="center">
  <strong>Base:</strong> V 0.1.20 · main `1f8b2262` ·
  <strong>Candidato:</strong> V 0.1.21 ·
  <strong>Producción comprobada:</strong> V 0.1.20 / DEPLOY_OBSERVED
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.20 / EXACT-MAIN** | SHA `1f8b22625f28de64c591c8083098c093e796e673` |
| Producción base | 🚧 **DEPLOY_OBSERVED** | versión/SHA exactos, health/home/login/CSS observados |
| Storefront base | ❌ **DERIVA DE ESQUEMA DETECTADA** | `/marcela-arias-tienda` devolvió 500 con migraciones pendientes |
| Candidato actual | 🚧 **V 0.1.21 EN VALIDACIÓN** | Issue #173 / PR #174 |
| CI de rama | ✅ **VERDE ANTES DEL HEAD FINAL** | debe repetirse tras cualquier corrección |
| SonarQube | ✅ **0 ISSUES ANTES DEL HEAD FINAL** | debe repetirse tras cualquier corrección |
| Producción V 0.1.21 | ⏳ **NO APLICA AÚN** | solo se observa después del merge/deploy |

## Qué incorpora V 0.1.21

- observador automático distingue una versión anterior legítima como `deploy_pendiente` y espera hasta 20 minutos;
- versiones futuras, SHA incompatibles o identidades ajenas fallan inmediatamente;
- assets JS/CSS tienen presupuesto separado de 4 MiB;
- se acepta el MIME legacy `application/x-javascript` servido por Hostinger;
- comentarios del Roadmap describen la causa real de observación, sin asumir deploy pendiente cuando no corresponde;
- `scripts/post-deploy.sh` aplica migraciones forward seguras antes de limpiar/calentar caché;
- el post-deploy usa lock con token de propietario y recuperación acotada de locks huérfanos;
- D-044 formaliza migrar esquema → limpiar caché → calentar caché bajo shared hosting;
- migraciones destructivas automáticas continúan prohibidas;
- regresiones unitarias y contract tests fijan espera de deploy, MIME/assets y orden/locking del post-deploy.

## Archivos del deploy

- `.github/workflows/observar-deploy-automatico.yml`
- `scripts/observar_release.py`
- `scripts/post-deploy.sh`
- `tests/test_observar_release.py`
- `tests/contract/test_tooling_contract.py`
- `ESPECIFICACIONES.md`
- `config/version.php`
- `package.json`
- `package-lock.json`
- `README.md`

## Validación requerida

- CI completo, SonarQube y CodeRabbit sobre el SHA final estable;
- squash merge y exact-main;
- tag anotado + GitHub Release `v0.1.21`;
- observador post-merge debe esperar el deploy real en vez de emitir NO_OBSERVADO prematuro;
- verificar que el cron post-deploy aplica migraciones pendientes y que el storefront representativo deja de responder 500;
- solo entonces evaluar `VALIDATED_IN_PRODUCTION`.

## AHORA / SIGUE / DESPUÉS

- **AHORA:** cerrar V 0.1.21 (#173/#174) con evidencia exact-head.
- **SIGUE:** reconstruir Inventario (#171/#172) sobre el nuevo `main` como V 0.1.22.
- **DESPUÉS:** continuar el Roadmap #1 sin mezclar identidad de código, deploy observado y validación productiva.

## Referencias

- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap canónico: Issue #1

> **Regla de estado:** migrar esquema automáticamente evita deriva código/esquema; no convierte por sí sola una release en VALIDATED_IN_PRODUCTION.
