"""Factory V3: execute real PHP PayU sandbox tests without network/PII."""
from __future__ import annotations

from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]

PHP = r'''
namespace PHPUnit\Framework {
    class TestCase {
        public static function assertSame(mixed $expected, mixed $actual): void {
            if ($expected !== $actual) throw new \RuntimeException('assertSame failed: ' . var_export($expected, true) . ' != ' . var_export($actual, true));
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
        public static function fail(string $message): never {
            throw new \RuntimeException($message);
        }
    }
}
namespace {
    require 'src/Application/Payments/PaymentGateway.php';
    require 'src/Application/Payments/PaymentGatewayRegistry.php';
    require 'src/Infrastructure/Payments/PayU/PayUPaymentGateway.php';
    require 'tests/php/Infrastructure/Payments/PayU/PayUPaymentGatewayTest.php';
    $test = new \App\Tests\Infrastructure\Payments\PayU\PayUPaymentGatewayTest();
    foreach ([
        'testApprovedRejectedRefundedAndInvalidSignature',
        'testPayURoundingSignatureAndTenantScope',
        'testIdempotencyAndRefundFailClosed',
        'testNoLiveSecretOrSensitivePayloadAndUnknownFailsClosed',
    ] as $method) {
        $test->$method();
    }
    echo "4 PayU PHP sandbox tests PASS\n";
}
'''


class PayUGatewayAcceptanceTests(unittest.TestCase):
    def test_approved_rejected_refunded_and_invalid_signature(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP runtime is mandatory for PayU acceptance")
        files = [
            "src/Application/Payments/PaymentGateway.php",
            "src/Application/Payments/PaymentGatewayRegistry.php",
            "src/Infrastructure/Payments/PayU/PayUPaymentGateway.php",
            "tests/php/Infrastructure/Payments/PayU/PayUPaymentGatewayTest.php",
        ]
        for file in files:
            path = ROOT / file
            self.assertTrue(path.is_file(), f"Missing source: {file}")
            if file.endswith("/PayUPaymentGateway.php"):
                source = path.read_text(encoding="utf-8")
                for banned in (
                    "curl_exec(", "file_get_contents('https://",
                    "PAYU_API_KEY=", "card_number", "cc_number", "PAYU_LIVE_MODE",
                ):
                    self.assertNotIn(banned, source)
            result = subprocess.run([php, "-l", str(path)], cwd=ROOT,
                                    capture_output=True, text=True, timeout=15, check=False)
            self.assertEqual(result.returncode, 0, f"PHP syntax error: {file}: {result.stderr}")
        result = subprocess.run([php, "-r", PHP], cwd=ROOT,
                                capture_output=True, text=True, timeout=30, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("4 PayU PHP sandbox tests PASS", result.stdout)


if __name__ == "__main__":
    unittest.main()
