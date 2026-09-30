# Recovery production rollout de Condor

Este runbook implementa el primer rollout por consumidor del contrato Recovery de
ControlBot #182. Su regla central es **fail-closed**: una copia local, un checksum o
un test sintético nunca equivalen a Recovery HEALTHY.

## Frontera de autoridad

Condor reutiliza `scripts/backup-database.sh` mediante `ops/factory/adapter.py
backup` y reutiliza `scripts/verify-backup-restore.sh` para el restore drill.
Condor no añade un driver de object storage, no copia credenciales al repositorio
y no amplía el execution plane.

FactoryRunner conserva la frontera externa:

- object storage usa `provider=object_storage` y `role=primary_offsite`;
- el alias de conexión tiene forma opaca `controlbot:connection/...`;
- `execute=false` permanece en el descriptor declarativo;
- el resultado válido devuelve el mismo `object_ref` y checksum, más
  `immutable_version_ref` y `evidence_ref`;
- Google Drive, cuando exista conexión válida, es únicamente **cold-copy** con
  `provider=google_drive` y `role=cold_copy`. Nunca sustituye primary offsite.

No se admiten tokens, passwords, DSN, URLs firmadas, service-account JSON ni
payloads de proveedor en argumentos, descriptores, recibos o reportes.

## Estado del workflow

`.github/workflows/recovery-production.yml` es un preflight manual y
deliberadamente se bloquea antes de crear el backup mientras el execution plane
live no esté provisionado. La variable `CONDOR_RECOVERY_LIVE_READY=true` por sí
sola **no autoriza ni ejecuta** el rollout: el runtime FactoryRunner live debe
estar disponible en el mismo entorno confiable que ejecuta el runbook.

No se suben dumps de producción a GitHub Actions artifacts y no se fabrican
recibos para atravesar este gate.

## Ejecución gobernada en un entorno confiable

El rollout completo debe ocurrir en una sesión continua con acceso autorizado a
la base fuente, al target descartable y al execution plane FactoryRunner.

### 1. Preflight

```bash
python3 scripts/recovery-production.py preflight \
  --offsite-connection-ref 'controlbot:connection/condor-primary-offsite'
```

Puede añadirse `--cold-copy-connection-ref` solo si existe conexión Google Drive
válida. El preflight valida aliases antes de tocar la base de producción.

### 2. Backup real + descriptor

Con `DATABASE_URL` disponible únicamente en el entorno autorizado:

```bash
python3 scripts/recovery-production.py prepare \
  --offsite-connection-ref 'controlbot:connection/condor-primary-offsite' \
  --output "$RUNNER_TEMP/condor-recovery-manifest.json"
```

`prepare` delega el backup a `ops/factory/adapter.py backup`, calcula SHA-256 y
genera el descriptor canónico para `recovery.object-storage.upload`. El manifest
contiene una ruta local temporal del backup; no debe publicarse ni conservarse
como artifact.

### 3. Primary offsite mediante FactoryRunner

Entregar **solo** `primary_request` del manifest al runtime FactoryRunner
provisionado. El driver obtiene credenciales desde su propia conexión opaca, no
desde la orden.

Guardar la respuesta sanitizada como archivo local, por ejemplo
`$RUNNER_TEMP/primary-receipt.json`. Debe contener exactamente:

```json
{
  "capability": "recovery.object-storage.upload",
  "operation": "upload",
  "descriptor_id": "<sha256-canónico>",
  "object_ref": "<ref-opaca>",
  "checksum_sha256": "<sha256-backup>",
  "immutable_version_ref": "<ref-opaca>",
  "evidence_ref": "<ref-opaca>"
}
```

Un mismatch de checksum, objeto, descriptor o ausencia de versión inmutable
falla cerrado.

Si se configuró Google Drive, ejecutar después su request como cold-copy y
guardar el recibo sanitizado. Su fallo no convierte Drive en primary offsite.

### 4. Restore drill descartable + RPO/RTO

Provisionar `CONDOR_DISPOSABLE_RESTORE_DATABASE_URL` apuntando exclusivamente a
una DB cuyo nombre cumpla las guardas de `verify-backup-restore.sh`. Los
centinelas se suministran por entorno. La URL fuente y la URL descartable nunca
pueden ser iguales.

```bash
python3 scripts/recovery-production.py finalize \
  --manifest "$RUNNER_TEMP/condor-recovery-manifest.json" \
  --primary-receipt "$RUNNER_TEMP/primary-receipt.json" \
  --output "$RUNNER_TEMP/condor-recovery-report.json"
```

`finalize` recalcula el checksum local, valida el recibo primary, ejecuta el
restore exclusivamente contra la DB descartable y mide:

- **RPO observado:** edad del backup al finalizar el drill;
- **RTO observado:** duración del restore verificado;
- timestamp UTC y freshness.

El reporte final contiene únicamente checksum, refs/evidence sanitizadas,
freshness y métricas. No contiene DSN, aliases de conexión, emails, provider
payloads ni secretos. `stale`, checksum mismatch, restore fallido, ausencia de
offsite o ausencia de inmutabilidad nunca se presentan como `HEALTHY`.

## Límites de seguridad

Este slice no ejecuta SQL destructivo sobre producción, no cambia DNS, no hace
cutover, no despliega producto, no crea un scheduler paralelo y no rota
credenciales. El único comportamiento destructivo permitido sigue siendo el ya
encapsulado por `verify-backup-restore.sh`, después de que ese script confirme
que el nombre de la base objetivo es descartable.
