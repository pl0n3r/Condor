# Condor App — Snapshot operativo · Dependabot policy V 0.1.52

> **Candidato:** Issue #247 · impedir upgrades semver-major automáticos fuera del stack canónico.

Condor continúa en **construcción**. V0.1.52 mantiene Dependabot semanal para minor/patch, pero evita que Composer, npm y GitHub Actions abran PRs automáticos de upgrades major que deben planificarse explícitamente.

## Alcance
- Composer conserva el grupo `composer-minor` para minor/patch;
- npm conserva el grupo `npm-minor` para minor/patch;
- GitHub Actions conserva el grupo `github-actions-minor` para minor/patch;
- cada ecosistema ignora `version-update:semver-major` mediante una regla única `dependency-name: "*"`;
- se preservan labels, frecuencia semanal y `open-pull-requests-limit: 3`;
- regresión `tests/test_dependabot_policy.py` fija AC-01..AC-03.

## Seguridad y reversión
Este slice solo modifica configuración de Dependabot y su contrato de regresión. No cambia runtime, base de datos, secretos, permisos, producción ni automerge. Revertir el candidato restaura la política anterior sin tocar dependencias instaladas.

## Evidencia base
- `main@c6c6d684d744a4896bb86f5379291f6ae529afa4` · V0.1.51.
- Reserva activa #247: `495ae98f-9186-43af-95e0-6d0867f5122f`.
- Rama histórica reconstruida desde main actual; no se arrastran commits obsoletos de V0.1.47.
- Factory v1, Política, Privacidad, CI Condor y revisión deben pasar sobre el HEAD exacto.

## Fuentes de verdad
- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap: Issue #1
- Slice actual: #247
