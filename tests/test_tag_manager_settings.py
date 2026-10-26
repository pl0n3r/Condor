#!/usr/bin/env python3
"""AC-01: tenant-scoped, consent-first GTM settings without remote calls."""

from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/Seo/TagManagerSettings.php"


def php_json(script: str) -> dict[str, object]:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("PHP CLI is required for the GTM acceptance test")
    process = subprocess.run(
        [php, "-d", "display_errors=stderr", "-r", script],
        cwd=ROOT, text=True, capture_output=True, check=False, timeout=30,
    )
    if process.returncode:
        raise AssertionError(process.stdout + process.stderr)
    return json.loads(process.stdout)


class GtmConfigTenantTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(SOURCE.is_file(), "The GTM settings contract must exist")
        result = php_json(r"""
require 'src/Application/Seo/TagManagerSettings.php';
use App\Application\Seo\TagManagerSettings;

$storage = [];
$read = function (string $tenant, string $channel) use (&$storage): ?array {
    return $storage[$tenant][$channel] ?? null;
};
$write = function (string $tenant, string $channel, array $record) use (&$storage): void {
    $storage[$tenant][$channel] = $record;
};
$gtm = new TagManagerSettings($read, $write);
$missing = $gtm->snapshot('tenant-a', 'web');
$gtm->configure('tenant-a', 'web', '  gtm-abc123  ');
$configured = $gtm->snapshot('tenant-a', 'web');
$beforeEnable = $gtm->renderPublicTag('tenant-a', 'web', true, true, true);
$gtm->setEnabled('tenant-a', 'web', true);
$withoutPermission = [
    $gtm->renderPublicTag('tenant-a', 'web'),
    $gtm->renderPublicTag('tenant-a', 'web', true, false, true),
    $gtm->renderPublicTag('tenant-a', 'web', true, true, false),
    $gtm->renderPublicTag('tenant-a', 'web', false, true, true),
];
$rendered = $gtm->renderPublicTag('tenant-a', 'web', true, true, true);
$otherTenant = $gtm->snapshot('tenant-b', 'web');
$otherChannel = $gtm->snapshot('tenant-a', 'mobile');
$otherRender = $gtm->renderPublicTag('tenant-b', 'web', true, true, true);
$gtm->configure('tenant-b', 'web', 'GTM-7XYZ9');
$gtm->setEnabled('tenant-b', 'web', true);
$independent = $gtm->snapshot('tenant-a', 'web');
$gtm->setEnabled('tenant-a', 'web', false);
$afterDisable = $gtm->renderPublicTag('tenant-a', 'web', true, true, true);
$gtm->configure('tenant-a', 'web', 'GTM-NEW7');
$afterChange = $gtm->snapshot('tenant-a', 'web');

$invalid = [];
foreach (['UA-12345', 'gtm abc', 'GTM-', 'GTM_ABC123',
          'GTM-A BC', '<script>', "GTM-A\nB", 'GTM-ＡＢ', str_repeat('X', 65)] as $value) {
    try { $gtm->configure('tenant-a', 'web', $value); $invalid[] = false; }
    catch (DomainException) { $invalid[] = true; }
}
$invalidScope = [];
foreach ([['../tenant','web'], ['tenant-a', '../private'], ['tenant-a', 'Web']] as $scope) {
    try { $gtm->configure($scope[0], $scope[1], 'GTM-VALID7'); $invalidScope[] = false; }
    catch (DomainException) { $invalidScope[] = true; }
}
$wrongTenantRejected = false;
$storage['tenant-a']['web']['tenant_id'] = 'tenant-b';
try { $gtm->snapshot('tenant-a', 'web'); }
catch (DomainException) { $wrongTenantRejected = true; }
print json_encode(compact('missing', 'configured', 'beforeEnable', 'withoutPermission',
    'rendered', 'otherTenant', 'otherChannel', 'otherRender', 'independent',
    'afterDisable', 'afterChange', 'invalid', 'invalidScope', 'wrongTenantRejected'), JSON_THROW_ON_ERROR);
""")
        self.assertIsNone(result["missing"]["container_id"])
        self.assertFalse(result["missing"]["enabled"])
        self.assertEqual("GTM-ABC123", result["configured"]["container_id"])
        self.assertFalse(result["configured"]["enabled"])
        self.assertEqual("", result["beforeEnable"])
        self.assertEqual(["", "", "", ""], result["withoutPermission"])
        self.assertIn('data-gtm-container="GTM-ABC123"', result["rendered"])
        self.assertNotIn("<script", result["rendered"])
        self.assertNotIn("tenant-b", result["rendered"])
        self.assertIsNone(result["otherTenant"]["container_id"])
        self.assertIsNone(result["otherChannel"]["container_id"])
        self.assertEqual("", result["otherRender"])
        self.assertEqual("GTM-ABC123", result["independent"]["container_id"])
        self.assertEqual("", result["afterDisable"])
        self.assertEqual("GTM-NEW7", result["afterChange"]["container_id"])
        self.assertFalse(result["afterChange"]["enabled"])
        self.assertTrue(all(result["invalid"]))
        self.assertTrue(all(result["invalidScope"]))
        self.assertTrue(result["wrongTenantRejected"])

    def test_php_lint_and_no_external_transport(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php)
        proc = subprocess.run([php, "-l", str(SOURCE)], cwd=ROOT,
                              text=True, capture_output=True, check=False, timeout=30)
        self.assertEqual(0, proc.returncode, proc.stdout + proc.stderr)
        code = SOURCE.read_text(encoding="utf-8")
        for forbidden in ("curl_exec(", "file_get_contents('http", "HttpClient", "eval(", "fetch(", "<script", "googletagmanager.com"):
            self.assertNotIn(forbidden, code)


if __name__ == "__main__":
    unittest.main()
