#!/usr/bin/env python3
"""#948 AC-01/AC-02: offline HTTP boundary and durable SEO settings contracts.

These checks deliberately never connect to production, a provider or a real
tenant. They exercise the canonical GTM validator with PHP and inspect the
Symfony API/persistence/migration boundaries without fabricating HTTP success.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/SeoSettingsController.php"
REPOSITORY = ROOT / "src/Infrastructure/Persistence/SeoSettingsRepository.php"
MIGRATION = ROOT / "migrations/Version20261026230000.php"
GTM = ROOT / "src/Application/Seo/TagManagerSettings.php"


def php(script: str) -> str:
    exe = shutil.which("php")
    if exe is None:
        raise AssertionError("Se requiere PHP para ejecutar las pruebas SEO")
    result = subprocess.run(
        [exe, "-d", "display_errors=stderr", "-r", script],
        cwd=ROOT, capture_output=True, text=True, timeout=20, check=False,
    )
    if result.returncode:
        raise AssertionError(result.stdout + result.stderr)
    return result.stdout


class SeoSettingsHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        code = CONTROLLER.read_text(encoding="utf-8")
        for path in (CONTROLLER, REPOSITORY, MIGRATION):
            result = subprocess.run(
                ["php", "-l", str(path)], cwd=ROOT, capture_output=True,
                text=True, timeout=20, check=False,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)

        route = "/api/v1/branches/{branchId}/seo/settings"
        self.assertEqual(2, code.count(route))
        self.assertRegex(code, r"methods:\s*\['GET'\]")
        self.assertRegex(code, r"methods:\s*\['PUT'\]")
        self.assertIn("authorizedBranchScope($branchId, 'settings.view')", code)
        self.assertIn("authorizedBranchScope($branchId, 'settings.update')", code)
        self.assertIn("$this->requireCsrf($request)", code)
        self.assertIn("$this->payload($request, ['indexnow', 'gtm'])", code)
        self.assertIn("TagManagerSettings::normalizeContainerId(", code)
        self.assertIn("Configurar y activar GTM exige dos pasos separados.", code)
        self.assertIn("'last_notifications' => []", code)
        self.assertIn("['enabled']", code)
        self.assertIn("Cache-Control", code)
        self.assertNotIn("curl_exec(", code)
        self.assertNotIn("HttpClient", code)
        self.assertNotIn("googletagmanager.com", code)
        self.assertNotIn("queuePublication(", code)

        # Real PHP execution of the shared #867 GTM validator.
        actual = json.loads(php(r"""
require 'src/Application/Seo/TagManagerSettings.php';
use App\Application\Seo\TagManagerSettings;
$result = ['valid' => TagManagerSettings::normalizeContainerId('gtm-abc123')];
foreach (['GTM-', 'UA-123', 'GTM A', 'GTM-!'] as $value) {
    try { TagManagerSettings::normalizeContainerId($value); $result['rejected'][] = false; }
    catch (DomainException) { $result['rejected'][] = true; }
}
echo json_encode($result, JSON_THROW_ON_ERROR);
"""))
        self.assertEqual("GTM-ABC123", actual["valid"])
        self.assertEqual([True, True, True, True], actual["rejected"])

    def test_additive_persistence_contract(self) -> None:
        code = REPOSITORY.read_text(encoding="utf-8")
        migration = MIGRATION.read_text(encoding="utf-8")
        self.assertIn("transactional(", code)
        self.assertIn("FOR UPDATE", code)
        self.assertIn("WHERE tenant_id = ? AND branch_id = ? AND channel_id = ?", code)
        self.assertIn("[$tenantId, $branchId, $channelId]", code)
        self.assertIn("ON DUPLICATE KEY UPDATE", code)
        self.assertIn("Ajustes SEO persistidos inconsistentes.", code)
        self.assertIn("Config", CONTROLLER.read_text(encoding="utf-8"))
        self.assertIn("PRIMARY KEY (tenant_id, branch_id, channel_id)", migration)
        self.assertIn("FOREIGN KEY (tenant_id, branch_id)", migration)
        self.assertIn("REFERENCES condor_branch (tenant_id, id)", migration)
        self.assertIn("NOT NULL DEFAULT 0", migration)
        self.assertIn("IrreversibleMigration", migration)
        for forbidden in ("DROP TABLE", "TRUNCATE", "curl_exec(", "file_get_contents('http"):
            self.assertNotIn(forbidden, migration + code)
        self.assertEqual(1, migration.count("CREATE TABLE "))


if __name__ == "__main__":
    unittest.main()
