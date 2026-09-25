# Condor App — Snapshot operativo · Factory deploy V 0.1.43

> **Candidato:** Issue #227 · adapters de deploy/rollback Factory v1 para Hostinger.

Condor permanece en **construcción** y la producción vigente continúa bajo la autoridad actual de Hostinger Git. Este corte añade el circuito reusable sin activarlo: `FACTORY_DEPLOY_ENABLED` debe permanecer distinto de `true` hasta la prueba E2E/cutover de #228.

## Alcance
- adapters fijos `ops/factory/{build,backup,migrate,deploy,rollback}`;
- backup previo reutilizando `scripts/backup-database.sh`;
- migración D-054 reutilizando `scripts/post-deploy.sh`;
- releases remotos por SHA bajo `HOSTINGER_RELEASE_ROOT/releases/<sha>`;
- cambio atómico de `current` y rollback exclusivo de artefacto a `.previous`;
- SSH con host key pinneada; nunca `StrictHostKeyChecking=no`;
- caller Factory v1 manual y fail-closed por `FACTORY_DEPLOY_ENABLED`.

## Fuera de alcance
No cambia DNS, no compra/cambia planes, no ejecuta SQL destructivo, no restaura la base de datos y no activa el cutover productivo. #228 debe demostrar el circuito real, smoke/health exactos y rollback antes de cambiar autoridad.

## Fuentes de verdad
- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap: Issue #1
- Épico Factory: #192
- Slice actual: #227
