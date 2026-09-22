# Condor App — Snapshot operativo · candidato V 0.1.19

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)

> **Objetivo actual:** hacer que cada versión integrada en `main` tenga identidad de repositorio recuperable mediante tag Git anotado y GitHub Release, sin mezclar ese estado con deploy o producción validada.

<p align="center">
  <strong>Base:</strong> V 0.1.18 · main `5c21e665` ·
  <strong>Candidato:</strong> V 0.1.19 ·
  <strong>Release metadata:</strong> tag + GitHub Release idempotentes
</p>

## Estado de entrega

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.18 / EXACT-MAIN** | SHA `5c21e665e49752ee22328876b1b7a63db3f106ca` |
| Candidato actual | 🚧 **V 0.1.19 EN VALIDACIÓN** | Issue #167 / PR #168 |
| Tag por versión | ✅ **IMPLEMENTADO EN CANDIDATO** | `vX.Y.Z` anotado sobre el SHA que introduce la versión |
| GitHub Release | ✅ **RECUPERABLE E IDEMPOTENTE** | completa el Release aunque el tag ya exista |
| Producción | ℹ️ **SEPARADA** | tag/release no equivalen a deploy ni a validación productiva |

## Qué incorpora V 0.1.19

- workflow `.github/workflows/tag-release.yml` en cada push a `main`;
- lectura y validación estricta de `config/version.php`;
- tag anotado `vX.Y.Z` creado mediante GitHub API;
- checkout sin credenciales persistidas;
- grupo de concurrencia único para evitar carreras entre pushes de `main`;
- comportamiento idempotente cuando el tag ya existe;
- recuperación automática del estado parcial “tag existe, GitHub Release falta”;
- GitHub Release con notas autogeneradas y `--verify-tag`;
- ninguna mutación de deploy, migraciones ni producción.

## Qué sigue

- cerrar CI, SonarQube y CodeRabbit sobre el SHA exacto final de V 0.1.19;
- integrar serialmente solo si el candidato queda verde y sin findings válidos;
- después reconstruir V 0.1.20 sobre el nuevo `main`;
- mantener separado `VALIDATED_IN_CODE`, `DEPLOY_OBSERVED` y `VALIDATED_IN_PRODUCTION`.

> **Regla de estado:** tag/GitHub Release identifican código; no prueban despliegue ni salud productiva.
