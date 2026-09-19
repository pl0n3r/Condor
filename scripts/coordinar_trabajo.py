#!/usr/bin/env python3
"""Coordinacion multiagente para Condor.

GitHub actua como arbitro central:
- una reserva crea de forma atomica la rama trabajo/issue-N;
- un Issue reservado no puede ser tomado por otra sesion;
- cada PR debe corresponder a su Issue reservado;
- los PR abiertos no pueden solapar archivos silenciosamente.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
from dataclasses import dataclass
from typing import Any
from urllib.error import HTTPError
from urllib.parse import quote
from urllib.request import Request, urlopen


API_URL = os.getenv("GITHUB_API_URL", "https://api.github.com").rstrip("/")
TOKEN = os.getenv("GH_TOKEN") or os.getenv("GITHUB_TOKEN")
ALLOWED_ASSOCIATIONS = {"OWNER", "MEMBER", "COLLABORATOR"}

STATUS_LABELS: dict[str, tuple[str, str]] = {
    "estado: disponible": ("2DA44E", "Trabajo disponible para ser reservado."),
    "estado: reservado": ("FBCA04", "Trabajo reservado por una sesion o agente."),
    "estado: en revision": ("1D76DB", "Trabajo con Pull Request listo para revision."),
    "estado: completado": ("0E8A16", "Trabajo completado."),
    "estado: cancelado": ("6E7781", "Trabajo cerrado sin completarse."),
}

BRANCH_RE = re.compile(r"^trabajo/issue-(\d+)$")
CLOSING_RE = re.compile(r"(?im)\b(?:closes|fixes|resolves)\s+#(\d+)\b")
RESERVATION_RE = re.compile(r"<!-- condor-reserva (\{.*?\}) -->")


class CoordinationError(RuntimeError):
    pass


@dataclass
class GitHubError(RuntimeError):
    status: int
    message: str

    def __str__(self) -> str:
        return f"GitHub API {self.status}: {self.message}"


class GitHub:
    def __init__(self, repo: str, token: str | None = None) -> None:
        self.repo = repo
        self.token = token or TOKEN
        if not self.token:
            raise CoordinationError("Falta GH_TOKEN/GITHUB_TOKEN para consultar GitHub.")

    def request(
        self,
        method: str,
        path: str,
        payload: Any | None = None,
        allow: tuple[int, ...] = (),
    ) -> Any:
        url = f"{API_URL}{path}"
        body = None if payload is None else json.dumps(payload).encode("utf-8")
        headers = {
            "Accept": "application/vnd.github+json",
            "Authorization": f"Bearer {self.token}",
            "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "condor-coordinacion",
        }
        request = Request(url, data=body, headers=headers, method=method)
        try:
            with urlopen(request, timeout=30) as response:
                raw = response.read()
                return None if not raw else json.loads(raw.decode("utf-8"))
        except HTTPError as exc:
            raw = exc.read().decode("utf-8", errors="replace")
            if exc.code in allow:
                return None
            try:
                message = json.loads(raw).get("message", raw)
            except json.JSONDecodeError:
                message = raw
            raise GitHubError(exc.code, str(message)) from exc

    def paginate(self, path: str) -> list[dict[str, Any]]:
        page = 1
        items: list[dict[str, Any]] = []
        while True:
            separator = "&" if "?" in path else "?"
            payload = self.request("GET", f"{path}{separator}per_page=100&page={page}")
            if not isinstance(payload, list):
                break
            items.extend(item for item in payload if isinstance(item, dict))
            if len(payload) < 100:
                break
            page += 1
        return items

    def issue(self, number: int) -> dict[str, Any]:
        payload = self.request("GET", f"/repos/{self.repo}/issues/{number}")
        if not isinstance(payload, dict):
            raise CoordinationError(f"No fue posible leer Issue #{number}.")
        return payload

    def pull(self, number: int) -> dict[str, Any]:
        payload = self.request("GET", f"/repos/{self.repo}/pulls/{number}")
        if not isinstance(payload, dict):
            raise CoordinationError(f"No fue posible leer PR #{number}.")
        return payload

    def comment(self, issue_number: int, body: str) -> None:
        self.request(
            "POST",
            f"/repos/{self.repo}/issues/{issue_number}/comments",
            {"body": body},
        )

    def ensure_label(self, name: str, color: str, description: str) -> None:
        encoded = quote(name, safe="")
        current = self.request(
            "GET",
            f"/repos/{self.repo}/labels/{encoded}",
            allow=(404,),
        )
        if current is None:
            self.request(
                "POST",
                f"/repos/{self.repo}/labels",
                {"name": name, "color": color, "description": description},
            )

    def ensure_status_labels(self) -> None:
        for name, (color, description) in STATUS_LABELS.items():
            self.ensure_label(name, color, description)

    def add_labels(self, issue_number: int, labels: list[str]) -> None:
        if labels:
            self.request(
                "POST",
                f"/repos/{self.repo}/issues/{issue_number}/labels",
                {"labels": labels},
            )

    def remove_label(self, issue_number: int, label: str) -> None:
        encoded = quote(label, safe="")
        self.request(
            "DELETE",
            f"/repos/{self.repo}/issues/{issue_number}/labels/{encoded}",
            allow=(404,),
        )

    def set_status(self, issue_number: int, status: str | None) -> None:
        self.ensure_status_labels()
        for label in STATUS_LABELS:
            self.remove_label(issue_number, label)
        if status:
            self.add_labels(issue_number, [status])

    def branch_sha(self, branch: str) -> str | None:
        encoded = quote(branch, safe="/")
        payload = self.request(
            "GET",
            f"/repos/{self.repo}/git/ref/heads/{encoded}",
            allow=(404,),
        )
        if not isinstance(payload, dict):
            return None
        obj = payload.get("object")
        return str(obj.get("sha")) if isinstance(obj, dict) and obj.get("sha") else None

    def create_branch(self, branch: str, sha: str) -> bool:
        try:
            self.request(
                "POST",
                f"/repos/{self.repo}/git/refs",
                {"ref": f"refs/heads/{branch}", "sha": sha},
            )
        except GitHubError as exc:
            if exc.status == 422:
                return False
            raise
        return True

    def delete_branch(self, branch: str) -> None:
        encoded = quote(branch, safe="/")
        self.request(
            "DELETE",
            f"/repos/{self.repo}/git/refs/heads/{encoded}",
            allow=(404,),
        )

    def issue_comments(self, issue_number: int) -> list[dict[str, Any]]:
        return self.paginate(f"/repos/{self.repo}/issues/{issue_number}/comments")

    def open_pulls(self) -> list[dict[str, Any]]:
        return self.paginate(f"/repos/{self.repo}/pulls?state=open")

    def pull_files(self, number: int) -> set[str]:
        files = self.paginate(f"/repos/{self.repo}/pulls/{number}/files")
        return {
            str(item["filename"])
            for item in files
            if isinstance(item.get("filename"), str)
        }

    def close_pull(self, number: int) -> None:
        self.request(
            "PATCH",
            f"/repos/{self.repo}/pulls/{number}",
            {"state": "closed"},
        )

    def try_assign(self, issue_number: int, login: str) -> None:
        self.request(
            "POST",
            f"/repos/{self.repo}/issues/{issue_number}/assignees",
            {"assignees": [login]},
            allow=(404, 422),
        )

    def try_unassign(self, issue_number: int, login: str) -> None:
        self.request(
            "DELETE",
            f"/repos/{self.repo}/issues/{issue_number}/assignees",
            {"assignees": [login]},
            allow=(404, 422),
        )


def issue_from_branch(branch: str) -> int | None:
    match = BRANCH_RE.fullmatch(branch)
    return int(match.group(1)) if match else None


def closing_issues(body: str) -> set[int]:
    return {int(value) for value in CLOSING_RE.findall(body or "")}


def reservation_marker(owner: str, branch: str, active: bool, reason: str) -> str:
    payload = json.dumps(
        {"owner": owner, "branch": branch, "active": active, "reason": reason},
        separators=(",", ":"),
        sort_keys=True,
    )
    return f"<!-- condor-reserva {payload} -->"


def latest_reservation(comments: list[dict[str, Any]]) -> dict[str, Any] | None:
    latest: dict[str, Any] | None = None
    for comment in comments:
        body = str(comment.get("body") or "")
        for match in RESERVATION_RE.finditer(body):
            try:
                parsed = json.loads(match.group(1))
            except json.JSONDecodeError:
                continue
            if isinstance(parsed, dict):
                latest = parsed
    return latest


def file_overlaps(
    current_files: set[str],
    others: dict[int, set[str]],
) -> dict[int, list[str]]:
    collisions: dict[int, list[str]] = {}
    for pr_number, files in others.items():
        overlap = sorted(current_files & files)
        if overlap:
            collisions[pr_number] = overlap
    return collisions


def label_names(issue: dict[str, Any]) -> set[str]:
    result: set[str] = set()
    for label in issue.get("labels", []):
        if isinstance(label, dict) and isinstance(label.get("name"), str):
            result.add(label["name"])
    return result


def authorized(association: str) -> bool:
    return association.upper() in ALLOWED_ASSOCIATIONS


def active_owner(api: GitHub, issue_number: int) -> str | None:
    reservation = latest_reservation(api.issue_comments(issue_number))
    if not reservation or not reservation.get("active"):
        return None
    owner = reservation.get("owner")
    return str(owner) if owner else None


def reserve_work(api: GitHub, issue_number: int, actor: str, association: str) -> None:
    if not authorized(association):
        raise CoordinationError(
            f"@{actor} no tiene una asociacion autorizada para reservar trabajo."
        )

    issue = api.issue(issue_number)
    if issue.get("pull_request"):
        raise CoordinationError("Los comandos de reserva se ejecutan sobre Issues, no PRs.")
    if issue.get("state") != "open":
        raise CoordinationError(f"Issue #{issue_number} no esta abierto.")

    labels = label_names(issue)
    if "estado: bloqueado" in labels:
        api.comment(issue_number, f"⛔ @{actor}: Issue #{issue_number} esta bloqueado.")
        return
    if "estado: disponible" not in labels:
        api.comment(
            issue_number,
            f"⛔ @{actor}: Issue #{issue_number} no esta marcado como estado: disponible.",
        )
        return

    branch = f"trabajo/issue-{issue_number}"
    main_sha = api.branch_sha("main")
    if not main_sha:
        raise CoordinationError("No fue posible resolver el SHA actual de main.")

    if not api.create_branch(branch, main_sha):
        api.set_status(issue_number, "estado: reservado")
        api.comment(
            issue_number,
            f"⛔ @{actor}: la reserva no fue concedida. La rama {branch} ya existe. "
            "El trabajo queda fail-closed hasta liberacion explicita.",
        )
        return

    api.set_status(issue_number, "estado: reservado")
    api.try_assign(issue_number, actor)
    marker = reservation_marker(actor, branch, True, "tomar")
    api.comment(
        issue_number,
        f"{marker}\n"
        f"🔒 **Trabajo reservado por @{actor}.**\n\n"
        f"- Rama canonica: {branch}\n"
        f"- Base de reserva: {main_sha}\n"
        "- La reserva no vence automaticamente.\n"
        "- Otra sesion no debe modificar esta rama ni trabajar este Issue.\n"
        "- Libera con /liberar; el dueno del repositorio puede usar /liberar-forzado.",
    )
    print(f"Reserva concedida: Issue #{issue_number} -> {branch} (@{actor})")


def open_pulls_for_branch(api: GitHub, branch: str) -> list[int]:
    result: list[int] = []
    for pull in api.open_pulls():
        head = pull.get("head")
        if isinstance(head, dict) and head.get("ref") == branch:
            number = pull.get("number")
            if isinstance(number, int):
                result.append(number)
    return result


def release_work(
    api: GitHub,
    issue_number: int,
    actor: str,
    association: str,
    force: bool,
) -> None:
    if not authorized(association):
        raise CoordinationError(
            f"@{actor} no tiene una asociacion autorizada para liberar trabajo."
        )

    repo_owner = api.repo.split("/", 1)[0]
    owner = active_owner(api, issue_number)
    if force:
        if actor != repo_owner:
            raise CoordinationError(
                f"Solo @{repo_owner} puede ejecutar /liberar-forzado."
            )
    elif owner != actor:
        api.comment(
            issue_number,
            f"⛔ @{actor}: solo el propietario activo "
            f"(@{owner or 'desconocido'}) puede liberar esta reserva.",
        )
        return

    branch = f"trabajo/issue-{issue_number}"
    for pr_number in open_pulls_for_branch(api, branch):
        api.close_pull(pr_number)
    api.delete_branch(branch)

    issue = api.issue(issue_number)
    if issue.get("state") == "open":
        api.set_status(issue_number, "estado: disponible")
    if owner:
        api.try_unassign(issue_number, owner)

    marker = reservation_marker(
        owner or actor,
        branch,
        False,
        "liberacion-forzada" if force else "liberar",
    )
    api.comment(
        issue_number,
        f"{marker}\n"
        f"🔓 Reserva liberada por @{actor}. La rama {branch} fue eliminada.",
    )
    print(f"Reserva liberada: Issue #{issue_number}")


def update_pr_state(api: GitHub, pr_number: int, action: str) -> None:
    pull = api.pull(pr_number)
    head = pull.get("head")
    branch = str(head.get("ref") or "") if isinstance(head, dict) else ""
    issue_number = issue_from_branch(branch)
    if issue_number is None:
        return

    issue = api.issue(issue_number)
    if action == "ready_for_review" and issue.get("state") == "open":
        api.set_status(issue_number, "estado: en revision")
        return
    if action == "converted_to_draft" and issue.get("state") == "open":
        api.set_status(issue_number, "estado: reservado")
        return
    if action != "closed":
        return

    merged = bool(pull.get("merged"))
    reservation = latest_reservation(api.issue_comments(issue_number))
    owner = str(reservation.get("owner")) if reservation and reservation.get("owner") else "sistema"

    api.delete_branch(branch)
    if merged:
        api.set_status(issue_number, "estado: completado")
        reason = "pr-merged"
        human = f"✅ PR #{pr_number} fusionado; reserva cerrada."
    else:
        if issue.get("state") == "open":
            api.set_status(issue_number, "estado: disponible")
        reason = "pr-cerrado-sin-merge"
        human = f"🔓 PR #{pr_number} cerrado sin merge; reserva liberada."

    if owner != "sistema":
        api.try_unassign(issue_number, owner)
    api.comment(
        issue_number,
        f"{reservation_marker(owner, branch, False, reason)}\n{human}",
    )


def update_issue_state(api: GitHub, issue_number: int, action: str) -> None:
    issue = api.issue(issue_number)
    branch = f"trabajo/issue-{issue_number}"

    if action == "reopened":
        if api.branch_sha(branch):
            api.set_status(issue_number, "estado: reservado")
        else:
            api.set_status(issue_number, "estado: disponible")
        return
    if action != "closed":
        return

    for pr_number in open_pulls_for_branch(api, branch):
        api.close_pull(pr_number)
    api.delete_branch(branch)

    state_reason = issue.get("state_reason")
    status = "estado: cancelado" if state_reason == "not_planned" else "estado: completado"
    api.set_status(issue_number, status)

    reservation = latest_reservation(api.issue_comments(issue_number))
    owner = str(reservation.get("owner")) if reservation and reservation.get("owner") else "sistema"
    if owner != "sistema":
        api.try_unassign(issue_number, owner)
    api.comment(
        issue_number,
        f"{reservation_marker(owner, branch, False, 'issue-cerrado')}\n"
        f"🧹 Reserva limpiada al cerrar Issue #{issue_number}.",
    )


def validate_pull(api: GitHub, pr_number: int, require_reservation: bool) -> None:
    pull = api.pull(pr_number)
    head = pull.get("head")
    branch = str(head.get("ref") or "") if isinstance(head, dict) else ""
    issue_number = issue_from_branch(branch)
    errors: list[str] = []

    if issue_number is None:
        errors.append("La rama del PR debe usar el formato canonico trabajo/issue-N.")
    else:
        body = str(pull.get("body") or "")
        if issue_number not in closing_issues(body):
            errors.append(
                f"El PR debe incluir Closes #{issue_number} (o Fixes/Resolves) en el cuerpo."
            )

        issue = api.issue(issue_number)
        if issue.get("state") != "open":
            errors.append(f"Issue #{issue_number} debe estar abierto durante el PR.")

        if require_reservation:
            labels = label_names(issue)
            if not ({"estado: reservado", "estado: en revision"} & labels):
                errors.append(f"Issue #{issue_number} no tiene una reserva activa visible.")
            if api.branch_sha(branch) is None:
                errors.append(f"La rama reservada {branch} no existe.")
            reservation = latest_reservation(api.issue_comments(issue_number))
            if not reservation or not reservation.get("active"):
                errors.append(f"Issue #{issue_number} no tiene marcador de reserva activo.")
            elif reservation.get("branch") != branch:
                errors.append(
                    f"El marcador de reserva apunta a {reservation.get('branch')}, no a {branch}."
                )

    current_files = api.pull_files(pr_number)
    others: dict[int, set[str]] = {}
    for other in api.open_pulls():
        other_number = other.get("number")
        if not isinstance(other_number, int) or other_number == pr_number:
            continue
        base = other.get("base")
        if isinstance(base, dict) and base.get("ref") != "main":
            continue
        others[other_number] = api.pull_files(other_number)

    collisions = file_overlaps(current_files, others)
    if collisions:
        for other_pr, files in collisions.items():
            rendered = ", ".join(files)
            errors.append(
                f"Colision con PR #{other_pr}: ambos modifican {rendered}."
            )

    if errors:
        raise CoordinationError("\n".join(f"- {error}" for error in errors))

    mode = "reserva obligatoria" if require_reservation else "bootstrap"
    print(
        f"Coordinacion valida para PR #{pr_number} "
        f"({mode}); sin solapamientos con otros PR abiertos."
    )


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Coordinacion multiagente de Condor")
    sub = parser.add_subparsers(dest="command", required=True)

    tomar = sub.add_parser("tomar")
    tomar.add_argument("--repo", required=True)
    tomar.add_argument("--issue", required=True, type=int)
    tomar.add_argument("--actor", required=True)
    tomar.add_argument("--association", required=True)

    liberar = sub.add_parser("liberar")
    liberar.add_argument("--repo", required=True)
    liberar.add_argument("--issue", required=True, type=int)
    liberar.add_argument("--actor", required=True)
    liberar.add_argument("--association", required=True)
    liberar.add_argument("--force", action="store_true")

    pr_event = sub.add_parser("pr-event")
    pr_event.add_argument("--repo", required=True)
    pr_event.add_argument("--pr", required=True, type=int)
    pr_event.add_argument("--action", required=True)

    issue_event = sub.add_parser("issue-event")
    issue_event.add_argument("--repo", required=True)
    issue_event.add_argument("--issue", required=True, type=int)
    issue_event.add_argument("--action", required=True)

    validar = sub.add_parser("validar-pr")
    validar.add_argument("--repo", required=True)
    validar.add_argument("--pr", required=True, type=int)
    validar.add_argument("--require-reservation", action="store_true")

    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    try:
        api = GitHub(args.repo)
        if args.command == "tomar":
            reserve_work(api, args.issue, args.actor, args.association)
        elif args.command == "liberar":
            release_work(
                api,
                args.issue,
                args.actor,
                args.association,
                args.force,
            )
        elif args.command == "pr-event":
            update_pr_state(api, args.pr, args.action)
        elif args.command == "issue-event":
            update_issue_state(api, args.issue, args.action)
        elif args.command == "validar-pr":
            validate_pull(api, args.pr, args.require_reservation)
        else:
            parser.error("Comando no soportado.")
    except (CoordinationError, GitHubError) as exc:
        print(f"::error::{exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
