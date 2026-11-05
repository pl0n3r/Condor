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
