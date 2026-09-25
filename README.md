# Condor App — Snapshot operativo · Factory v1 E2E V 0.1.44

> **Candidato:** Issue #228 · prueba end-to-end y cierre de adopción Factory v1.

Condor permanece en **construcción**. V0.1.44 es un corte deliberadamente mínimo para ejercer en serie el `main` real, los gates Factory/Condor, el release observable y la verificación productiva exacta sin introducir cambios funcionales ni de esquema.

## Alcance
- ejecutar CI Factory v1, política, coordinación y gates específicos de Condor sobre el HEAD exacto del PR;
- integrar serialmente sobre el `main` real;
- verificar después del merge versión `0.1.44`, SHA exacto y esquema sano mediante el observer;
- mantener probado el contrato fail-closed de backup/migración y rollback exclusivo de artefacto;
- documentar explícitamente los remanentes reales de #188 y #190 antes de cerrar el épico #192.

## Seguridad y reversión
- no añade migraciones ni SQL;
- no restaura automáticamente la base de datos;
- no cambia DNS, plan de Hostinger ni secretos;
- el caller reusable de Factory solo puede operar con su configuración segura existente; no se inventan variables/secretos faltantes;
- si el release productivo no coincide exactamente con versión/SHA o falla smoke, no se declara GREEN y #192 permanece abierto.

## Evidencia base
- #226: adopción del núcleo/workflows Factory v1 completada;
- #227 / PR #236: adapters deploy/rollback V0.1.43 integrados y producción GREEN;
- #228: cierre E2E de TANDA 2, condicionado a evidencia exact-main y productiva.

## Fuentes de verdad
- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap: Issue #1
- Épico Factory: #192
- Slice actual: #228
