# Condor App — Snapshot operativo · Dependency promotion flow V 0.1.80

> **Candidato objetivo:** V0.1.80 · Issue #338 · hardening post-merge de la ruta canónica para PRs automáticos de dependencias.
>
> **Base:** V0.1.79 · `main@2bc5e24c284149bc6eb4cd45942dd30d7616be23`.

Condor continúa en construcción. Los PRs Dependabot/Renovate siguen siendo fuentes read-only que solo pueden llegar a `main` mediante una promoción versionada y gobernada por una reserva Factory.

## Alcance
- exigir un Issue abierto y una reserva activa antes de promover un PR bot;
- aceptar únicamente Dependabot/Renovate del mismo repositorio y rutas de dependencias allowlisted;
- validar el marker de reserva con el schema cerrado Factory v1/v2/v3;
- preservar el diff fuente mediante parche binario y verificación exacta de archivos;
- usar un runtime privado `.condor-runtime/dependency-promotion` con permisos 0700 y sin symlinks;
- aplicar el cambio sobre la rama `trabajo/issue-N` ya reservada y nacida del `main` exacto;
- materializar automáticamente el siguiente patch de `config/version.php` con identidad revalidada;
- abrir un PR versionado y clasificado sin editar ni fusionar directamente el PR bot original.

## Límites
- no existe bypass del release identity guard de #332;
- no se reutilizan tags ni versiones humanas;
- conflicts, reservas inválidas, schema incompleto, rama stale, autor/ruta no permitidos o SHA cambiante fallan cerrado;
- la promoción no crea tags/releases ni toca producción;
- no usa directorios públicos `/tmp` ni silencia Sonar;
- Release Factory v1 conserva la autoridad final de publicación.

## Evidencia base
- #338 cubre markers v2/v3 canónicos y rechaza fingerprints faltantes, extras y tipos inválidos;
- las dos revalidaciones TOCTOU permanecen antes de los writes remotos;
- `scripts/dependency_pr_promotion.py` valida bot, reserva, scope de archivos e identidad;
- `.github/workflows/promote-dependency-pr.yml` usa únicamente el runtime privado;
- `tests/test_release_identity_guard.py` cubre AC-01..AC-05 de #338.
