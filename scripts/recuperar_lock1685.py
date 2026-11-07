"""Recuperación CAS del nonce huérfano de Condor #1685; nunca concede V3."""
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
ISSUE = 1685
WORK_BRANCH = "trabajo/issue-1685"
REF = "refs/heads/coordinacion/lock-issue-1685"
NONCE = "d24837972852295b8f221110491d72f01ffad88a"
PARENT = "ee3b97f9df33688d52af737c47080b72abc66a5f"
MESSAGE = "lock coordinacion Issue #1685 30d5bf5e-7a44-4842-92fa-fc39e5b30bdc"
LOCK_API = f"/repos/{REPO}/git/ref/heads/coordinacion/lock-issue-1685"
BRANCH_API = f"/repos/{REPO}/git/ref/heads/{WORK_BRANCH}"


def _must_be_exact_parent(api, path: str) -> None:
    """Rama huérfana únicamente si sigue en el parent original, sin trabajo."""
    try:
        ref = api.get(path)
    except HTTPError as exc:
        raise Denied("rama huérfana desaparecida o ilegible") from exc
    demand(type(ref) is dict and type(ref.get("object")) is dict
           and ref["object"].get("sha") == PARENT,
           "rama de trabajo movida: no limpiar lock")



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
    _must_be_exact_parent(api, BRANCH_API)
    pulls = api.get(f"/repos/{REPO}/pulls?state=open&per_page=100")
    demand(type(pulls) is list and len(pulls) < 100, "PRs incompletos")
    for pr in pulls:
        demand(type(pr) is dict, "PR ilegible")
        head = pr.get("head") or {}
        demand(type(head) is dict and head.get("ref") != WORK_BRANCH,
               "PR activo sobre la rama bloqueada")
    shared.no_live_coordination(api, run_id)


def check_nonce(api, now: datetime) -> None:
    """Identidad firmada, parent, árbol y edad: sin alias ni inferencias."""
    demand(now.tzinfo is not None, "reloj UTC no verificable")
    lock = api.get(LOCK_API)
    demand(type(lock) is dict and type(lock.get("object")) is dict
           and lock["object"].get("sha") == NONCE,
           "nonce de lock no coincide")
    commit = api.get(f"/repos/{REPO}/git/commits/{NONCE}")
    demand(type(commit) is dict and commit.get("sha") == NONCE
           and commit.get("message") == MESSAGE,
           "mensaje o SHA del lock distinto")
    parents = commit.get("parents")
    demand(type(parents) is list and len(parents) == 1
           and type(parents[0]) is dict and parents[0].get("sha") == PARENT,
           "parent del lock distinto")
    tree = commit.get("tree")
    demand(type(tree) is dict and tree.get("sha") == "908c791e40054fc86cc5f9076e86f3050ee1f48a",
           "árbol de lock alterado")
    parent_commit = api.get(f"/repos/{REPO}/git/commits/{PARENT}")
    parent_tree = parent_commit.get("tree") if type(parent_commit) is dict else None
    demand(type(parent_tree) is dict
           and parent_tree.get("sha") == tree.get("sha"),
           "lock no es un commit inerte sobre el parent")
    author, committer, verification = (
        commit.get("author"), commit.get("committer"), commit.get("verification"))
    demand(type(author) is dict and author.get("name") == "github-actions[bot]"
           and author.get("email") ==
               "41898282+github-actions[bot]@users.noreply.github.com",
           "autor del lock no autenticado")
    demand(type(committer) is dict and committer.get("name") == "GitHub"
           and committer.get("email") == "noreply@github.com",
           "committer del lock no autenticado")
    demand(type(verification) is dict
           and verification.get("verified") is True
           and verification.get("reason") == "valid",
           "firma GitHub del lock no verificada")
    for identity in (author, committer):
        timestamp = identity.get("date")
        demand(type(timestamp) is str, "fecha de lock ausente")
        try:
            date = datetime.fromisoformat(timestamp.replace("Z", "+00:00"))
        except ValueError as exc:
            raise Denied("fecha del nonce malformada") from exc
        demand(date.tzinfo is not None
               and (now - date).total_seconds() >= 3600,
               "lock reciente, futuro o no verificable")



def prepare(api, now: datetime, run_id: str) -> str:
    shared.valid_global(api)
    freeze = api.get("/repos/pl0n3r/Factory/issues/921")
    demand(type(freeze) is dict and freeze.get("state") == "closed", "freeze activo")
    issue = api.get(f"/repos/{REPO}/issues/{ISSUE}")
    demand(type(issue) is dict and issue.get("state") == "open"
           and any(type(row) is dict and row.get("name") == "estado: bloqueado"
                   for row in issue.get("labels", [])),
           "issue #1685 no está abierto/bloqueado")
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
               and os.getenv("GITHUB_EVENT_NAME") == "issue_comment"
               and os.getenv("GITHUB_REF") == "refs/heads/main"
               and os.getenv("GITHUB_WORKFLOW_REF") ==
               f"{REPO}/.github/workflows/recuperar-lock1685.yml@refs/heads/main",
               "evento o actor no autorizado")
        event = json.loads(Path(os.environ["GITHUB_EVENT_PATH"]).read_text(encoding="utf-8"))
        issue, comment = event.get("issue") or {}, event.get("comment") or {}
        demand(event.get("action") == "created"
               and issue.get("number") == ISSUE and issue.get("pull_request") is None
               and comment.get("body") == "/limpiar-lock-1685"
               and (comment.get("user") or {}).get("login") == "pl0n3r"
               and (event.get("sender") or {}).get("login") == "pl0n3r"
               and comment.get("author_association") == "OWNER",
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
