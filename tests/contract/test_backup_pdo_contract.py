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

    def test_backup_uses_same_doctrine_dsn_semantics_as_application(self) -> None:
        backup = (ROOT / "scripts/backup-database-pdo.php").read_text(
            encoding="utf-8"
        )
        native_parser = (ROOT / "scripts/parse-database-url.php").read_text(
            encoding="utf-8"
        )
        shared = (ROOT / "src/Shared/Runtime/DatabaseDsn.php").read_text(
            encoding="utf-8"
        )

        self.assertIn("DatabaseDsn::parse($url)", backup)
        self.assertIn("DriverManager::getConnection", backup)
        self.assertIn("getNativeConnection()", backup)
        self.assertNotIn("parse_url(", backup)

        self.assertIn("DatabaseDsn::parse($url)", native_parser)
        self.assertIn("socket=", native_parser)
        self.assertNotIn("function parseDatabaseUrl", native_parser)

        self.assertIn("parse_url($url)", shared)
        self.assertIn("parse_str($parts[\'query\']", shared)
        self.assertIn("'mysql', 'mariadb' => 'pdo_mysql'", shared)
        self.assertIn("unix_socket", shared)
        self.assertNotIn("getMessage()", shared)


if __name__ == "__main__":
    unittest.main()
