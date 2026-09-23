# Condor App — Snapshot operativo · candidato V 0.1.22

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** cerrar Slice 4 — Inventario con stock por variante + fuente, movimientos auditables y transferencias atómicas, preservando aislamiento por tenant, entidad legal y sede.

<p align="center">
  <strong>Base integrada:</strong> V 0.1.21 · main actual `38c06714` ·
  <strong>Candidato:</strong> V 0.1.22 ·
  <strong>Rama:</strong> `trabajo/issue-171`
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.21 / MAIN ACTUAL** | SHA `38c06714358656a83948e969239f687f7ad40de9` · #176 es documental sobre la release V 0.1.21; tag/Release `v0.1.21` siguen apuntando al commit de release `b743ce32534a9a6619b1ee0328cc809b53efe88d` |
| Producción V 0.1.21 | ⛔ **NO OBSERVADA / ESTADO SEPARADO** | el observador de `b743ce32534a9a6619b1ee0328cc809b53efe88d` terminó con HTTP 500; la última identidad confirmada manualmente sigue siendo V 0.1.20. Una eventual reconciliación de esquema requiere autorización humana explícita |
| Inventario sincronizado | ✅ **BASE ACTUAL SINCRONIZADA** | merge técnico `a41e07f46bc44b71139470db86d39030165d13a9` incorpora `main` `38c06714358656a83948e969239f687f7ad40de9` · `behind_by=0` |
| Candidato actual | 🚧 **V 0.1.22 EN VALIDACIÓN EXACT-HEAD** | Issue #171 / PR #172 |
| CI/Sonar del head final | ⏳ **EXACT-HEAD OBLIGATORIO** | todos los gates deben terminar sobre el último commit del candidato después de cualquier corrección documental o funcional |
| CodeRabbit | ⏳ **EXACT-HEAD OBLIGATORIO** | la revisión final debe corresponder al mismo HEAD que CI y Sonar antes de sacar el PR de draft |

## Qué incorpora V 0.1.22

- fuentes de inventario por sede o lógicas, con titularidad explícita por `LegalEntity`;
- stock actual por `ProductVariant + InventorySource`;
- movimientos inmutables/auditables para ajustes y transferencias;
- transferencias atómicas con locking pesimista, idempotencia y rechazo de cruces entre entidades legales;
- backorder configurable por producto y desactivado por defecto;
- API administrativa branch-scoped con CSRF y permisos `inventory.*`;
- aislamiento server-side por tenant, entidad legal y sede;
- ciclo de vida seguro de fuentes, incluida desactivación serializada frente a mutaciones concurrentes;
- UI administrativa responsive para fuentes, saldos, ajustes, transferencias e historial;
- estados loading, empty, error y permission-denied reales;
- pruebas de dominio, HTTP, MariaDB y Playwright del flujo crítico;
- bundle administrativo reproducible generado con Vite.

## Invariantes del slice

- una fuente de sede pertenece a la misma entidad legal que su sede;
- una fuente lógica declara entidad legal efectiva;
- una transferencia simple solo ocurre entre fuentes de la misma entidad legal;
- una operación repetida con la misma clave idempotente no duplica efectos;
- una mutación nueva sobre fuente inactiva se rechaza incluso bajo carrera concurrente;
- no existe fallback automático de stock entre sedes;
- producción nunca se migra automáticamente desde CI, deploy, smoke ni observador.

## Archivos principales

- `src/Application/Inventory/InventoryService.php`
- `src/Domain/Inventory/Entity/*`
- `src/Http/Controller/InventoryController.php`
- `frontend/admin/InventoryManagement.tsx`
- `frontend/admin/AdminApp.tsx`
- `migrations/Version20260922184500.php`
- `tests/php/Application/Inventory/InventoryServiceTest.php`
- `tests/php/Http/InventoryControllerTest.php`
- `tests/e2e/slice4-inventory.spec.mjs`
- `public/build/admin.js`

## Validación requerida

- CI, SonarQube y CodeRabbit terminales sobre el SHA final exacto, sin gates fallidos ni findings válidos pendientes;
- PR fuera de draft únicamente después de fijar el snapshot V 0.1.22 y obtener evidencia exact-head;
- squash merge y exact-main;
- tag anotado + GitHub Release `v0.1.22`;
- observación post-merge de la nueva release como estado separado de la validación productiva;
- cualquier migración productiva necesaria requiere autorización humana explícita y una operación separada.

## Estado inmediato

- **V 0.1.22:** candidato serial activo en validación exact-head.
- **Producción:** la última identidad confirmada manualmente sigue siendo V 0.1.20; V 0.1.21 quedó `NO_OBSERVADO` por HTTP 500. Ninguna migración o reconciliación productiva queda autorizada por este candidato.
- La planificación posterior vive exclusivamente en el Roadmap canónico #1.

## Referencias

- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap canónico: Issue #1

> **Regla de estado:** código integrado, deploy observado, esquema reconciliado y producción validada son evidencias distintas. Una migración productiva exige autorización explícita.
