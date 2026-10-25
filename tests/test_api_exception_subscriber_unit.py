#!/usr/bin/env python3
"""#846 AC-01: real PHPUnit coverage of API exception redaction and status contract.

No network, database, credentials, or production data are used. The CI PHP lane
executes the complete PHPUnit suite; this adapter verifies discovery and lint.
"""
from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Infrastructure/Http/ApiExceptionSubscriber.php"
FACTORY = ROOT / "src/Infrastructure/Http/ApiErrorResponseFactory.php"
SUITE = ROOT / "tests/php/Infrastructure/Http/ApiExceptionSubscriberTest.php"
PHPUNIT_CONFIG = ROOT / "phpunit.xml.dist"
CI = ROOT / ".github/workflows/ci.yml"
RUNNER = ROOT / "vendor/bin/simple-phpunit"


class ApiExceptionSubscriberUnitTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        for path in (SOURCE, FACTORY, SUITE, PHPUNIT_CONFIG, CI):
            self.assertTrue(path.is_file(), f"Required file missing: {path}")
        source = SOURCE.read_text(encoding="utf-8")
        suite = SUITE.read_text(encoding="utf-8")
        factory = FACTORY.read_text(encoding="utf-8")
        config = PHPUNIT_CONFIG.read_text(encoding="utf-8")
        workflow = CI.read_text(encoding="utf-8")

        self.assertIn("final readonly class ApiExceptionSubscriber", source)
        self.assertIn("ApiRequest::matches(", source)
        self.assertIn("$event->isMainRequest()", source)
        self.assertIn("$status < 400 || $status >= 500", source)
        self.assertIn("'validation_error'", source)
        self.assertIn("private static function safeHeaders(", source)
        self.assertIn("'Cache-Control' => 'no-store'", factory)
        self.assertIn("<directory>tests/php</directory>", config)
        self.assertIn("run: vendor/bin/simple-phpunit", workflow)

        for method in (
            "testClientErrorCodesUseStablePublicContractsWithoutLeakingExceptionDetails",
            "testOnlyApprovedTransportHeadersArePropagated",
            "testNonApiRequestsAndSubRequestsRemainUntouched",
            "testUnexpectedExceptionsAndServerErrorsRemainForTheNormalExceptionPipeline",
        ):
            self.assertIn(f"function {method}(", suite)
        for assertion in (
            "422 => ['validation_error'", "self::assertArrayNotHasKey('trace'",
            "self::assertStringNotContainsString('/srv/private'",
            "self::assertFalse($response->headers->has('X-Debug-Trace'))",
            "HttpKernelInterface::SUB_REQUEST", "self::assertNull($event->getResponse())",
        ):
            self.assertIn(assertion, suite)
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI is required")
        lint = subprocess.run(
            [php, "-l", str(SUITE)], cwd=ROOT,
            capture_output=True, text=True, timeout=30, check=False,
        )
        self.assertEqual(lint.returncode, 0, lint.stdout + lint.stderr)
        if RUNNER.is_file():
            run = subprocess.run(
                [str(RUNNER), "--configuration", str(PHPUNIT_CONFIG), str(SUITE)],
                cwd=ROOT, capture_output=True, text=True, timeout=180, check=False,
            )
            self.assertEqual(run.returncode, 0, (run.stdout + run.stderr)[-3600:])


if __name__ == "__main__":
    unittest.main()
