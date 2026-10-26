"""Reparación puntual del nonce huérfano de Condor #727. Fail closed, CAS only.

Este archivo NO da autoridad para escribir en #727: solo el bot V3 la concede
tras el /tomar legítimo posterior. No ejecutar como solución a otros locks.
"""
from __future__ import annotations

from datetime import datetime, timezone
import json
import os
import re
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

REPO = "pl0n3r/Condor"
ISSUE = 727
PR = 771
LOCK_REF = "refs/heads/coordinacion/lock-issue-727"
EXPECTED_NONCE = "3156bba33114b4097eb532ae6de21093fb753bd0"
EXPECTED_PARENT = "823bd8aa5217f8b573479d198cfddac47e28e49f"
EXPECTED_MESSAGE = "lock coordinacion Issue #727 d0fe7d7a-5dd2-42c2-8776-922f21fde7d5"
EXPECTED_LEASE = "95d9734c-6e4e-4d74-a2b6-25b19c0dbda3"
EXPECTED_PATHS = ["frontend/admin/ReportsDownloads.tsx", "tests/test_reports_admin_ui.py", "tests/test_ci_reports_admin_ui.py"]
BOT = "github-actions[bot]"
ZERO = "0" * 40
MARKER_RE = re.compile(r"<!--\s*factory-unattended-kill-switch\s+(\{[^\n]*\})\s*-->")
LEASE_RE = re.compile(r"<!--\s*condor-reserva\s+(\{[^\n]*\})\s*-->")

class Denied(RuntimeError):
    """No autoridad para modificar la ref; no iniciar una mutación parcial."""


def demand(condition: bool, reason: str) -> None:
    if not condition:
        raise Denied(reason)


def valid_global(api) -> None:
    issue = api.get("/repos/pl0n3r/Factory/issues/767")
    markers = MARKER_RE.findall(str(issue.get("body") or ""))
    demand(issue.get("number") == 767 and issue.get("state") == "closed"
           and (issue.get("user") or {}).get("login") == "pl0n3r"
           and len(markers) == 1, "kill switch no canónico")
    try:
        value = json.loads(markers[0])
    except (TypeError, ValueError) as exc:
        raise Denied("kill switch malformado") from exc
    demand(value == {"version": 1, "state": "RUNNING", "owner": "pl0n3r"},
           "kill switch no RUNNING")


def _lease_from_bot_comment(item: dict) -> dict | None:
    """Rechazar marcadores múltiples o corruptos del bot sin confiar en texto humano."""
    if (item.get("user") or {}).get("login") != BOT:
        return None
    markers = LEASE_RE.findall(str(item.get("body") or ""))
    demand(len(markers) <= 1, "recibo V3 duplicado")
    if not markers:
        return None
    try:
        return json.loads(markers[0])
    except (ValueError, TypeError) as exc:
        raise Denied("recibo V3 ilegible") from exc


def latest_lease(api) -> dict:
    found = None
    for page in range(1, 11):
        data = api.get(f"/repos/{REPO}/issues/{ISSUE}/comments?per_page=100&page={page}")
        demand(type(data) is list, "comentarios ilegibles")
        for item in data:
            receipt = _lease_from_bot_comment(item)
            if receipt is not None:
                found = receipt
        if len(data) < 100:
            return found or {}
    raise Denied("paginación V3 sin cota")


def no_live_coordination(api, current_run_id: str) -> None:
    # Rechazar cualquier proceso de Coordinación aún vivo; nunca borrar un lock
    # durante una ejecución que pudiera poseerlo. Fallar si hay más de 100 runs.
    for status in ("in_progress", "queued", "waiting", "pending", "requested"):
        page = api.get(f"/repos/{REPO}/actions/runs?status={status}&per_page=100")
        demand(type(page) is dict and type(page.get("workflow_runs")) is list
               and type(page.get("total_count")) is int
               and page["total_count"] <= 100, "observación de workflows incompleta")
        for run in page["workflow_runs"]:
            if str(run.get("id")) == current_run_id:
                continue
            name = str(run.get("name") or "")
            demand(not ("coordinaci" in name.lower() or "coordination" in name.lower()),
                   "hay coordinador activo")


def verify_nonce(api, now: datetime) -> None:
    lock = api.get(f"/repos/{REPO}/git/ref/heads/coordinacion/lock-issue-727")
    demand((lock.get("object") or {}).get("sha") == EXPECTED_NONCE,
           "nonce cambió o es ilegible")
    commit = api.get(f"/repos/{REPO}/git/commits/{EXPECTED_NONCE}")
    demand(commit.get("message") == EXPECTED_MESSAGE
           and len(commit.get("parents") or []) == 1
           and commit["parents"][0].get("sha") == EXPECTED_PARENT
           and (commit.get("author") or {}).get("name") == BOT,
           "nonce no fue emitido por el bot sobre el HEAD previsto")
    created = (commit.get("committer") or {}).get("date")
    demand(isinstance(created, str), "nonce sin fecha")
    try:
        at = datetime.fromisoformat(created.replace("Z", "+00:00"))
    except ValueError as exc:
        raise Denied("fecha nonce inválida") from exc
    demand(at.tzinfo is not None and (now - at).total_seconds() >= 3600,
           "el lock no es huérfano por antigüedad")


def prepare(api, now: datetime, run_id: str) -> str:
    valid_global(api)
    freeze = api.get("/repos/pl0n3r/Factory/issues/921")
    demand(freeze.get("state") == "closed", "freeze global")
    current = api.get(f"/repos/{REPO}/issues/{ISSUE}")
    demand(current.get("state") == "open"
           and any(x.get("name") == "estado: bloqueado" for x in current.get("labels", [])),
           "issue #727 no está bloqueado y abierto")
    pr = api.get(f"/repos/{REPO}/pulls/{PR}")
    demand(pr.get("state") == "open" and pr.get("draft") is True
           and (pr.get("head") or {}).get("sha") == EXPECTED_PARENT
           and (pr.get("head") or {}).get("ref") == "trabajo/issue-727",
           "PR/HEAD de rescate cambiados")
    lease = latest_lease(api)
    demand(lease.get("version") == 3 and lease.get("active") is True
           and lease.get("owner") == "pl0n3r"
           and lease.get("reservation_id") == EXPECTED_LEASE
           and lease.get("branch") == "trabajo/issue-727"
           and lease.get("task_paths") == EXPECTED_PATHS,
           "reserva V3 no es la última esperada")
    no_live_coordination(api, run_id)
    verify_nonce(api, now)
    identity = api.get(f"/repos/{REPO}")
    rid = identity.get("node_id")
    demand(identity.get("name") == "Condor" and (identity.get("owner") or {}).get("login") == "pl0n3r" and isinstance(rid, str) and rid,
           "identidad del repo ilegible")
    return rid


def recover(api, now: datetime, run_id: str) -> None:
    repo_id = prepare(api, now, run_id)
    # Última comprobación fail-closed antes de la única mutación: el CAS
    # beforeOid impide borrar una generación de lock que cambió.
    valid_global(api)
    verify_nonce(api, now)
    payload = {
        "query": "mutation($input:UpdateRefsInput!){updateRefs(input:$input){clientMutationId}}",
        "variables": {"input": {
            "repositoryId": repo_id,
            "refUpdates": [{
                "name": LOCK_REF, "beforeOid": EXPECTED_NONCE,
                "afterOid": ZERO, "force": True,
            }],
        }},
    }
    result = api.post("/graphql", payload)
    demand(isinstance(result, dict) and not result.get("errors")
           and isinstance((result.get("data") or {}).get("updateRefs"), dict),
           "CAS GraphQL denegado; estado UNKNOWN")
    # La autoridad proviene del borrado exacto, NO del comentario o de CI.
    try:
        api.get(f"/repos/{REPO}/git/ref/heads/coordinacion/lock-issue-727")
    except HTTPError as exc:
        demand(exc.code == 404, "no se puede corroborar ausencia del lock")
    else:
        raise Denied("el lock sigue presente tras CAS")
    api.post(f"/repos/{REPO}/issues/{ISSUE}/comments", {
        "body": (
            "CAS auténtico del lock huérfano ejecutado por workflow autorizado: "
            f"{LOCK_REF}@{EXPECTED_NONCE} -> eliminado, verificado 404. "
            "No se tocó trabajo/issue-727 ni PR #741. "
            "Esta reparación NO emite V3: enviar /tomar para recuperar la "
            "reserva con los tres paths, y reconstruir el bundle antes de CI."
        )
    })


class GitHubApi:
    def __init__(self, token: str):
        self.token = token

    def request(self, method: str, path: str, body=None):
        if path == "/graphql":
            url = "https://api.github.com/graphql"
        else:
            demand(path.startswith("/repos/"), "ruta no autorizada")
            url = "https://api.github.com" + path
        headers = {
            "Authorization": "Bearer " + self.token,
            "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
        }
        data = None
        if body is not None:
            data = json.dumps(body, separators=(",", ":")).encode("utf-8")
            headers["Content-Type"] = "application/json"
        with urlopen(Request(url, data=data, method=method, headers=headers), timeout=20) as response:
            return json.load(response)

    def get(self, path: str):
        return self.request("GET", path)

    def post(self, path: str, body):
        return self.request("POST", path, body)


def main() -> int:
    try:
        demand(os.getenv("GITHUB_REPOSITORY") == REPO
               and os.getenv("GITHUB_ACTOR") == "pl0n3r"
               and os.getenv("GITHUB_EVENT_NAME") == "issue_comment",
               "evento no autorizado")
        with open(os.environ["GITHUB_EVENT_PATH"], encoding="utf-8") as handle:
            event = json.load(handle)
        demand((event.get("issue") or {}).get("number") == ISSUE
               and (event.get("issue") or {}).get("pull_request") is None
               and (event.get("comment") or {}).get("body") == "/limpiar-lock-727"
               and (event.get("comment") or {}).get("user", {}).get("login") == "pl0n3r",
               "comando diferente")
        token = os.environ.get("GITHUB_TOKEN")
        run_id = os.environ.get("GITHUB_RUN_ID")
        demand(isinstance(token, str) and bool(token)
               and isinstance(run_id, str) and run_id.isdigit(),
               "token o run_id ausentes")
        recover(GitHubApi(token), datetime.now(timezone.utc), run_id)
        print("CAS exitoso. Solicitar una V3 nueva antes de cualquier escritura en #727.")
        return 0
    except (Denied, KeyError, OSError, ValueError, TypeError) as exc:
        # Nunca imprimir token, PII, URLs de credenciales ni respuesta bruta.
        print("RECOVERY DENIED: " + type(exc).__name__, file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
