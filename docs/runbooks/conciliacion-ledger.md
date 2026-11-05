# Runbook: conciliación del ledger de uso

Alcance: diferencias entre el uso registrado (`UsageObservation`/`UsageLedger`) y los
límites efectivos del plan de un tenant. Solo lectura hasta decidir.

1. Consultar `GET /adminpl0n3r/api/tenants/{tenantId}/operational-snapshot` (propietario).
2. Si `ledger.status` es `no_observado` o `limits_usage` queda vacío, tratar como UNKNOWN: no asumir 0 ni ilimitado.
3. Comparar `used` contra `limit` por métrica; las ventanas vigentes ambiguas se escalan, no se corrigen a mano.
4. Cualquier ajuste del ledger requiere autorización del dueño y registro en el Issue; SQL destructivo está prohibido.

## RPO / RTO

- RPO: **decisión pendiente del dueño** (no se asume ningún valor).
- RTO: **decisión pendiente del dueño** (no se asume ningún valor).
