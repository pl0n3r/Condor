import json
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


class StaticAnalysisToolingTests(unittest.TestCase):
    def test_required_tools_are_declared_and_locked(self):
        composer = json.loads((ROOT / "composer.json").read_text(encoding="utf-8"))
        required = {
            "phpstan/phpstan",
            "phpstan/phpstan-doctrine",
            "phpstan/phpstan-symfony",
            "rector/rector",
        }
        self.assertTrue(required.issubset(composer["require-dev"]))

        lock = json.loads((ROOT / "composer.lock").read_text(encoding="utf-8"))
        locked = {
            package["name"]
            for package in [*lock.get("packages", []), *lock.get("packages-dev", [])]
        }
        self.assertTrue(required.issubset(locked))

    def test_phpstan_level_eight_uses_baseline_and_framework_extensions(self):
        config = (ROOT / "phpstan.neon.dist").read_text(encoding="utf-8")
        for expected in (
            "phpstan-baseline.neon",
            "vendor/phpstan/phpstan-symfony/extension.neon",
            "vendor/phpstan/phpstan-symfony/rules.neon",
            "vendor/phpstan/phpstan-doctrine/extension.neon",
            "level: 8",
            "- src",
        ):
            self.assertIn(expected, config)

        baseline = (ROOT / "phpstan-baseline.neon").read_text(encoding="utf-8")
        self.assertIn("ignoreErrors:", baseline)
        self.assertGreaterEqual(baseline.count("message:"), 1)

    def test_rector_configuration_is_conservative_and_composer_aware(self):
        config = (ROOT / "rector.php").read_text(encoding="utf-8")
        for expected in (
            "__DIR__ . '/src'",
            "->withPhpSets()",
            "deadCode: true",
            "codeQuality: true",
            "doctrine: true",
            "symfony: true",
        ):
            self.assertIn(expected, config)

    def test_ci_runs_phpstan_and_defers_rector_gate(self):
        """Compatibilidad del contrato histórico #244 tras activar #245."""
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("name: Análisis estático PHP", workflow)
        self.assertIn(
            "vendor/bin/phpstan analyse --configuration=phpstan.neon.dist "
            "--no-progress --memory-limit=1G",
            workflow,
        )
        self.assertIn("static-analysis", workflow)
        self.assertIn('[[ "$STATIC_ANALYSIS" == "success" ]]', workflow)
        self.assertIn("rector-dry-run", workflow)

    def test_agents_documents_static_analysis_commands(self):
        agents = (ROOT / "AGENTES.md").read_text(encoding="utf-8")
        self.assertIn(
            "vendor/bin/phpstan analyse --configuration=phpstan.neon.dist "
            "--no-progress --memory-limit=1G",
            agents,
        )
        self.assertIn("vendor/bin/rector process --dry-run --no-progress-bar", agents)
        self.assertIn("es gate obligatorio de CI", agents)

    def test_bootstrap_workflow_is_not_part_of_final_tooling(self):
        self.assertFalse(
            (ROOT / ".github/workflows/issue-244-bootstrap.yml").exists(),
            "El workflow efímero del bootstrap no debe quedar en la rama final.",
        )


if __name__ == "__main__":
    unittest.main()
