# Condor App — Snapshot operativo · candidato V 0.1.19

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)

> **Objetivo actual:** hacer que cada versión integrada en `main` tenga identidad de repositorio recuperable mediante tag Git anotado y GitHub Release, sin mezclar ese estado con deploy o producción validada.

<p align="center">
  <strong>Base:</strong> V 0.1.18 ·
  <strong>Candidato:</strong> V 0.1.19 ·
  <strong>Release metadata:</strong> tag + GitHub Release idempotentes
</p>

## Qué incorpora V 0.1.19

- workflow `.github/workflows/tag-release.yml` tras cada push a `main`;
- lectura y validación estricta de `config/version.php`;
- tag anotado `vX.Y.Z` creado mediante GitHub API, sin credenciales persistidas por checkout;
- grupo de concurrencia único para evitar carreras entre pushes de `main`;
- comportamiento idempotente cuando el tag ya existe;
- recuperación automática del estado parcial “tag existe, GitHub Release falta”;
- GitHub Release con notas autogeneradas y `--verify-tag`;
- no despliega, no migra y no declara `VALIDATED_IN_PRODUCTION`.

## Estado

V 0.1.18 sigue siendo el candidato inmediatamente anterior hasta que #166 se integre. Este PR permanece apilado temporalmente sobre #166 y se retargeteará a `main` después de ese merge.

> **Regla de estado:** tag/GitHub Release identifican código; no equivalen a deploy ni a validación productiva.
