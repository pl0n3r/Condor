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
MACHINE = {
    "version": 1,
    "criteria": [
        {"id": "AC-01", "kind": "test",
         "target": "tests/test_ci_frontend_claims.py::FrontendArtifactClaimsTests::test_unclaimed_bundle_never_pushes"},
        {"id": "AC-02", "kind": "test",
         "target": "tests/test_ci_frontend_claims.py::FrontendArtifactClaimsTests::test_verified_claim_can_reconcile"},
    ],
}


def issue_body(task=TASK):
    """A full, valid Factory acceptance contract with canonical task marker."""
    import json
    return (
        "### Contexto\nContrato V3 aislado\n"
        "### Alcance\nAutorizar solo artefactos exactos\n"
        "### Fuera de alcance\nSin deploy\n"
        "### Criterios de aceptación\n"
        "- [ ] [AC-01] No push without exact claims\n"
        "- [ ] [AC-02] Verify bot lease and its fingerprints\n"
        "### Contrato ejecutable\n"
        "<!-- factory-acceptance " + json.dumps(MACHINE) + " -->\n"
        "### Rutas reclamadas\n"
        "- public/build/admin.js\n"
        "<!-- factory-plan-task " + json.dumps(task) + " -->"
    )


def bot_comment(lease):
    import json
    return {"user": {"login": "github-actions[bot]"},
            "body": "<!-- condor-reserva " + json.dumps(lease) + " -->"}


def context(task=None):
    from copy import deepcopy
    scoped = deepcopy(TASK if task is None else task)
    body = issue_body(scoped)
    lease = {
        "version": 3, "active": True, "branch": BRANCH, "owner": "pl0n3r",
        "reason": "tomar", "reservation_id": ID,
        "task_depends_on": scoped["depends_on"],
        "task_paths": scoped["paths"],
        "acceptance_sha256": guard.contract_fingerprint(body),
        "task_marker_sha256": guard.task_marker_fingerprint(scoped),
    }
    pr = {
        "state": "open", "draft": True, "body": "<!-- condor-reserva-id: " + ID + " -->",
        "head": {"ref": BRANCH, "sha": SHA, "repo": {"name": "Condor", "owner": {"login": "pl0n3r"}}},
        "base": {"ref": "main"}, "user": {"login": "pl0n3r"},
    }
    issue = {"number": 673, "state": "open", "body": body}
    return pr, issue, [bot_comment(lease)]


class FrontendArtifactClaimsTests(unittest.TestCase):
    def authorized(self, files=None, ctx=None):
        pr, issue, comments = ctx or context()
        return guard.authorize(pr, issue, comments,
                               files if files is not None else ["public/build/admin.js"],
                               REPO, BRANCH, SHA)

    def test_denied_staged_bundle_fails_reproducibility_gate(self):
        workflow = (SCRIPT.parents[1] / ".github" / "workflows" / "ci.yml").read_text()
        self.assertIn("run: git diff HEAD --exit-code -- public/build", workflow)
        self.assertIn("if ! python3 scripts/ci_frontend_claim_guard.py; then", workflow)
        self.assertRegex(
            workflow,
            r"- name: Conservar build frontend para reconciliación\s+"
            r"if: always\(\)\s+"
            r"uses: actions/upload-artifact@",
        )
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

    def test_run_guard_rejects_bad_environment_and_api_snapshot(self):
        """Exercise the executable wrapper, not merely the pure authorize()."""
        import os
        from unittest.mock import patch

        pr, issue, comments = context()
        env = {
            "GITHUB_REPOSITORY": REPO,
            "HEAD_REF": BRANCH,
            "EXPECTED_HEAD_SHA": SHA,
            "PR_NUMBER": "716",
        }
        def git_run(command, **_kwargs):
            if command[:3] == ["git", "rev-parse", "HEAD"]:
                return subprocess.CompletedProcess(command, 0, stdout=SHA + "\n")
            if command[:3] == ["git", "diff", "--cached"]:
                return subprocess.CompletedProcess(command, 0, stdout=b"public/build/admin.js\x00")
            raise AssertionError("Unexpected git call")

        with patch.dict(os.environ, env), patch.object(guard.subprocess, "run",
                side_effect=git_run), patch.object(guard, "gh_api",
                side_effect=[pr, issue, [comments]]) as api:
            self.assertEqual(guard.run_guard(), 0)
            self.assertEqual(api.call_count, 3)

        for wrong in ({"PR_NUMBER": "oops"}, {"EXPECTED_HEAD_SHA": "b" * 40},
                      {"HEAD_REF": "trabajo/issue-691"}):
            with patch.dict(os.environ, {**env, **wrong}), \
                 patch.object(guard.subprocess, "run", side_effect=git_run), \
                 patch.object(guard, "gh_api") as api:
                self.assertEqual(guard.run_guard(), 1)
                api.assert_not_called()

        with patch.dict(os.environ, env), \
             patch.object(guard.subprocess, "run", side_effect=git_run), \
             patch.object(guard, "gh_api", side_effect=[pr, issue, {"bad": "page"}]):
            self.assertEqual(guard.run_guard(), 1)

    def test_unclaimed_bundle_never_pushes(self):
        from copy import deepcopy
        no_bundle = deepcopy(TASK)
        no_bundle["paths"].remove("public/build/admin.js")
        pr, issue, comments = context(no_bundle)
        self.assertFalse(self.authorized(ctx=(pr, issue, comments)))
        # The guard does not infer V3 coverage from adjacent paths, and
        # cannot accept an entire directory in place of concrete claims.
        directory = deepcopy(TASK)
        directory["paths"][-1] = "public/build/"
        self.assertFalse(self.authorized(ctx=context(directory)))
        for path in ("public/build/admin.css", "public/build/chunks/runtime.js",
                     "scripts/private.py", "public/build/../secrets"):
            self.assertFalse(self.authorized(files=["public/build/admin.js", path]))
        self.assertFalse(self.authorized(files=[]))
        self.assertFalse(self.authorized(files=["public/build/admin.js"] * 2))

    def test_verified_claim_can_reconcile(self):
        import json
        pr, issue, comments = context()
        self.assertTrue(self.authorized())
        # Hashes must represent the live *canonical* task and acceptance.
        # A SHA256-shaped but forged digest must not confer authority.
        lease = json.loads(guard.LEASE.findall(comments[0]["body"])[0])
        self.assertFalse(self.authorized(ctx=(pr, issue, [bot_comment({
            **lease, "acceptance_sha256": "b" * 64})])))
        self.assertFalse(self.authorized(ctx=(pr, issue, [bot_comment({
            **lease, "task_marker_sha256": "c" * 64})])))
        # Same reservation ID and claims, but altered current Issue AC:
        # the previously valid bot marker becomes stale.
        changed_ac = issue_body().replace(
            "No push without exact claims", "No push without exact claims or races")
        self.assertFalse(self.authorized(ctx=(pr, {**issue, "body": changed_ac}, comments)))
        # Changing an unrelated task field is still marker fingerprint drift.
        from copy import deepcopy
        altered_task = deepcopy(TASK)
        altered_task["task_key"] = "CONDOR_SAAS_CC_UI_V2"
        self.assertFalse(self.authorized(
            ctx=(pr, {**issue, "body": issue_body(altered_task)}, comments)))
        # Same claim but directory authority is never accepted.
        folder_task = deepcopy(TASK)
        folder_task["paths"][-1] = "public/build/"
        self.assertFalse(self.authorized(ctx=context(folder_task)))
        # Duplicate/ambiguous markers must deny rather than permit.
        self.assertFalse(self.authorized(
            ctx=(pr, {**issue, "body": issue["body"] + "\n" +
                 guard.TASK.search(issue["body"]).group(0)}, comments)))
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
        invalid = bot_comment({**lease, "active": False})
        self.assertFalse(self.authorized(ctx=(pr, issue, [old, invalid])))
        self.assertFalse(self.authorized(
            ctx=(pr, issue, [old, {"user": {"login": "github-actions[bot]"},
                                   "body": "<!-- condor-reserva broken -->"}])))
        pr["body"] = "<!-- condor-reserva-id: 00000000-0000-4000-8000-000000000000 -->"
        self.assertFalse(self.authorized(ctx=(pr, issue, comments)))
        pr["body"] = "<!-- condor-reserva-id: " + ID + " -->"
        issue["state"] = "closed"
        self.assertFalse(self.authorized(ctx=(pr, issue, comments)))


if __name__ == "__main__":
    unittest.main()
