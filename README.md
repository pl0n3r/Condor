# Condor App — Snapshot operativo · Release identity guard V 0.1.78

> **Candidato objetivo:** V0.1.78 · Issue #332 · bloquear reutilización de versión antes del merge.
>
> **Producción validada antes del cambio:** V0.1.77 · `main@03becea6e84f16b05407d5920d5c4039bd41dfd7` · release/tag exactos, Deploy Observer y CI exact-main en success; `/health` con esquema al día.

Condor continúa en construcción. V0.1.78 convierte incidentes repetidos de identidad de release en un guardrail pre-merge, read-only y reversible.

## Alcance
- leer la versión canónica desde `config/version.php`;
- exigir título terminal `(V X.Y.Z)` igual a la versión canónica;
- rechazar tags consumidos y versiones no monotónicas antes del merge;
- permitir saltos SemVer hacia adelante sin crear ni mover tags;
- ejecutar el guard en Preflight antes de gates costosos;
- cubrir los patrones históricos #233, #307, #315 y #330.

## Límites
- no crea, mueve ni borra tags/releases;
- no autoedita `config/version.php`;
- Release Factory v1 sigue siendo la autoridad final;
- no toca producción, dependencias, esquema, datos, DNS ni secretos.

## Evidencia base
- #233, #307, #315 y #330 documentan reutilización de identidad.
- `scripts/release_identity_guard.py` usa solo metadata/refs Git locales.
- `tests/test_release_identity_guard.py` fija AC-01..AC-06.

