# Condor App — Snapshot operativo · Release transition V 0.1.82

> **Candidato objetivo:** V0.1.82 · Issue #346 · separar identidad de release de transición operativa.
>
> **Base:** V0.1.81 · `main@0276d7ceaa21e4d8e99f19780db0c90aa22d71b0`.

Condor continúa en construcción. V0.1.82 corrige una clasificación sobregeneralizada: `config/version.php` sigue siendo identidad de release y dispara el CI completo, pero por sí solo ya no exige transición operativa.

## Alcance
- mantener `config/version.php` como `categoria_release=true`;
- mantener full-stack CI para cambios de release;
- excluir únicamente la identidad de versión de `transicion_release`;
- conservar transición obligatoria para migraciones, configuración runtime, comandos, servicios, identidad y scripts operativos;
- mantener `release_evidence.py` coherente con la misma semántica;
- conservar el observer automático consumiendo el clasificador canónico, sin una lista paralela en YAML.

## Límites
- no cambia la fuente canónica de versión;
- no relaja health, smoke, schema ni transiciones operativas reales;
- no cambia producción, DB, DNS, secretos ni permisos;
- no convierte `DEPLOY_OBSERVED` en success ante fallos reales.

## Evidencia objetivo
- AC-01..AC-05: clasificador y wiring canónico;
- AC-06: observer distingue release sin transición de release sensible;
- AC-07: manifest de evidencia coherente;
- AC-08: README y `config/version.php` conservan identidad V0.1.82 en paridad;
- tras merge, exact-main CI + Release + Deploy Observer deben validar el SHA de V0.1.82 antes de declarar producción GREEN.
