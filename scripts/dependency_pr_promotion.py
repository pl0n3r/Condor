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
RESERVATION_RE = re.compile(r"<!--\s*condor-reserva\s+({.*?})\s*-->")
ALLOWED_EXACT = {
    "composer.json", "composer.lock", "package.json", "package-lock.json",
    "npm-shrinkwrap.json", "pnpm-lock.yaml", "yarn.lock",
}
WORKFLOW_RE = re.compile(r"^\.github/workflows/[^/]+\.(?:yml|yaml)$")


class PromotionError(RuntimeError):
    pass


def semver(value: str) -> tuple[int, int, int]:
    match = re.fullmatch(r"(\d+)\.(\d+)\.(\d+)", value)
    if not match:
        raise PromotionError(f"invalid SemVer: {value!r}")
    return tuple(map(int, match.groups()))


def read_version(path: Path) -> str:
    matches = VERSION_RE.findall(path.read_text(encoding="utf-8"))
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


def active_reservation(comments: list[dict], reservation_id: str, branch: str) -> dict:
    try:
        uuid.UUID(reservation_id)
    except ValueError as exc:
        raise PromotionError("reservation_id must be a UUID") from exc
    found: dict | None = None
    for comment in comments:
        body = comment.get("body")
        if not isinstance(body, str):
            continue
        for match in RESERVATION_RE.finditer(body):
            try:
                payload = json.loads(match.group(1))
            except json.JSONDecodeError:
                continue
            if payload.get("reservation_id") == reservation_id:
                found = payload
    if found is None or found.get("active") is not True:
        raise PromotionError("reservation is not active")
    if found.get("branch") != branch:
        raise PromotionError("reservation branch does not match target branch")
    return found


def build_plan(
    pr: dict,
    files: list[dict],
    issue: dict,
    comments: list[dict],
    reservation_id: str,
    current_version: str,
    latest_tag: str,
    repository: str,
) -> dict:
    if pr.get("state") != "open":
        raise PromotionError("source PR must be open")
    author = ((pr.get("user") or {}).get("login"))
    if author not in BOT_LOGINS:
        raise PromotionError(f"source PR author is not an allowed dependency bot: {author!r}")
    base = pr.get("base") or {}
    head = pr.get("head") or {}
    if base.get("ref") != "main":
        raise PromotionError("source PR must target main")
    if ((base.get("repo") or {}).get("full_name")) != repository:
        raise PromotionError("source PR base repository mismatch")
    if ((head.get("repo") or {}).get("full_name")) != repository:
        raise PromotionError("source PR must originate from the same repository")
    source_sha = head.get("sha")
    base_sha = base.get("sha")
    if not isinstance(source_sha, str) or SHA_RE.fullmatch(source_sha) is None:
        raise PromotionError("source PR head SHA is invalid")
    if not isinstance(base_sha, str) or SHA_RE.fullmatch(base_sha) is None:
        raise PromotionError("source PR base SHA is invalid")
    number = pr.get("number")
    if not isinstance(number, int) or number < 1:
        raise PromotionError("source PR number is invalid")
    title = pr.get("title")
    if not isinstance(title, str) or not title or any(ord(ch) < 32 for ch in title):
        raise PromotionError("source PR title is invalid")

    issue_number = issue.get("number")
    if not isinstance(issue_number, int) or issue_number < 1 or issue.get("state") != "open":
        raise PromotionError("promotion issue must be open")
    labels = {
        item.get("name") for item in issue.get("labels", [])
        if isinstance(item, dict)
    }
    if "estado: reservado" not in labels:
        raise PromotionError("promotion issue must be reserved")

    target_branch = f"trabajo/issue-{issue_number}"
    active_reservation(comments, reservation_id, target_branch)

    current = semver(current_version)
    tag_match = TAG_RE.fullmatch(latest_tag)
    if tag_match is None:
        raise PromotionError("latest tag must be strict vX.Y.Z")
    latest = tuple(map(int, tag_match.groups()))
    if current != latest:
        raise PromotionError("current version must equal latest released tag before promotion")
    version = next_patch(current_version)
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
    root: Path,
    version: str,
    issue_number: int,
    source_pr: int,
    source_title: str,
    source_sha: str,
    main_sha: str,
) -> None:
    version_file = root / "config/version.php"
    previous = read_version(version_file)
    if version != next_patch(previous):
        raise PromotionError("promotion version must be the next patch version")
    text = version_file.read_text(encoding="utf-8")
    updated, count = VERSION_RE.subn(
        lambda match: match.group(0).replace(match.group(1), version),
        text,
    )
    if count != 1:
        raise PromotionError("could not update canonical version")
    version_file.write_text(updated, encoding="utf-8")

    safe_title = source_title.replace("\`", "'")
    readme = f"""# Condor App — Snapshot operativo · Dependency promotion V {version}

> **Candidato objetivo:** V{version} · Issue #{issue_number} · promoción canónica del PR automático #{source_pr}.
>
> **Base de promoción:** V{previous} · \`main@{main_sha}\` · identidad humana ya publicada y no reutilizable.

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
- PR fuente #{source_pr}: \`{safe_title}\`.
- SHA fuente: \`{source_sha}\`.
- La rama de promoción nace de \`main@{main_sha}\`.
- \`scripts/dependency_pr_promotion.py\` valida bot, reserva, paths e identidad.
"""
    (root / "README.md").write_text(readme, encoding="utf-8")


def load_json(path: str):
    return json.loads(Path(path).read_text(encoding="utf-8"))


def write_outputs(path: str, plan: dict) -> None:
    keys = (
        "source_pr", "source_sha", "base_sha", "source_title", "target_branch",
        "version", "pr_title", "issue_number", "reservation_id",
    )
    with Path(path).open("a", encoding="utf-8") as handle:
        for key in keys:
            handle.write(f"{key}={plan[key]}\n")


def main() -> int:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="command", required=True)

    plan = sub.add_parser("plan")
    plan.add_argument("--pr-json", required=True)
    plan.add_argument("--files-json", required=True)
    plan.add_argument("--issue-json", required=True)
    plan.add_argument("--comments-json", required=True)
    plan.add_argument("--reservation-id", required=True)
    plan.add_argument("--version-file", required=True)
    plan.add_argument("--latest-tag", required=True)
    plan.add_argument("--repository", required=True)
    plan.add_argument("--github-output")

    verify = sub.add_parser("verify-files")
    verify.add_argument("--files-json", required=True)
    verify.add_argument("--actual-files", required=True)

    mat = sub.add_parser("materialize")
    mat.add_argument("--repo-root", default=".")
    mat.add_argument("--version", required=True)
    mat.add_argument("--issue-number", required=True, type=int)
    mat.add_argument("--source-pr", required=True, type=int)
    mat.add_argument("--source-title", required=True)
    mat.add_argument("--source-sha", required=True)
    mat.add_argument("--main-sha", required=True)

    args = parser.parse_args()
    try:
        if args.command == "plan":
            result = build_plan(
                load_json(args.pr_json),
                load_json(args.files_json),
                load_json(args.issue_json),
                load_json(args.comments_json),
                args.reservation_id,
                read_version(Path(args.version_file)),
                args.latest_tag,
                args.repository,
            )
            if args.github_output:
                write_outputs(args.github_output, result)
            print(json.dumps(result, sort_keys=True))
        elif args.command == "verify-files":
            verify_files(
                load_json(args.files_json),
                Path(args.actual_files).read_text(encoding="utf-8").splitlines(),
            )
            print("dependency_pr_promotion: source diff verified")
        else:
            materialize(
                Path(args.repo_root),
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
