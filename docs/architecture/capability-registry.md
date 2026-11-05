# Registro de capacidades GAP-P0

Fuente de la puerta de release (`CapabilityReleaseGate`). Cada fila `GAP-P0-*`
debe enlazar un Issue (`#N`) y un runbook existente en `docs/runbooks/`; si falta
uno de los dos, el test de release falla y bloquea el release.

| ID | Capacidad | Issue | Runbook |
| --- | --- | --- | --- |
| GAP-P0-01 | Outbox DB + worker bajo concurrencia real | #1483 | docs/runbooks/conciliacion-ledger.md |
| GAP-P0-02 | Correo transaccional mediante outbox durable | #1484 | docs/runbooks/recuperacion-tenant.md |
| GAP-P0-03 | Reserva de uso atómica al crear órdenes | #1486 | docs/runbooks/conciliacion-ledger.md |
| GAP-P0-04 | Métricas facturables de usuarios y sedes | #1487 | docs/runbooks/conciliacion-ledger.md |
| GAP-P0-05 | Controles comerciales migrados a capacidades | #1488 | docs/runbooks/recuperacion-tenant.md |

## GAP-P0-04 — Contrato de métricas facturables (#1487)

Decisión auditable de qué consume capacidad del plan. Implementación:
`App\Application\Commercial\BillableUsage`; los límites salen del
`EntitlementSnapshot` (`users`, `locations`) y no se inventan valores.

| Métrica | Clave de límite | Se factura (cuenta) | No se factura |
| --- | --- | --- | --- |
| Asiento | `users` | Membresía **activa** del tenant con rol distinto de `OWNER` (incluye invitaciones pendientes: el asiento se reserva al invitar). | El owner y el staff de plataforma. |
| Sede | `locations` | Sede del tenant con `is_default = false`. | La sede inicial (default) creada por el onboarding. |

Reglas para quien reserve uso (p. ej. #1486 sobre órdenes):

- Todo alta posterior al bootstrap pasa por `AddTenantUser` / `AddTenantBranch`
  con un `ExecutionContext` (tenant + `EntitlementSnapshot` + actor); el
  snapshot debe ser del mismo tenant.
- Reserva atómica: `TenantUsageReservation` abre transacción y toma
  `PESSIMISTIC_WRITE` sobre la fila del tenant; cuenta, compara y escribe bajo
  ese lock, así que dos altas concurrentes se serializan.
- Idempotencia por clave natural: correo (usuario) y slug (sede). Repetir
  devuelve lo existente y no consume capacidad.
- Límite `null` = «sin límite definido» (no se bloquea ni se inventa); límite
  desconocido o inválido falla cerrado; al alcanzarlo se lanza
  `UsageLimitExceeded` (métrica y límite) y no se persiste nada.
- No hay contador persistido: el uso se deriva de las tablas de negocio, por
  lo que no puede divergir de la realidad.

Evidencia (GAP-P0-04): `tests/php/Application/Commercial/BillableUsageTest.php`
(owner/sede inicial excluidos, altas consumen, límites rechazan sin persistir,
reintentos idempotentes, aislamiento por tenant, `null` sin límite).

## GAP-P0-05 — Primera ola de enforcement comercial (#1488)

**Estado PARCIAL: el GAP global permanece abierto.** El contrato permite
un write boundary a la vez. No habilita despliegue, usuarios reales ni go-live.
La capacidad se evalúa del lado servidor; no filtra la UI, GET ni el tenant.

| Superficie | Estado y frontera de escritura | Evidencia o GAP pendiente |
| --- | --- | --- |
| catálogo CSV | ENFORCEMENT PARCIAL: ProductCsvImporter::commit invoca CatalogCapabilityGuard::assertCanWrite dentro de su transacción, antes de preview/persist/flush; usa tenant y actor autorizados, SubscriptionEntitlementContextFactory y EntitlementResolver del servidor | tests/test_capability_gap_p0_wave1.py (tenant cruzado, capability denegada/desconocida, posición previa a escrituras); prueba HTTP sintética existente tests/test_catalog_csv_import_api.py cubre CSRF, idempotencia, tenant |
| catálogo individual | GAP: CatalogController::createProduct es otra frontera sin snapshot | Fuera de estos claims; no invadir la reserva activa #1485 |
| comercio | GAP para esta ola | OrderCreationCoordinator (#1486) ya maneja orders; otras escrituras requieren frontera y pruebas propias |
| CMS | GAP: controladores CMS no incorporan snapshot comercial en un write boundary reclamado | Requiere claim específico y suite HTTP |
| producción | GAP: falta write boundary con contexto comercial comprobado | No usar guard para filtrar UI ni tenant |
| compras | GAP: no hay write boundary reclamado bajo este contrato | Preservar los claims del frente #1534 |
| workflows | GAP: el permiso de rama no es capability comercial | Necesita frontera y pruebas de tenant |

**Compatibilidad explícita:** sin suscripción comercial para un tenant heredado,
ProductCsvImporter::commit preserva su comportamiento anterior: esa vía permanece
GAP, nunca se declara enforcement total. Con suscripción existente, un snapshot
incompatible, una capability denegada, ausente o de otro tenant falla antes de
persistir. Nunca se aceptan capacidades enviadas por cliente ni se inventan
límites, precios o unidades de uso.

**Reversión:** revertir el squash restaura el importador original. Sin migración,
contador, datos reales, PII, publicación ni operación destructiva.
