# Recovery production rollout

Workflow manual: `Recovery production drill`. No agenda backups ni despliega producto.

Flujo: backup real de `DATABASE_URL` → cifrado cliente AES-256-CBC/PBKDF2 → checksum ciphertext → FactoryRunner `45f5571…` upload/verify/materialize S3-compatible con Object Lock → restore sobre **DB descartable** → integrity + app local `/health` + smoke `/` → evaluación canónica Factory #330.

## Precondiciones live
- `RECOVERY_PRIMARY_CONNECTION_REF=controlbot:connection/recovery-primary`.
- `RECOVERY_PROVIDER_PRIVACY_REF=controlbot:privacy/...`: referencia a revisión/inventario del proveedor. Sin ella el run falla cerrado.
- secrets S3-compatible, `RECOVERY_S3_OBJECT_LOCK_DAYS` (1–3650 días), `DATABASE_URL`, `RECOVERY_DISPOSABLE_DATABASE_URL` y `CONDOR_RECOVERY_ENCRYPTION_PASSPHRASE`.
- la DB descartable debe usar un nombre aceptado por `verify-backup-restore.sh`; los centinelas llegan por variables no secretas.

El primary offsite debe demostrar Object Lock `COMPLIANCE` con retención futura; VersionId sin lock no es evidencia suficiente. No se imprime ningún secreto, backup ni URL firmada. El provider recibe solo ciphertext. Google Drive no participa como primary offsite y permanece cold-copy opcional fuera de este slice.

## Métricas y autoridad del drill

Factory `bb732b0feb2b5f45b9d9f96efe257c289e76bbc9` / #330 es la autoridad del resultado. Condor construye únicamente sus inputs y llama `build_restore_drill_plan()` + `evaluate_restore_drill()`.

- `recovery_point_at`: timestamp de creación del backup.
- `incident_at`: inicio observado del drill, cuando se simula la necesidad de recuperación.
- RPO observado: `incident_at - recovery_point_at`.
- RTO observado: `completed_at - started_at`, incluyendo decrypt, restore, integrity, health y smoke.
- `offsite_verified_at - backup_created_at` se muestra únicamente como **latencia de protección**; no se denomina RPO.
- Resultado canónico: `PASSED|BREACHED`; razones `RPO_EXCEEDED|RTO_EXCEEDED`.
- Un drill no puede completar sin `health_ok=true`, `smoke_ok=true` e `integrity_ok=true`. Health y smoke se ejecutan contra la aplicación local conectada a la DB descartable, nunca contra producción.

El resultado conserva `authority=unchanged` y `execute=false`; no equivale por sí solo a HEALTHY global. Cualquier precondición/evidencia faltante termina el run sin fabricar estado.
