import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MIGRATION = ROOT / "migrations" / "Version20260927044500.php"
PLAN_VERSION = ROOT / "src" / "Domain" / "CommercialCatalog" / "Entity" / "PlanVersion.php"
ADDON = ROOT / "src" / "Domain" / "CommercialCatalog" / "Entity" / "AddOn.php"
READER = ROOT / "src" / "Application" / "CommercialCatalog" / "CommercialCatalogReader.php"
REPOSITORY = ROOT / "src" / "Infrastructure" / "Persistence" / "DoctrineCommercialCatalogRepository.php"


class CommercialCatalogAcceptanceTest(unittest.TestCase):
    def migration(self) -> str:
        return MIGRATION.read_text(encoding="utf-8")

    def test_plan_versions_can_coexist_and_resolve_by_date(self) -> None:
        source = PLAN_VERSION.read_text(encoding="utf-8")
        migration = self.migration()
        self.assertIn("function isEffectiveAt", source)
        self.assertIn("validFrom", source)
        self.assertIn("validUntil", source)
        self.assertIn(
            "UNIQUE INDEX uniq_commercial_plan_version (plan_id, version_number)",
            migration,
        )

    def test_plan_and_vertical_are_independent(self) -> None:
        migration = self.migration()
        source = PLAN_VERSION.read_text(encoding="utf-8")
        self.assertIn("condor_commercial_plan_version_vertical", migration)
        self.assertIn("function addVertical", source)
        self.assertIn("01K6A", migration)
        self.assertIn("01K6B", migration)

    def test_capabilities_and_addons_use_stable_keys(self) -> None:
        migration = self.migration()
        addon = ADDON.read_text(encoding="utf-8")
        self.assertIn("catalog_key", migration)
        self.assertIn("condor_commercial_plan_version_capability", migration)
        self.assertIn("condor_commercial_plan_version_addon", migration)
        self.assertIn("production-lite", migration)
        self.assertIn("class AddOn", addon)

    def test_initial_plans_are_versioned_data(self) -> None:
        migration = self.migration()
        for key, monthly, annual in (
            ("basic", "79900", "799000"),
            ("business", "199900", "1999000"),
            ("pro", "499900", "4999000"),
            ("enterprise", "1200000", "NULL"),
        ):
            self.assertIn(f"'{key}'", migration)
            self.assertIn(monthly, migration)
            self.assertIn(annual, migration)
        self.assertIn("condor_commercial_plan_version", migration)

    def test_production_lite_is_independent_addon(self) -> None:
        migration = self.migration()
        self.assertIn("'production-lite'", migration)
        self.assertIn("'Producción Lite'", migration)
        self.assertIn("99900", migration)
        self.assertNotIn("role_permission", migration.lower())

    def test_migration_is_expand_compatible(self) -> None:
        source = self.migration()
        up = source.split("public function down", 1)[0].upper()
        self.assertNotRegex(up, r"\bDROP\s+(TABLE|COLUMN|INDEX)\b")
        self.assertNotRegex(up, r"\bDELETE\s+FROM\b")
        self.assertNotRegex(up, r"\bTRUNCATE\b")
        self.assertNotRegex(up, r"\bRENAME\s+(TABLE|COLUMN)\b")
        self.assertGreaterEqual(up.count("CREATE TABLE CONDOR_COMMERCIAL_"), 8)

    def test_reader_resolves_current_catalog_without_plan_name_switches(self) -> None:
        source = (
            READER.read_text(encoding="utf-8")
            + REPOSITORY.read_text(encoding="utf-8")
        ).lower()
        for key in ("'basic'", "'business'", "'pro'", "'enterprise'"):
            self.assertNotIn(key, source)
        self.assertIn("effectiveplanversions", source)
        self.assertIn("validuntil", source)

    def test_frontend_does_not_hardcode_commercial_prices(self) -> None:
        price_tokens = ("79900", "199900", "499900", "1200000", "99900")
        offenders = []
        for suffix in ("*.ts", "*.tsx"):
            for path in (ROOT / "frontend").rglob(suffix):
                source = path.read_text(encoding="utf-8")
                if any(token in source for token in price_tokens):
                    offenders.append(str(path.relative_to(ROOT)))
        self.assertEqual([], offenders)


if __name__ == "__main__":
    unittest.main()
