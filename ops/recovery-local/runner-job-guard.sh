#!/usr/bin/env bash
# Hook ACTIONS_RUNNER_HOOK_JOB_STARTED del runner efímero de recuperación.
# Condor es público: este runner SOLO ejecuta el drill lanzado a mano por el
# propietario desde main. Cualquier otro trabajo se aborta antes del primer paso.
set -euo pipefail
expected_workflow="pl0n3r/Condor/.github/workflows/recovery-production.yml@refs/heads/main"
expected_repo="pl0n3r/Condor"
expected_actor="pl0n3r"
reject() { echo "runner-job-guard: trabajo rechazado ($1)" >&2; exit 1; }
[[ "${GITHUB_REPOSITORY:-}" == "$expected_repo" ]] || reject "repositorio"
[[ "${GITHUB_WORKFLOW_REF:-}" == "$expected_workflow" ]] || reject "workflow/ref"
[[ "${GITHUB_EVENT_NAME:-}" == "workflow_dispatch" ]] || reject "evento"
[[ "${GITHUB_ACTOR:-}" == "$expected_actor" ]] || reject "actor"
[[ "${GITHUB_TRIGGERING_ACTOR:-}" == "$expected_actor" ]] || reject "actor de disparo"
[[ "${GITHUB_REF:-}" == "refs/heads/main" ]] || reject "ref"
echo "runner-job-guard: drill autorizado"
