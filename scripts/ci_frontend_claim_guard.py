#!/usr/bin/env python3
"""Fail-closed V3 gate for CI-generated frontend assets (Condor #715).

The build still runs without write authority. Only the exact verified bot
reservation may authorize publishing generated files to the matching PR head.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import re
import subprocess
import sys
from typing import Any

# Depend on Condor's existing canonical pure validators, which mirror
# Factory/aceptacion_kit.py and Factory/orquestador_kit.py.
# CI invokes this as a script; unittest imports it as a module.
try:
    from scripts.dependency_pr_promotion import (
        PromotionError, contract_fingerprint, parse_task_marker, task_marker_fingerprint,
    )
except ModuleNotFoundError:
    from dependency_pr_promotion import (
        PromotionError, contract_fingerprint, parse_task_marker, task_marker_fingerprint,
    )

TASK = re.compile(r"<!-- factory-plan-task (\{[^\n]+\}) -->")
ACCEPTANCE = re.compile(r"<!-- factory-acceptance (\{[^\n]+\}) -->")
CRITERION = re.compile(r"^- \[[ xX]\] \[(AC-[0-9]{2})\] (.{1,500})$")
TEST_TARGET = re.compile(
    r"^((?:tests|metricas|seguridad|lecciones|producto)/test_[A-Za-z0-9_/-]+\.py)::"
    r"([A-Za-z_][A-Za-z0-9_]*)::(test_[A-Za-z0-9_]+)$"
)
LEASE = re.compile(r"<!-- condor-reserva (\{[^\n]+\}) -->")
RESERVATION_ID = re.compile(r"<!-- condor-reserva-id: ([a-f0-9-]{36}) -->")
BRANCH = re.compile(r"trabajo/issue-([1-9][0-9]*)\Z")
SHA = re.compile(r"[a-f0-9]{40}\Z")
UUID = re.compile(r"[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\Z")


def _unique_object(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    """Reject duplicate JSON keys rather than silently accepting last-key-wins."""
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("Duplicate marker key")
        result[key] = value
    return result


def _single_json_marker(body: Any, pattern: re.Pattern[str]) -> dict[str, Any] | None:
    if not isinstance(body, str):
        return None
    matches = pattern.findall(body)
    if len(matches) != 1:
        return None
    try:
        value = json.loads(matches[0], object_pairs_hook=_unique_object)
    except (ValueError, TypeError):
        return None
    return value if isinstance(value, dict) else None


def _valid_request(repository: Any, branch: Any, head_sha: Any, paths: Any) -> bool:
    if not isinstance(repository, str) or re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repository) is None:
        return False
    if not isinstance(branch, str) or BRANCH.fullmatch(branch) is None:
        return False
    if not isinstance(head_sha, str) or SHA.fullmatch(head_sha) is None:
        return False
    if not isinstance(paths, list) or not paths:
        return False
    if any(not isinstance(path, str) for path in paths):
        return False
    if len(paths) != len(set(paths)):
        return False
    return all(
        path.startswith("public/build/") and ".." not in path.split("/")
        for path in paths
    )


def _head_repo_matches(head: dict[str, Any], repository: str) -> bool:
    owner, sep, repo_name = repository.partition("/")
    details = head.get("repo")
    if not sep or not isinstance(details, dict):
        return False
    actor = details.get("owner")
    return (isinstance(actor, dict) and actor.get("login") == owner
            and details.get("name") == repo_name)


def _pr_owner(pr: Any, repository: str, branch: str, head_sha: str) -> str | None:
    if not isinstance(pr, dict) or pr.get("state") != "open" or pr.get("draft") is None:
        return None
    base = pr.get("base")
    if not isinstance(base, dict) or base.get("ref") != "main":
        return None
    head = pr.get("head")
    if not isinstance(head, dict) or head.get("ref") != branch or head.get("sha") != head_sha:
        return None
    if not _head_repo_matches(head, repository):
        return None
    author = pr.get("user")
    return author.get("login") if isinstance(author, dict) and isinstance(author.get("login"), str) else None


def _reservation_from_pr(pr: dict[str, Any]) -> str | None:
    body = pr.get("body")
    matches = RESERVATION_ID.findall(body) if isinstance(body, str) else []
    return matches[0] if len(matches) == 1 and UUID.fullmatch(matches[0]) else None


def _issue_contract(
    issue: Any, expected_number: int, author: str,
) -> tuple[str, str, list[int], list[str]] | None:
    if not isinstance(issue, dict) or issue.get("number") != expected_number or issue.get("state") != "open":
        return None
    body = issue.get("body")
    if not isinstance(body, str) or _single_json_marker(body, TASK) is None:
        return None
    try:
        task = parse_task_marker(body)
        acceptance_hash = contract_fingerprint(body)
        task_hash = task_marker_fingerprint(task)
    except (PromotionError, ValueError, TypeError, KeyError):
        return None
    if task is None or task_hash is None or task.get("owner") != author:
        return None
    paths, deps = task.get("paths"), task.get("depends_on")
    if not isinstance(paths, list) or not all(isinstance(p, str) for p in paths):
        return None
    if not isinstance(deps, list) or not all(type(d) is int for d in deps):
        return None
    return acceptance_hash, task_hash, deps, paths


def _trusted_lease(comments: Any) -> dict[str, Any] | None:
    if not isinstance(comments, list):
        return None
    leases: list[dict[str, Any]] = []
    for comment in comments:
        if not isinstance(comment, dict):
            return None
        author = comment.get("user")
        if not isinstance(author, dict) or author.get("login") != "github-actions[bot]":
            continue
        raw = comment.get("body")
        if not isinstance(raw, str) or "<!-- condor-reserva " not in raw:
            continue
        marker = _single_json_marker(raw, LEASE)
        if marker is None:
            return None
        leases.append(marker)
    return leases[-1] if leases else None


def _lease_matches(
    lease: dict[str, Any], branch: str, owner: str, reservation_id: str,
    acceptance_hash: str, task_hash: str, deps: list[int], paths: list[str],
    changed_paths: list[str],
) -> bool:
    if (lease.get("version") != 3 or lease.get("active") is not True
        or lease.get("branch") != branch or lease.get("owner") != owner
        or lease.get("reservation_id") != reservation_id
        or lease.get("acceptance_sha256") != acceptance_hash
        or lease.get("task_marker_sha256") != task_hash
        or lease.get("task_depends_on") != deps):
        return False
    claims = lease.get("task_paths")
    if not isinstance(claims, list) or sorted(claims) != sorted(paths):
        return False
    if any(not isinstance(claim, str) or claim.endswith("/") for claim in claims):
        return False
    return all(path in claims for path in changed_paths)


def authorize(
    pr: dict[str, Any], issue: dict[str, Any], comments: list[dict[str, Any]],
    changed_paths: list[str], repository: str, branch: str, head_sha: str,
) -> bool:
    """Pure authorization. Every missing, ambiguous or stale source denies."""
    if not _valid_request(repository, branch, head_sha, changed_paths):
        return False
    author = _pr_owner(pr, repository, branch, head_sha)
    if author is None:
        return False
    reservation_id = _reservation_from_pr(pr)
    if reservation_id is None:
        return False
    contract = _issue_contract(issue, int(BRANCH.fullmatch(branch).group(1)), author)
    if contract is None:
        return False
    lease = _trusted_lease(comments)
    if lease is None:
        return False
    return _lease_matches(
        lease, branch, author, reservation_id, *contract, changed_paths,
    )

def gh_api(resource: str, *, pages: bool = False) -> Any:
    command = ["gh", "api", resource]
    if pages:
        command += ["--paginate", "--slurp"]
    result = subprocess.run(command, check=True, text=True, capture_output=True, timeout=25)
    return json.loads(result.stdout)


def run_guard() -> int:
    repository = os.environ.get("GITHUB_REPOSITORY", "")
    branch = os.environ.get("HEAD_REF", "")
    head_sha = os.environ.get("EXPECTED_HEAD_SHA", "")
    pr_number = os.environ.get("PR_NUMBER", "")
    if not re.fullmatch(r"[1-9][0-9]*", pr_number):
        return 1
    m = BRANCH.fullmatch(branch)
    if not m or not SHA.fullmatch(head_sha):
        return 1
    # Local checkout must still be the exact SHA that triggered the PR.
    if subprocess.run(["git", "rev-parse", "HEAD"], check=True, text=True,
                      capture_output=True, timeout=5).stdout.strip() != head_sha:
        return 1
    staged = subprocess.run(["git", "diff", "--cached", "--name-only", "-z"],
                            check=True, capture_output=True, timeout=5).stdout
    files = [p.decode("utf-8") for p in staged.split(b"\x00") if p]
    if not files:
        return 0
    pr = gh_api(f"repos/{repository}/pulls/{pr_number}")
    issue = gh_api(f"repos/{repository}/issues/{m.group(1)}")
    pages = gh_api(f"repos/{repository}/issues/{m.group(1)}/comments?per_page=100", pages=True)
    if not isinstance(pages, list) or any(not isinstance(page, list) for page in pages):
        return 1
    comments = [comment for page in pages for comment in page]
    if authorize(pr, issue, comments, files, repository, branch, head_sha):
        return 0
    return 1


if __name__ == "__main__":
    try:
        status = run_guard()
    except (ValueError, OSError, subprocess.SubprocessError, KeyError, UnicodeError, TypeError):
        status = 1
    if status:
        print("D-069 V3: bundle no reclamado o autoridad no verificable; sin autopush.", file=sys.stderr)
    sys.exit(status)
