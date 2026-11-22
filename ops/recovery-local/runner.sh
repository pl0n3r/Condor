#!/usr/bin/env bash
# Runner autoalojado EFÍMERO para «Recovery production drill» (Condor #1952).
#
#   ops/recovery-local/runner.sh drill   # levanta el lab, registra el runner, lanza el drill y espera
#   ops/recovery-local/runner.sh once    # solo registra y atiende UN trabajo (el drill se lanza aparte)
#
# Condor es público. Defensas: runner --ephemeral (un trabajo y se borra), solo
# encendido bajo demanda, etiqueta exclusiva condor-recovery, `if` del workflow
# (main + workflow_dispatch + propietario) y guardia runner-job-guard.sh que
# aborta cualquier otro trabajo antes del primer paso.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
repo_slug="pl0n3r/Condor"
lab_home="${CONDOR_RECOVERY_HOME:-$HOME/.condor-recovery-local}"
runner_version="2.338.2"
runner_sha256="25a065af409ffa317ab4d5a3b614dd15fc9c40f2172bc1b35a1b1258e919a388"
runner_tgz="actions-runner-osx-arm64-${runner_version}.tar.gz"
mode="${1:-}"
case "$mode" in drill|once) ;; *) echo "Uso: runner.sh drill|once" >&2; exit 2 ;; esac

[[ "$(uname -s)-$(uname -m)" == "Darwin-arm64" ]] || { echo "runner.sh: solo macOS arm64" >&2; exit 1; }
command -v gh >/dev/null || { echo "runner.sh: falta gh autenticado como propietario" >&2; exit 1; }
[[ "$(gh api user -q .login)" == "pl0n3r" ]] || { echo "runner.sh: gh no está autenticado como pl0n3r" >&2; exit 1; }

"$here/lab.sh" up >/dev/null
"$here/disposable-url.sh" >/dev/null

base="$lab_home/runner"
dist="$base/dist/$runner_version"
work="$base/instance-$(date -u +%Y%m%dT%H%M%SZ)-$(openssl rand -hex 3)"
umask 077
mkdir -p "$dist"
if [[ ! -x "$dist/run.sh" ]]; then
  # Sonar shell:S6506: --location must never downgrade this download to HTTP.
  curl --proto '=https' --proto-redir '=https' -fsSL -o "$dist/$runner_tgz" "https://github.com/actions/runner/releases/download/v${runner_version}/${runner_tgz}"
  echo "$runner_sha256  $dist/$runner_tgz" | shasum -a 256 -c - >/dev/null
  tar -xzf "$dist/$runner_tgz" -C "$dist"
  rm -f "$dist/$runner_tgz"
fi
mkdir -p "$work"
cp -R "$dist/." "$work/"
cleanup() { rm -rf -- "$work"; }
trap cleanup EXIT

token="$(gh api -X POST "repos/$repo_slug/actions/runners/registration-token" -q .token)"
(cd "$work" && ./config.sh --unattended --ephemeral --replace \
   --url "https://github.com/$repo_slug" --token "$token" \
   --name "condor-recovery-$(hostname -s)-$(openssl rand -hex 3)" \
   --labels condor-recovery --work _work >/dev/null)
unset token
# La guardia corre antes de cada trabajo; si falla, el trabajo no ejecuta ningún paso.
printf 'ACTIONS_RUNNER_HOOK_JOB_STARTED=%s\n' "$here/runner-job-guard.sh" >> "$work/.env"

if [[ "$mode" == "drill" ]]; then
  (cd "$work" && ./run.sh) &
  runner_pid=$!
  sleep 5
  gh workflow run recovery-production.yml -R "$repo_slug" --ref main
  wait "$runner_pid" || true
  run_id="$(gh run list -R "$repo_slug" -w "Recovery production drill" -L 1 --json databaseId -q '.[0].databaseId')"
  gh run view "$run_id" -R "$repo_slug" --json conclusion,url -q '"Drill: \(.conclusion) \(.url)"'
else
  (cd "$work" && ./run.sh)
fi
