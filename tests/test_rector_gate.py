import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
ALLOWED_RECTOR_PATHS = (
    "src/Infrastructure/Persistence/CatalogSchemaListener.php",
    "src/Infrastructure/Runtime/ContainerRecovery.php",
)


class RectorGateTests(unittest.TestCase):
    def test_first_batch_stays_within_bounded_paths(self):
        config = (ROOT / "rector.php").read_text(encoding="utf-8")
        self.assertNotIn("__DIR__ . '/src',", config)
        for path in ALLOWED_RECTOR_PATHS:
            self.assertIn(f"__DIR__ . '/{path}'", config)
        self.assertNotIn("src/Infrastructure/Observability", config)

        catalog = (
            ROOT / "src/Infrastructure/Persistence/CatalogSchemaListener.php"
        ).read_text(encoding="utf-8")
        self.assertIn(
            "private const string TENANT_PRODUCT_FOREIGN_KEY",
            catalog,
        )
        self.assertIn(
            "private const string TENANT_PRODUCT_INDEX",
            catalog,
        )

        recovery = (
            ROOT / "src/Infrastructure/Runtime/ContainerRecovery.php"
        ).read_text(encoding="utf-8")
        self.assertIn("private const string RELATIVE_CACHE_DIR", recovery)
        self.assertIn("private const string RELATIVE_LOCK_FILE", recovery)

    def test_rector_dry_run_is_clean(self):
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("name: Rector dry-run", workflow)
        self.assertIn(
            "vendor/bin/rector process --dry-run --no-progress-bar",
            workflow,
        )
        self.assertFalse(
            (ROOT / ".github/workflows/issue-245-apply.yml").exists(),
            "El workflow efímero de aplicación no debe quedar en el candidato final.",
        )

    def test_ci_runs_rector_dry_run(self):
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("rector-dry-run:", workflow)
        self.assertIn("rector-dry-run, frontend", workflow)
        self.assertIn(
            "RECTOR_DRY_RUN: ${{ needs.rector-dry-run.result }}",
            workflow,
        )
        self.assertIn(
            '[[ "$RECTOR_DRY_RUN" == "success" ]]',
            workflow,
        )


if __name__ == "__main__":
    unittest.main()
