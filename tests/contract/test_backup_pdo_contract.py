"""Contratos del backup PDO de producción."""

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class BackupPdoContractTest(unittest.TestCase):
    def test_metadata_is_buffered_and_only_rows_stream_unbuffered(self) -> None:
        script = (ROOT / "scripts/backup-database-pdo.php").read_text(
            encoding="utf-8"
        )

        self.assertNotIn(
            "Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY => false",
            script,
        )
        disable = script.index(
            "$pdo->setAttribute(Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY, false);"
        )
        row_query = script.index('"SELECT {$columnList} FROM {$quoted}"', disable)
        close = script.index("$rows->closeCursor();", row_query)
        restore = script.index(
            "$pdo->setAttribute(Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY, true);",
            close,
        )
        self.assertLess(disable, row_query)
        self.assertLess(row_query, close)
        self.assertLess(close, restore)

    def test_pdo_failures_use_sanitized_stage_exit_codes(self) -> None:
        script = (ROOT / "scripts/backup-database-pdo.php").read_text(
            encoding="utf-8"
        )

        for code in range(31, 39):
            self.assertIn(f"= {code};", script)
        self.assertIn("stage={$stage}", script)
        self.assertNotIn("getMessage()", script)


if __name__ == "__main__":
    unittest.main()
