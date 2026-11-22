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

## Integración con GitHub Actions

Un runner alojado por GitHub **no** puede llegar a `127.0.0.1` de este Mac. Para que
`RECOVERY_DISPOSABLE_DATABASE_URL` apunte a este laboratorio haría falta un runner autoalojado
efímero, restringido al workflow `Recovery production drill`, a `main` y al propietario.
No se registra ningún runner sin autorización expresa del dueño.

## Eliminación segura

```bash
ops/recovery-local/lab.sh destroy
rm -rf ~/.condor-recovery-local     # secretos y evidencias locales
```
