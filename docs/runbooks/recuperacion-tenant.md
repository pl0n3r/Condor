# Runbook: recuperación operativa de un tenant

Alcance: degradación de plan/capacidades o de entrega de correo/outbox de un tenant.

1. Separar causa: código, deploy, esquema, configuración, caché, estado persistente.
2. Leer `operational-snapshot` del tenant (solo propietario) y `scripts/post-deploy.sh` según `AGENTES.md` §9.
3. Si `job_errors` o `diagnostics` figuran `no_observado`, no existe fuente por tenant: usar los diagnósticos globales de plataforma sin cruzar datos entre tenants.
4. Restaurar solo desde un backup verificado; la restauración real exige autorización explícita.

## RPO / RTO

- RPO: **decisión pendiente del dueño** (no se asume ningún valor).
- RTO: **decisión pendiente del dueño** (no se asume ningún valor).
