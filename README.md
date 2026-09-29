# Condor App — Snapshot operativo · Dependency promotion flow V 0.1.79

> **Candidato objetivo:** V0.1.79 · Issue #335 · ruta canónica para PRs automáticos de dependencias.
>
> **Base publicada antes del cambio:** V0.1.78 · `main@7096ac6c0a4b871e51348f5643a5d111b94eb3f6` · tag/release exactos y Deploy Observer en success.

Condor continúa en construcción. V0.1.79 convierte los PRs Dependabot/Renovate en fuentes read-only que solo pueden llegar a `main` mediante una promoción versionada y gobernada por una reserva Factory.

## Alcance
- exigir un Issue abierto y una reserva activa antes de promover un PR bot;
- aceptar únicamente Dependabot/Renovate del mismo repositorio y rutas de dependencias allowlisted;
- preservar el diff fuente mediante parche binario y verificación exacta de archivos;
- aplicar el cambio sobre la rama `trabajo/issue-N` ya reservada y nacida del `main` exacto;
- materializar automáticamente el siguiente patch de `config/version.php` y un snapshot README coherente;
- abrir un PR versionado y clasificado sin editar ni fusionar directamente el PR bot original.

## Límites
- no existe bypass del release identity guard de #332;
- no se reutilizan tags ni versiones humanas;
- conflictos de parche, reserva inválida, rama stale, autor/ruta no permitidos o SHA cambiante fallan cerrado;
- la promoción no crea tags/releases ni toca producción;
- Release Factory v1 conserva la autoridad final de publicación.

## Evidencia base
- PR #309 reproduce el caso real: Dependabot sin identidad `(V X.Y.Z)`.
- CI #309 falló en Preflight antes de existir una ruta de promoción.
- `scripts/dependency_pr_promotion.py` valida bot, reserva, scope de archivos e identidad.
- `.github/workflows/promote-dependency-pr.yml` aplica el diff sobre una rama Factory reservada.
- `tests/test_release_identity_guard.py` cubre AC-01..AC-05 de #335.
