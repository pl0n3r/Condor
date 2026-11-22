# Laboratorio local de recuperación de Condor

Ensayo **reproducible y local** de backup → cifrado → SHA-256 → restauración → verificación,
con **datos sintéticos**, sobre MariaDB **11.8.9** (la serie de producción), para Condor #1929 y #1590.

No sustituye al workflow `Recovery production drill` (`.github/workflows/recovery-production.yml`):
reutiliza exactamente sus piezas (`scripts/backup-database.sh`, `scripts/recovery-production.py`,
`scripts/verify-backup-restore.sh`) para probarlas de punta a punta sin producción ni offsite.

## Qué demuestra y qué no

| Demuestra (local, sintético) | No demuestra |
|---|---|
| Backup PDO consistente del esquema real (todas las migraciones Doctrine) | Backup de la base productiva |
| Cifrado AES-256-CBC/PBKDF2 200k y SHA-256 del ciphertext | Copia offsite con Object Lock `COMPLIANCE` |
| Restauración solo en `condor_restore_test` + `CHECKSUM TABLE` idéntico por tabla | RPO/RTO de producción (Factory #330) |
| App Condor arranca contra la base restaurada: `/health` `schema_up_to_date=true`, `/` 200 | Continuidad S07 en entorno autorizado |
| 8 fallos controlados rechazados y la base restaurada intacta | |

## Requisitos

- Docker (probado con Colima + Docker CLI + Compose en macOS arm64).
- PHP 8.5, Composer (`composer install` en la raíz), cliente `mariadb`, `openssl`, `python3`, `shasum`, `lsof`.

## Uso

```bash
ops/recovery-local/lab.sh up        # crea secretos fuera de Git y arranca MariaDB en 127.0.0.1:3317
ops/recovery-local/lab.sh drill     # un ensayo completo; --repeat N para repetibilidad
ops/recovery-local/lab.sh status
ops/recovery-local/lab.sh down      # para el contenedor, conserva el volumen
ops/recovery-local/lab.sh destroy   # borra SOLO el proyecto condor-recovery-lab y su volumen
```

Puerto alternativo: `CONDOR_RECOVERY_PORT=3327 ops/recovery-local/lab.sh up`.

## Seguridad

- **Red:** el puerto se publica solo en `127.0.0.1`; no es accesible desde la LAN.
- **Secretos:** `~/.condor-recovery-local/secrets/` (modo 700), generados con `openssl rand`.
  Nunca se versionan ni se imprimen. No reutilizar credenciales de producción.
- **Mínimo privilegio:** el usuario `condor_restore` solo tiene permisos sobre bases `condor_restore_%`.
- **Guarda anti-producción:** `drill.sh` rechaza antes de conectar cualquier URL que no sea
  `127.0.0.1|localhost`, el puerto del laboratorio, el usuario `condor_restore` y una base `condor_restore_*`.
  Además `verify-backup-restore.sh` exige nombre descartable y `CONDOR_ALLOW_DESTRUCTIVE_RESTORE=1`.
- **Contenedor:** imagen fijada por digest, `no-new-privileges`, `cap_drop: ALL` (+ mínimas), 1 GB, 1 CPU, 256 pids.

## Evidencias

Cada ejecución deja en `~/.condor-recovery-local/runs/<run_id>/`:
`evidence.json` (sanitizado: run_id, SHA del código, imagen, versión del servidor, tiempos, SHA-256 del
ciphertext, resultado por check), `checks.tsv`, `drill.log`, `backup.enc.sha256` y el `backup.enc` cifrado.
Los artefactos en claro se borran al terminar.

## Drill productivo en runner efímero del Mac (#1952)

`Recovery production drill` corre en un runner autoalojado **efímero** de este Mac
(`runs-on: [self-hosted, macOS, ARM64, condor-recovery]`), solo con `workflow_dispatch`
desde `main` por el propietario. Condor es público, así que hay tres barreras: el `if` del
workflow, la etiqueta exclusiva y `runner-job-guard.sh` (hook `ACTIONS_RUNNER_HOOK_JOB_STARTED`)
que aborta cualquier otro trabajo antes del primer paso. El runner solo existe mientras dura
un drill: `runner.sh` lo registra con `--ephemeral`, atiende un trabajo y se borra.

- **Backup:** `remote-backup.sh` ejecuta `scripts/backup-database.sh` en Hostinger por SSH
  (la `DATABASE_URL` no sale del servidor), trae el `.sql.gz`, lo borra del servidor y el
  runner lo cifra antes del offsite. Configuración local (modo 600, fuera de Git):
  `~/.condor-recovery-local/ssh/target` = `HOST PUERTO USUARIO RAIZ_REMOTA` y
  `~/.condor-recovery-local/ssh/id` = clave SSH dedicada; el host debe estar en `~/.ssh/known_hosts`.
  Alternativamente, el dueño puede provisionar `CONDOR_RECOVERY_SSH_HOST`,
  `CONDOR_RECOVERY_SSH_PORT`, `CONDOR_RECOVERY_SSH_USER` y `CONDOR_RECOVERY_SSH_KEY`
  como GitHub Secrets, más `CONDOR_RECOVERY_SSH_ROOT` como variable del repositorio.
  Si falta parte de un conjunto de secretos, se aborta; la clave se escribe solo en
  un archivo temporal modo 600 y se borra antes de salir.
- **Base desechable:** `disposable-url.sh` deriva la URL del laboratorio (`127.0.0.1:3317/condor_restore_test`)
  y rechaza cualquier destino que no sea loopback/puerto/usuario/base del laboratorio.
- **Offsite:** S3 compatible con Object Lock `COMPLIANCE` y versionado (Backblaze B2 funciona con
  el driver actual de FactoryRunner; Cloudflare R2 requiere soporte nuevo en FactoryRunner).

Lanzar un drill (el Mac debe estar encendido):

```bash
ops/recovery-local/runner.sh drill
```

## Eliminación segura

```bash
ops/recovery-local/lab.sh destroy
rm -rf ~/.condor-recovery-local     # secretos y evidencias locales
```
