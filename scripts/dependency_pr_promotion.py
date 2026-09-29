#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import re
import subprocess
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

ROOT = Path(__file__).resolve().parents[1]
RUNTIME_DIR = ROOT / ".condor-runtime" / "dependency-promotion"
SOURCE_PR_JSON = RUNTIME_DIR / "source-pr.json"
SOURCE_FILES_JSON = RUNTIME_DIR / "source-files.json"
PROMOTION_ISSUE_JSON = RUNTIME_DIR / "promotion-issue.json"
PROMOTION_COMMENTS_JSON = RUNTIME_DIR / "promotion-comments.json"
ACTUAL_FILES = RUNTIME_DIR / "source-files.txt"
VERSION_FILE = Path("config/version.php")
README_FILE = Path("README.md")
SESSION_RE = re.compile(
    r"^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-"
    r"[89ab][0-9a-f]{3}-[0-9a-f]{12}$"
)
BRANCH_RE = re.compile(r"^trabajo/issue-([1-9][0-9]*)$")
FINGERPRINT_RE = re.compile(r"^[0-9a-f]{64}$")


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


def ensure_private_runtime(path: Path = RUNTIME_DIR) -> Path:
    """Crea el runtime fijo con permisos privados y sin atravesar symlinks."""
    parent = path.parent
    for candidate in (parent, path):
        if candidate.is_symlink():
            raise PromotionError("promotion runtime must not be a symlink")
    parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    path.mkdir(exist_ok=True, mode=0o700)
    for candidate in (parent, path):
        if candidate.is_symlink() or not candidate.is_dir():
            raise PromotionError("promotion runtime must be a private directory")
        candidate.chmod(0o700)
        if candidate.stat().st_mode & 0o077:
            raise PromotionError("promotion runtime permissions are not private")
    return path


def valid_reservation_payload(value: object) -> bool:
    """Replica el schema cerrado de reservas Factory v1/v2/v3."""
    if not isinstance(value, dict):
        return False
    base = {"version", "owner", "reservation_id", "branch", "active", "reason"}
    version = value.get("version")
    if version == 1:
        if set(value) != base:
            return False
    elif version == 2:
        if set(value) != base | {"acceptance_sha256"}:
            return False
    elif version == 3:
        if set(value) != base | {
            "acceptance_sha256",
            "task_marker_sha256",
            "task_paths",
            "task_depends_on",
        }:
            return False
        marker_fingerprint = value.get("task_marker_sha256")
        paths = value.get("task_paths")
        dependencies = value.get("task_depends_on")
        if (
            not isinstance(marker_fingerprint, str)
            or FINGERPRINT_RE.fullmatch(marker_fingerprint) is None
            or not isinstance(paths, list)
            or not paths
            or not all(isinstance(item, str) and item for item in paths)
            or not isinstance(dependencies, list)
            or not all(
                isinstance(number, int)
                and not isinstance(number, bool)
                and number > 0
                for number in dependencies
            )
        ):
            return False
    else:
        return False

    if version in (2, 3):
        fingerprint = value.get("acceptance_sha256")
        if (
            not isinstance(fingerprint, str)
            or FINGERPRINT_RE.fullmatch(fingerprint) is None
        ):
            return False
    if not isinstance(value.get("owner"), str) or not value["owner"]:
        return False
    reservation_id = value.get("reservation_id")
    if (
        not isinstance(reservation_id, str)
        or SESSION_RE.fullmatch(reservation_id.lower()) is None
    ):
        return False
    branch = value.get("branch")
    if not isinstance(branch, str) or BRANCH_RE.fullmatch(branch) is None:
        return False
    if not isinstance(value.get("active"), bool):
        return False
    return isinstance(value.get("reason"), str) and bool(value["reason"])


def _reservation_from_text(body: str) -> dict | None:
    latest = None
    for match in RESERVATION_RE.finditer(body):
        try:
            payload = json.loads(match.group(1))
        except json.JSONDecodeError:
            continue
        if valid_reservation_payload(payload):
            latest = payload
    return latest


def _trusted_reservation_marker(comment: dict) -> dict | None:
    actor = ((comment.get("user") or {}).get("login"))
    body = comment.get("body")
    if actor != "github-actions[bot]" or not isinstance(body, str):
        return None
    return _reservation_from_text(body)


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
    if (
        not isinstance(issue_number, int)
        or isinstance(issue_number, bool)
        or issue_number < 1
        or not isinstance(source_pr, int)
        or isinstance(source_pr, bool)
        or source_pr < 1
    ):
        raise PromotionError("materialize issue/source PR identity invalid")
    if not isinstance(source_sha, str) or SHA_RE.fullmatch(source_sha) is None:
        raise PromotionError("materialize source SHA invalid")
    if not isinstance(main_sha, str) or SHA_RE.fullmatch(main_sha) is None:
        raise PromotionError("materialize main SHA invalid")
    if (
        not isinstance(source_title, str)
        or not source_title
        or len(source_title) > 240
        or any(ord(ch) < 32 or ord(ch) == 127 for ch in source_title)
    ):
        raise PromotionError("materialize source title invalid")

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


def _git_main_sha() -> str:
    result = subprocess.run(
        ["git", "rev-parse", "origin/main"],
        cwd=ROOT,
        check=False,
        text=True,
        capture_output=True,
    )
    sha = result.stdout.strip()
    if result.returncode != 0 or SHA_RE.fullmatch(sha) is None:
        raise PromotionError("origin/main SHA unavailable")
    return sha


def _materialize_from_fixed_inputs(args: argparse.Namespace) -> None:
    pr = load_json(SOURCE_PR_JSON)
    head = pr.get("head") if isinstance(pr, dict) else None
    materialize(
        args.version,
        args.issue_number,
        pr.get("number") if isinstance(pr, dict) else None,
        pr.get("title") if isinstance(pr, dict) else None,
        head.get("sha") if isinstance(head, dict) else None,
        _git_main_sha(),
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
    sub.add_parser("prepare-runtime")

    reservation = sub.add_parser("validate-reservation")
    reservation.add_argument("--reservation-id", required=True)
    reservation.add_argument("--branch", required=True)
    reservation.add_argument("--expected-owner", required=True)

    mat = sub.add_parser("materialize")
    mat.add_argument("--version", required=True)
    mat.add_argument("--issue-number", required=True, type=int)

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
        elif args.command == "prepare-runtime":
            print(ensure_private_runtime())
        elif args.command == "validate-reservation":
            active_reservation(
                load_json(PROMOTION_COMMENTS_JSON),
                args.reservation_id,
                args.branch,
                args.expected_owner,
            )
            print("dependency_pr_promotion: reservation authority verified")
        else:
            _materialize_from_fixed_inputs(args)
            print(f"dependency_pr_promotion: materialized V{args.version}")
    except (PromotionError, OSError, json.JSONDecodeError) as exc:
        print(f"::error::dependency_pr_promotion: {exc}")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
