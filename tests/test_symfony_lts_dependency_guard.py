#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import json
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/verify_symfony_lts_dependency_guard.py"
CI = ROOT / ".github/workflows/ci.yml"
PROMOTION = ROOT / ".github/workflows/promote-dependency-pr.yml"

spec = importlib.util.spec_from_file_location("symfony_lts_guard", SCRIPT)
assert spec and spec.loader
m = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = m
spec.loader.exec_module(m)


def package(name: str, version: str, require: dict[str, str] | None = None) -> dict[str, object]:
    row: dict[str, object] = {"name": name, "version": version}
    if require is not None:
        row["require"] = require
    return row


def lock_for(composer: dict[str, object], rows: list[dict[str, object]]) -> dict[str, object]:
    return {
        "content-hash": m.composer_content_hash(composer),
        "packages": rows,
        "packages-dev": [],
    }


class SymfonyLtsDependencyGuardTests(unittest.TestCase):
    def test_ac01_rejects_unplanned_transitive_major_drift(self) -> None:
        composer = {"name": "fixture/condor", "require": {}}
        base = lock_for(composer, [package("doctrine/collections", "2.6.0")])
        candidate = lock_for(composer, [package("doctrine/collections", "3.1.0")])
        self.assertEqual(
            [("doctrine/collections", "2.6.0", "3.1.0")],
            m.major_drifts(base, candidate),
        )
        with self.assertRaisesRegex(m.GuardError, r"doctrine/collections 2\.6\.0 -> 3\.1\.0"):
            m.validate_documents(
                composer,
                candidate,
                base_lock=base,
                pr_title="chore(deps): bump the composer-minor group",
            )

    def test_ac02_allows_minor_patch_and_explicit_exceptions(self) -> None:
        composer = {
            "name": "fixture/condor",
            "require": {"symfony/framework-bundle": "7.4.*", "symfony/monolog-bundle": "^3.10"},
            "conflict": {"symfony/mime": ">=8"},
        }
        base = lock_for(composer, [
            package("doctrine/dbal", "4.4.4"),
            package("symfony/framework-bundle", "v7.4.18"),
            package("symfony/mime", "v7.4.17"),
            package("symfony/deprecation-contracts", "v3.7.1"),
            package("symfony/polyfill-mbstring", "v1.38.2"),
            package("symfony/monolog-bundle", "v3.11.2"),
            package("symfony/ux-turbo", "v2.30.0"),
            package("symfony/psr-http-message-bridge", "v8.1.0", {"symfony/http-foundation": "^7.4|^8.0"}),
        ])
        candidate = lock_for(composer, [
            package("doctrine/dbal", "4.5.0"),
            package("symfony/framework-bundle", "v7.4.19"),
            package("symfony/mime", "v7.4.18"),
            package("symfony/deprecation-contracts", "v3.7.2"),
            package("symfony/polyfill-mbstring", "v1.39.0"),
            package("symfony/monolog-bundle", "v3.11.3"),
            package("symfony/ux-turbo", "v2.31.0"),
            package("symfony/psr-http-message-bridge", "v8.1.1", {"symfony/http-foundation": "^7.4|^8.0"}),
        ])
        m.validate_documents(
            composer,
            candidate,
            base_lock=base,
            pr_title="chore(deps): bump the composer-minor group",
        )

    def test_ac03_composer_constraints_prevent_major_drift(self) -> None:
        composer = json.loads((ROOT / "composer.json").read_text(encoding="utf-8"))
        lock = json.loads((ROOT / "composer.lock").read_text(encoding="utf-8"))
        self.assertEqual(m.composer_content_hash(composer), lock.get("content-hash"))
        self.assertEqual([], m.static_errors(composer, lock))
        conflicts = composer.get("conflict", {})
        for name in ("symfony/mime", "symfony/string", "symfony/var-exporter"):
            with self.subTest(name=name):
                self.assertEqual(">=8", conflicts.get(name))
        self.assertNotIn("symfony/psr-http-message-bridge", conflicts)
        self.assertNotIn("symfony/deprecation-contracts", conflicts)
        self.assertNotIn("symfony/polyfill-mbstring", conflicts)

    def test_ac04_models_dependabot_309_major_drift(self) -> None:
        composer = {"name": "fixture/condor", "require": {}}
        versions = {
            "doctrine/collections": ("2.6.0", "3.1.0"),
            "doctrine/dbal": ("4.4.4", "4.5.0"),
            "doctrine/instantiator": ("2.0.0", "2.1.0"),
            "doctrine/lexer": ("3.0.1", "3.0.2"),
            "doctrine/orm": ("3.7.1", "3.7.2"),
            "symfony/mailer": ("7.4.17", "7.4.19"),
            "symfony/mime": ("7.4.17", "8.1.7"),
            "symfony/string": ("7.4.19", "8.1.7"),
            "symfony/var-exporter": ("7.4.18", "8.1.6"),
        }
        base = lock_for(composer, [package(name, values[0]) for name, values in versions.items()])
        candidate = lock_for(composer, [package(name, values[1]) for name, values in versions.items()])
        self.assertEqual(
            [
                ("doctrine/collections", "2.6.0", "3.1.0"),
                ("symfony/mime", "7.4.17", "8.1.7"),
                ("symfony/string", "7.4.19", "8.1.7"),
                ("symfony/var-exporter", "7.4.18", "8.1.6"),
            ],
            m.major_drifts(base, candidate),
        )
        with self.assertRaises(m.GuardError):
            m.validate_documents(
                composer,
                candidate,
                base_lock=base,
                pr_title="chore(deps): bump the composer-minor group with 3 updates",
            )

    def test_ac05_ci_runs_guard_without_network_or_polling(self) -> None:
        workflow = CI.read_text(encoding="utf-8")
        preflight = workflow.split("\n  preflight:\n", 1)[1].split("\n  documentacion:\n", 1)[0]
        self.assertLess(
            preflight.index("Validar identidad candidata de release"),
            preflight.index("Validar línea Symfony 7.4 LTS"),
        )
        self.assertLess(
            preflight.index("Validar línea Symfony 7.4 LTS"),
            preflight.index("Clasificar cambios"),
        )
        guard_block = preflight.split("- name: Validar línea Symfony 7.4 LTS", 1)[1].split("\n      - name:", 1)[0]
        self.assertIn('BASE_PR: ${{ github.event.pull_request.base.sha }}'.replace("\\$", "$"), guard_block)
        self.assertIn('--base-sha "$BASE_PR"', guard_block)
        self.assertIn('--pr-title "$TITULO_PR"', guard_block)
        self.assertIn("scripts/verify_symfony_lts_dependency_guard.py", guard_block)
        for forbidden in ("curl ", "gh api", "composer update", "composer audit", "while ", "sleep "):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, guard_block)
        pruebas = workflow.split("\n  pruebas-base:\n", 1)[1].split("\n  static-analysis:\n", 1)[0]
        self.assertEqual(1, pruebas.count("run: python3 tests/test_symfony_lts_dependency_guard.py"))
        self.assertLess(
            pruebas.index("Probar guard de identidad de release"),
            pruebas.index("Probar guard Symfony 7.4 LTS"),
        )

    def test_ac06_promotion_contract_requires_regenerated_lock(self) -> None:
        workflow = PROMOTION.read_text(encoding="utf-8")
        self.assertIn('git diff --binary "$BASE_SHA" "$SOURCE_SHA"', workflow)
        self.assertIn("verify-files", workflow)
        self.assertNotIn("@dependabot rebase", workflow)
        composer = {
            "name": "fixture/condor",
            "require": {"symfony/mailer": "7.4.*"},
            "conflict": {"symfony/mime": ">=8"},
        }
        incompatible = lock_for(composer, [
            package("symfony/mailer", "v7.4.19"),
            package("symfony/mime", "v8.1.7"),
        ])
        with self.assertRaisesRegex(m.GuardError, "symfony/mime"):
            m.validate_documents(
                composer,
                incompatible,
                pr_title="chore(deps): promote bot PR #309 (V 0.1.84)",
            )


if __name__ == "__main__":
    unittest.main()
