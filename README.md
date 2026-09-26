# Condor App — Snapshot operativo · Coordinación Factory v2 V 0.1.48

> **Candidato:** Issue #251 · enrutar comandos v2 del coordinador Factory.

Condor permanece en **construcción**. V0.1.48 prepara el wrapper local de coordinación para recuperar contratos huérfanos y renovar contratos v2 sin cerrar ni duplicar ramas/PR existentes.

## Alcance
- enruta `/adoptar-contrato-huerfana` hacia el coordinador canónico de Factory;
- enruta `/renovar-contrato <uuid>` y concede `checks: write` únicamente al job de comentarios que necesita invalidar evidencia previa;
- conserva `pl0n3r/factory@v1`, acciones fijadas por SHA y permisos mínimos en los demás jobs;
- añade regresiones de contrato sobre comandos, permisos, referencia estable y versión;
- prepara la recuperación de #188/PR #249 y la renovación de #191/PR #232 cuando Factory v1.0.5 sea publicado por su puerta humana.

## Dependencia externa
- Factory #176 sigue siendo una puerta humana exact-SHA y no se considera aprobada por mensajes genéricos;
- mientras el canal estable `factory@v1` siga en v1.0.4, los comandos nuevos pueden quedar rechazados por el coordinador publicado;
- no se usa `@main` como bypass.

## Serialización
- `main` base: V0.1.47;
- este candidato ocupa V0.1.48;
- #188/PR #249 deberá rebasarse después sobre este `main` y pasar a V0.1.49 antes de integrar.

## Seguridad y reversión
- no hay SQL, migraciones, secretos, Hostinger ni cambios de runtime;
- `checks: write` no se concede globalmente ni a jobs que no lo requieren;
- revertir este corte restaura el filtro previo sin modificar datos ni producto.

## Evidencia base
- `main@d12dee38cf4227889a00207acc2229af0089e64f` · V0.1.47;
- #188/PR #249 está huérfano y Factory exige adopción explícita;
- #191/PR #232 requiere renovación de contrato v2;
- reserva v2 #251: `f5609a24-5c3c-4917-9d08-17bbd16dfd51`.

## Fuentes de verdad
- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap: Issue #1
- Slice actual: Issue #251
