#!/usr/bin/env bash
# Laboratorio local de recuperación Condor: up | status | down | destroy | drill
# Secretos y evidencias viven fuera de Git en ~/.condor-recovery-local (modo 700).
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
export CONDOR_RECOVERY_HOME="${CONDOR_RECOVERY_HOME:-$HOME/.condor-recovery-local}"
export CONDOR_RECOVERY_SECRETS_DIR="$CONDOR_RECOVERY_HOME/secrets"
export CONDOR_RECOVERY_PORT="${CONDOR_RECOVERY_PORT:-3317}"
compose() { docker compose -f "$here/compose.yaml" "$@"; }

ensure_secrets() {
  umask 077
  mkdir -p "$CONDOR_RECOVERY_SECRETS_DIR"
  chmod 700 "$CONDOR_RECOVERY_HOME" "$CONDOR_RECOVERY_SECRETS_DIR"
  for name in root_password app_password encryption_passphrase; do
    f="$CONDOR_RECOVERY_SECRETS_DIR/$name"
    [[ -s "$f" ]] || openssl rand -hex 32 > "$f"
    chmod 600 "$f"
  done
  # El contenedor lee los secretos como usuario mysql: copia legible solo para ese montaje.
  chmod 644 "$CONDOR_RECOVERY_SECRETS_DIR/root_password" "$CONDOR_RECOVERY_SECRETS_DIR/app_password"
}

case "${1:-}" in
  up)
    command -v docker >/dev/null || { echo "lab.sh: falta docker" >&2; exit 1; }
    ensure_secrets
    compose up -d --wait
    compose ps --format '{{.Name}} {{.Status}} {{.Ports}}'
    ;;
  status) compose ps --format '{{.Name}} {{.Status}} {{.Ports}}' ;;
  down) compose down ;;
  destroy)
    # Solo elimina el proyecto condor-recovery-lab y su volumen; nada más.
    compose down -v --remove-orphans
    ;;
  drill) shift; exec "$here/drill.sh" "$@" ;;
  *) echo "Uso: lab.sh up|status|down|destroy|drill [--repeat N]" >&2; exit 2 ;;
esac
