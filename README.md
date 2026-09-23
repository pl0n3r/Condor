# Condor App — Snapshot operativo · candidato V 0.1.24

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** consolidar la base comercial reusable de Condor con clientes, categorías comerciales, listas/reglas de precio y resolución determinista de un único precio efectivo, sin mezclar todavía pedidos, pagos ni consumo de inventario.

<p align="center">
  <strong>Base integrada:</strong> V 0.1.23 · main `d6170126` ·
  <strong>Candidato:</strong> V 0.1.24 ·
  <strong>Rama:</strong> `trabajo/issue-179`
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.23 / MAIN ACTUAL** | SHA `d61701267dd2f95292162a92fe07e15570824422` · arquitectura jurídica y contexto multi-entidad integrados |
| Exact-main V 0.1.23 | ✅ **VALIDADO EN CÓDIGO** | `Validar`, Backend PHP/MariaDB, contrato/integración, Playwright, Frontend, auditorías, gobierno, coordinación y SonarQube en success |
| Producción V 0.1.23 | ⛔ **NO OBSERVADA / ESTADO SEPARADO** | observador post-push terminó `NO_OBSERVADO` al no recibir una respuesta HTTP válida; no se declara `VALIDATED_IN_PRODUCTION` |
| Candidato actual | 🚧 **V 0.1.24** | Issue #179 / PR #180 |
| CI/Sonar del HEAD final | ⏳ **EXACT-HEAD OBLIGATORIO** | debe revalidarse después del bump de versión y snapshot |
| CodeRabbit | ⏳ **EXACT-HEAD OBLIGATORIO** | revisión terminal sobre el mismo SHA final antes de ready/merge |

## Qué incorpora V 0.1.24

- entidad `Customer` tenant-owned y separada de `User`;
- categorías comerciales configurables por tenant, con una única categoría principal efectiva por cliente;
- listas de precios tenant-owned con COP como moneda por defecto;
- precios por variante y lista persistidos en unidades menores enteras, evitando aritmética monetaria flotante;
- reglas comerciales iniciales con alcance explícito, prioridad, vigencia y categoría opcional;
- motor de precio efectivo que selecciona una sola regla ganadora de forma determinista por prioridad, especificidad y desempate estable;
- lista preferida configurable por categoría comercial y posibilidad de solicitar explícitamente una lista permitida;
- respuesta trazable del precio efectivo con precio base, precio final, moneda, lista y regla ganadora;
- constraints MariaDB y validaciones de dominio para impedir referencias cross-tenant incoherentes;
- permisos server-side `customers.*` y `pricing.*`, CSRF y auditoría de mutaciones administrativas;
- UI administrativa responsive para clientes, categorías, listas y precios;
- regresiones unitarias, HTTP/MariaDB y Playwright del flujo comercial crítico.

## Invariantes del slice

- Cliente y Usuario son conceptos distintos;
- ningún identificador recibido desde UI puede ampliar tenant ni permisos;
- una categoría comercial asignada a un cliente debe pertenecer al mismo tenant;
- una lista, variante y precio deben pertenecer al mismo tenant;
- la resolución de precio produce exactamente un precio efectivo y nunca apila descuentos silenciosamente;
- reglas fuera de vigencia o de otra lista/tenant no participan en la resolución;
- importes monetarios se persisten como enteros en unidades menores;
- mutaciones administrativas relevantes quedan auditadas;
- pedido, checkout, pago, reserva/consumo de inventario e intercompany permanecen fuera de este slice;
- producción nunca se migra automáticamente desde CI, deploy, smoke ni observador.

## Archivos principales

- `src/Domain/Commerce/`
- `src/Application/Commerce/PricingService.php`
- `src/Http/Controller/CustomerController.php`
- `src/Http/Controller/PricingController.php`
- `migrations/Version20260923101500.php`
- `frontend/admin/CommerceManagement.tsx`
- `tests/php/Domain/Commerce/CommercialDomainTest.php`
- `tests/php/Http/CommerceControllerTest.php`
- `tests/e2e/slice5-commerce.spec.mjs`
- `public/build/admin.js`
- `config/version.php`

## Validación requerida

- CI, SonarQube y CodeRabbit terminales sobre el SHA final exacto, sin gates fallidos ni findings válidos pendientes;
- PR fuera de draft únicamente después de fijar V 0.1.24 y obtener evidencia exact-head;
- squash merge serial y validación exact-main;
- tag anotado + GitHub Release `v0.1.24` si la integración produce un nuevo deploy identificable;
- observación post-merge separada de la validación productiva;
- cualquier migración o reconciliación productiva requiere autorización humana explícita.

## Estado inmediato

- **V 0.1.24:** candidato serial de #179/#180; requiere revalidación exact-head tras este snapshot.
- **V 0.1.23:** integrada y validada en código; producción permanece `NO_OBSERVADO`.
- **Producción:** no se declara `VALIDATED_IN_PRODUCTION` y no se autoriza migración productiva desde este PR.
- La planificación posterior vive exclusivamente en el Roadmap canónico #1.

## Referencias

- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap canónico: Issue #1

> **Regla de estado:** código integrado, deploy observado, esquema reconciliado y producción validada son evidencias distintas. Una migración productiva exige autorización explícita.
