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
