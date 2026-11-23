#!/usr/bin/env python3
"""Reparación one-off del lock V3 huérfano de Condor #1957. CAS only, fail closed.

Este script elimina exclusivamente coordinacion/lock-issue-1957. No libera la
reserva V3, no borra la rama de trabajo y no concede una sesión nueva.
"""
from __future__ import annotations

from datetime import datetime, timezone
import hashlib
import json
import os
import re
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

REPO = "pl0n3r/Condor"
ISSUE = 1957
DEPENDENCY = 1955
BRANCH = "trabajo/issue-1957"
LOCK_REF = "refs/heads/coordinacion/lock-issue-1957"
LOCK_API_REF = f"/repos/{REPO}/git/ref/heads/coordinacion/lock-issue-1957"
EXPECTED_NONCE = "529e213b7025e106dbe8b3a395645fa2c8a3aa3b"
EXPECTED_PARENT = "8d75e1636076c87b1ccf018404f1e88fb456ac28"
EXPECTED_MESSAGE = (
    "lock coordinacion Issue #1957 "
    "c6c71bc1-258b-4018-a1b1-b6a81a2012bd"
)
EXPECTED_LEASE = "83fb970d-a8da-4c45-9f57-63f1843d6528"
EXPECTED_ACCEPTANCE_SHA256 = (
    "91c01bd1b0789391451583b3110256d99aac87ec5dc27fed68a4f6b83d8ccdd1"
)
EXPECTED_TASK_MARKER_SHA256 = (
    "47d0fd2b4da52ad5b572ea2a1cc98ebc6063ac70cd479c6f8c46b3bce74d885b"
)
EXPECTED_PATHS = [
    "src/Http/Controller/EmployeeController.php",
    "src/Application/Platform/ModuleCatalog.php",
    "src/Application/Platform/ModuleAccess.php",
    "src/Http/EventSubscriber/ModuleAccessSubscriber.php",
    "tests/php/Http/EmployeeControllerAvailabilityTest.php",
    "tests/php/Http/ModuleAccessTest.php",
    "tests/test_employees_http_api.py",
    "tests/e2e/admin-nav.spec.mjs",
]
EXPECTED_TASK = {
    "version": 1,
    "epic": 1,
    "task_key": "HR_EMPLOYEES_AVAILABILITY",
    "order": 31510,
    "owner": "pl0n3r",
    "roles": [
        "ingenieria-software",
        "seguridad",
        "qa",
        "frontend",
        "diseno-visual",
        "ux",
    ],
    "depends_on": [DEPENDENCY],
    "paths": EXPECTED_PATHS,
}
WORKFLOW_REF = (
    "pl0n3r/Condor/.github/workflows/recuperar-lock1957.yml@refs/heads/main"
)
COMMAND = "/limpiar-lock-1957"
BOT = "github-actions[bot]"
ZERO = "0" * 40
MAX_BODY = 200_000
MAX_COMMENTS_PAGES = 10
MARKER_RE = re.compile(
    r"<!--\s*factory-unattended-kill-switch\s+(\{[^\n]*\})\s*-->"
)
LEASE_RE = re.compile(r"<!--\s*condor-reserva\s+(\{[^\n]*\})\s*-->")
CRITERION_LINE = re.compile(r"^- \[[ xX]\] \[(AC-[0-9]{2})\] (.{1,500})$")


class Denied(RuntimeError):
    """No existe autoridad suficiente para retirar la ref."""


def demand(condition: bool, reason: str) -> None:
    if not condition:
        raise Denied(reason)


def extract_marker(body: str, name: str) -> str:
    demand(isinstance(body, str) and 1 <= len(body) <= MAX_BODY, "body inválido")
    prefix = f"<!-- {name} "
    html_prefix = f"<!-- {name}"
    suffix = " -->"
    starts: list[int] = []
    offset = 0
    while True:
        start = body.find(html_prefix, offset)
        if start < 0:
            break
        boundary = start + len(html_prefix)
        if (
            boundary == len(body)
            or body[boundary].isspace()
            or body.startswith("-->", boundary)
        ):
            demand(body.startswith(prefix, start), f"marker {name} malformado")
            starts.append(start)
        offset = boundary
    demand(len(starts) == 1, f"se requiere exactamente un marker {name}")
    start = starts[0] + len(prefix)
    end = body.find(suffix, start)
    demand(end >= 0, f"marker {name} incompleto")
    return body[start:end].strip()


def section(body: str, heading: str) -> str:
    lines = body.splitlines()
    indexes = [index for index, line in enumerate(lines) if line.strip() == heading]
    demand(len(indexes) == 1, f"sección {heading} ausente o duplicada")
    start = indexes[0] + 1
    end = len(lines)
    for index in range(start, len(lines)):
        if lines[index].startswith("### "):
            end = index
            break
    content = "\n".join(lines[start:end]).strip()
    demand(bool(content), f"sección {heading} vacía")
    return content


def parse_task_marker(body: str) -> dict:
    try:
        marker = json.loads(extract_marker(body, "factory-plan-task"))
    except (TypeError, ValueError) as exc:
        raise Denied("factory-plan-task ilegible") from exc
    demand(type(marker) is dict, "factory-plan-task no es objeto")
    return marker


def task_marker_fingerprint(marker: dict) -> str:
    canonical = json.dumps(
        marker,
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")
    return hashlib.sha256(canonical).hexdigest()


def contract_fingerprint(body: str) -> str:
    required = (
        "### Contexto",
        "### Alcance",
        "### Fuera de alcance",
        "### Criterios de aceptación",
        "### Contrato ejecutable",
    )
    for heading in required:
        section(body, heading)

    human: dict[str, str] = {}
    for line in section(body, "### Criterios de aceptación").splitlines():
        stripped = line.strip()
        if not stripped.startswith("- ["):
            continue
        match = CRITERION_LINE.fullmatch(stripped)
        demand(match is not None, "criterio humano AC inválido")
        criterion_id, description = match.groups()
        demand(criterion_id not in human, "criterio AC duplicado")
        human[criterion_id] = description.strip()
    demand(1 <= len(human) <= 20, "cantidad de criterios AC inválida")

    try:
        raw = json.loads(extract_marker(body, "factory-acceptance"))
    except (TypeError, ValueError) as exc:
        raise Denied("factory-acceptance ilegible") from exc
    demand(
        type(raw) is dict
        and set(raw) == {"version", "criteria"}
        and type(raw.get("version")) is int
        and raw.get("version") == 1
        and type(raw.get("criteria")) is list
        and 1 <= len(raw["criteria"]) <= 20,
        "contrato máquina inválido",
    )
    machine: list[dict[str, str]] = []
    seen: set[str] = set()
    for row in raw["criteria"]:
        demand(
            type(row) is dict and set(row) == {"id", "kind", "target"},
            "criterio máquina malformado",
        )
        criterion_id, kind, target = row["id"], row["kind"], row["target"]
        demand(
            isinstance(criterion_id, str)
            and re.fullmatch(r"AC-[0-9]{2}", criterion_id) is not None
            and criterion_id not in seen
            and kind in {"test", "check"}
            and isinstance(target, str)
            and 1 <= len(target) <= 120
            and "\n" not in target
            and "\r" not in target,
            "criterio máquina inválido",
        )
        seen.add(criterion_id)
        machine.append({"id": criterion_id, "kind": kind, "target": target})
    demand(set(human) == seen, "IDs humanos y máquina no coinciden")

    canonical = {
        "human": [
            {"id": criterion_id, "description": human[criterion_id]}
            for criterion_id in sorted(human)
        ],
        "machine": sorted(machine, key=lambda item: item["id"]),
    }
    payload = json.dumps(
        canonical,
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")
    return hashlib.sha256(payload).hexdigest()


def valid_global(api) -> None:
    issue = api.get("/repos/pl0n3r/Factory/issues/767")
    markers = MARKER_RE.findall(str(issue.get("body") or ""))
    demand(
        issue.get("number") == 767
        and issue.get("state") == "closed"
        and (issue.get("user") or {}).get("login") == "pl0n3r"
        and len(markers) == 1,
        "kill switch global no canónico",
    )
    try:
        value = json.loads(markers[0])
    except (TypeError, ValueError) as exc:
        raise Denied("kill switch global ilegible") from exc
    demand(
        value == {"version": 1, "state": "RUNNING", "owner": "pl0n3r"},
        "kill switch global no está RUNNING",
    )


def _lease_from_bot_comment(item: dict) -> dict | None:
    if (item.get("user") or {}).get("login") != BOT:
        return None
    markers = LEASE_RE.findall(str(item.get("body") or ""))
    demand(len(markers) <= 1, "comentario bot con recibos V3 duplicados")
    if not markers:
        return None
    try:
        value = json.loads(markers[0])
    except (TypeError, ValueError) as exc:
        raise Denied("recibo V3 ilegible") from exc
    demand(type(value) is dict, "recibo V3 no es objeto")
    return value


def latest_lease(api) -> dict:
    found = None
    for page in range(1, MAX_COMMENTS_PAGES + 1):
        data = api.get(
            f"/repos/{REPO}/issues/{ISSUE}/comments?per_page=100&page={page}"
        )
        demand(type(data) is list and len(data) <= 100, "comentarios ilegibles")
        for item in data:
            receipt = _lease_from_bot_comment(item)
            if receipt is not None:
                found = receipt
        if len(data) < 100:
            return found or {}
    raise Denied("paginación V3 excede el límite")


def no_live_coordination(api, current_run_id: str) -> None:
    for status in ("in_progress", "queued", "waiting", "pending", "requested"):
        page = api.get(
            f"/repos/{REPO}/actions/runs?status={status}&per_page=100"
        )
        demand(
            type(page) is dict
            and type(page.get("workflow_runs")) is list
            and type(page.get("total_count")) is int
            and page["total_count"] <= 100,
            "observación de workflows incompleta",
        )
        for run in page["workflow_runs"]:
            if str(run.get("id")) == current_run_id:
                continue
            name = str(run.get("name") or "").lower()
            demand(
                "coordinaci" not in name and "coordination" not in name,
                "hay un coordinador activo",
            )


def verify_issue_and_lease(api) -> tuple[dict, dict]:
    current = api.get(f"/repos/{REPO}/issues/{ISSUE}")
    labels = {
        label.get("name")
        for label in current.get("labels", [])
        if isinstance(label, dict)
    }
    demand(
        current.get("number") == ISSUE
        and current.get("state") == "open"
        and "estado: reservado" in labels,
        "issue #1957 no está abierta y reservada",
    )
    dependency = api.get(f"/repos/{REPO}/issues/{DEPENDENCY}")
    demand(
        dependency.get("number") == DEPENDENCY
        and dependency.get("state") == "closed",
        "dependencia #1955 no está completada",
    )
    lease = latest_lease(api)
    demand(
        lease.get("version") == 3
        and lease.get("active") is True
        and lease.get("reason") == "tomar"
        and lease.get("owner") == "pl0n3r"
        and lease.get("reservation_id") == EXPECTED_LEASE
        and lease.get("branch") == BRANCH
        and lease.get("task_paths") == EXPECTED_PATHS
        and lease.get("task_depends_on") == [DEPENDENCY]
        and lease.get("acceptance_sha256") == EXPECTED_ACCEPTANCE_SHA256
        and lease.get("task_marker_sha256") == EXPECTED_TASK_MARKER_SHA256,
        "la última V3 activa no coincide con la reserva esperada",
    )
    body = str(current.get("body") or "")
    task = parse_task_marker(body)
    demand(task == EXPECTED_TASK, "task marker de #1957 cambió")
    demand(
        task_marker_fingerprint(task) == EXPECTED_TASK_MARKER_SHA256,
        "fingerprint task de #1957 cambió",
    )
    demand(
        contract_fingerprint(body) == EXPECTED_ACCEPTANCE_SHA256,
        "fingerprint de aceptación de #1957 cambió",
    )
    return current, lease


def verify_branch(api) -> str:
    """El HEAD puede avanzar, pero solo en los ocho claims V3 de #1957."""
    branch = api.get(f"/repos/{REPO}/branches/{BRANCH}")
    branch_sha = (branch.get("commit") or {}).get("sha")
    demand(type(branch_sha) is str and re.fullmatch(r"[0-9a-f]{40}", branch_sha)
           is not None, "HEAD de la rama ilegible")
    ancestry = api.get(
        f"/repos/{REPO}/compare/{EXPECTED_PARENT}...{branch_sha}"
    )
    demand(type(ancestry) is dict and ancestry.get("status") in
           {"ahead", "identical"} and ancestry.get("behind_by") == 0,
           "rama no desciende del parent firmado")
    files = ancestry.get("files")
    demand(type(files) is list and len(files) <= 100,
           "diff de la rama incompleto")
    demand(all(type(row) is dict and type(row.get("filename")) is str
               and row["filename"] in EXPECTED_PATHS for row in files),
           "el HEAD contiene archivos fuera de claims #1957")
    main = api.get(f"/repos/{REPO}/branches/main")
    main_sha = (main.get("commit") or {}).get("sha")
    demand(type(main_sha) is str and re.fullmatch(r"[0-9a-f]{40}", main_sha)
           is not None, "HEAD de main ilegible")
    comparison = api.get(
        f"/repos/{REPO}/compare/{EXPECTED_PARENT}...{main_sha}"
    )
    demand(type(comparison) is dict
           and comparison.get("status") in {"ahead", "identical"}
           and comparison.get("behind_by") == 0,
           "main ya no desciende del parent del lock")
    pulls = api.get(f"/repos/{REPO}/pulls?state=open&per_page=100")
    demand(type(pulls) is list and len(pulls) < 100,
           "inventario PR incompleto")
    demand(all(type(pr) is dict and type(pr.get("head")) is dict
               for pr in pulls), "PR ajeno o ilegible")
    matching = [
        pr for pr in pulls
        if (pr.get("head") or {}).get("ref") == BRANCH
    ]
    demand(not matching, "ya existe un PR abierto para la rama #1957")
    return branch_sha


def verify_nonce(api, now: datetime) -> None:
    lock = api.get(LOCK_API_REF)
    demand(
        lock.get("ref") == LOCK_REF
        and (lock.get("object") or {}).get("type") == "commit"
        and (lock.get("object") or {}).get("sha") == EXPECTED_NONCE,
        "nonce/ref cambió o es ilegible",
    )
    commit = api.get(f"/repos/{REPO}/git/commits/{EXPECTED_NONCE}")
    parents = commit.get("parents") or []
    author = commit.get("author") or {}
    committer = commit.get("committer") or {}
    verification = commit.get("verification") or {}
    demand(
        commit.get("sha") == EXPECTED_NONCE
        and commit.get("message") == EXPECTED_MESSAGE
        and len(parents) == 1
        and parents[0].get("sha") == EXPECTED_PARENT
        and author.get("name") == BOT
        and author.get("email")
        == "41898282+github-actions[bot]@users.noreply.github.com"
        and committer.get("name") == "GitHub"
        and committer.get("email") == "noreply@github.com"
        and verification.get("verified") is True
        and verification.get("reason") == "valid",
        "nonce no es el commit firmado e inerte esperado",
    )
    parent = api.get(f"/repos/{REPO}/git/commits/{EXPECTED_PARENT}")
    demand(
        (commit.get("tree") or {}).get("sha")
        == (parent.get("tree") or {}).get("sha"),
        "el lock introdujo cambios en el árbol",
    )
    created = committer.get("date")
    demand(isinstance(created, str), "nonce sin fecha")
    try:
        at = datetime.fromisoformat(created.replace("Z", "+00:00"))
    except ValueError as exc:
        raise Denied("fecha nonce inválida") from exc
    demand(
        at.tzinfo is not None and (now - at).total_seconds() >= 3600,
        "el lock todavía no cumple 60 minutos de antigüedad",
    )
    author_time = author.get("date")
    demand(type(author_time) is str, "nonce sin fecha de autor")
    try:
        author_at = datetime.fromisoformat(author_time.replace("Z", "+00:00"))
    except ValueError as exc:
        raise Denied("fecha de autor inválida") from exc
    demand(author_at.tzinfo is not None
           and (now - author_at).total_seconds() >= 3600,
           "el autor del lock todavía no cumple 60 minutos")


def prepare(api, now: datetime, run_id: str) -> str:
    valid_global(api)
    freeze = api.get("/repos/pl0n3r/Factory/issues/921")
    demand(freeze.get("state") == "closed", "freeze global activo")
    verify_issue_and_lease(api)
    verify_branch(api)
    no_live_coordination(api, run_id)
    verify_nonce(api, now)
    identity = api.get(f"/repos/{REPO}")
    repo_id = identity.get("node_id")
    demand(
        identity.get("name") == "Condor"
        and (identity.get("owner") or {}).get("login") == "pl0n3r"
        and isinstance(repo_id, str)
        and bool(repo_id),
        "identidad canónica del repositorio inválida",
    )
    return repo_id


def recover(api, now: datetime, run_id: str) -> None:
    repo_id = prepare(api, now, run_id)
    branch_before = verify_branch(api)
    valid_global(api)
    freeze = api.get("/repos/pl0n3r/Factory/issues/921")
    demand(freeze.get("state") == "closed", "freeze global cambió")
    verify_issue_and_lease(api)
    verify_branch(api)
    no_live_coordination(api, run_id)
    verify_nonce(api, now)

    payload = {
        "query": (
            "mutation($input:UpdateRefsInput!){"
            "updateRefs(input:$input){clientMutationId}}"
        ),
        "variables": {
            "input": {
                "repositoryId": repo_id,
                "refUpdates": [
                    {
                        "name": LOCK_REF,
                        "beforeOid": EXPECTED_NONCE,
                        "afterOid": ZERO,
                        "force": True,
                    }
                ],
            }
        },
    }
    result = api.post("/graphql", payload)
    demand(
        isinstance(result, dict)
        and not result.get("errors")
        and isinstance((result.get("data") or {}).get("updateRefs"), dict),
        "CAS GraphQL denegado; estado UNKNOWN",
    )
    try:
        api.get(LOCK_API_REF)
    except HTTPError as exc:
        demand(exc.code == 404, "no se corroboró GET 404 del lock")
    else:
        raise Denied("el lock sigue presente después del CAS")

    verify_issue_and_lease(api)
    demand(verify_branch(api) == branch_before,
           "la rama #1957 cambió durante el CAS")
    api.post(f"/repos/{REPO}/issues/{ISSUE}/comments", {
        "body": (
            "Reparación lock-only verificada: CAS eliminó únicamente "
            f"{LOCK_REF} con beforeOid {EXPECTED_NONCE}; GET posterior = 404. "
            f"La reserva V3 {EXPECTED_LEASE} sigue activa y la rama "
            f"{BRANCH} permanece intacta en {branch_before}. "
            "No se liberó ni recreó la V3. Continuar en la misma rama; no enviar /tomar."
        )
    })


def authorized_event(event: dict, env: dict[str, str]) -> None:
    demand(
        env.get("GITHUB_REPOSITORY") == REPO
        and env.get("GITHUB_ACTOR") == "pl0n3r"
        and env.get("GITHUB_EVENT_NAME") == "issue_comment"
        and env.get("GITHUB_REF") == "refs/heads/main"
        and env.get("GITHUB_WORKFLOW_REF") == WORKFLOW_REF,
        "workflow/evento no autorizado",
    )
    issue = event.get("issue") or {}
    comment = event.get("comment") or {}
    demand(
        event.get("action") == "created"
        and issue.get("number") == ISSUE
        and issue.get("pull_request") is None
        and comment.get("body") == COMMAND
        and (comment.get("user") or {}).get("login") == "pl0n3r"
        and comment.get("author_association") == "OWNER",
        "comando o autor distinto",
    )


class GitHubApi:
    def __init__(self, token: str):
        self.token = token

    def request(self, method: str, path: str, body=None):
        if path == "/graphql":
            url = "https://api.github.com/graphql"
        else:
            demand(path.startswith("/repos/"), "ruta API no autorizada")
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
        with urlopen(
            Request(url, data=data, method=method, headers=headers),
            timeout=20,
        ) as response:
            return json.load(response)

    def get(self, path: str):
        return self.request("GET", path)

    def post(self, path: str, body):
        return self.request("POST", path, body)


def main() -> int:
    try:
        with open(os.environ["GITHUB_EVENT_PATH"], encoding="utf-8") as handle:
            event = json.load(handle)
        authorized_event(event, dict(os.environ))
        token = os.environ.get("GITHUB_TOKEN")
        run_id = os.environ.get("GITHUB_RUN_ID")
        demand(
            isinstance(token, str)
            and bool(token)
            and isinstance(run_id, str)
            and run_id.isdigit(),
            "token o run_id ausentes",
        )
        recover(GitHubApi(token), datetime.now(timezone.utc), run_id)
        print(
            "CAS lock-only exitoso. La V3 y la rama de trabajo #1957 siguen intactas."
        )
        return 0
    except (Denied, HTTPError, URLError, KeyError, OSError, ValueError, TypeError) as exc:
        print("RECOVERY DENIED: " + type(exc).__name__, file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
