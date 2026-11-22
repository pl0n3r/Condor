#!/usr/bin/env bash
# Backup productivo por SSH: ejecuta scripts/backup-database.sh EN el servidor
# (con su propia configuración; DATABASE_URL nunca sale del servidor), trae el
# .sql.gz por SSH a $BACKUP_DIR y borra la copia remota. Solo lectura de la BD.
#
# Configuración local del runner (fuera de Git, modo 600):
#   ~/.condor-recovery-local/ssh/target   -> "HOST PORT USER REMOTE_ROOT"
#   ~/.condor-recovery-local/ssh/id       -> clave privada SSH dedicada
# La clave del host debe estar ya en ~/.ssh/known_hosts (StrictHostKeyChecking=yes).
set -euo pipefail
umask 077
: "${BACKUP_DIR:?remote-backup: falta BACKUP_DIR}"
cfg="${CONDOR_RECOVERY_HOME:-$HOME/.condor-recovery-local}/ssh"
[[ -s "$cfg/target" && -s "$cfg/id" ]] || { echo "remote-backup: falta $cfg/target o $cfg/id" >&2; exit 1; }
read -r host port user remote_root < "$cfg/target"
[[ "$host" =~ ^[A-Za-z0-9.-]+$ && "$port" =~ ^[0-9]+$ && "$user" =~ ^[A-Za-z0-9_-]+$ && "$remote_root" =~ ^[A-Za-z0-9._/-]+$ ]] \
  || { echo "remote-backup: target inválido" >&2; exit 1; }
[[ "$remote_root" != *..* ]] || { echo "remote-backup: target inválido" >&2; exit 1; }
ssh_opts=(-o BatchMode=yes -o StrictHostKeyChecking=yes -o IdentitiesOnly=yes -i "$cfg/id" -p "$port")
token="$(openssl rand -hex 6)"
remote_dir="private/recovery-drill-${GITHUB_RUN_ID:-manual}-$token"
cleanup() { ssh "${ssh_opts[@]}" "$user@$host" "cd '$remote_root' && rm -rf -- '$remote_dir'" >/dev/null 2>&1 || true; }
trap cleanup EXIT
remote_file="$(ssh "${ssh_opts[@]}" "$user@$host" "set -eu; umask 077; cd '$remote_root'; mkdir -m 700 '$remote_dir'; cd public_html; \
  DATABASE_URL=\"\$(sed -n 's/^DATABASE_URL=//p' .env | head -1 | sed 's/^\"//; s/\"\$//')\"; export DATABASE_URL; \
  BACKUP_DIR='../$remote_dir' sh scripts/backup-database.sh >&2; ls ../'$remote_dir'/condor-*.sql.gz | head -1")"
[[ "$remote_file" =~ ^\.\./private/recovery-drill-[A-Za-z0-9_-]+/condor-[A-Za-z0-9_.-]+\.sql\.gz$ ]] \
  || { echo "remote-backup: el servidor no devolvió un backup válido" >&2; exit 1; }
mkdir -p "$BACKUP_DIR"
name="$(basename "$remote_file")"
scp -q -o BatchMode=yes -o StrictHostKeyChecking=yes -o IdentitiesOnly=yes -i "$cfg/id" -P "$port" \
  "$user@$host:$remote_root/${remote_file#../}" "$BACKUP_DIR/$name"
gzip -t "$BACKUP_DIR/$name"
echo "Backup remoto traído: $BACKUP_DIR/$name"
