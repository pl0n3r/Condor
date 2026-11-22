#!/usr/bin/env bash
# Backup productivo por SSH. DATABASE_URL permanece exclusivamente en Hostinger.
# Credenciales: secrets CONDOR_RECOVERY_SSH_* + vars.CONDOR_RECOVERY_SSH_ROOT,
# o configuración manual local ~/.condor-recovery-local/ssh/{target,id}.
set -euo pipefail
umask 077
: "${BACKUP_DIR:?remote-backup: falta BACKUP_DIR}"
cfg="${CONDOR_RECOVERY_HOME:-$HOME/.condor-recovery-local}/ssh"
key_temp=""
remote_dir=""
cleanup() {
  if [[ -n "$remote_dir" ]]; then
    ssh "${ssh_opts[@]}" "$user@$host" "cd '$remote_root' && rm -rf -- '$remote_dir'" >/dev/null 2>&1 || true
  fi
  [[ -z "$key_temp" ]] || rm -f -- "$key_temp"
}
trap cleanup EXIT

# Si se recibe cualquier secreto por GitHub, se exige la configuración completa;
# no se combina parcialmente con credenciales locales.
if [[ -n "${CONDOR_RECOVERY_SSH_HOST:-}${CONDOR_RECOVERY_SSH_PORT:-}${CONDOR_RECOVERY_SSH_USER:-}${CONDOR_RECOVERY_SSH_KEY:-}" ]]; then
  : "${CONDOR_RECOVERY_SSH_HOST:?remote-backup: host SSH ausente}"
  : "${CONDOR_RECOVERY_SSH_PORT:?remote-backup: puerto SSH ausente}"
  : "${CONDOR_RECOVERY_SSH_USER:?remote-backup: usuario SSH ausente}"
  : "${CONDOR_RECOVERY_SSH_KEY:?remote-backup: clave SSH ausente}"
  : "${CONDOR_RECOVERY_SSH_ROOT:?remote-backup: raíz remota ausente}"
  host="$CONDOR_RECOVERY_SSH_HOST"
  port="$CONDOR_RECOVERY_SSH_PORT"
  user="$CONDOR_RECOVERY_SSH_USER"
  remote_root="$CONDOR_RECOVERY_SSH_ROOT"
  [[ "$host" =~ ^[A-Za-z0-9.-]+$ && "$port" =~ ^[0-9]+$ && "$user" =~ ^[A-Za-z0-9_-]+$ && "$remote_root" =~ ^[A-Za-z0-9._/-]+$ ]] \
    || { echo "remote-backup: target inválido" >&2; exit 1; }
  [[ "$remote_root" != *..* ]] || { echo "remote-backup: target inválido" >&2; exit 1; }
  mkdir -p "$BACKUP_DIR"
  key_temp="$(mktemp "$BACKUP_DIR/.condor-recovery-ssh-key.XXXXXX")"
  printf '%s\n' "$CONDOR_RECOVERY_SSH_KEY" > "$key_temp"
  unset CONDOR_RECOVERY_SSH_KEY
  key_file="$key_temp"
else
  [[ -s "$cfg/target" && -s "$cfg/id" ]] || { echo "remote-backup: falta $cfg/target o $cfg/id" >&2; exit 1; }
  read -r host port user remote_root < "$cfg/target"
  key_file="$cfg/id"
fi
[[ "$host" =~ ^[A-Za-z0-9.-]+$ && "$port" =~ ^[0-9]+$ && "$user" =~ ^[A-Za-z0-9_-]+$ && "$remote_root" =~ ^[A-Za-z0-9._/-]+$ ]] \
  || { echo "remote-backup: target inválido" >&2; exit 1; }
[[ "$remote_root" != *..* ]] || { echo "remote-backup: target inválido" >&2; exit 1; }
ssh_opts=(-o BatchMode=yes -o StrictHostKeyChecking=yes -o IdentitiesOnly=yes -i "$key_file" -p "$port")
token="$(openssl rand -hex 6)"
remote_dir="private/recovery-drill-${GITHUB_RUN_ID:-manual}-$token"
remote_file="$(ssh "${ssh_opts[@]}" "$user@$host" "set -eu; umask 077; cd '$remote_root'; mkdir -m 700 '$remote_dir'; cd public_html; \
  DATABASE_URL=\"\$(sed -n 's/^DATABASE_URL=//p' .env | head -1 | sed 's/^\"//; s/\"\$//')\"; export DATABASE_URL; \
  BACKUP_DIR='../$remote_dir' sh scripts/backup-database.sh >&2; ls ../'$remote_dir'/condor-*.sql.gz | head -1")"
[[ "$remote_file" =~ ^\.\./private/recovery-drill-[A-Za-z0-9_-]+/condor-[A-Za-z0-9_.-]+\.sql\.gz$ ]] \
  || { echo "remote-backup: el servidor no devolvió un backup válido" >&2; exit 1; }
mkdir -p "$BACKUP_DIR"
name="$(basename "$remote_file")"
scp -q -o BatchMode=yes -o StrictHostKeyChecking=yes -o IdentitiesOnly=yes -i "$key_file" -P "$port" \
  "$user@$host:$remote_root/${remote_file#../}" "$BACKUP_DIR/$name"
gzip -t "$BACKUP_DIR/$name"
echo "Backup remoto traído: $BACKUP_DIR/$name"
