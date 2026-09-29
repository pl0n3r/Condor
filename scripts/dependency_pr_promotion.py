#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import re
import uuid
from pathlib import Path

BOT_LOGINS = {"dependabot[bot]", "renovate[bot]"}
VERSION_RE = re.compile(r"""['"]version['"]\s*=>\s*['"](\d+\.\d+\.\d+)['"]""")
TAG_RE = re.compile(r"^v(\d+)\.(\d+)\.(\d+)$")
SHA_RE = re.compile(r"^[0-9a-f]{40}$")
RESERVATION_RE = re.compile(r"<!--\s*condor-reserva\s+({[^}]*})\s*-->")
ALLOWED_EXACT = {
    "composer.json", "composer.lock", "package.json", "package-lock.json",
    "npm-shrinkwrap.json", "pnpm-lock.yaml", "yarn.lock",
}
WORKFLOW_RE = re.compile(r"^\.github/workflows/[^/]+\.(?:yml|yaml)$")
REPO_NAME_KEY = "full" + "_name"

SOURCE_PR_JSON = Path("/tmp/source-pr.json")
SOURCE_FILES_JSON = Path("/tmp/source-files.json")
PROMOTION_ISSUE_JSON = Path("/tmp/promotion-issue.json")
PROMOTION_COMMENTS_JSON = Path("/tmp/promotion-comments.json")
ACTUAL_FILES = Path("/tmp/source-files.txt")
VERSION_FILE = Path("config/version.php")
README_FILE = Path("README.md")


class PromotionError(RuntimeError):
    pass


def semver(value: str) -> tuple[int, int, int]:
    match = re.fullmatch(r"(\d+)\.(\d+)\.(\d+)", value)
    if not match:
        raise PromotionError(f"invalid SemVer: {value!r}")
    return tuple(map(int, match.groups()))


def read_version() -> str:
    matches = VERSION_RE.findall(VERSION_FILE.read_text(encoding="utf-8"))
    if len(matches) != 1:
        raise PromotionError("config/version.php must define exactly one version")
    return matches[0]


def next_patch(value: str) -> str:
    major, minor, patch = semver(value)
    return f"{major}.{minor}.{patch + 1}"


def allowed_dependency_path(path: str) -> bool:
    return path in ALLOWED_EXACT or WORKFLOW_RE.fullmatch(path) is not None


def source_files(files: list[dict]) -> list[str]:
    if not files or len(files) > 100:
        raise PromotionError("source PR must contain between 1 and 100 files")
    names: list[str] = []
    for row in files:
        name = row.get("filename")
        if not isinstance(name, str) or not allowed_dependency_path(name):
            raise PromotionError(f"source PR contains non-dependency path: {name!r}")
        names.append(name)
    if len(names) != len(set(names)):
        raise PromotionError("source PR contains duplicate file entries")
    return sorted(names)


def _trusted_reservation_marker(comment: dict) -> dict | None:
    actor = ((comment.get("user") or {}).get("login"))
    body = comment.get("body")
    if actor != "github-actions[bot]" or not isinstance(body, str):
        return None
    match = RESERVATION_RE.search(body)
    if match is None:
        return None
    try:
        payload = json.loads(match.group(1))
    except json.JSONDecodeError:
        return None
    return payload if isinstance(payload, dict) else None


def active_reservation(
    comments: list[dict],
    reservation_id: str,
    branch: str,
    expected_owner: str,
) -> dict:
    try:
        uuid.UUID(reservation_id)
    except ValueError as exc:
        raise PromotionError("reservation_id must be a UUID") from exc

    markers = [
        marker
        for comment in comments
        if (marker := _trusted_reservation_marker(comment)) is not None
    ]
    if not markers:
        raise PromotionError("reservation marker is missing")

    latest = markers[-1]
    if latest.get("reservation_id") != reservation_id:
        raise PromotionError("reservation_id is not the latest canonical reservation")
    if latest.get("active") is not True:
        raise PromotionError("reservation is not active")
    if latest.get("branch") != branch:
        raise PromotionError("reservation branch does not match target branch")
    if latest.get("owner") != expected_owner:
        raise PromotionError("reservation owner does not match workflow actor")
    return latest


def _validate_source_pr(pr: dict, repository: str) -> tuple[int, str, str, str]:
    if pr.get("state") != "open":
        raise PromotionError("source PR must be open")
    author = ((pr.get("user") or {}).get("login"))
    if author not in BOT_LOGINS:
        raise PromotionError(f"source PR author is not an allowed dependency bot: {author!r}")

    base = pr.get("base") or {}
    head = pr.get("head") or {}
    if base.get("ref") != "main":
        raise PromotionError("source PR must target main")
    if ((base.get("repo") or {}).get(REPO_NAME_KEY)) != repository:
        raise PromotionError("source PR base repository mismatch")
    if ((head.get("repo") or {}).get(REPO_NAME_KEY)) != repository:
        raise PromotionError("source PR must originate from the same repository")

    source_sha = head.get("sha")
    base_sha = base.get("sha")
    if not isinstance(source_sha, str) or SHA_RE.fullmatch(source_sha) is None:
        raise PromotionError("source PR head SHA is invalid")
    if not isinstance(base_sha, str) or SHA_RE.fullmatch(base_sha) is None:
        raise PromotionError("source PR base SHA is invalid")

    number = pr.get("number")
    title = pr.get("title")
    if not isinstance(number, int) or number < 1:
        raise PromotionError("source PR number is invalid")
    if not isinstance(title, str) or not title or any(ord(ch) < 32 for ch in title):
        raise PromotionError("source PR title is invalid")
    return number, title, source_sha, base_sha


def _validate_promotion_issue(issue: dict) -> int:
    number = issue.get("number")
    if not isinstance(number, int) or number < 1 or issue.get("state") != "open":
        raise PromotionError("promotion issue must be open")
    labels = {
        item.get("name")
        for item in issue.get("labels", [])
        if isinstance(item, dict)
    }
    if "estado: reservado" not in labels:
        raise PromotionError("promotion issue must be reserved")
    return number


def _promotion_version(current_version: str, latest_tag: str) -> str:
    current = semver(current_version)
    match = TAG_RE.fullmatch(latest_tag)
    if match is None:
        raise PromotionError("latest tag must be strict vX.Y.Z")
    latest = tuple(map(int, match.groups()))
    if current != latest:
        raise PromotionError("current version must equal latest released tag before promotion")
    return next_patch(current_version)


def build_plan(
    pr: dict,
    files: list[dict],
    issue: dict,
    comments: list[dict],
    reservation_id: str,
    current_version: str,
    latest_tag: str,
    repository: str,
    expected_owner: str,
) -> dict:
    number, title, source_sha, base_sha = _validate_source_pr(pr, repository)
    issue_number = _validate_promotion_issue(issue)
    target_branch = f"trabajo/issue-{issue_number}"
    active_reservation(comments, reservation_id, target_branch, expected_owner)
    version = _promotion_version(current_version, latest_tag)
    return {
        "source_pr": number,
        "source_sha": source_sha,
        "base_sha": base_sha,
        "source_title": title,
        "source_files": source_files(files),
        "target_branch": target_branch,
        "version": version,
        "pr_title": f"chore(deps): promote bot PR #{number} (V {version})",
        "issue_number": issue_number,
        "reservation_id": reservation_id,
    }


def verify_files(files: list[dict], actual: list[str]) -> None:
    expected = source_files(files)
    normalized = sorted(line.strip() for line in actual if line.strip())
    if expected != normalized:
        raise PromotionError(
            f"git diff files do not match GitHub PR files: expected={expected} actual={normalized}"
        )


def materialize(
    version: str,
    issue_number: int,
    source_pr: int,
    source_title: str,
    source_sha: str,
    main_sha: str,
) -> None:
    previous = read_version()
    if version != next_patch(previous):
        raise PromotionError("promotion version must be the next patch version")

    text = VERSION_FILE.read_text(encoding="utf-8")
    updated, count = VERSION_RE.subn(
        lambda match: match.group(0).replace(match.group(1), version),
        text,
    )
    if count != 1:
        raise PromotionError("could not update canonical version")
    VERSION_FILE.write_text(updated, encoding="utf-8")

    safe_title = source_title.replace("'", "’")
    readme = f"""# Condor App — Snapshot operativo · Dependency promotion V {version}

> **Candidato objetivo:** V{version} · Issue #{issue_number} · promoción canónica del PR automático #{source_pr}.
>
> **Base de promoción:** V{previous} · main@{main_sha} · identidad humana ya publicada y no reutilizable.

Condor continúa en construcción. V{version} promueve un cambio automático de dependencias a una entrega gobernada sin editar ni fusionar directamente el PR bot original.

## Alcance
- preservar exactamente el diff de dependencias de PR #{source_pr};
- aplicar ese diff sobre una rama reservada por Factory;
- materializar una versión patch nueva antes de abrir el PR de entrega;
- conservar el release identity guard de #332 sin excepciones para bots;
- mantener el PR automático original como fuente read-only.

## Límites
- no crea, mueve ni borra tags/releases;
- no fusiona directamente PRs Dependabot/Renovate;
- no ejecuta código proveniente del PR fuente durante la promoción;
- conflictos al aplicar el parche fallan cerrado;
- Release Factory v1 conserva la autoridad final.

## Evidencia base
- PR fuente #{source_pr}: {safe_title}.
- SHA fuente: {source_sha}.
- La rama de promoción nace de main@{main_sha}.
- scripts/dependency_pr_promotion.py valida bot, reserva, paths e identidad.
"""
    README_FILE.write_text(readme, encoding="utf-8")


def load_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def _plan_from_fixed_inputs(args: argparse.Namespace) -> dict:
    return build_plan(
        load_json(SOURCE_PR_JSON),
        load_json(SOURCE_FILES_JSON),
        load_json(PROMOTION_ISSUE_JSON),
        load_json(PROMOTION_COMMENTS_JSON),
        args.reservation_id,
        read_version(),
        args.latest_tag,
        args.repository,
        args.expected_owner,
    )


def main() -> int:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="command", required=True)

    plan = sub.add_parser("plan")
    plan.add_argument("--reservation-id", required=True)
    plan.add_argument("--latest-tag", required=True)
    plan.add_argument("--repository", required=True)
    plan.add_argument("--expected-owner", required=True)

    sub.add_parser("verify-files")

    reservation = sub.add_parser("validate-reservation")
    reservation.add_argument("--reservation-id", required=True)
    reservation.add_argument("--branch", required=True)
    reservation.add_argument("--expected-owner", required=True)

    mat = sub.add_parser("materialize")
    mat.add_argument("--version", required=True)
    mat.add_argument("--issue-number", required=True, type=int)
    mat.add_argument("--source-pr", required=True, type=int)
    mat.add_argument("--source-title", required=True)
    mat.add_argument("--source-sha", required=True)
    mat.add_argument("--main-sha", required=True)

    args = parser.parse_args()
    try:
        if args.command == "plan":
            print(json.dumps(_plan_from_fixed_inputs(args), sort_keys=True))
        elif args.command == "verify-files":
            verify_files(
                load_json(SOURCE_FILES_JSON),
                ACTUAL_FILES.read_text(encoding="utf-8").splitlines(),
            )
            print("dependency_pr_promotion: source diff verified")
        elif args.command == "validate-reservation":
            active_reservation(
                load_json(PROMOTION_COMMENTS_JSON),
                args.reservation_id,
                args.branch,
                args.expected_owner,
            )
            print("dependency_pr_promotion: reservation authority verified")
        else:
            materialize(
                args.version,
                args.issue_number,
                args.source_pr,
                args.source_title,
                args.source_sha,
                args.main_sha,
            )
            print(f"dependency_pr_promotion: materialized V{args.version}")
    except (PromotionError, OSError, json.JSONDecodeError) as exc:
        print(f"::error::dependency_pr_promotion: {exc}")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
