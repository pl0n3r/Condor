"""Regresión offline CAS #1384: ninguna petición HTTP real ni lock modificado."""
from datetime import datetime, timezone
from pathlib import Path
from urllib.error import HTTPError
from unittest.mock import patch
import json
import os
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
import recuperar_lock1384 as recovery

NOW = datetime(2026, 10, 30, 5, 0, tzinfo=timezone.utc)


def not_found(url):
    return HTTPError(url, 404, "Not Found", {}, None)


class Fake:
    def __init__(self):
        self.calls = []
        self.deleted = False
        self.nonce = recovery.NONCE
        self.parent = recovery.PARENT
        self.bot = "github-actions[bot]"
        self.message = recovery.MESSAGE
        self.when = "2026-10-29T17:20:59Z"
        self.lease = []
        self.branch = False
        self.pulls = []
        self.blocked = True
        self.freeze = "closed"
        self.paused = False
        self.live_coord = False
        self.cas_error = False

    def get(self, url):
        self.calls.append(("GET", url))
        root = "/repos/pl0n3r/Condor"
        if url == recovery.LOCK_API:
            if self.deleted:
                raise not_found(url)
            return {"object": {"sha": self.nonce}}
        if url == recovery.BRANCH_API:
            if not self.branch:
                raise not_found(url)
            return {"object": {"sha": "a" * 40}}
        if url == f"{root}/git/commits/{recovery.NONCE}":
            return {"message": self.message, "parents": [{"sha": self.parent}],
                    "author": {"name": self.bot}, "committer": {"date": self.when}}
        if url.startswith(f"{root}/issues/1384/comments?"):
            return self.lease
        if url == f"{root}/pulls?state=open&per_page=100":
            return self.pulls
        if url == f"{root}/issues/1384":
            return {"state": "open", "labels": ([{"name": "estado: bloqueado"}]
                                               if self.blocked else [{"name": "estado: disponible"}])}
        if url == "/repos/pl0n3r/Factory/issues/921":
            return {"state": self.freeze}
        if url == "/repos/pl0n3r/Factory/issues/767":
            state = "PAUSED" if self.paused else "RUNNING"
            return {"number": 767, "state": "closed", "user": {"login": "pl0n3r"},
                    "body": '<!-- factory-unattended-kill-switch {"version":1,"state":"'
                    + state + '","owner":"pl0n3r"} -->'}
        if url == root:
            return {"name": "Condor", "owner": {"login": "pl0n3r"}, "node_id": "RID"}
        if "/actions/runs?status=" in url:
            return {"total_count": int(self.live_coord),
                    "workflow_runs": ([{"name": "Coordinación Factory v1", "id": 111}]
                                      if self.live_coord else [])}
        raise AssertionError(f"GET no esperado {url}")

    def post(self, url, data):
        self.calls.append(("POST", url, data))
        if url == "/graphql":
            if self.cas_error:
                return {"errors": [{"message": "CAS rejected"}]}
            self.deleted = True
            return {"data": {"updateRefs": {"clientMutationId": None}}}
        if url == "/repos/pl0n3r/Condor/issues/1384/comments":
            return {"id": 1}
        raise AssertionError(f"POST no esperado {url}")


class RecuperarLock1384Tests(unittest.TestCase):
    def test_identidad_exacta_del_lock(self):
        self.assertEqual(recovery.prepare(Fake(), NOW, "123"), "RID")
        for key, val in (("nonce", "0" * 40), ("parent", "f" * 40),
                         ("bot", "wrong-bot"), ("message", "wrong-message"),
                         ("when", "2026-10-30T04:59:00Z"),
                         ("when", "invalid-date")):
            with self.subTest(key=key, val=val):
                api = Fake()
                setattr(api, key, val)
                with self.assertRaises(recovery.Denied):
                    recovery.prepare(api, NOW, "123")
                self.assertFalse([x for x in api.calls if x[0] == "POST"])

    def test_denegar_con_reserva_rama_o_ci_vivo(self):
        for key in ("lease", "branch", "pulls", "blocked", "freeze",
                    "paused", "live_coord"):
            api = Fake()
            if key == "lease":
                api.lease = [{"user": {"login": "github-actions[bot]"},
                              "body": '<!-- condor-reserva {"version":3,"active":true} -->'}]
            elif key == "branch":
                api.branch = True
            elif key == "pulls":
                api.pulls = [{"head": {"ref": recovery.WORK_BRANCH}}]
            elif key == "blocked":
                api.blocked = False
            elif key == "freeze":
                api.freeze = "open"
            elif key == "paused":
                api.paused = True
            else:
                api.live_coord = True
            with self.subTest(key=key), self.assertRaises(recovery.Denied):
                recovery.prepare(api, NOW, "123")
            self.assertFalse([x for x in api.calls if x[0] == "POST"])

    def test_cas_antes_de_mutar_y_corrobora_404(self):
        api = Fake()
        recovery.recover(api, NOW, "123")
        mutations = [x for x in api.calls if x[0] == "POST" and x[1] == "/graphql"]
        self.assertEqual(len(mutations), 1)
        self.assertEqual(mutations[0][2]["variables"]["input"]["refUpdates"], [{
            "name": recovery.REF, "beforeOid": recovery.NONCE,
            "afterOid": "0" * 40, "force": True,
        }])
        self.assertTrue(api.deleted)
        self.assertEqual(api.calls[-1][1], "/repos/pl0n3r/Condor/issues/1384/comments")
        denied = Fake()
        denied.cas_error = True
        with self.assertRaises(recovery.Denied):
            recovery.recover(denied, NOW, "123")
        self.assertFalse(denied.deleted)
        self.assertEqual(len([x for x in denied.calls if x[0] == "POST"]), 1)

        # Un CAS con respuesta positiva NO acredita borrado si GET aún ve la ref.
        still_present = Fake()
        original_get = still_present.get
        def sticky_ref(url):
            if still_present.deleted and url == recovery.LOCK_API:
                return {"object": {"sha": recovery.NONCE}}
            return original_get(url)
        still_present.get = sticky_ref
        with self.assertRaises(recovery.Denied):
            recovery.recover(still_present, NOW, "123")
        posts = [x[1] for x in still_present.calls if x[0] == "POST"]
        self.assertEqual(posts, ["/graphql"])

        # La segunda lectura impide borrar si apareció una rama entretanto.
        raced = Fake()
        original = recovery.prepare
        attempts = [0]
        def change_state(client, now, run_id):
            attempts[0] += 1
            if attempts[0] == 2:
                client.branch = True
            return original(client, now, run_id)
        with patch.object(recovery, "prepare", side_effect=change_state):
            with self.assertRaises(recovery.Denied):
                recovery.recover(raced, NOW, "123")
        self.assertFalse([x for x in raced.calls if x[0] == "POST"])

    def test_disparador_owner_literal_y_permisos(self):
        workflow = (ROOT / ".github/workflows/recuperar-lock1384.yml").read_text("utf-8")
        for value in (
            "issue_comment:", "types: [created]",
            "github.event.issue.number == 1384",
            "github.event.comment.body == '/limpiar-lock-1384'",
            "github.actor == 'pl0n3r'",
            "github.ref == 'refs/heads/main'",
            "persist-credentials: false",
            "contents: write", "issues: write", "actions: read",
            "run: python3 scripts/recuperar_lock1384.py",
        ):
            self.assertIn(value, workflow)
        self.assertNotIn("pull_request_target:", workflow)
        self.assertNotIn("workflow_run:", workflow)
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "event.json"
            event = {"action": "created", "issue": {"number": 1384},
                     "comment": {"body": "/limpiar-lock-1384", "user": {"login": "pl0n3r"}}}
            env = {"GITHUB_REPOSITORY": "pl0n3r/Condor", "GITHUB_ACTOR": "pl0n3r",
                   "GITHUB_EVENT_NAME": "issue_comment",
                   "GITHUB_EVENT_PATH": str(path),
                   "GITHUB_TOKEN": "synthetic-token", "GITHUB_RUN_ID": "123"}
            with patch.dict(os.environ, env), patch.object(recovery, "recover") as call:
                path.write_text(json.dumps(event))
                self.assertEqual(recovery.main(), 0)
                self.assertEqual(call.call_count, 1)
                event["comment"]["body"] = "/limpiar-lock-724"
                path.write_text(json.dumps(event))
                self.assertEqual(recovery.main(), 1)
                self.assertEqual(call.call_count, 1)


if __name__ == "__main__":
    unittest.main()
