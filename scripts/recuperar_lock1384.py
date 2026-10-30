"""Recuperación CAS del nonce huérfano de Condor #1384; nunca concede V3."""
from __future__ import annotations

from datetime import datetime, timezone
import json
import os
from pathlib import Path
import sys
from urllib.error import HTTPError

sys.path.insert(0, str(Path(__file__).resolve().parent))
import recuperar_lock724 as shared

Denied = shared.Denied
demand = shared.demand
REPO = "pl0n3r/Condor"
ISSUE = 1384
WORK_BRANCH = "trabajo/issue-1384"
REF = "refs/heads/coordinacion/lock-issue-1384"
NONCE = "d433356e40d2c208c23c22992046ad9f62e675c4"
PARENT = "6d6484a4118f803d5927d048c7e130d8f5ef67f6"
MESSAGE = "lock coordinacion Issue #1384 c2afa6df-6271-4498-b800-b21a4f106908"
LOCK_API = f"/repos/{REPO}/git/ref/heads/coordinacion/lock-issue-1384"
BRANCH_API = f"/repos/{REPO}/git/ref/heads/{WORK_BRANCH}"


def _must_be_missing(api, path: str) -> None:
    """Solo 404 autentica que una ref no existe."""
    try:
        api.get(path)
    except HTTPError as exc:
        demand(exc.code == 404, "ref no observable")
    else:
        raise Denied("rama de trabajo existente")


def _latest_bot_lease(api) -> dict:
    last = None
    for page in range(1, 11):
        items = api.get(f"/repos/{REPO}/issues/{ISSUE}/comments?per_page=100&page={page}")
        demand(type(items) is list, "historial V3 no verificable")
        for item in items:
            demand(type(item) is dict, "comentario ilegible")
            receipt = shared._lease_from_bot_comment(item)
            if receipt is not None:
                last = receipt
        if len(items) < 100:
            return last or {}
    raise Denied("historial V3 truncado")


def check_quiescence(api, run_id: str) -> None:
    receipt = _latest_bot_lease(api)
    demand(not receipt or (receipt.get("version") == 3 and receipt.get("active") is False),
           "reserva V3 activa o ilegible")
    _must_be_missing(api, BRANCH_API)
    pulls = api.get(f"/repos/{REPO}/pulls?state=open&per_page=100")
    demand(type(pulls) is list and len(pulls) < 100, "PRs incompletos")
    for pr in pulls:
        demand(type(pr) is dict, "PR ilegible")
        head = pr.get("head") or {}
        demand(type(head) is dict and head.get("ref") != WORK_BRANCH,
               "PR activo sobre la rama bloqueada")
    shared.no_live_coordination(api, run_id)


def check_nonce(api, now: datetime) -> None:
    lock = api.get(LOCK_API)
    demand(type(lock) is dict and (lock.get("object") or {}).get("sha") == NONCE,
           "nonce de lock no coincide")
    commit = api.get(f"/repos/{REPO}/git/commits/{NONCE}")
    parents = commit.get("parents") if type(commit) is dict else None
    demand(type(commit) is dict and commit.get("message") == MESSAGE
           and type(parents) is list and len(parents) == 1
           and parents[0].get("sha") == PARENT
           and (commit.get("author") or {}).get("name") == "github-actions[bot]",
           "nonce sin procedencia exacta del bot")
    timestamp = (commit.get("committer") or {}).get("date")
    demand(type(timestamp) is str and now.tzinfo is not None, "fecha ilegible")
    try:
        date = datetime.fromisoformat(timestamp.replace("Z", "+00:00"))
    except ValueError as exc:
        raise Denied("fecha del nonce malformada") from exc
    demand(date.tzinfo is not None and (now - date).total_seconds() >= 3600,
           "nonce demasiado nuevo o futuro")


def prepare(api, now: datetime, run_id: str) -> str:
    shared.valid_global(api)
    freeze = api.get("/repos/pl0n3r/Factory/issues/921")
    demand(type(freeze) is dict and freeze.get("state") == "closed", "freeze activo")
    issue = api.get(f"/repos/{REPO}/issues/{ISSUE}")
    demand(type(issue) is dict and issue.get("state") == "open"
           and any(type(row) is dict and row.get("name") == "estado: bloqueado"
                   for row in issue.get("labels", [])),
           "issue #1384 no está abierto/bloqueado")
    check_quiescence(api, run_id)
    check_nonce(api, now)
    identity = api.get(f"/repos/{REPO}")
    node_id = identity.get("node_id") if type(identity) is dict else None
    demand(type(identity) is dict and identity.get("name") == "Condor"
           and (identity.get("owner") or {}).get("login") == "pl0n3r"
           and type(node_id) is str and bool(node_id),
           "identidad repo ilegible")
    return node_id


def recover(api, now: datetime, run_id: str) -> None:
    repo_id = prepare(api, now, run_id)
    # Segunda lectura completa para evitar actuar sobre una reserva o rama nueva.
    demand(prepare(api, now, run_id) == repo_id, "identidad cambió")
    result = api.post("/graphql", {
        "query": "mutation($input:UpdateRefsInput!){updateRefs(input:$input){clientMutationId}}",
        "variables": {"input": {"repositoryId": repo_id, "refUpdates": [{
            "name": REF, "beforeOid": NONCE, "afterOid": "0" * 40, "force": True,
        }]}}
    })
    demand(type(result) is dict and not result.get("errors")
           and type(result.get("data")) is dict
           and type(result["data"].get("updateRefs")) is dict,
           "CAS GraphQL denegado o de estado incierto")
    try:
        api.get(LOCK_API)
    except HTTPError as exc:
        demand(exc.code == 404, "borrado del lock no acreditado")
    else:
        raise Denied("lock aún existe")
    api.post(f"/repos/{REPO}/issues/{ISSUE}/comments", {"body": (
        f"CAS autorizado: {REF}@{NONCE} eliminado, GET=404 autenticado. "
        "Se verificó ausencia de V3 activa, rama y PR; ninguna otra ref se modificó. "
        "ESTE RECIBO NO CONCEDE V3: restaurar disponibilidad legítimamente, "
        "enviar /tomar y esperar reserva nueva del bot antes de editar."
    )})


def main() -> int:
    try:
        demand(os.getenv("GITHUB_REPOSITORY") == REPO
               and os.getenv("GITHUB_ACTOR") == "pl0n3r"
               and os.getenv("GITHUB_EVENT_NAME") == "issue_comment",
               "evento o actor no autorizado")
        event = json.loads(Path(os.environ["GITHUB_EVENT_PATH"]).read_text(encoding="utf-8"))
        issue, comment = event.get("issue") or {}, event.get("comment") or {}
        demand(event.get("action") == "created"
               and issue.get("number") == ISSUE and issue.get("pull_request") is None
               and comment.get("body") == "/limpiar-lock-1384"
               and (comment.get("user") or {}).get("login") == "pl0n3r",
               "comando OWNER no coincide")
        token, run_id = os.getenv("GITHUB_TOKEN"), os.getenv("GITHUB_RUN_ID")
        demand(bool(token) and type(run_id) is str and run_id.isdigit(),
               "token o ID de workflow no acreditado")
        recover(shared.GitHubApi(token), datetime.now(timezone.utc), run_id)
        return 0
    except (Denied, KeyError, OSError, ValueError, TypeError):
        print("RECOVERY DENIED: autoridad o evidencia insuficiente", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
