#!/usr/bin/env python3
"""Condor #1490: explicitly reversible, non-destructive Doctrine drift slices.

The MariaDB CI job executes the migrations; this suite protects the DDL contract
even when run without a database (no real or customer connections required).
"""
from __future__ import annotations

import re
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
MIGRATIONS = (
    ROOT / "migrations/Version20261207120000.php",
    ROOT / "migrations/Version20261207121000.php",
)
INVENTORY = ROOT / "docs/architecture/doctrine-historical-drift.md"
INDEX_RENAMES = (
    ("condor_tenant", "UNIQ_TENANT_SLUG", "UNIQ_7B8C3E58989D9B62"),
    ("condor_tenant_domain", "UNIQ_DOMAIN_HOSTNAME", "UNIQ_B8F8428BE551C011"),
    (
        "condor_tenant_domain",
        "uniq_domain_primary_verified_tenant",
        "UNIQ_B8F8428B9676AA7E",
    ),
    (
        "condor_storefront_profile",
        "uniq_storefront_profile_tenant",
        "UNIQ_5DBD65159033212A",
    ),
)
SQL_CALL = re.compile(r"\$this->addSql\('([^']+)'\);")
SQL_RENAME = re.compile(
    r"^ALTER TABLE (condor_[a-z_]+) RENAME INDEX ([A-Za-z0-9_]+) TO ([A-Za-z0-9_]+)$"
)


def sql_in(method: str, source: str) -> list[str]:
    start = source.index(f"public function {method}(")
    body = source[start:]
    next_method = body.find("public function ", len("public function "))
    if next_method != -1:
        body = body[:next_method]
    return SQL_CALL.findall(body)


class DoctrineHistoricalDriftTests(unittest.TestCase):
    def test_migration_up_and_down_are_exact_inverses(self) -> None:
        expected = {
            f"ALTER TABLE {table} RENAME INDEX {old} TO {new}"
            for table, old, new in INDEX_RENAMES
        }
        up_statements: list[str] = []
        for path in MIGRATIONS:
            self.assertTrue(path.is_file(), str(path))
            source = path.read_text(encoding="utf-8")
            self.assertIn("extends AbstractMigration", source)
            up = sql_in("up", source)
            down = sql_in("down", source)
            self.assertEqual(len(up), 2, path.name)
            self.assertEqual(len(down), 2, path.name)
            for statement in up + down:
                self.assertIsNotNone(SQL_RENAME.fullmatch(statement), statement)
                self.assertNotRegex(
                    statement, r"\b(?:DROP|CREATE|TRUNCATE|DELETE|INSERT|UPDATE|MODIFY|CHANGE|ADD)\b"
                )
            self.assertEqual(
                down,
                [
                    f"ALTER TABLE {match.group(1)} RENAME INDEX {match.group(3)} TO {match.group(2)}"
                    for statement in reversed(up)
                    if (match := SQL_RENAME.fullmatch(statement))
                ],
                path.name,
            )
            up_statements.extend(up)
            syntax = subprocess.run(
                ["php", "-l", str(path)], capture_output=True, text=True, check=False,
            )
            self.assertEqual(syntax.returncode, 0, syntax.stdout + syntax.stderr)
        self.assertEqual(set(up_statements), expected)
        self.assertEqual(len(up_statements), len(expected))

    def test_old_unique_keys_are_grounded_in_versioned_schema(self) -> None:
        all_migrations = "\n".join(
            p.read_text(encoding="utf-8")
            for p in (ROOT / "migrations").glob("Version*.php")
            if p not in MIGRATIONS
        ).lower()
        for table, old, new in INDEX_RENAMES:
            with self.subTest(table=table, old=old):
                self.assertIn(old.lower(), all_migrations)
                self.assertNotEqual(old.lower(), new.lower())

    def test_inventory_prioritizes_unsafe_drift_and_never_executes_schema_update(self) -> None:
        self.assertTrue(INVENTORY.is_file())
        document = INVENTORY.read_text(encoding="utf-8")
        for token in (
            "349", "345", "53", "21", "73", "103", "43",
            "doctrine:schema:update --dump-sql",
            "D-054", "backup", "rollback", "condor_tenant_domain",
        ):
            self.assertIn(token, document)
        self.assertIn("no ejecutar", document.lower())

    def test_version_is_a_distinct_pre_live_patch(self) -> None:
        version = (ROOT / "config/version.php").read_text(encoding="utf-8")
        self.assertIn("'version' => '0.1.196'", version)


if __name__ == "__main__":
    unittest.main()
