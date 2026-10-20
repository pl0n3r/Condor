"""Contract security tests for Condor #715: build may not bypass V3 claims."""
from __future__ import annotations

import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "scripts/ci_frontend_claim_guard.py"
spec = importlib.util.spec_from_file_location("ci_frontend_claim_guard", SCRIPT)
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)

REPO = "pl0n3r/Condor"
BRANCH = "trabajo/issue-673"
SHA = "a" * 40
ID = "11111111-2222-4333-8444-555555555555"
TASK = {
    "version": 1, "epic": 639, "task_key": "CONDOR_SAAS_CC_UI_V1",
    "order": 2, "owner": "pl0n3r", "roles": ["frontend", "qa"],
    "depends_on": [640], "paths": ["frontend/admin/PlatformOwnerApp.tsx", "public/build/admin.js"],
}
LEASE = {
    "version": 3, "active": True, "branch": BRANCH, "owner": "pl0n3r",
    "reason": "tomar", "reservation_id": ID, "task_depends_on": [640],
    "task_paths": TASK["paths"], "acceptance_sha256": "b" * 64,
    "task_marker_sha256": "c" * 64,
}


def context():
    import json
    pr = {
        "state": "open", "draft": True, "body": "<!-- condor-reserva-id: " + ID + " -->",
        "head": {"ref": BRANCH, "sha": SHA, "repo": {"name": "Condor", "owner": {"login": "pl0n3r"}}},
        "base": {"ref": "main"}, "user": {"login": "pl0n3r"},
    }
    issue = {"number": 673, "state": "open",
             "body": "<!-- factory-plan-task " + json.dumps(TASK) + " -->"}
    bot = {"user": {"login": "github-actions[bot]"},
           "body": "<!-- condor-reserva " + json.dumps(LEASE) + " -->"}
    return pr, issue, [bot]


class FrontendArtifactClaimsTests(unittest.TestCase):
    def authorized(self, files=None, ctx=None):
        pr, issue, comments = ctx or context()
        return guard.authorize(pr, issue, comments,
                               files if files is not None else ["public/build/admin.js"],
                               REPO, BRANCH, SHA)

    def test_denied_staged_bundle_fails_reproducibility_gate(self):
        workflow = (SCRIPT.parents[0] / ".github" / "workflows" / "ci.yml").read_text()
        self.assertIn("run: git diff HEAD --exit-code -- public/build", workflow)
        self.assertIn("if ! python3 scripts/ci_frontend_claim_guard.py; then", workflow)
        self.assertIn("if: always()", workflow)
        # Git diff without HEAD checks only the unstaged worktree, not the
        # index.  Reproduce the exact denied-V3 path in a disposable repo.
        with tempfile.TemporaryDirectory() as folder:
            repo = Path(folder)
            subprocess.run(["git", "init", "-q"], cwd=repo, check=True)
            bundle = repo / "public" / "build" / "admin.js"
            bundle.parent.mkdir(parents=True)
            bundle.write_text("original")
            subprocess.run(["git", "add", "public/build/admin.js"], cwd=repo, check=True)
            subprocess.run(
                ["git", "-c", "user.name=CI Fixture",
                 "-c", "user.email=ci-fixture@example.invalid",
                 "commit", "-qm", "fixture"],
                cwd=repo, check=True,
            )
            self.assertEqual(
                subprocess.run(
                    ["git", "diff", "HEAD", "--exit-code", "--", "public/build"],
                    cwd=repo, capture_output=True,
                ).returncode, 0,
            )
            bundle.write_text("modified by Vite")
            subprocess.run(["git", "add", "-f", "public/build/admin.js"], cwd=repo, check=True)
            self.assertEqual(
                subprocess.run(
                    ["git", "diff", "--exit-code", "--", "public/build"],
                    cwd=repo, capture_output=True,
                ).returncode, 0,
                "Old gate would falsely approve a staged and unauthorized bundle",
            )
            self.assertEqual(
                subprocess.run(
                    ["git", "diff", "HEAD", "--exit-code", "--", "public/build"],
                    cwd=repo, capture_output=True,
                ).returncode, 1,
                "New gate must reject the unauthorized staged bundle",
            )

    def test_unclaimed_bundle_never_pushes(self):
        pr, issue, comments = context()
        import json
        TASK["paths"].remove("public/build/admin.js")
        try:
            issue["body"] = "<!-- factory-plan-task " + json.dumps(TASK) + " -->"
            comments[0]["body"] = "<!-- condor-reserva " + json.dumps({
                **LEASE, "task_paths": TASK["paths"]}) + " -->"
            self.assertFalse(self.authorized(ctx=(pr, issue, comments)))
        finally:
            TASK["paths"].append("public/build/admin.js")
        for path in ("public/build/admin.css", "public/build/chunks/runtime.js",
                     "scripts/private.py", "public/build/../secrets"):
            self.assertFalse(self.authorized(files=["public/build/admin.js", path]))
        self.assertFalse(self.authorized(files=[]))
        self.assertFalse(self.authorized(files=["public/build/admin.js"] * 2))

    def test_verified_claim_can_reconcile(self):
        pr, issue, comments = context()
        self.assertTrue(self.authorized())
        pr["head"]["sha"] = "d" * 40  # HEAD moved, cannot push.
        self.assertFalse(self.authorized(ctx=(pr, issue, comments)))
        pr["head"]["sha"] = SHA
        pr["head"]["ref"] = "trabajo/issue-691"  # wrong Issue/branch.
        self.assertFalse(self.authorized(ctx=(pr, issue, comments)))
        pr["head"]["ref"] = BRANCH
        self.assertFalse(self.authorized(ctx=(pr, issue, [])))  # no authority
        fake = {"user": {"login": "pl0n3r"}, "body": comments[0]["body"]}
        self.assertFalse(self.authorized(ctx=(pr, issue, [fake])))
        old = {"user": {"login": "github-actions[bot]"},
               "body": comments[0]["body"]}
        import json
        invalid = {"user": {"login": "github-actions[bot]"},
                   "body": "<!-- condor-reserva " + json.dumps({
                       **LEASE, "active": False}) + " -->"}
        self.assertFalse(self.authorized(ctx=(pr, issue, [old, invalid])))
        self.assertFalse(self.authorized(ctx=(pr, issue, [old, {"user": {"login": "github-actions[bot]"}, "body": "<!-- condor-reserva broken -->"}])))
        pr["body"] = "<!-- condor-reserva-id: 00000000-0000-4000-8000-000000000000 -->"
        self.assertFalse(self.authorized(ctx=(pr, issue, comments)))
        pr["body"] = "<!-- condor-reserva-id: " + ID + " -->"
        issue["state"] = "closed"
        self.assertFalse(self.authorized(ctx=(pr, issue, comments)))


if __name__ == "__main__":
    unittest.main()
