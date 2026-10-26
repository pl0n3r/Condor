"""Recuperación de un único lock de Coordinación (#727), sin afectar otras refs.

Usa las validaciones globales y el transporte probados por el reparador #724,
pero conserva aquí los parámetros inmutables y las comprobaciones particulares
del nonce de #727. Ninguna reserva se crea en este procedimiento.
"""
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
ISSUE = 727
PR = 771
BRANCH = "trabajo/issue-727"
REF = "refs/heads/coordinacion/lock-issue-727"
NONCE = "3156bba33114b4097eb532ae6de21093fb753bd0"
PARENT = "823bd8aa5217f8b573479d198cfddac47e28e49f"
MESSAGE = "lock coordinacion Issue #727 d0fe7d7a-5dd2-42c2-8776-922f21fde7d5"
HISTORICAL_LEASE = "95d9734c-6e4e-4d74-a2b6-25b19c0dbda3"
HISTORICAL_PATHS = (
    "frontend/admin/ReportsDownloads.tsx",
    "tests/test_reports_admin_ui.py",
    "tests/test_ci_reports_admin_ui.py",
)
LOCK_API = f"/repos/{REPO}/git/ref/heads/coordinacion/lock-issue-727"


def newest_receipt(api) -> dict:
    """Solo recibos V3 del bot, paginación acotada; no usar marcadores humanos."""
    found = None
    for page in range(1, 11):
        comments = api.get(f"/repos/{REPO}/issues/{ISSUE}/comments?per_page=100&page={page}")
        demand(isinstance(comments, list), "comentarios V3 ilegibles")
        for comment in comments:
            receipt = shared._lease_from_bot_comment(comment)
            if receipt is not None:
                found = receipt
        if len(comments) < 100:
            return found or {}
    raise Denied("más de diez páginas de recibos V3")


def check_nonce(api, now: datetime) -> None:
    item = api.get(LOCK_API)
    demand((item.get("object") or {}).get("sha") == NONCE, "lock cambió")
    commit = api.get(f"/repos/{REPO}/git/commits/{NONCE}")
    parents = commit.get("parents")
    demand(
        commit.get("message") == MESSAGE
        and isinstance(parents, list) and len(parents) == 1
        and parents[0].get("sha") == PARENT
        and (commit.get("author") or {}).get("name") == "github-actions[bot]",
        "autor, padre o mensaje del lock no corresponden",
    )
    timestamp = (commit.get("committer") or {}).get("date")
    demand(isinstance(timestamp, str), "lock sin fecha")
    try:
        then = datetime.fromisoformat(timestamp.replace("Z", "+00:00"))
        age = (now - then).total_seconds()
    except (ValueError, TypeError) as exc:
        raise Denied("fecha del lock inválida") from exc
    demand(then.tzinfo is not None and age >= 3600, "lock no huérfano o fecha futura")


def prepare(api, now: datetime, run_id: str) -> str:
    shared.valid_global(api)
    frozen = api.get("/repos/pl0n3r/Factory/issues/921")
    demand(frozen.get("state") == "closed", "freeze Factory activa")
    issue = api.get(f"/repos/{REPO}/issues/{ISSUE}")
    demand(
        issue.get("state") == "open"
        and {row.get("name") for row in issue.get("labels", [])}.__contains__("estado: bloqueado"),
        "issue #727 no está abierto y bloqueado",
    )
    pull = api.get(f"/repos/{REPO}/pulls/{PR}")
    head = pull.get("head") or {}
    demand(
        pull.get("state") == "open" and pull.get("draft") is True
        and head.get("sha") == PARENT and head.get("ref") == BRANCH,
        "PR #771 ha cambiado",
    )
    receipt = newest_receipt(api)
    demand(
        receipt.get("version") == 3 and receipt.get("active") is True
        and receipt.get("owner") == "pl0n3r"
        and receipt.get("reservation_id") == HISTORICAL_LEASE
        and receipt.get("branch") == BRANCH
        and receipt.get("task_paths") == list(HISTORICAL_PATHS),
        "última V3 no coincide con el recibo histórico",
    )
    shared.no_live_coordination(api, run_id)
    check_nonce(api, now)
    repo = api.get(f"/repos/{REPO}")
    node = repo.get("node_id")
    demand(
        repo.get("name") == "Condor"
        and (repo.get("owner") or {}).get("login") == "pl0n3r"
        and isinstance(node, str) and bool(node),
        "repositorio no canónico",
    )
    return node


def recover(api, now: datetime, run_id: str) -> None:
    repo_node_id = prepare(api, now, run_id)
    shared.valid_global(api)
    check_nonce(api, now)
    api_response = api.post("/graphql", {
        "query": "mutation($input:UpdateRefsInput!){updateRefs(input:$input){clientMutationId}}",
        "variables": {"input": {
            "repositoryId": repo_node_id,
            "refUpdates": [{
                "name": REF,
                "beforeOid": NONCE,
                "afterOid": "0" * 40,
                "force": True,
            }],
        }},
    })
    demand(
        isinstance(api_response, dict) and not api_response.get("errors")
        and isinstance((api_response.get("data") or {}).get("updateRefs"), dict),
        "CAS rechazado: resultado desconocido",
    )
    try:
        api.get(LOCK_API)
    except HTTPError as exc:
        demand(exc.code == 404, "ausencia de ref no acreditada")
    else:
        raise Denied("el lock continúa presente")
    api.post(f"/repos/{REPO}/issues/{ISSUE}/comments", {
        "body": (
            f"Reparación CAS de ref huérfana confirmada: {REF}@{NONCE} eliminada (GET=404). "
            "La rama trabajo/issue-727 y PR #771 no se tocaron. "
            "Solicitar /tomar para emitir una nueva V3 antes de editar o fusionar."
        ),
    })


def main() -> int:
    try:
        demand(
            os.environ.get("GITHUB_REPOSITORY") == REPO
            and os.environ.get("GITHUB_ACTOR") == "pl0n3r"
            and os.environ.get("GITHUB_EVENT_NAME") == "issue_comment",
            "identidad del evento no autorizada",
        )
        event = json.loads(Path(os.environ["GITHUB_EVENT_PATH"]).read_text(encoding="utf-8"))
        issue = event.get("issue") or {}
        comment = event.get("comment") or {}
        demand(
            issue.get("number") == ISSUE and issue.get("pull_request") is None
            and comment.get("body") == "/limpiar-lock-727"
            and (comment.get("user") or {}).get("login") == "pl0n3r",
            "comentario no autorizado",
        )
        token, run_id = os.environ.get("GITHUB_TOKEN"), os.environ.get("GITHUB_RUN_ID")
        demand(bool(token) and isinstance(run_id, str) and run_id.isdigit(),
               "token o run_id no disponibles")
        recover(shared.GitHubApi(token), datetime.now(timezone.utc), run_id)
        print("Lock exacto de #727 eliminado mediante CAS; solicitar nueva V3.")
        return 0
    except (Denied, KeyError, OSError, ValueError, TypeError):
        print("RECOVERY DENIED: identidad o fuente no verificable", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
