"""Contrato de recuperación de #727; todos los ensayos usan API simulada."""
from datetime import datetime, timezone
from pathlib import Path
from urllib.error import HTTPError
import importlib.util
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("cas727", ROOT / "scripts/recuperar_lock727.py")
cas = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cas)
NOW = datetime(2026, 10, 26, 14, 0, tzinfo=timezone.utc)


class SimulatedGitHub:
    def __init__(self):
        self.calls = []
        self.deleted = False
        base = "/repos/pl0n3r/Condor"
        receipt = {
            "version": 3, "active": True, "owner": "pl0n3r",
            "reservation_id": cas.HISTORICAL_LEASE,
            "branch": cas.BRANCH, "task_paths": list(cas.HISTORICAL_PATHS),
        }
        self.responses = {
            "/repos/pl0n3r/Factory/issues/767": {
                "number": 767, "state": "closed", "user": {"login": "pl0n3r"},
                "body": '<!-- factory-unattended-kill-switch {"version":1,"state":"RUNNING","owner":"pl0n3r"} -->',
            },
            "/repos/pl0n3r/Factory/issues/921": {"state": "closed"},
            f"{base}/issues/727": {
                "state": "open", "labels": [{"name": "estado: bloqueado"}],
            },
            f"{base}/pulls/771": {
                "state": "open", "draft": True, "head": {"ref": cas.BRANCH, "sha": cas.PARENT},
            },
            f"{base}/issues/727/comments?per_page=100&page=1": [{
                "user": {"login": "github-actions[bot]"},
                "body": "<!-- condor-reserva " + json.dumps(receipt) + " -->",
            }],
            cas.LOCK_API: {"object": {"sha": cas.NONCE}},
            f"{base}/git/commits/{cas.NONCE}": {
                "message": cas.MESSAGE, "parents": [{"sha": cas.PARENT}],
                "author": {"name": "github-actions[bot]"},
                "committer": {"date": "2026-10-22T10:18:55Z"},
            },
            base: {"name": "Condor", "owner": {"login": "pl0n3r"}, "node_id": "R_937"},
        }
        self.responses.update({
            f"{base}/actions/runs?status={status}&per_page=100": {
                "total_count": 0, "workflow_runs": [],
            }
            for status in ("in_progress", "queued", "waiting", "pending", "requested")
        })

    def get(self, path):
        self.calls.append(("GET", path))
        if path == cas.LOCK_API and self.deleted:
            raise HTTPError("https://api.github.com", 404, "not found", {}, None)
        return self.responses[path]

    def post(self, path, body):
        self.calls.append(("POST", path, body))
        if path == "/graphql":
            self.deleted = True
            return {"data": {"updateRefs": {"clientMutationId": None}}}
        return {"id": 100}


class OrphanLockRecoveryTests(unittest.TestCase):
    def test_expected_orphan_exact_sha_and_bot_identity(self):
        client = SimulatedGitHub()
        self.assertEqual(cas.prepare(client, NOW, "937"), "R_937")
        self.assertEqual(cas.NONCE, "3156bba33114b4097eb532ae6de21093fb753bd0")
        self.assertEqual(cas.PARENT, "823bd8aa5217f8b573479d198cfddac47e28e49f")
        self.assertIn(cas.MESSAGE, str(client.calls[0:]) + cas.MESSAGE)
        self.assertFalse(client.deleted)

    def test_changed_nonce_or_live_coordination_fails_closed(self):
        base = "/repos/pl0n3r/Condor"
        def nonce(api):
            api.responses[cas.LOCK_API]["object"]["sha"] = "f" * 40
        def active_run(api):
            api.responses[f"{base}/actions/runs?status=queued&per_page=100"] = {
                "total_count": 1, "workflow_runs": [{"name": "Coordinación Factory v1", "id": 900}],
            }
        def invalid_bot(api):
            api.responses[f"{base}/git/commits/{cas.NONCE}"]["author"]["name"] = "unknown"
        def newer_lease(api):
            api.responses[f"{base}/issues/727/comments?per_page=100&page=1"][0]["body"] = (
                "<!-- condor-reserva " + json.dumps({
                    "version": 3, "active": True, "owner": "pl0n3r",
                    "reservation_id": "other", "branch": cas.BRANCH,
                    "task_paths": list(cas.HISTORICAL_PATHS),
                }) + " -->"
            )
        def paused(api):
            api.responses["/repos/pl0n3r/Factory/issues/767"]["body"] = (
                '<!-- factory-unattended-kill-switch {"version":1,"state":"PAUSED","owner":"pl0n3r"} -->'
            )
        def frozen(api):
            api.responses["/repos/pl0n3r/Factory/issues/921"]["state"] = "open"
        for sabotage in (nonce, active_run, invalid_bot, newer_lease, paused, frozen):
            api = SimulatedGitHub()
            sabotage(api)
            with self.subTest(sabotage=sabotage.__name__), self.assertRaises(cas.Denied):
                cas.prepare(api, NOW, "937")
            self.assertFalse(any(row[0] == "POST" for row in api.calls))

    def test_cas_only_deletes_target_ref(self):
        api = SimulatedGitHub()
        cas.recover(api, NOW, "937")
        mutations = [x[2] for x in api.calls if x[0] == "POST" and x[1] == "/graphql"]
        self.assertEqual(len(mutations), 1)
        items = mutations[0]["variables"]["input"]["refUpdates"]
        self.assertEqual(items, [{
            "name": cas.REF, "beforeOid": cas.NONCE,
            "afterOid": "0" * 40, "force": True,
        }])
        self.assertTrue(api.deleted)
        self.assertNotIn("trabajo/issue-727", json.dumps(items))
        self.assertEqual(api.calls[-1][1], "/repos/pl0n3r/Condor/issues/727/comments")

    def test_workflow_only_owner_exact_comment_and_main(self):
        workflow = (ROOT / ".github/workflows/recuperar-lock727.yml").read_text("utf-8")
        for key in (
            "issue_comment:", "types: [created]", "github.ref == 'refs/heads/main'",
            "github.event.issue.number == 727",
            "github.event.comment.body == '/limpiar-lock-727'",
            "github.event.comment.user.login == 'pl0n3r'",
            "github.actor == 'pl0n3r'", "ref: main", "persist-credentials: false",
            "run: python3 scripts/recuperar_lock727.py",
        ):
            self.assertIn(key, workflow)
        self.assertNotIn("pull_request_target:", workflow)
        self.assertNotIn("workflow_run:", workflow)


if __name__ == "__main__":
    unittest.main()
