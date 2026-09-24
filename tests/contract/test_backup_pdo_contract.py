"""Contratos del backup PDO de producción."""

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class BackupPdoContractTest(unittest.TestCase):
    def test_metadata_is_buffered_and_only_rows_stream_unbuffered(self) -> None:
        service = (
            ROOT / "src/Infrastructure/Database/PdoDatabaseBackup.php"
        ).read_text(encoding="utf-8")

        self.assertNotIn(
            "Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY => false",
            service,
        )
        disable = service.index(
            "$pdo->setAttribute(\\Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY, false);"
        )
        row_query = service.index('"SELECT {$columnList} FROM {$quoted}"', disable)
        close = service.index("$rows->closeCursor();", row_query)
        restore = service.index(
            "\\Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY,\n                            true,",
            close,
        )
        self.assertLess(disable, row_query)
        self.assertLess(row_query, close)
        self.assertLess(close, restore)

    def test_pdo_failures_use_sanitized_stage_exit_codes(self) -> None:
        service = (
            ROOT / "src/Infrastructure/Database/PdoDatabaseBackup.php"
        ).read_text(encoding="utf-8")
        command = (ROOT / "src/Console/BackupDatabaseCommand.php").read_text(
            encoding="utf-8"
        )

        for code in range(31, 39):
            self.assertIn(f"= {code};", service)
        self.assertIn("stage=%s", command)
        self.assertIn("$failure->safeMessage", command)
        self.assertNotIn("getMessage()", service)
        self.assertNotIn("getMessage()", command)

    def test_fallback_reuses_injected_doctrine_connection(self) -> None:
        backup = (ROOT / "scripts/backup-database.sh").read_text(
            encoding="utf-8"
        )
        command = (ROOT / "src/Console/BackupDatabaseCommand.php").read_text(
            encoding="utf-8"
        )
        service = (
            ROOT / "src/Infrastructure/Database/PdoDatabaseBackup.php"
        ).read_text(encoding="utf-8")
        native_parser = (ROOT / "scripts/parse-database-url.php").read_text(
            encoding="utf-8"
        )

        self.assertIn(
            '"$PHP_BIN" bin/console app:database:backup-pdo',
            backup,
        )
        self.assertNotIn("backup-database-pdo.php", backup)
        self.assertIn("PdoDatabaseBackup $backup", command)
        self.assertIn("private readonly Connection $connection", service)
        self.assertIn("$this->connection->getNativeConnection()", service)
        self.assertNotIn("DriverManager", service)
        self.assertNotIn("DatabaseDsn", service)
        self.assertNotIn("DATABASE_URL", service)
        self.assertNotIn("getenv(", service)

        # El parser standalone queda únicamente para el dump nativo.
        self.assertIn("DatabaseDsn::parse($url)", native_parser)
        self.assertIn("socket=", native_parser)


if __name__ == "__main__":
    unittest.main()
