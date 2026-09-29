# Condor App — Snapshot operativo · Dependency promotion hardening V 0.1.80

> **Candidato objetivo:** V0.1.80 · Issue #338 · reparación post-merge de la promoción gobernada de PRs bot.
>
> **Base:** V0.1.79 · `main@2bc5e24c284149bc6eb4cd45942dd30d7616be23`.

Condor continúa en construcción. V0.1.80 endurece el flujo de promoción introducido en #335 sin cambiar su objetivo: un PR Dependabot/Renovate sigue siendo una fuente read-only y solo puede convertirse en una entrega versionada cuando la autoridad Factory es válida.

## Alcance
- validar los markers de reserva con el schema cerrado Factory v1/v2/v3 antes de aceptar autoridad;
- conservar latest-global, owner, branch, UUID y estado activo como requisitos fail-closed;
- mover los artefactos temporales a `.condor-runtime/dependency-promotion` dentro del workspace;
- crear el runtime con permisos 0700 y rechazar symlinks;
- eliminar rutas públicas `/tmp` del workflow;
- reducir `materialize` a inputs CLI mínimos y revalidar SHA/título antes de escribir versión/README;
- conservar paginación, revalidaciones TOCTOU, rollback y clasificación atómica ya integrados.

## Límites
- no cambia el formato canónico de reservas Factory;
- no relaja el release identity guard ni reutiliza versiones/tags;
- no ejecuta una promoción real durante CI;
- no toca producción, DB, DNS, secretos ni dependencias concretas;
- no silencia Sonar.

## Evidencia
- #338 fija AC-01..AC-05 como contrato ejecutable;
- el runtime privado no depende de paths suministrados por CLI;
- markers v2 sin `acceptance_sha256`, schemas con extras o tipos inválidos se rechazan;
- v2/v3 canónicos permanecen compatibles;
- las dos revalidaciones de reserva siguen antes de los writes remotos.
