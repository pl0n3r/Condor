# Condor App — Snapshot operativo · Dependabot V 0.1.45

> **Candidato:** Issue #208 · actualizaciones agrupadas de Composer, npm y GitHub Actions.

Condor permanece en **construcción**. V0.1.45 recupera el PR histórico #209 sobre el `main` GREEN V0.1.44 y añade únicamente mantenimiento automatizado de dependencias; no cambia runtime, esquema, secretos ni autoridad productiva.

## Alcance
- habilitar Dependabot para Composer, npm y GitHub Actions;
- agrupar actualizaciones minor/patch con frecuencia semanal los lunes;
- limitar a tres PRs abiertos por ecosistema;
- aplicar clasificación inicial canónica de tipo, prioridad y estado a los PRs de Dependabot;
- conservar Factory v1, CI Condor, release, observer y deploy reversible sin cambios funcionales.

## Seguridad y reversión
- Dependabot solo propone PRs; no habilita automerge;
- ningún token, secreto, dato productivo ni permiso adicional se añade al repositorio;
- revertir este corte elimina `.github/dependabot.yml` y vuelve al comportamiento anterior;
- este candidato incrementa versión a 0.1.45 para mantener identidad única versión/SHA tras el merge.

## Evidencia base
- `main@30ed70c265653291fb768f111a30c72826c955cb` · V0.1.44;
- TANDA 2 / Factory v1 completada en #228;
- CI exact-main, Release Factory y Observer productivo de V0.1.44: GREEN;
- reserva v2 #208: `882f4656-e2a0-4710-b9b1-a4faa5fdde85`.

## Fuentes de verdad
- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap: Issue #1
- Slice actual: #208
