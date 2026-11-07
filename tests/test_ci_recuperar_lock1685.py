"""Pruebas adversariales offline del limpiador de #1685; sin acceso de red."""
import json
import os
import sys
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import patch
from urllib.error import HTTPError

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
import recuperar_lock1685 as subject

NOW = datetime(2026, 11, 7, 6, 30, tzinfo=timezone.utc)


class Stub:
    """Devuelve datos API deterministas; registra y simula solo CAS GraphQL."""
    def __init__(self):
        self.calls = []
        self.lock_present = True
        self.branch_sha = subject.PARENT
        self.active = False
        self.pr = False
        self.busy = False
        self.paused = False
        self.frozen = False
        self.state = "estado: bloqueado"
        self.nonce = subject.NONCE
        self.parent = subject.PARENT
        self.tree = subject.TREE
        self.parent_tree = subject.TREE
        self.msg = subject.MESSAGE
        self.actor = "github-actions[bot]"
        self.author_email = "41898282+github-actions[bot]@users.noreply.github.com"
        self.committer = "GitHub"
        self.comm_email = "noreply@github.com"
        self.signed = True
        self.reason = "valid"
        self.date = "2026-11-07T04:51:01Z"
        self.author_date = self.date
        self.cas_rejected = False
        self.sticky_ref = False

    def get(self, url):
        self.calls.append(("GET", url))
        path = f"/repos/{subject.REPO}"
        if url == "/repos/pl0n3r/Factory/issues/767":
            mode = "PAUSED" if self.paused else "RUNNING"
            return {"number": 767, "state": "closed", "user": {"login": "pl0n3r"},
                    "body": '<!-- factory-unattended-kill-switch {"version":1,"state":"'
                    + mode + '","owner":"pl0n3r"} -->'}
        if url == "/repos/pl0n3r/Factory/issues/921":
            return {"state": "open" if self.frozen else "closed"}
        if url == f"{path}/issues/1685":
            return {"state": "open", "labels": [{"name": self.state}]}
        if url.startswith(f"{path}/issues/1685/comments?"):
            return ([{"user": {"login": "github-actions[bot]"},
                      "body": '<!-- condor-reserva {"version":3,"active":true} -->'}]
                    if self.active else [])
        if url == subject.BRANCH_API:
            if self.branch_sha is None:
                raise HTTPError(url, 404, "Not Found", {}, None)
            return {"object": {"sha": self.branch_sha}}
        if url == f"{path}/pulls?state=open&per_page=100":
            return ([{"head": {"ref": subject.BRANCH}}] if self.pr else [])
        if "/actions/runs?status=" in url:
            return {"total_count": int(self.busy),
                    "workflow_runs": ([{"name": "Coordinación Factory v1", "id": 100}]
                                      if self.busy else [])}
        if url == subject.LOCK_API:
            if not self.lock_present:
                raise HTTPError(url, 404, "Not Found", {}, None)
            return {"object": {"sha": self.nonce}}
        if url == f"{path}/git/commits/{subject.NONCE}":
            return {"sha": self.nonce, "message": self.msg,
                    "parents": [{"sha": self.parent}], "tree": {"sha": self.tree},
                    "author": {"name": self.actor, "email": self.author_email,
                               "date": self.author_date},
                    "committer": {"name": self.committer, "email": self.comm_email,
                                  "date": self.date},
                    "verification": {"verified": self.signed, "reason": self.reason}}
        if url == f"{path}/git/commits/{subject.PARENT}":
            return {"tree": {"sha": self.parent_tree}}
        if url == path:
            return {"owner": {"login": "pl0n3r"}, "name": "Condor", "node_id": "RID"}
        raise AssertionError("GET inesperado: " + url)

    def _cas_done(self):
        return any(x[0] == "POST" and x[1] == "/graphql" for x in self.calls)

    def post(self, url, payload):
        self.calls.append(("POST", url, payload))
        if url == "/graphql":
            if self.cas_rejected:
                return {"errors": ["rejected"]}
            if not self.sticky_ref:
                self.lock_present = False
            return {"data": {"updateRefs": {"clientMutationId": None}}}
        if url.endswith("/issues/1685/comments"):
            return {"id": 1}
        raise AssertionError("POST inesperado: " + url)


class RecuperarLock1685Tests(unittest.TestCase):
    def assert_no_write(self, api):
        self.assertFalse([x for x in api.calls if x[0] == "POST"])

    def test_identidad_exacta_del_lock(self):
        self.assertEqual(subject.snapshot(Stub(), NOW, "123"), "RID")
        mutations = {
            "nonce": "a" * 40, "parent": "b" * 40,
            "tree": "c" * 40, "parent_tree": "d" * 40,
            "msg": "otro commit", "actor": "unknown",
            "author_email": "forged", "committer": "unknown",
            "comm_email": "forged", "signed": False,
            "reason": "invalid", "date": "2026-11-07T06:29:00Z",
            "author_date": "2026-11-07T06:29:00Z",
        }
        for field, invalid in mutations.items():
            with self.subTest(field=field):
                api = Stub()
                setattr(api, field, invalid)
                with self.assertRaises(subject.Denied):
                    subject.snapshot(api, NOW, "123")
                self.assert_no_write(api)

    def test_denegar_con_reserva_rama_movida_o_ci_vivo(self):
        for field, value in [
            ("active", True), ("pr", True), ("busy", True),
            ("paused", True), ("frozen", True),
            ("branch_sha", "f" * 40), ("branch_sha", None),
            ("state", "estado: disponible"), ("state", "estado: en revisión"),
        ]:
            with self.subTest(field=field, value=value):
                api = Stub()
                setattr(api, field, value)
                with self.assertRaises((subject.Denied, HTTPError)):
                    subject.snapshot(api, NOW, "123")
                self.assert_no_write(api)

    def test_cas_antes_de_mutar_y_corrobora_404(self):
        api = Stub()
        subject.recover(api, NOW, "123")
        calls = [call for call in api.calls if call[0] == "POST"]
        self.assertEqual([x[1] for x in calls],
                         ["/graphql", "/repos/pl0n3r/Condor/issues/1685/comments"])
        ref = calls[0][2]["variables"]["input"]["refUpdates"]
        self.assertEqual(ref, [{"name": subject.LOCK_REF, "beforeOid": subject.NONCE,
                                "afterOid": "0" * 40, "force": True}])
        self.assertFalse(api.lock_present)

        for field in ("cas_rejected", "sticky_ref"):
            failing = Stub()
            setattr(failing, field, True)
            with self.subTest(field=field), self.assertRaises(subject.Denied):
                subject.recover(failing, NOW, "123")
            self.assertFalse(any(x[1].endswith("/comments")
                                 for x in failing.calls if x[0] == "POST"))

        raced = Stub()
        original = subject.snapshot
        rounds = [0]
        def move_branch(api, now, rid):
            rounds[0] += 1
            if rounds[0] == 2:
                api.branch_sha = "c" * 40
            return original(api, now, rid)
        with patch.object(subject, "snapshot", side_effect=move_branch):
            with self.assertRaises(subject.Denied):
                subject.recover(raced, NOW, "123")
        self.assert_no_write(raced)

    def test_disparador_owner_literal_y_permisos(self):
        text = (ROOT / ".github/workflows/recuperar-lock1685.yml").read_text()
        for literal in (
            "issue_comment:", "types: [created]", "github.ref == 'refs/heads/main'",
            "github.event.issue.number == 1685",
            "github.event.comment.body == '/limpiar-lock-1685'",
            "github.event.comment.author_association == 'OWNER'",
            "group: coordinacion-", "contents: write", "issues: write",
            "actions: read", "persist-credentials: false",
            "python3 scripts/recuperar_lock1685.py",
        ):
            self.assertIn(literal, text)
        for forbidden in ("pull_request_target:", "workflow_dispatch:", "schedule:"):
            self.assertNotIn(forbidden, text)

        event = {"action": "created", "issue": {"number": 1685},
                 "comment": {"body": "/limpiar-lock-1685",
                             "user": {"login": "pl0n3r"},
                             "author_association": "OWNER"},
                 "sender": {"login": "pl0n3r"}}
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / "event.json"
            env = {
                "GITHUB_REPOSITORY": subject.REPO, "GITHUB_ACTOR": "pl0n3r",
                "GITHUB_EVENT_NAME": "issue_comment",
                "GITHUB_REF": "refs/heads/main", "GITHUB_WORKFLOW_REF": subject.WORKFLOW,
                "GITHUB_EVENT_PATH": str(path), "GITHUB_RUN_ID": "123",
                "GITHUB_TOKEN": "dummy",
            }
            with patch.dict(os.environ, env), patch.object(subject, "recover") as run:
                path.write_text(json.dumps(event))
                self.assertEqual(subject.main(), 0)
                self.assertEqual(run.call_count, 1)
                for key, value in (("body", "/limpiar-lock-724"),
                                   ("author_association", "CONTRIBUTOR")):
                    altered = json.loads(json.dumps(event))
                    altered["comment"][key] = value
                    path.write_text(json.dumps(altered))
                    self.assertEqual(subject.main(), 1)
                    self.assertEqual(run.call_count, 1)


if __name__ == "__main__":
    unittest.main()
