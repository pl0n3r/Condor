# Condor App — Snapshot operativo · candidato V 0.1.23

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** consolidar la propiedad jurídica por razón social y el contexto multi-entidad del administrador, sin ampliar permisos ni confundir transferencias internas con operaciones intercompany.

<p align="center">
  <strong>Base integrada:</strong> V 0.1.22 · main `90083457` ·
  <strong>Candidato:</strong> V 0.1.23 ·
  <strong>Rama:</strong> `trabajo/issue-177`
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.22 / MAIN ACTUAL** | SHA `90083457d9d2d1777138b7a4d54048fa6fafc513` · tag/Release `v0.1.22` publicado |
| Exact-main V 0.1.22 | ✅ **VALIDADO EN CÓDIGO** | `Validar`, Backend PHP/MariaDB, contrato/integración, Playwright, Frontend, auditorías, gobierno, coordinación y SonarQube en success |
| Producción V 0.1.22 | ⛔ **NO OBSERVADA / ESTADO SEPARADO** | observador post-push terminó `NO_OBSERVADO` por HTTP 500; la última identidad confirmada manualmente sigue siendo V 0.1.20 |
| Candidato actual | 🚧 **V 0.1.23** | Issue #177 / PR #178 |
| CI/Sonar del HEAD final | ⏳ **EXACT-HEAD OBLIGATORIO** | debe revalidarse después del bump de versión y snapshot |
| CodeRabbit | ⏳ **EXACT-HEAD OBLIGATORIO** | revisión terminal sobre el mismo SHA final antes de ready/merge |

## Qué incorpora V 0.1.23

- decisión durable D-052: `Tenant` representa la cuenta/grupo Condor y `LegalEntity` el titular jurídico;
- contexto HTTP de razones sociales derivado únicamente de sedes ya autorizadas;
- metadata jurídica mínima en contexto administrativo: id y nombre, sin exponer NIT;
- entidad legal activa derivada de la sede activa, sin confiar en selección cliente como autoridad;
- selector «Razón social» solo cuando el usuario tiene acceso a más de una entidad legal;
- selector de sedes limitado a la entidad legal activa en contexto multi-entidad;
- cambio de razón social reutilizando una sede autorizada y recalculando permisos server-side;
- compatibilidad explícita con sedes legacy sin `legal_entity_id`, sin asignación silenciosa a la entidad primaria;
- transferencias intercompany permanecen fuera del flujo de transferencia interna de inventario;
- regresiones HTTP contra fuga de metadata y Playwright para multi-entidad/single-entity;
- documentación de arquitectura y especificaciones alineadas con la implementación.

## Invariantes del slice

- una razón social visible para un usuario debe provenir de al menos una sede que ese usuario ya puede consultar;
- cambiar contexto jurídico no concede permisos adicionales: el servidor vuelve a resolver sede y permisos efectivos;
- una sede legacy sin entidad legal permanece explícitamente sin entidad hasta una reconciliación autorizada;
- una transferencia simple de inventario no cruza entidades legales;
- una operación intercompany es un caso de uso separado y auditable;
- producción nunca se migra automáticamente desde CI, deploy, smoke ni observador.

## Archivos principales

- `ESPECIFICACIONES.md`
- `docs/arquitectura-entidades-legales.md`
- `src/Http/Controller/ApiContextController.php`
- `frontend/admin/AdminApp.tsx`
- `tests/php/Http/BranchAccessControllerTest.php`
- `tests/e2e/slice2.spec.mjs`
- `public/build/admin.js`
- `config/version.php`
- `package.json`
- `package-lock.json`

## Validación requerida

- CI, SonarQube y CodeRabbit terminales sobre el SHA final exacto, sin gates fallidos ni findings válidos pendientes;
- PR fuera de draft únicamente después de fijar V 0.1.23 y obtener evidencia exact-head;
- squash merge serial y validación exact-main;
- tag anotado + GitHub Release `v0.1.23` si la integración produce un nuevo deploy identificable;
- observación post-merge separada de la validación productiva;
- cualquier migración o reconciliación productiva requiere autorización humana explícita.

## Estado inmediato

- **V 0.1.23:** candidato serial de #177/#178; requiere revalidación exact-head tras este snapshot.
- **V 0.1.22:** integrada y validada en código; producción permanece `NO_OBSERVADO` por HTTP 500.
- **Producción:** la última identidad confirmada manualmente sigue siendo V 0.1.20. No se autoriza migración ni reconciliación productiva desde este PR.
- La planificación posterior vive exclusivamente en el Roadmap canónico #1.

## Referencias

- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap canónico: Issue #1

> **Regla de estado:** código integrado, deploy observado, esquema reconciliado y producción validada son evidencias distintas. Una migración productiva exige autorización explícita.
