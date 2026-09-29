#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
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

ROOT = Path(__file__).resolve().parents[1]
RUNTIME_DIR = ROOT / ".condor-runtime" / "dependency-promotion"
SOURCE_PR_JSON = RUNTIME_DIR / "source-pr.json"
SOURCE_FILES_JSON = RUNTIME_DIR / "source-files.json"
PROMOTION_ISSUE_JSON = RUNTIME_DIR / "promotion-issue.json"
PROMOTION_COMMENTS_JSON = RUNTIME_DIR / "promotion-comments.json"
ACTUAL_FILES = RUNTIME_DIR / "source-files.txt"
PLAN_JSON = RUNTIME_DIR / "plan.json"
VERSION_FILE = Path("config/version.php")
SESSION_RE = re.compile(
    r"^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-"
    r"[89ab][0-9a-f]{3}-[0-9a-f]{12}$"
)
BRANCH_RE = re.compile(r"^trabajo/issue-([1-9][0-9]*)$")
FINGERPRINT_RE = re.compile(r"^[0-9a-f]{64}$")
ACCEPTANCE_MARKER = "factory-acceptance"
TASK_MARKER = "factory-plan-task"
MAX_BODY = 200_000
MAX_CRITERIA = 20
ACCEPTANCE_REQUIRED_HEADINGS = (
    "### Contexto",
    "### Alcance",
    "### Fuera de alcance",
    "### Criterios de aceptación",
    "### Contrato ejecutable",
)
ACCEPTANCE_CRITERION_LINE = re.compile(
    r"^- \[[ xX]\] \[(AC-[0-9]{2})\] (.{1,500})$"
)
ACCEPTANCE_TEST_TARGET = re.compile(
    r"^((?:tests|metricas|seguridad|lecciones|producto)/"
    r"test_[A-Za-z0-9_/-]+\.py)::"
    r"([A-Za-z_][A-Za-z0-9_]*)::"
    r"(test_[A-Za-z0-9_]+)$"
)
ACCEPTANCE_CHECK_NAME = re.compile(r"^[^\r\n]{1,120}$")
ACCEPTANCE_FORBIDDEN_CHECKS = {"Validar", "Criterios de aceptación"}


class PromotionError(RuntimeError):
    pass


def _section(body: str, heading: str) -> str:
    lines = body.splitlines()
    indexes = [i for i, line in enumerate(lines) if line.strip() == heading]
    if len(indexes) != 1:
        raise PromotionError(f"acceptance section invalid: {heading}")
    start = indexes[0] + 1
    end = len(lines)
    for index in range(start, len(lines)):
        if lines[index].startswith("### "):
            end = index
            break
    content = "\n".join(lines[start:end]).strip()
    if not content:
        raise PromotionError(f"acceptance section empty: {heading}")
    return content


def _human_criteria(body: str) -> dict[str, str]:
    section = _section(body, "### Criterios de aceptación")
    criteria: dict[str, str] = {}
    for line in section.splitlines():
        stripped = line.strip()
        if not stripped.startswith("- ["):
            continue
        match = ACCEPTANCE_CRITERION_LINE.fullmatch(stripped)
        if match is None:
            raise PromotionError("acceptance criterion syntax is invalid")
        criterion_id, description = match.groups()
        if criterion_id in criteria:
            raise PromotionError("acceptance criterion IDs must be unique")
        criteria[criterion_id] = description.strip()
    if not 1 <= len(criteria) <= MAX_CRITERIA:
        raise PromotionError("acceptance criteria count is invalid")
    return criteria


def _machine_criteria(body: str) -> list[dict[str, str]]:
    prefix = f"<!-- {ACCEPTANCE_MARKER} "
    suffix = " -->"
    if body.count(prefix) != 1:
        raise PromotionError("factory-acceptance marker must be unique")
    start = body.index(prefix) + len(prefix)
    end = body.find(suffix, start)
    if end < 0:
        raise PromotionError("factory-acceptance marker is malformed")
    try:
        raw = json.loads(body[start:end].strip())
    except json.JSONDecodeError as exc:
        raise PromotionError("factory-acceptance JSON is invalid") from exc
    if not isinstance(raw, dict) or set(raw) != {"version", "criteria"}:
        raise PromotionError("factory-acceptance schema is invalid")
    if type(raw.get("version")) is not int or raw["version"] != 1:
        raise PromotionError("factory-acceptance version is invalid")
    rows = raw.get("criteria")
    if not isinstance(rows, list) or not 1 <= len(rows) <= MAX_CRITERIA:
        raise PromotionError("factory-acceptance criteria are invalid")
    result: list[dict[str, str]] = []
    seen: set[str] = set()
    for row in rows:
        if not isinstance(row, dict) or set(row) != {"id", "kind", "target"}:
            raise PromotionError("factory-acceptance criterion schema is invalid")
        criterion_id = row.get("id")
        kind = row.get("kind")
        target = row.get("target")
        if (
            not isinstance(criterion_id, str)
            or re.fullmatch(r"AC-[0-9]{2}", criterion_id) is None
            or criterion_id in seen
            or not isinstance(kind, str)
            or kind not in {"test", "check"}
            or not isinstance(target, str)
        ):
            raise PromotionError("factory-acceptance criterion is invalid")
        if kind == "test":
            match = ACCEPTANCE_TEST_TARGET.fullmatch(target)
            if match is None:
                raise PromotionError("factory-acceptance test target is invalid")
            path = Path(match.group(1))
            if (
                path.is_absolute()
                or ".." in path.parts
                or any(part in ("", ".") for part in path.parts)
            ):
                raise PromotionError("factory-acceptance test path is invalid")
        elif (
            ACCEPTANCE_CHECK_NAME.fullmatch(target) is None
            or target in ACCEPTANCE_FORBIDDEN_CHECKS
        ):
            raise PromotionError("factory-acceptance check target is invalid")
        seen.add(criterion_id)
        result.append({"id": criterion_id, "kind": kind, "target": target})
    return result


def contract_fingerprint(body: str) -> str:
    if not isinstance(body, str) or not 1 <= len(body) <= MAX_BODY:
        raise PromotionError("promotion issue body is invalid")
    for heading in ACCEPTANCE_REQUIRED_HEADINGS:
        _section(body, heading)
    human = _human_criteria(body)
    machine = _machine_criteria(body)
    if set(human) != {item["id"] for item in machine}:
        raise PromotionError("acceptance human/machine criteria do not match")
    canonical = {
        "human": [
            {"id": criterion_id, "description": human[criterion_id]}
            for criterion_id in sorted(human)
        ],
        "machine": [
            {"id": item["id"], "kind": item["kind"], "target": item["target"]}
            for item in sorted(machine, key=lambda item: item["id"])
        ],
    }
    payload = json.dumps(
        canonical,
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")
    return hashlib.sha256(payload).hexdigest()


def _valid_task_key(value: object) -> str:
    if (
        not isinstance(value, str)
        or not 1 <= len(value) <= 32
        or not value.isascii()
        or not value[0].isalpha()
        or not value[0].isupper()
        or any(not (char.isupper() or char.isdigit() or char in "_-") for char in value)
    ):
        raise PromotionError("factory-plan-task key is invalid")
    return value


def _valid_task_owner(value: object) -> str:
    if (
        not isinstance(value, str)
        or not 1 <= len(value) <= 39
        or not value.isascii()
        or value.startswith("-")
        or value.endswith("-")
        or "--" in value
        or any(not (char.isalnum() or char == "-") for char in value)
    ):
        raise PromotionError("factory-plan-task owner is invalid")
    return value


def _valid_task_path(value: object) -> str:
    if (
        not isinstance(value, str)
        or not 1 <= len(value) <= 240
        or value.startswith("/")
        or value.startswith("./")
        or "\\" in value
        or any(char in value for char in ("\n", "\r", "\x00", "*", "?", "[", "]", "{", "}"))
    ):
        raise PromotionError("factory-plan-task path is invalid")
    base = value[:-1] if value.endswith("/") else value
    if not base or any(part in ("", ".", "..") for part in base.split("/")):
        raise PromotionError("factory-plan-task path is invalid")
    return value


def parse_task_marker(body: str) -> dict | None:
    prefix = f"<!-- {TASK_MARKER} "
    suffix = " -->"
    count = body.count(prefix)
    if count == 0:
        return None
    if count != 1:
        raise PromotionError("factory-plan-task marker must be unique")
    start = body.index(prefix) + len(prefix)
    end = body.find(suffix, start)
    if end < 0:
        raise PromotionError("factory-plan-task marker is malformed")
    try:
        raw = json.loads(body[start:end].strip())
    except json.JSONDecodeError as exc:
        raise PromotionError("factory-plan-task JSON is invalid") from exc
    required = {
        "version", "epic", "task_key", "order", "owner",
        "roles", "depends_on", "paths",
    }
    if (
        not isinstance(raw, dict)
        or set(raw) != required
        or type(raw.get("version")) is not int
        or raw["version"] != 1
    ):
        raise PromotionError("factory-plan-task schema is invalid")
    _valid_task_key(raw["task_key"])
    _valid_task_owner(raw["owner"])
    if (
        isinstance(raw["epic"], bool)
        or not isinstance(raw["epic"], int)
        or raw["epic"] < 1
        or isinstance(raw["order"], bool)
        or not isinstance(raw["order"], int)
        or raw["order"] < 1
    ):
        raise PromotionError("factory-plan-task epic/order are invalid")
    if (
        not isinstance(raw["roles"], list)
        or not raw["roles"]
        or not all(isinstance(role, str) and role for role in raw["roles"])
        or not isinstance(raw["depends_on"], list)
        or not all(
            isinstance(number, int) and not isinstance(number, bool) and number > 0
            for number in raw["depends_on"]
        )
        or not isinstance(raw["paths"], list)
        or not raw["paths"]
    ):
        raise PromotionError("factory-plan-task lists are invalid")
    raw["paths"] = [_valid_task_path(path) for path in raw["paths"]]
    return raw


def task_marker_fingerprint(marker: dict | None) -> str | None:
    if marker is None:
        return None
    payload = json.dumps(
        marker,
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")
    return hashlib.sha256(payload).hexdigest()


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
    if any(path.iterdir()):
        raise PromotionError("promotion runtime must be empty at start")
    return path


def valid_reservation_payload(value: object) -> bool:
    """Replica el schema cerrado de reservas Factory v1/v2/v3."""
    if not isinstance(value, dict):
        return False
    base = {"version", "owner", "reservation_id", "branch", "active", "reason"}
    version = value.get("version")
    if type(version) is not int:
        return False
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


def live_reservation_authority(
    issue: dict,
    comments: list[dict],
    reservation_id: str,
    branch: str,
    expected_owner: str,
) -> dict:
    _validate_promotion_issue(issue)
    marker = active_reservation(
        comments,
        reservation_id,
        branch,
        expected_owner,
    )
    version = marker.get("version")
    if version not in (2, 3):
        raise PromotionError("legacy reservation cannot authorize promotion")
    body = issue.get("body")
    if not isinstance(body, str):
        raise PromotionError("promotion issue body is missing")
    current_acceptance = contract_fingerprint(body)
    if marker.get("acceptance_sha256") != current_acceptance:
        raise PromotionError("reservation acceptance fingerprint is stale")
    if version == 3:
        current_task = task_marker_fingerprint(parse_task_marker(body))
        if current_task is None or marker.get("task_marker_sha256") != current_task:
            raise PromotionError("reservation task fingerprint is stale")
    return marker


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
    planned_main_sha: str,
) -> dict:
    if not isinstance(planned_main_sha, str) or SHA_RE.fullmatch(planned_main_sha) is None:
        raise PromotionError("planned main SHA is invalid")
    number, title, source_sha, base_sha = _validate_source_pr(pr, repository)
    issue_number = _validate_promotion_issue(issue)
    target_branch = f"trabajo/issue-{issue_number}"
    live_reservation_authority(
        issue,
        comments,
        reservation_id,
        target_branch,
        expected_owner,
    )
    version = _promotion_version(current_version, latest_tag)
    return {
        "source_pr": number,
        "source_sha": source_sha,
        "base_sha": base_sha,
        "planned_source_sha": source_sha,
        "planned_base_sha": base_sha,
        "planned_main_sha": planned_main_sha,
        "source_title": title,
        "source_files": source_files(files),
        "target_branch": target_branch,
        "version": version,
        "pr_title": f"chore(deps): promote bot PR #{number} (V {version})",
        "issue_number": issue_number,
        "reservation_id": reservation_id,
        "repository": repository,
        "expected_owner": expected_owner,
    }


def validate_live_authority(
    plan: dict,
    issue: dict,
    comments: list[dict],
    source_pr: dict,
    current_main_sha: str,
    expected_owner: str,
    repository: str,
) -> None:
    if not isinstance(plan, dict):
        raise PromotionError("promotion plan is invalid")
    if plan.get("repository") != repository or plan.get("expected_owner") != expected_owner:
        raise PromotionError("promotion plan actor/repository identity changed")
    issue_number = _validate_promotion_issue(issue)
    if issue_number != plan.get("issue_number"):
        raise PromotionError("promotion issue identity changed")
    branch = plan.get("target_branch")
    reservation_id = plan.get("reservation_id")
    if not isinstance(branch, str) or not isinstance(reservation_id, str):
        raise PromotionError("promotion plan reservation identity is invalid")
    live_reservation_authority(
        issue,
        comments,
        reservation_id,
        branch,
        expected_owner,
    )
    planned_main_sha = plan.get("planned_main_sha")
    if (
        not isinstance(current_main_sha, str)
        or SHA_RE.fullmatch(current_main_sha) is None
        or current_main_sha != planned_main_sha
    ):
        raise PromotionError("main SHA drifted after promotion plan")
    number, _title, source_sha, base_sha = _validate_source_pr(source_pr, repository)
    if number != plan.get("source_pr"):
        raise PromotionError("source PR number changed")
    if source_sha != plan.get("planned_source_sha"):
        raise PromotionError("source PR head SHA drifted after promotion plan")
    if base_sha != plan.get("planned_base_sha"):
        raise PromotionError("source PR base SHA drifted after promotion plan")


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
        args.planned_main_sha,
    )


def _materialize_from_fixed_inputs() -> dict:
    plan = load_json(PLAN_JSON)
    materialize(
        plan.get("version"),
        plan.get("issue_number"),
        plan.get("source_pr"),
        plan.get("source_title"),
        plan.get("planned_source_sha"),
        plan.get("planned_main_sha"),
    )
    return plan


def _validate_live_from_fixed_inputs(args: argparse.Namespace) -> None:
    validate_live_authority(
        load_json(PLAN_JSON),
        load_json(PROMOTION_ISSUE_JSON),
        load_json(PROMOTION_COMMENTS_JSON),
        load_json(SOURCE_PR_JSON),
        args.current_main_sha,
        args.expected_owner,
        args.repository,
    )


def main() -> int:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="command", required=True)

    plan = sub.add_parser("plan")
    plan.add_argument("--reservation-id", required=True)
    plan.add_argument("--latest-tag", required=True)
    plan.add_argument("--repository", required=True)
    plan.add_argument("--expected-owner", required=True)
    plan.add_argument("--planned-main-sha", required=True)

    sub.add_parser("verify-files")
    sub.add_parser("prepare-runtime")

    reservation = sub.add_parser("validate-reservation")
    reservation.add_argument("--reservation-id", required=True)
    reservation.add_argument("--branch", required=True)
    reservation.add_argument("--expected-owner", required=True)

    live = sub.add_parser("validate-live")
    live.add_argument("--current-main-sha", required=True)
    live.add_argument("--repository", required=True)
    live.add_argument("--expected-owner", required=True)

    sub.add_parser("materialize")

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
            live_reservation_authority(
                load_json(PROMOTION_ISSUE_JSON),
                load_json(PROMOTION_COMMENTS_JSON),
                args.reservation_id,
                args.branch,
                args.expected_owner,
            )
            print("dependency_pr_promotion: reservation authority verified")
        elif args.command == "validate-live":
            _validate_live_from_fixed_inputs(args)
            print("dependency_pr_promotion: live authority and identity verified")
        else:
            plan = _materialize_from_fixed_inputs()
            print(f"dependency_pr_promotion: materialized V{plan['version']}")
    except (PromotionError, OSError, json.JSONDecodeError) as exc:
        print(f"::error::dependency_pr_promotion: {exc}")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
