# Condor App — Snapshot operativo · candidato V 0.1.25

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** entregar el primer canal e-commerce reusable de Condor con catálogo público SSR, precio efectivo y disponibilidad derivados de la configuración comercial existente, sin introducir checkout, pedidos ni mutaciones de inventario.

<p align="center">
  <strong>Base integrada:</strong> V 0.1.24 · main `f22e9d15` ·
  <strong>Candidato:</strong> V 0.1.25 ·
  <strong>Rama:</strong> `trabajo/issue-181`
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.24 / MAIN ACTUAL** | SHA `f22e9d15269adebaee5155450f100c8b87a0ef7f` · clientes, categorías comerciales y precio efectivo integrados |
| Exact-main V 0.1.24 | ✅ **VALIDADO EN CÓDIGO** | `Validar`, Backend PHP/MariaDB, contrato/integración, Playwright, Frontend, backup/restauración, auditorías, gobierno, coordinación y SonarQube en success |
| Producción V 0.1.24 | ⛔ **NO OBSERVADA / ESTADO SEPARADO** | observador post-push terminó `NO_OBSERVADO` por HTTP 500; no se declara `VALIDATED_IN_PRODUCTION` |
| Candidato actual | 🚧 **V 0.1.25** | Issue #181 / PR #182 |
| CI/Sonar del HEAD final | ⏳ **EXACT-HEAD OBLIGATORIO** | debe revalidarse después de este bump y snapshot |
| CodeRabbit | ⏳ **EXACT-HEAD OBLIGATORIO** | revisión terminal sobre el mismo SHA final antes de ready/merge |

## Qué incorpora V 0.1.25

- entidad `SalesChannel` tenant-owned, reusable y con tipo operativo inicial `ecommerce`;
- una fuente de inventario efectiva y una lista de precios explícita por canal, sin fallback silencioso;
- titularidad jurídica derivada de la fuente y protegida por constraints MariaDB compuestos tenant/entidad/fuente;
- lista de precios acotada por tenant mediante constraint compuesto;
- administración del canal integrada al storefront existente: edición exclusiva del propietario del tenant, consulta con `site.view` / `site.update`, CSRF y auditoría;
- catálogo público SSR/Twig sobre Producto/Variante existentes, sin duplicar catálogo;
- precio público delegado a `PricingService` V 0.1.24, con una única salida efectiva determinista;
- disponibilidad calculada exclusivamente desde la fuente configurada; otra sede/fuente nunca actúa como fallback;
- backorder respetado desde la política del producto sin reservar, consumir ni mutar inventario durante la lectura;
- canal, fuente o lista inactivos fallan cerrado;
- ruta Condor por slug y dominio personalizado reutilizan el mismo contrato público y aislamiento de tenant;
- regresiones de dominio, HTTP/MariaDB y Playwright para configuración + storefront público.

## Invariantes del slice

- el servidor es la autoridad final de tenant, permisos, fuente y lista;
- un canal referencia exactamente una fuente efectiva y una lista predeterminada;
- canal y fuente pertenecen al mismo tenant y entidad legal efectiva;
- producto o variante inactivos no se publican;
- una variante sin precio resoluble en la lista configurada no se publica como comprable;
- saldo inexistente equivale a cero y nunca activa búsqueda en otra fuente;
- disponibilidad pública es read-only;
- no se aceptan IDs públicos de fuente/lista para ampliar alcance;
- carrito, checkout, pedido, pago, reserva/consumo de stock y autenticación de clientes permanecen fuera de V 0.1.25;
- producción nunca se migra automáticamente desde CI, deploy, smoke ni observador.

## Archivos principales

- `src/Domain/Commerce/Entity/SalesChannel.php`
- `src/Application/Storefront/PublicCatalogPresentation.php`
- `src/Http/Controller/StorefrontAdminController.php`
- `src/Http/Controller/TenantPublicController.php`
- `src/Http/Controller/HomeController.php`
- `migrations/Version20260923153500.php`
- `templates/tenant/index.html.twig`
- `templates/tenant/manage.html.twig`
- `tests/php/Domain/Commerce/SalesChannelTest.php`
- `tests/php/Application/Storefront/PublicCatalogPresentationTest.php`
- `tests/php/Http/StorefrontAdminControllerTest.php`
- `tests/e2e/slice6-ecommerce.spec.mjs`

## Validación requerida

- CI, SonarQube y CodeRabbit terminales sobre el SHA final exacto, sin gates fallidos ni findings válidos pendientes;
- PR fuera de draft únicamente después de evidencia exact-head terminal;
- squash merge serial y validación exact-main;
- tag anotado + GitHub Release `v0.1.25` si la integración produce un nuevo deploy identificable;
- observación post-merge separada de la validación productiva;
- cualquier migración o reconciliación productiva requiere autorización humana explícita.

## Estado inmediato

- **V 0.1.25:** candidato serial de #181/#182; implementación funcional completa y pendiente de revalidación exact-head posterior a este snapshot.
- **V 0.1.24:** integrada y validada en código; producción permanece `NO_OBSERVADO`.
- **Producción:** no se declara `VALIDATED_IN_PRODUCTION` y no se autoriza migración productiva desde este PR.
- La planificación posterior vive exclusivamente en el Roadmap canónico #1.

## Referencias

- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap canónico: Issue #1

> **Regla de estado:** código integrado, deploy observado, esquema reconciliado y producción validada son evidencias distintas. Una migración productiva exige autorización explícita.
