# Condor App — Snapshot operativo · Sonar merge gate V 0.1.81

> **Candidato objetivo:** V0.1.81 · Issue #340 · protección nativa de main con Validar + SonarQube Cloud.
>
> **Base:** V0.1.80 · `main@3548ad2e7d835bf13d19b1a9fdf75a6cc0cc346a`.

Condor continúa en construcción. V0.1.81 convierte el Quality Gate de Sonar en una condición de gobierno verificable de la rama principal sin ejecutar un segundo análisis ni hacer polling de Sonar.

## Alcance
- exigir en la ruleset `23709472` los pares exactos `Validar@15368` y `SonarCloud Code Analysis@12526`;
- verificar target, enforcement, default branch y flags del required status gate;
- fallar cerrado ante ausencia, integration id incorrecto, duplicados o payload malformed;
- validar `bypass_actors=[]` cuando el campo sea visible y declarar `visibility=unknown` cuando GitHub lo oculte por privilegio mínimo;
- consultar la ruleset una sola vez dentro del job existente `Validar gobierno del repositorio`;
- mantener `strict_required_status_checks_policy=false` y `do_not_enforce_on_create=false`.

## Límites
- no consulta la API de Sonar ni ejecuta otro scanner;
- no espera ni sondea check-runs;
- no autoaprueba workflows;
- no introduce PAT ni credenciales administrativas en CI;
- no cambia producto, DB, DNS ni producción;
- la mutación administrativa de ruleset es reversible y debe preservar las demás reglas existentes.

## Evidencia base
- #338 cerró con producción V0.1.80 exacta y Sonar Security Rating A;
- el snapshot vivo previo de ruleset `23709472` exige hoy solo `Validar@15368`;
- `scripts/verify_sonar_merge_gate.py` implementa el drift checker fail-closed;
- `tests/test_sonar_merge_gate.py` cubre AC-01..AC-05;
- `.github/workflows/ci.yml` ejecuta tests y una única lectura live de ruleset;
- el candidato permanecerá bloqueado hasta que la ruleset viva incluya también `SonarCloud Code Analysis@12526`.
