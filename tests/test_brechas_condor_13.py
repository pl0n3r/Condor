"""Contract tests for Condor D-043-S04..S08 (#1208), synthetic and offline.

A passing suite never certifies a real Hostinger build, migration, release,
public smoke or authenticated tenant; those remain UNKNOWN pending evidence.
Only this file is within the V3 reservation.
"""
from __future__ import annotations

from datetime import datetime, timezone
import importlib.util
import json
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = ROOT / "scripts"
if str(SCRIPTS) not in sys.path:
    sys.path.insert(0, str(SCRIPTS))

IDS = {
    "D-043-S04": "Build reproducible",
    "D-043-S05": "Migraciones de producción",
    "D-043-S06": "Observación del despliegue",
    "D-043-S07": "Smoke público",
    "D-043-S08": "Smoke autenticado",
}
UNKNOWN = "UNKNOWN"
VERSION = "0.1.195"
SHA = "a" * 40


def load(name: str):
    """Load local pure entry points without invoking any CLI or network."""
    spec = importlib.util.spec_from_file_location(name, SCRIPTS / (name + ".py"))
    if spec is None or spec.loader is None:
        raise AssertionError("UNKNOWN: missing offline module " + name)
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


release = load("release_evidence")
observer = load("observar_release")
auth = load("authenticated_production_smoke")


def matrix_rows(document: str) -> dict[str, list[str]]:
    """Each ID must have exactly one unambiguous six-column matrix row."""
    result = {}
    for identifier in IDS:
        lines = [line for line in document.splitlines()
                 if line.startswith("| `" + identifier + "` ·")]
        if len(lines) != 1:
            raise ValueError("UNKNOWN: absent or duplicate " + identifier)
        cells = [cell.strip() for cell in lines[0].strip("|").split("|")]
        if len(cells) != 6 or cells[2] != "`NO_VERIFICABLE`":
            raise ValueError("UNKNOWN: invalid matrix row " + identifier)
        if identifier not in cells[0] or identifier not in cells[3]:
            raise ValueError("UNKNOWN: missing row evidence " + identifier)
        result[identifier] = cells
    return result


def manifest(paths: tuple[str, ...] = ("README.md",)) -> dict:
    return release.build_manifest_explicit(
        version=VERSION, sha=SHA, changed_paths=paths
    )


def observed(*, sha: str = SHA, state: str = "VALIDATED_IN_PRODUCTION",
             missing: str | None = None) -> dict:
    checks = {
        name: {"ok": True, "clase": "ok", "detalle": "Fixture offline"}
        for name in release.public_checks_for_version(VERSION)
        if name != missing
    }
    return {
        "estado": state, "version_esperada": VERSION,
        "sha_esperado": sha, "comprobaciones": checks,
    }


class FakeClient:
    """In-memory browser adapter: no HTTP, cookies, filesystem or credentials."""

    def __init__(self):
        self.responses = {
            ("GET", "/health"): auth.Reply(200, json.dumps({
                "status": "ok", "schema_up_to_date": True,
                "version": VERSION, "release_sha": SHA,
            }).encode("utf-8")),
            ("GET", "/admin/login"): auth.Reply(
                200, b'<form><input name="_csrf_token" value="fake-csrf"></form>'
            ),
            ("POST", "/admin/login"): auth.Reply(
                200, b'<section id="condor-admin-root"></section>'
            ),
            ("GET", "/admin"): auth.Reply(
                200, b'<section id="condor-admin-root"></section>'
            ),
            ("GET", "/api/v1/context"): auth.Reply(200, json.dumps({
                "tenant": {"id": "synthetic-tenant"},
                "active_branch": {"id": "synthetic-branch"},
                "branches": [{"id": "synthetic-branch"}],
            }).encode("utf-8")),
            ("GET", "/adminpl0n3r"): auth.Reply(403),
            ("GET", auth.BAD_BRANCH): auth.Reply(403),
        }
        self.calls = []

    def request(self, method: str, path: str, data=None):
        self.calls.append((method, path))
        if method == "POST" and set(data or {}) != {
            "_username", "_password", "_csrf_token"
        }:
            raise auth.SmokeFailure("invalid_fake_login_request")
        return self.responses[(method, path)]


def smoke_fixture(client: FakeClient | None = None) -> dict:
    return auth.probe(
        client if client is not None else FakeClient(),
        "synthetic@example.test", "fake-password", SHA,
        expected_version=VERSION,
        clock=lambda: datetime(2026, 10, 28, tzinfo=timezone.utc),
    )


def operational_evidence(identifier: str, claimed: object = None) -> str:
    """No synthetic state or check result authorizes a production assertion."""
    return UNKNOWN


class BrechasCondor13Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: five cases exercise actual offline release/smoke contracts."""
        rows = matrix_rows((ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8"))
        self.assertEqual(set(rows), set(IDS))
        specification = (ROOT / "ESPECIFICACIONES.md").read_text(encoding="utf-8")
        for identifier, name in IDS.items():
            with self.subTest(identifier=identifier):
                self.assertIn(name, rows[identifier][1])
                self.assertIn("Falta evidencia integral", rows[identifier][3])
                self.assertIn("#### " + name, specification)
                self.assertEqual(operational_evidence(identifier), UNKNOWN)

        # S04: the version is canonical; both build managers are locked.
        for path in ("composer.lock", "package-lock.json", "config/version.php"):
            self.assertTrue((ROOT / path).is_file(), path)
        self.assertEqual(release.read_version(ROOT / "config/version.php"), VERSION)
        built = manifest()
        release.validate_manifest(built)
        self.assertEqual((built["version"], built["sha"]), (VERSION, SHA))
        self.assertEqual(built["version_source"], "config/version.php")

        # S05: a migration is a separate required transition, not a build flag.
        migration = manifest(("migrations/VersionSynthetic.php",))
        required = {item["id"]: item["required"]
                    for item in migration["transition"]["checks"]}
        self.assertTrue(required["migraciones"])
        self.assertTrue(required["cache"])
        pending = release.finalize(migration, observed(), ())
        self.assertEqual(pending["estado"], "DEPLOY_OBSERVED")
        self.assertIn("migraciones", pending["transition"]["pending"])
        self.assertFalse(release.migration_source_safety(
            ["migrations/VersionSynthetic.php"], None
        )["safe"])

        # S06: a release with a mismatched SHA is never observed.
        self.assertEqual(release.finalize(built, observed(sha="b" * 40), ())[
            "estado"], "NO_OBSERVADO")
        self.assertEqual(release.finalize(built, observed(
            state="DEPLOY_OBSERVED"), ())["estado"], "DEPLOY_OBSERVED")
        self.assertEqual(observer.validar_base_url(
            "https://www.condorapp.com.co"), "https://www.condorapp.com.co")

        # S07: a public smoke must include all checks, not just /health.
        self.assertEqual(release.finalize(built, observed(missing="admin_login"), ())[
            "estado"], "DEPLOY_OBSERVED")
        self.assertFalse(release.finalize(built, observed(missing="admin_login"), ())[
            "public_checks"]["admin_login"]["ok"])

        # S08: run the isolated CSRF/login/tenant/denials/health contract.
        proof = smoke_fixture()
        self.assertEqual(proof["state"], "VALIDATED")  # synthetic only
        self.assertTrue(proof["read_only"])
        self.assertTrue(proof["tenant_context_consistent"])
        self.assertFalse(proof["tenant_safe"])
        self.assertFalse(proof["cross_tenant_verified"])
        self.assertEqual(operational_evidence("D-043-S08", proof), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: absence, identity ambiguity and unsafe tenant checks deny."""
        document = (ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8")
        matrix_rows(document)
        for identifier in IDS:
            row = next(line for line in document.splitlines()
                       if line.startswith("| `" + identifier + "` ·"))
            for malformed in (document.replace(row + "\n", "", 1),
                              document + "\n" + row,
                              document.replace(row, "| `" + identifier +
                                               "` · malformed |", 1)):
                with self.subTest(identifier=identifier), self.assertRaisesRegex(
                    ValueError, "UNKNOWN"
                ):
                    matrix_rows(malformed)
        for bad_sha in ("", "b" * 39, "B" * 40, "not-a-sha"):
            with self.subTest(sha=bad_sha), self.assertRaises(release.EvidenceError):
                release.build_manifest_explicit(
                    version=VERSION, sha=bad_sha, changed_paths=("README.md",)
                )
        with self.assertRaises(release.EvidenceError):
            release.build_manifest(
                version_file=ROOT / "config/version.php",
                sha=SHA, changed_paths=("README.md",),
                expected_version="0.0.0",
            )
        with self.assertRaises(observer.ObservacionError):
            observer.validar_base_url("http://www.condorapp.com.co")
        with self.assertRaises(auth.SmokeFailure):
            auth.target("https://another.example.test/admin")
        self.assertFalse(auth.context_safe(
            b'{"tenant":{"id":"t"},"active_branch":{"id":"a"},'
            b'"branches":[{"id":"b"}]}'
        ))
        self.assertFalse(auth.context_safe(
            b'{"tenant":{"id":"t"},"tenant":{"id":"duplicate"}}'
        ))
        broken = FakeClient()
        broken.responses[("GET", "/health")] = auth.Reply(200, b'{"status":"ok"}')
        with self.assertRaises(auth.SmokeFailure):
            smoke_fixture(broken)
        broken = FakeClient()
        broken.responses[("GET", auth.BAD_BRANCH)] = auth.Reply(200)
        with self.assertRaises(auth.SmokeFailure):
            smoke_fixture(broken)

    def test_sin_datos_reales(self) -> None:
        """AC-03: all requests are in memory with synthetic names and SHAs."""
        client = FakeClient()
        report = smoke_fixture(client)
        self.assertEqual(client.calls[0], ("GET", "/health"))
        self.assertEqual(client.calls[-1], ("GET", "/health"))
        self.assertEqual(client.calls.count(("POST", "/admin/login")), 1)
        serialized = json.dumps(report, ensure_ascii=False).lower()
        for forbidden in ("fake-password", "fake-csrf", "synthetic@example.test",
                          "synthetic-tenant", "authorization", "access_token",
                          "database_url", "session_cookie"):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, serialized)
        self.assertEqual(operational_evidence("D-043-S08", report), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: CI, a fake deploy or synthetic auth never imply live proof."""
        rows = matrix_rows((ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8"))
        for identifier in IDS:
            with self.subTest(identifier=identifier):
                self.assertEqual(rows[identifier][2], "`NO_VERIFICABLE`")
                for claim in (None, {}, True, {"ci": "success"},
                              {"head": SHA, "build": "passed"},
                              {"stage": "production", "state": "VALIDATED"},
                              smoke_fixture() if identifier.endswith("S08") else
                              {"state": "VALIDATED_IN_PRODUCTION"}):
                    self.assertEqual(operational_evidence(identifier, claim), UNKNOWN)
        self.assertEqual(release.finalize(
            manifest(), observed(missing="health"), ())["estado"], "NO_OBSERVADO")
        self.assertEqual(release.finalize(
            manifest(), observed(state="DEPLOY_OBSERVED"), ())["estado"],
            "DEPLOY_OBSERVED")


if __name__ == "__main__":
    unittest.main()
