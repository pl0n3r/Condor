"""Pruebas offline del reparador CAS exacto: sin llamadas a GitHub real."""
from datetime import datetime, timezone
from pathlib import Path
import importlib.util
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "scripts/recuperar_lock724.py"
spec = importlib.util.spec_from_file_location("recuperar_lock724", SOURCE)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

NOW = datetime(2026, 10, 25, 15, 0, tzinfo=timezone.utc)

class Fake:
    def __init__(self):
        self.calls = []
        self.fail_at = None
        self.data = {
            "/repos/pl0n3r/Factory/issues/767": {
                "number": 767, "state": "closed", "user": {"login": "pl0n3r"},
                "body": '<!-- factory-unattended-kill-switch {"version":1,"state":"RUNNING","owner":"pl0n3r"} -->',
            },
            "/repos/pl0n3r/Factory/issues/921": {"state": "closed"},
            "/repos/pl0n3r/Condor/issues/724": {
                "state": "open", "labels": [{"name": "estado: bloqueado"}],
            },
            "/repos/pl0n3r/Condor/pulls/741": {
                "state": "open", "draft": True, "head": {
                    "sha": module.EXPECTED_PARENT, "ref": "trabajo/issue-724",
                },
            },
            "/repos/pl0n3r/Condor/issues/724/comments?per_page=100&page=1": [{
                "user": {"login": module.BOT},
                "body": '<!-- condor-reserva ' + json.dumps({
                    "version": 3, "active": True,
                    "owner": "pl0n3r",
                    "reservation_id": module.EXPECTED_LEASE,
                    "branch": "trabajo/issue-724",
                    "task_paths": module.EXPECTED_PATHS,
                }) + " -->",
            }],
            "/repos/pl0n3r/Condor/git/ref/heads/coordinacion/lock-issue-724": {
                "object": {"sha": module.EXPECTED_NONCE},
            },
            "/repos/pl0n3r/Condor/git/commits/" + module.EXPECTED_NONCE: {
                "message": module.EXPECTED_MESSAGE,
                "author": {"name": module.BOT},
                "committer": {"date": "2026-10-21T23:27:18Z"},
                "parents": [{"sha": module.EXPECTED_PARENT}],
            },
            "/repos/pl0n3r/Condor": {
                "name": "Condor", "owner": {"login": "pl0n3r"}, "node_id": "R_012345",
            },
        }
        for state in ("in_progress", "queued", "waiting", "pending", "requested"):
            self.data[f"/repos/pl0n3r/Condor/actions/runs?status={state}&per_page=100"] = {
                "total_count": 0, "workflow_runs": [],
            }

    def get(self, path):
        self.calls.append(("GET", path))
        return self.data[path]

    def post(self, path, body):
        self.calls.append(("POST", path, body))
        if path == "/graphql":
            # Model GraphQL's CAS delete: after success REST returns 404.
            self.data.pop("/repos/pl0n3r/Condor/git/ref/heads/coordinacion/lock-issue-724")
            from urllib.error import HTTPError
            def missing(_):
                raise HTTPError("https://api.github.com", 404, "not found", {}, None)
            old_get = self.get
            self.get = lambda p: missing(p) if p.endswith("lock-issue-724") else old_get(p)
            return {"data": {"updateRefs": {"clientMutationId": None}}}
        return {"id": 1}


class OrphanLockRecoveryTests(unittest.TestCase):
    def test_expected_orphan_exact_sha_and_bot_identity(self):
        api = Fake()
        self.assertEqual(module.prepare(api, NOW, "1234"), "R_012345")
        self.assertIn(("GET", "/repos/pl0n3r/Condor/git/commits/" + module.EXPECTED_NONCE), api.calls)

    def test_changed_nonce_or_live_coordination_fails_closed(self):
        for kind in ("nonce", "workflow", "bot", "lease", "pause", "freeze"):
            api = Fake()
            if kind == "nonce":
                api.data["/repos/pl0n3r/Condor/git/ref/heads/coordinacion/lock-issue-724"]["object"]["sha"] = "f" * 40
            elif kind == "workflow":
                path = "/repos/pl0n3r/Condor/actions/runs?status=in_progress&per_page=100"
                api.data[path] = {
                    "total_count": 1,
                    "workflow_runs": [{"id": 901, "name": "Coordinación Factory v1"}],
                }
            elif kind == "bot":
                api.data["/repos/pl0n3r/Condor/git/commits/" + module.EXPECTED_NONCE]["author"]["name"] = "attacker"
            elif kind == "lease":
                row = api.data["/repos/pl0n3r/Condor/issues/724/comments?per_page=100&page=1"][0]
                row["body"] = row["body"].replace(module.EXPECTED_LEASE, "f" * 36)
            elif kind == "pause":
                api.data["/repos/pl0n3r/Factory/issues/767"]["body"] = (
                    '<!-- factory-unattended-kill-switch {"version":1,"state":"PAUSED","owner":"pl0n3r"} -->'
                )
            else:
                api.data["/repos/pl0n3r/Factory/issues/921"]["state"] = "open"
            with self.subTest(kind=kind), self.assertRaises(module.Denied):
                module.prepare(api, NOW, "1234")
            self.assertFalse(any(x[0] == "POST" for x in api.calls))

    def test_cas_only_deletes_target_ref(self):
        api = Fake()
        module.recover(api, NOW, "1234")
        changes = [x for x in api.calls if x[0] == "POST" and x[1] == "/graphql"]
        self.assertEqual(len(changes), 1)
        payload = changes[0][2]
        self.assertEqual(len(payload["variables"]["input"]["refUpdates"]), 1)
        update = payload["variables"]["input"]["refUpdates"][0]
        self.assertEqual(update["name"], module.LOCK_REF)
        self.assertEqual(update["beforeOid"], module.EXPECTED_NONCE)
        self.assertEqual(update["afterOid"], module.ZERO)
        self.assertNotIn("trabajo/issue", str(update))

    def test_cas_failure_is_not_a_success_receipt(self):
        class DeniedGraphql(Fake):
            def post(self, path, body):
                if path == "/graphql":
                    self.calls.append(("POST", path, body))
                    return {"errors": [{"message": "CAS rejected"}]}
                return super().post(path, body)
        api = DeniedGraphql()
        with self.assertRaises(module.Denied):
            module.recover(api, NOW, "1234")
        self.assertEqual(
            api.data["/repos/pl0n3r/Condor/git/ref/heads/coordinacion/lock-issue-724"]["object"]["sha"],
            module.EXPECTED_NONCE,
        )
        self.assertFalse(any(
            x[0] == "POST" and x[1].endswith("/comments") for x in api.calls
        ))

    def test_workflow_only_owner_exact_comment_and_main(self):
        s = (ROOT / ".github/workflows/recuperar-lock724.yml").read_text(encoding="utf-8")
        for expected in (
            "issue_comment:", "types: [created]",
            "github.event.issue.number == 724",
            "github.event.comment.body == '/limpiar-lock-724'",
            "github.event.comment.user.login == 'pl0n3r'",
            "github.actor == 'pl0n3r'",
            "github.ref == 'refs/heads/main'",
            "ref: main",
            "persist-credentials: false",
            "run: python3 scripts/recuperar_lock724.py",
        ):
            self.assertIn(expected, s)
        self.assertNotIn("pull_request_target:", s)
        self.assertNotIn("workflow_run:", s)

if __name__ == "__main__":
    unittest.main()
