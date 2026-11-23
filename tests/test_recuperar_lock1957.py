"""Regresión one-off: la reparación de #1957 nunca invade la V3 ni elimina rama."""
from __future__ import annotations

from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import patch
from urllib.error import HTTPError
import json
import os
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
import recuperar_lock1957 as subject

NOW = datetime(2026, 11, 23, 6, 5, tzinfo=timezone.utc)
BASE = "/repos/pl0n3r/Condor"


def gone(path: str) -> HTTPError:
    return HTTPError(path, 404, "Not Found", {}, None)


class FakeAPI:
    def __init__(self):
        self.calls = []
        self.lock_exists = True
        self.lock_sha = subject.EXPECTED_NONCE
        self.nonce_message = subject.EXPECTED_MESSAGE
        self.commit_parent = subject.EXPECTED_PARENT
        self.signed = True
        self.author_name = subject.BOT
        self.author_time = "2026-11-23T04:57:36Z"
        self.committer_time = self.author_time
        self.nonce_tree = "a" * 40
        self.parent_tree = self.nonce_tree
        self.lease = {
            "version": 3, "active": True, "reason": "tomar",
            "owner": "pl0n3r", "reservation_id": subject.EXPECTED_LEASE,
            "branch": subject.BRANCH,
            "task_paths": list(subject.EXPECTED_PATHS),
            "task_depends_on": [subject.DEPENDENCY],
            "acceptance_sha256": subject.EXPECTED_ACCEPTANCE_SHA256,
            "task_marker_sha256": subject.EXPECTED_TASK_MARKER_SHA256,
        }
        self.task = dict(subject.EXPECTED_TASK)
        self.branch_sha = "b" * 40
        self.branch_ancestor = "ahead"
        self.changed_paths = [subject.EXPECTED_PATHS[0]]
        self.main_ancestor = "ahead"
        self.pulls = []
        self.lock_issue_status = "estado: reservado"
        self.frozen = False
        self.paused = False
        self.dependency_closed = True
        self.coord_active = False
        self.cas_rejected = False
        self.cas_keeps_lock = False
        self.drift_second = False
        self.branch_reads = 0

    def get(self, path):
        self.calls.append(("GET", path))
        if path == "/repos/pl0n3r/Factory/issues/767":
            state = "PAUSED" if self.paused else "RUNNING"
            return {"number": 767, "state": "closed",
                    "user": {"login": "pl0n3r"},
                    "body": '<!-- factory-unattended-kill-switch {"version":1,"state":"'
                    + state + '","owner":"pl0n3r"} -->'}
        if path == "/repos/pl0n3r/Factory/issues/921":
            return {"state": "open" if self.frozen else "closed"}
        if path == f"{BASE}/issues/1957":
            body = '\n'.join(["### Contexto", "Contrato sintético",
                              "### Alcance", "Reparación fail closed",
                              "### Fuera de alcance", "Datos reales",
                              "### Criterios de aceptación",
                              "- [ ] [AC-01] negativa sintética",
                              "### Contrato ejecutable", "Contratos",
                              "<!-- factory-plan-task "
                              + json.dumps(self.task, ensure_ascii=False) + " -->"])
            return {"number": 1957, "state": "open",
                    "labels": [{"name": self.lock_issue_status}], "body": body}
        if path == f"{BASE}/issues/1955":
            return {"number": 1955,
                    "state": "closed" if self.dependency_closed else "open"}
        if path.startswith(f"{BASE}/issues/1957/comments?"):
            return [{"user": {"login": subject.BOT}, "body":
                     "<!-- condor-reserva " + json.dumps(self.lease) + " -->"}]
        if path == f"{BASE}/branches/{subject.BRANCH}":
            self.branch_reads += 1
            sha = ("d" * 40 if self.drift_second and self.branch_reads >= 3
                   else self.branch_sha)
            return {"commit": {"sha": sha}}
        if path == f"{BASE}/branches/main":
            return {"commit": {"sha": "c" * 40}}
        if path.startswith(f"{BASE}/compare/{subject.EXPECTED_PARENT}..."):
            end = path.rsplit("...", 1)[-1]
            if end == "c" * 40:
                return {"status": self.main_ancestor, "behind_by": 0}
            return {"status": self.branch_ancestor, "behind_by": 0,
                    "files": [{"filename": name} for name in self.changed_paths]}
        if path == f"{BASE}/pulls?state=open&per_page=100":
            return self.pulls
        if "/actions/runs?status=" in path:
            return {"total_count": int(self.coord_active),
                    "workflow_runs": ([{"id": 111, "name": "Coordinación Factory v1"}]
                                      if self.coord_active else [])}
        if path == subject.LOCK_API_REF:
            if not self.lock_exists:
                raise gone(path)
            return {"ref": subject.LOCK_REF,
                    "object": {"type": "commit", "sha": self.lock_sha}}
        if path == f"{BASE}/git/commits/{subject.EXPECTED_NONCE}":
            return {"sha": self.lock_sha, "message": self.nonce_message,
                    "parents": [{"sha": self.commit_parent}],
                    "tree": {"sha": self.nonce_tree},
                    "author": {"name": self.author_name, "email":
                               "41898282+github-actions[bot]@users.noreply.github.com",
                               "date": self.author_time},
                    "committer": {"name": "GitHub", "email": "noreply@github.com",
                                  "date": self.committer_time},
                    "verification": {"verified": self.signed, "reason": "valid"}}
        if path == f"{BASE}/git/commits/{subject.EXPECTED_PARENT}":
            return {"tree": {"sha": self.parent_tree}}
        if path == BASE:
            return {"name": "Condor", "owner": {"login": "pl0n3r"},
                    "node_id": "REPO_NODE_TEST"}
        raise AssertionError(f"GET no previsto: {path}")

    def post(self, path, payload):
        self.calls.append(("POST", path, payload))
        if path == "/graphql":
            if self.cas_rejected:
                return {"errors": [{"message": "CAS mismatch"}]}
            if not self.cas_keeps_lock:
                self.lock_exists = False
            return {"data": {"updateRefs": {"clientMutationId": None}}}
        if path == f"{BASE}/issues/1957/comments":
            return {"id": 1}
        raise AssertionError(f"POST no previsto: {path}")


class RecoveryTests(unittest.TestCase):
    def setUp(self):
        self.task_fingerprint = patch.object(subject, "task_marker_fingerprint",
                                             return_value=subject.EXPECTED_TASK_MARKER_SHA256)
        self.contract_fingerprint = patch.object(subject, "contract_fingerprint",
                                                return_value=subject.EXPECTED_ACCEPTANCE_SHA256)
        self.task_fingerprint.start()
        self.contract_fingerprint.start()
        self.addCleanup(self.task_fingerprint.stop)
        self.addCleanup(self.contract_fingerprint.stop)

    def assert_no_post(self, fake):
        self.assertFalse([x for x in fake.calls if x[0] == "POST"])

    def test_lease_exacta_y_parent_descendiente(self):
        api = FakeAPI()
        self.assertEqual(subject.prepare(api, NOW, "999"), "REPO_NODE_TEST")
        self.assertEqual(subject.verify_branch(api), api.branch_sha)
        self.assert_no_post(api)

    def test_rechaza_cualquier_driftsin_cas(self):
        cases = [
            ("paused", True), ("frozen", True),
            ("lock_issue_status", "estado: disponible"),
            ("dependency_closed", False), ("coord_active", True),
            ("lock_sha", "0" * 40), ("nonce_message", "otro mensaje"),
            ("commit_parent", "0" * 40), ("nonce_tree", "0" * 40),
            ("author_name", "otro"), ("signed", False),
            ("author_time", "2026-11-23T05:59:30Z"),
            ("committer_time", "2026-11-23T05:59:30Z"),
            ("branch_ancestor", "diverged"),
            ("main_ancestor", "diverged"),
            ("changed_paths", ["README.md"]),
            ("changed_paths", [subject.EXPECTED_PATHS[0], "secret.env"]),
            ("pulls", [{"head": {"ref": subject.BRANCH,
                                 "repo": {"full_name": subject.REPO}}}]),
        ]
        for key, value in cases:
            with self.subTest(key=key, value=str(value)):
                api = FakeAPI()
                setattr(api, key, value)
                with self.assertRaises(subject.Denied):
                    subject.prepare(api, NOW, "999")
                self.assert_no_post(api)

    def test_rechaza_claim_y_huella_divergente(self):
        for key, value in [
            ("reservation_id", "otra-lease"), ("active", False),
            ("task_paths", ["README.md"]), ("task_depends_on", []),
            ("acceptance_sha256", "f" * 64),
        ]:
            with self.subTest(key=key):
                fake = FakeAPI()
                fake.lease[key] = value
                with self.assertRaises(subject.Denied):
                    subject.prepare(fake, NOW, "999")
                self.assert_no_post(fake)
        for method in ("task_marker_fingerprint", "contract_fingerprint"):
            with self.subTest(method=method), patch.object(subject, method,
                                                            return_value="0" * 64):
                fake = FakeAPI()
                with self.assertRaises(subject.Denied):
                    subject.prepare(fake, NOW, "999")
                self.assert_no_post(fake)

    def test_CAS_unico_verifica_404_y_preserva_lease(self):
        api = FakeAPI()
        before_lease = dict(api.lease)
        before_branch = api.branch_sha
        subject.recover(api, NOW, "999")
        posts = [x for x in api.calls if x[0] == "POST"]
        self.assertEqual([p[1] for p in posts],
                         ["/graphql", f"{BASE}/issues/1957/comments"])
        self.assertEqual(posts[0][2]["variables"]["input"]["refUpdates"], [{
            "name": subject.LOCK_REF, "beforeOid": subject.EXPECTED_NONCE,
            "afterOid": "0" * 40, "force": True,
        }])
        self.assertFalse(api.lock_exists)
        self.assertEqual(api.lease, before_lease)
        self.assertEqual(api.branch_sha, before_branch)
        with self.assertRaises(HTTPError):
            api.get(subject.LOCK_API_REF)

    def test_CAS_rechazado_o_respuesta_ambigua_no_da_exito(self):
        for flag in ("cas_rejected", "cas_keeps_lock"):
            with self.subTest(flag=flag):
                api = FakeAPI()
                setattr(api, flag, True)
                with self.assertRaises(subject.Denied):
                    subject.recover(api, NOW, "999")
                self.assertEqual(
                    [x[1] for x in api.calls if x[0] == "POST"], ["/graphql"])
        api = FakeAPI()
        api.lock_exists = False
        with self.assertRaises(HTTPError):
            subject.recover(api, NOW, "999")
        self.assert_no_post(api)

    def test_race_de_rama_nunca_concede_escritura_extra(self):
        api = FakeAPI()
        api.drift_second = True
        with self.assertRaises(subject.Denied):
            subject.recover(api, NOW, "999")
        self.assert_no_post(api)
        # Cualquier carrera en la rama debe vetar el CAS antes de tocar refs.
        self.assertEqual(api.lease["reservation_id"], subject.EXPECTED_LEASE)

    def test_disparador_owner_exacto_y_no_persistencia_real(self):
        workflow = (ROOT / ".github/workflows/recuperar-lock1957.yml").read_text()
        ci = (ROOT / ".github/workflows/ci.yml").read_text()
        for literal in (
            "issue_comment:", "types: [created]",
            "github.event.issue.number == 1957",
            "github.event.comment.body == '/limpiar-lock-1957'",
            "github.event.comment.author_association == 'OWNER'",
            "github.actor == 'pl0n3r'",
            "github.ref == 'refs/heads/main'",
            "contents: write", "issues: write", "actions: read",
            "persist-credentials: false",
            "python3 scripts/recuperar_lock1957.py",
        ):
            self.assertIn(literal, workflow)
        for forbidden in ("workflow_dispatch:", "pull_request_target:",
                          "schedule:"):
            self.assertNotIn(forbidden, workflow)
        for literal in ("employees-nav-before-*.png", "employees-nav-after-*.png",
                        "if: always()", "retention-days: 14",
                        "if-no-files-found: ignore"):
            self.assertIn(literal, ci)

        event = {
            "action": "created", "issue": {"number": 1957},
            "comment": {"body": subject.COMMAND, "user": {"login": "pl0n3r"},
                        "author_association": "OWNER"},
        }
        env = {
            "GITHUB_REPOSITORY": subject.REPO, "GITHUB_ACTOR": "pl0n3r",
            "GITHUB_EVENT_NAME": "issue_comment",
            "GITHUB_REF": "refs/heads/main",
            "GITHUB_WORKFLOW_REF": subject.WORKFLOW_REF,
        }
        subject.authorized_event(event, env)
        for target, value in (("comment", {"body": "/tomar",
                                          "user": {"login": "pl0n3r"},
                                          "author_association": "OWNER"}),
                              ("issue", {"number": 2000}), ("action", "edited")):
            with self.subTest(target=target):
                mutated = json.loads(json.dumps(event))
                mutated[target] = value
                with self.assertRaises(subject.Denied):
                    subject.authorized_event(mutated, env)
        env["GITHUB_ACTOR"] = "other"
        with self.assertRaises(subject.Denied):
            subject.authorized_event(event, env)


if __name__ == "__main__":
    unittest.main()
