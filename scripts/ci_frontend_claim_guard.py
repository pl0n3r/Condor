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

TASK = re.compile(r"<!-- factory-plan-task (\{[^\n]+\}) -->")
LEASE = re.compile(r"<!-- condor-reserva (\{[^\n]+\}) -->")
RESERVATION_ID = re.compile(r"<!-- condor-reserva-id: ([a-f0-9-]{36}) -->")
BRANCH = re.compile(r"trabajo/issue-([1-9][0-9]*)\Z")
SHA = re.compile(r"[a-f0-9]{40}\Z")
UUID = re.compile(r"[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\Z")


def _single_json_marker(body: Any, pattern: re.Pattern[str]) -> dict[str, Any] | None:
    if not isinstance(body, str):
        return None
    matches = pattern.findall(body)
    if len(matches) != 1:
        return None
    try:
        value = json.loads(matches[0])
    except (ValueError, TypeError):
        return None
    return value if isinstance(value, dict) else None


def authorize(
    pr: dict[str, Any], issue: dict[str, Any], comments: list[dict[str, Any]],
    changed_paths: list[str], repository: str, branch: str, head_sha: str,
) -> bool:
    """Pure authorization decision. Missing/ambiguous/stale metadata denies."""
    if not isinstance(repository, str) or re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repository) is None:
        return False
    branch_match = BRANCH.fullmatch(branch) if isinstance(branch, str) else None
    if not branch_match or not isinstance(head_sha, str) or not SHA.fullmatch(head_sha):
        return False
    if not isinstance(pr, dict) or not isinstance(issue, dict) or not isinstance(comments, list):
        return False
    if pr.get("state") != "open" or pr.get("draft") is None:
        return False
    if pr.get("base", {}).get("ref") != "main":
        return False
    head = pr.get("head")
    if not isinstance(head, dict) or head.get("ref") != branch or head.get("sha") != head_sha:
        return False
    if not isinstance(head.get("repo"), dict) or head["repo"].get("full_name") != repository:
        return False
    user = pr.get("user")
    if not isinstance(user, dict) or not isinstance(user.get("login"), str):
        return False
    if issue.get("number") != int(branch_match.group(1)) or issue.get("state") != "open":
        return False
    if not isinstance(changed_paths, list) or not changed_paths or len(changed_paths) != len(set(changed_paths)):
        return False
    if any(not isinstance(p, str) or not p.startswith("public/build/") or ".." in p.split("/")
           for p in changed_paths):
        return False
    body = pr.get("body")
    refs = RESERVATION_ID.findall(body) if isinstance(body, str) else []
    if len(refs) != 1 or not UUID.fullmatch(refs[0]):
        return False
    task = _single_json_marker(issue.get("body"), TASK)
    if task is None or task.get("version") != 1 or task.get("owner") != user["login"]:
        return False
    task_paths = task.get("paths")
    task_deps = task.get("depends_on")
    if not isinstance(task_paths, list) or not all(isinstance(x, str) for x in task_paths):
        return False
    if not isinstance(task_deps, list) or not all(isinstance(x, int) and not isinstance(x, bool) for x in task_deps):
        return False
    # The latest *real* bot marker is authoritative; never trust copied text.
    leases: list[dict[str, Any]] = []
    for comment in comments:
        if not isinstance(comment, dict):
            return False
        author = comment.get("user")
        if not isinstance(author, dict) or author.get("login") != "github-actions[bot]":
            continue
        raw = comment.get("body")
        if not isinstance(raw, str) or "<!-- condor-reserva " not in raw:
            continue
        parsed = _single_json_marker(raw, LEASE)
        if parsed is None:
            return False
        leases.append(parsed)
    if not leases:
        return False
    lease = leases[-1]
    if (lease.get("version") != 3 or lease.get("active") is not True
        or lease.get("branch") != branch or lease.get("owner") != user["login"]
        or lease.get("reservation_id") != refs[0]
        or not isinstance(lease.get("acceptance_sha256"), str)
        or not SHA256.fullmatch(lease["acceptance_sha256"])
        or not isinstance(lease.get("task_marker_sha256"), str)
        or not SHA256.fullmatch(lease["task_marker_sha256"])
        or lease.get("task_depends_on") != task_deps):
        return False
    claims = lease.get("task_paths")
    if not isinstance(claims, list) or sorted(claims) != sorted(task_paths):
        return False
    # The lease must claim every generated file (an exact path or directory),
    # not merely claim a sibling such as frontend/admin/AdminApp.tsx.
    return all(any(p == claim or (claim.endswith("/") and p.startswith(claim))
                   for claim in claims) for p in changed_paths)


SHA256 = re.compile(r"[a-f0-9]{64}\Z")


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
