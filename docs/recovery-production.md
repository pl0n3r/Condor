# Recovery production rollout

Workflow manual: `Recovery production drill`. No agenda backups ni despliega producto.

Flujo: backup real de `DATABASE_URL` → cifrado cliente AES-256-CBC/PBKDF2 → checksum ciphertext → FactoryRunner `23df010…` upload/verify/materialize S3-compatible → descifrado → `verify-backup-restore.sh` contra una **DB descartable** → RPO/RTO observados.

## Precondiciones live
- `RECOVERY_PRIMARY_CONNECTION_REF=controlbot:connection/recovery-primary`.
- `RECOVERY_PROVIDER_PRIVACY_REF=controlbot:privacy/...`: referencia a revisión/inventario del proveedor. Sin ella el run falla cerrado.
- secrets S3-compatible, `DATABASE_URL`, `RECOVERY_DISPOSABLE_DATABASE_URL` y `CONDOR_RECOVERY_ENCRYPTION_PASSPHRASE`.
- la DB descartable debe usar un nombre aceptado por `verify-backup-restore.sh`; los centinelas llegan por variables no secretas.

No se imprime ningún secreto, backup ni URL firmada. El provider recibe solo ciphertext. Google Drive no participa como primary offsite y permanece cold-copy opcional fuera de este slice.

`VERIFIED_WITHIN_TARGETS` significa evidencia completa y targets cumplidos; no equivale a HEALTHY global ni a producción validada. Cualquier precondición/evidencia faltante termina el run sin fabricar estado.
