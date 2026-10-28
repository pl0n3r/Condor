#!/usr/bin/env python3
"""Condor #1199: five synthetic, fail-closed contracts for AGENTES 6/7.

Real release-identity logic and the offline schema policy are exercised; the
Hostinger deployment sequence is inspected only as source code. These checks
cannot attest deploy history, a live backup, a DB migration or cache mutation.
The coverage matrix is intentionally not modified by this leaf.
"""
from __future__ import annotations

import importlib.util
import json
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
AGENTS = ROOT / "AGENTES.md"
RELEASE = ROOT / "scripts/release_identity_guard.py"
SCHEMA = ROOT / "scripts/live-schema-policy.php"
POST = ROOT / "scripts/post-deploy.sh"
VERSION = ROOT / "config/version.php"

GAPS = {
    "AGENTES-06-03": "6. Versionado y entrega — no reutilizar una versión para dos deploys distintos;",
    "AGENTES-06-06": "6. Versionado y entrega — una PR deploy-bound contiene su versión objetivo antes de gates finales.",
    "AGENTES-07-01": "7. Producción en Hostinger — solo migraciones versionadas, aditivas/expand-compatible;",
    "AGENTES-07-02": "7. Producción en Hostinger — dry-run/allowlist;",
    "AGENTES-07-05": "7. Producción en Hostinger — migrate + recheck antes de tocar caché;",
}
UNKNOWN = "UNKNOWN"
LOCAL_PARTIAL = "LOCAL_PARTIAL"
SHA = "1" * 40
OTHER_SHA = "2" * 40
VERSION_EXAMPLE = "0.1.196"


def coverage_rows(document: object) -> dict[str, tuple[str, ...]]:
    """A partial, duplicated or internally inconsistent inventory is UNKNOWN."""
    if not isinstance(document, str):
        raise ValueError("UNKNOWN: missing matrix")
    found: dict[str, tuple[str, ...]] = {}
    for line in document.splitlines():
        match = re.match(r"^\|\s*" + chr(96) + r"(AGENTES-(?:06-03|06-06|07-01|07-02|07-05))" + chr(96) + r"\s*·", line)
        if match is None:
            continue
        key = match.group(1)
        cells = tuple(part.strip() for part in line.strip().strip("|").split("|"))
        expected = chr(96) + key + chr(96) + " · " + chr(96) + "AGENTES.md" + chr(96)
        if (key in found or len(cells) != 5 or not all(cells)
                or cells[0] != expected or cells[1] != GAPS[key]
                or cells[2] != chr(96) + "NO_VERIFICABLE" + chr(96)
                or key not in cells[3] or "Falta evidencia integral" not in cells[3]):
            raise ValueError("UNKNOWN: contradictory or duplicate " + key)
        found[key] = cells
    if set(found) != set(GAPS):
        raise ValueError("UNKNOWN: five AGENTES rows are mandatory")
    return found


def release_guard():
    """Import committed Python logic; the Git backend is always mocked."""
    spec = importlib.util.spec_from_file_location("condor_1199_guard", RELEASE)
    if spec is None or spec.loader is None:
        raise AssertionError("UNKNOWN: release guard unavailable")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def release_fixture(*, title: str | None = None, tags: str = "v0.1.195",
                    tag_sha: str = OTHER_SHA, version: str = VERSION_EXAMPLE) -> tuple[str, str]:
    """Exercise the actual identity validator with only synthetic Git replies."""
    module = release_guard()
    with tempfile.TemporaryDirectory(prefix="condor-1199-synthetic-") as directory:
        root = Path(directory)
        (root / "config").mkdir()
        (root / "config/version.php").write_text(
            "<?php return ['version' => '" + version + "'];\n", encoding="utf-8",
        )

        def fake_git(_root, *args):
            if args == ("tag", "--list"):
                return tags
            if args == ("rev-list", "-n", "1", "v" + version):
                return tag_sha
            raise AssertionError("UNKNOWN: unexpected Git command")

        with patch.object(module, "git", side_effect=fake_git):
            try:
                return module.validate(
                    root, title if title is not None
                    else "test(cobertura): offline fixture (V " + version + ")",
                    SHA,
                )
            except module.GuardError as exc:
                return UNKNOWN, str(exc)


def policy_probe(payload: object) -> dict[str, object]:
    """Run only the deterministic PHP policy CLI, never php-fpm, DB or hosting."""
    binary = shutil.which("php")
    if not binary:
        raise AssertionError("UNKNOWN: PHP runtime unavailable")
    proc = subprocess.run(
        [binary, str(SCHEMA)],
        input=json.dumps(payload), text=True, capture_output=True,
        cwd=ROOT, timeout=15, check=False,
    )
    if proc.returncode != 0:
        raise AssertionError("UNKNOWN: schema policy rejected fixture: " + proc.stderr[-250:])
    output = json.loads(proc.stdout)
    if not isinstance(output, dict):
        raise AssertionError("UNKNOWN: invalid schema-policy response")
    return output


def schema_fixture(*, classification: str = "additive", dry_run: object = True,
                   allowlist: object = True, backup: object = True,
                   recheck: object = True) -> dict[str, object]:
    return {
        "classification": classification,
        "dry_run_valid": dry_run,
        "allowlist_complete": allowlist,
        "backup_receipt_verified": backup,
        "post_checks": {
            "service_readiness": True,
            "schema": recheck,
            "smoke": True,
        },
    }


def ordered_migration_contract(script: object) -> str:
    """Only local ordering, not proof of executing in Hostinger."""
    if not isinstance(script, str) or not script.strip():
        return UNKNOWN
    start = script.find("run_construction_migrations() {")
    end = script.find("crear_lock() {", start)
    if start < 0 or end <= start:
        return UNKNOWN
    block = script[start:end]
    ordered = (
        "if validate_construction_migrations; then",
        "if run_pre_migration_backup; then",
        'set_post_deploy_phase "migrate"',
        "if run_migration_command apply; then",
        'set_post_deploy_phase "recheck"',
        "if run_schema_check; then",
    )
    positions = [block.find(x) for x in ordered]
    if -1 in positions or positions != sorted(positions) or len(set(positions)) != len(positions):
        return UNKNOWN
    post = script[end:]
    if not all(token in script for token in (
        "--dry-run", "--write-sql=", "CONDOR_AUTO_MIGRATE", "Caché no modificada.",
        "destructiva/contract", "allowlist forward/expand-compatible",
    )):
        return UNKNOWN
    cache = post.find('set_post_deploy_phase "cache"')
    call = post.find("run_construction_migrations; then")
    if call < 0 or cache <= call:
        return UNKNOWN
    if ("if [ \"$recheck_status\" -ne 0 ]; then" not in block
            or "return 2" not in block
            or "exit 2" not in post
            or 'bin/console cache:clear' not in post[cache:]):
        return UNKNOWN
    return LOCAL_PARTIAL


def integral_status(clause: str, evidence: object = None) -> str:
    """Self-asserted green/live flags do not establish operational evidence."""
    if clause not in GAPS:
        return UNKNOWN
    return UNKNOWN


class BrechasCondor04Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.rows = coverage_rows(cls.matrix)
        cls.post = POST.read_text(encoding="utf-8")
        cls.agents = AGENTS.read_text(encoding="utf-8")

    def test_contrato_principal(self) -> None:
        """AC-01: each of the five requirements is exercised or UNKNOWN."""
        self.assertEqual(set(self.rows), set(GAPS))
        self.assertIn("solo migraciones versionadas, aditivas/expand-compatible", self.agents)
        self.assertIn("una PR deploy-bound contiene su versión objetivo", self.agents)

        fresh = release_fixture()
        duplicate = release_fixture(tags="v0.1.195\nv0.1.196")
        self.assertEqual(fresh, ("v0.1.196", "v0.1.195"))
        self.assertEqual(duplicate[0], UNKNOWN)
        self.assertIn("already exists", duplicate[1])

        good = policy_probe(schema_fixture())
        destructive = policy_probe(schema_fixture(classification="destructive"))
        self.assertTrue(good["eligible_after_owner_gate"])
        self.assertIs(good["auto_execute"], False)
        self.assertEqual(good["authority"], "owner_gate_required")
        self.assertFalse(destructive["eligible_after_owner_gate"])
        self.assertEqual(destructive["reason"], "destructive_blocked")

        no_allowlist = policy_probe(schema_fixture(allowlist=False))
        self.assertFalse(no_allowlist["eligible_after_owner_gate"])
        self.assertEqual(no_allowlist["reason"], "evidence_incomplete")

        self.assertEqual(ordered_migration_contract(self.post), LOCAL_PARTIAL)
        for clause, row in self.rows.items():
            with self.subTest(clause=clause):
                self.assertEqual(row[1], GAPS[clause])
                self.assertEqual(row[2], chr(96) + "NO_VERIFICABLE" + chr(96))
                self.assertEqual(integral_status(clause, {
                    "release": fresh, "policy": good, "source": LOCAL_PARTIAL,
                }), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: ambiguous versions, evidence and migration order fail shut."""
        line = next(line for line in self.matrix.splitlines()
                    if line.startswith("| " + chr(96) + "AGENTES-06-03" + chr(96)))
        for broken in (
            self.matrix.replace(line + "\n", ""),
            self.matrix + "\n" + line,
            self.matrix.replace(line, "| incomplete |"),
            self.matrix.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO")),
        ):
            with self.subTest(matrix=len(broken)):
                with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                    coverage_rows(broken)
        for bad_title in ("test: missing version", "test: false (V 0.1.197)"):
            self.assertEqual(release_fixture(title=bad_title)[0], UNKNOWN)
        for invalid_tags in ("v0.1.195\nv0.1.196", "v0.1.195\nv0.1.196\nv0.1.197"):
            self.assertEqual(release_fixture(tags=invalid_tags)[0], UNKNOWN)
        for payload in (
            schema_fixture(dry_run=False),
            schema_fixture(allowlist=False),
            schema_fixture(backup=False),
            schema_fixture(recheck=False),
            schema_fixture(classification="unknown"),
            schema_fixture(classification="destructive"),
            {**schema_fixture(), "extra": True},
            {},
        ):
            with self.subTest(schema=payload):
                output = policy_probe(payload)
                self.assertFalse(output["eligible_after_owner_gate"])
                self.assertFalse(output["auto_execute"])
        for missing in (
            "if run_schema_check; then",
            "if run_migration_command apply; then",
            "--dry-run",
        ):
            self.assertEqual(ordered_migration_contract(
                self.post.replace(missing, "", 1)), UNKNOWN)
        self.assertEqual(ordered_migration_contract(""), UNKNOWN)
        self.assertEqual(ordered_migration_contract(None), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: only synthetic payloads and a read-only PHP policy CLI."""
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", own)
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|urllib|httpx|socket)\b")
        self.assertNotIn("DATABASE_URL=", own)
        self.assertNotIn("curl_" + "exec(", own)
        self.assertNotIn("https://" + "api.", own)
        self.assertEqual(policy_probe(schema_fixture())["authority"], "owner_gate_required")
        self.assertIsNone(re.search(r"(?i)(?:customer|client)[_-]?(?:email|phone)\s*=", own))
        self.assertTrue(all(p.is_file() for p in (AGENTS, MATRIX, RELEASE, SCHEMA, POST, VERSION)))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: CI success and synthetic proof are not live validation."""
        for clause in GAPS:
            with self.subTest(clause=clause):
                self.assertEqual(self.rows[clause][2],
                                 chr(96) + "NO_VERIFICABLE" + chr(96))
                for claim in (
                    None, "", {"ci": "success"}, {"version": "0.1.196"},
                    {"backup": "success", "dry_run": True},
                    {"migrated": True, "schema_up_to_date": True},
                    {"stage": "live", "sha": SHA},
                    {"evidence": LOCAL_PARTIAL},
                ):
                    self.assertEqual(integral_status(clause, claim), UNKNOWN)
        self.assertEqual(integral_status("AGENTES-07-99", {"ci": "success"}), UNKNOWN)
        self.assertEqual(ordered_migration_contract(self.post), LOCAL_PARTIAL)
        self.assertIs(policy_probe(schema_fixture())["auto_execute"], False)


if __name__ == "__main__":
    unittest.main()
