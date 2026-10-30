"""Factory V3 acceptance bridge: executes the real PHP gateway contract offline."""
from __future__ import annotations

import pathlib
import shutil
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = r'''
namespace PHPUnit\Framework {
    class TestCase {
        public static function assertSame(mixed $expected, mixed $actual): void {
            if ($expected !== $actual) throw new \RuntimeException('assertSame failed');
        }
        public static function assertTrue(mixed $value): void {
            if ($value !== true) throw new \RuntimeException('assertTrue failed');
        }
        public static function assertFalse(mixed $value): void {
            if ($value !== false) throw new \RuntimeException('assertFalse failed');
        }
        public static function assertNull(mixed $value): void {
            if ($value !== null) throw new \RuntimeException('assertNull failed');
        }
        public static function assertInstanceOf(string $class, mixed $value): void {
            if (!$value instanceof $class) throw new \RuntimeException('assertInstanceOf failed');
        }
        public static function fail(string $message): never {
            throw new \RuntimeException($message);
        }
    }
}
namespace {
    require 'src/Application/Payments/PaymentGateway.php';
    require 'src/Application/Payments/PaymentGatewayRegistry.php';
    require 'src/Application/Payments/Fake/FakePaymentGateway.php';
    require 'tests/php/Application/Payments/PaymentGatewayContractTest.php';
    $test = new \App\Tests\Application\Payments\PaymentGatewayContractTest();
    foreach ([
        'testApprovedRejectedRefundAndInvalidWebhookSignature',
        'testCompanyRegistryRejectsUnauthorizedTenantCurrencyAndProvider',
        'testIdempotencyConflictsAndCrossTenantQueriesFailClosed',
        'testNoSensitiveInputOrLiveGatewayCanBeActivated',
    ] as $method) {
        $test->$method();
    }
    echo "4 PHP contract tests PASS\n";
}
'''


class PaymentGatewayAcceptanceTests(unittest.TestCase):
    def test_approved_rejected_refunded_and_invalid_signature(self) -> None:
        """Execute real PHP code with synthetic payments and negative signatures."""
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP runtime required, never assume a pass")
        sources = [
            "src/Application/Payments/PaymentGateway.php",
            "src/Application/Payments/PaymentGatewayRegistry.php",
            "src/Application/Payments/Fake/FakePaymentGateway.php",
            "tests/php/Application/Payments/PaymentGatewayContractTest.php",
        ]
        for path in sources:
            source = ROOT / path
            self.assertTrue(source.is_file(), f"Missing claimed PHP source: {path}")
            code = source.read_text(encoding="utf-8")
            self.assertNotIn("curl_exec(", code)
            self.assertNotIn("file_get_contents('https://", code)
            self.assertNotIn("PAYMENT_GATEWAY_PRODUCTION_KEY", code)
            lint = subprocess.run([php, "-l", str(source)], cwd=ROOT,
                                  capture_output=True, text=True, check=False,
                                  timeout=15)
            self.assertEqual(0, lint.returncode, lint.stderr)
        run = subprocess.run([php, "-r", PHP], cwd=ROOT,
                             capture_output=True, text=True, check=False,
                             timeout=30)
        self.assertEqual(0, run.returncode, run.stderr)
        self.assertIn("4 PHP contract tests PASS", run.stdout)


if __name__ == "__main__":
    unittest.main()
