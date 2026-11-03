"""Factory V3 acceptance: Wompi sandbox adapter through a real PHP runtime, offline."""
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
        public static function fail(string $message): never {
            throw new \RuntimeException($message);
        }
    }
}
namespace {
    require 'src/Application/Payments/PaymentGateway.php';
    require 'src/Application/Payments/PaymentGatewayRegistry.php';
    require 'src/Infrastructure/Payments/Wompi/WompiPaymentGateway.php';
    require 'tests/php/Infrastructure/Payments/Wompi/WompiPaymentGatewayTest.php';
    $test = new \App\Tests\Infrastructure\Payments\Wompi\WompiPaymentGatewayTest();
    foreach ([
        'testApprovedRejectedRefundAndInvalidWebhookSignature',
        'testIdempotencyAndCrossTenantAndUnverifiableStatusFailClosed',
        'testWithoutTransportOrLiveSecretsIsNotEnabled',
    ] as $method) {
        $test->$method();
        $cleanup = new \ReflectionMethod($test, 'tearDown');
        $cleanup->invoke($test);
    }
    echo "Wompi synthetic PHPUnit scenarios PASS\n";
}
'''


class WompiGatewayAcceptanceTests(unittest.TestCase):
    def test_approved_rejected_refunded_and_invalid_signature(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "Real PHP required; never silently skip")
        sources = [
            "src/Application/Payments/PaymentGateway.php",
            "src/Application/Payments/PaymentGatewayRegistry.php",
            "src/Infrastructure/Payments/Wompi/WompiPaymentGateway.php",
            "tests/php/Infrastructure/Payments/Wompi/WompiPaymentGatewayTest.php",
        ]
        for name in sources:
            source = ROOT / name
            self.assertTrue(source.is_file(), f"Missing real PHP source: {name}")
            contents = source.read_text(encoding="utf-8")
            self.assertNotIn("curl_exec(", contents)
            self.assertNotIn("file_get_contents('https://", contents)
            lint = subprocess.run([php, "-l", str(source)], cwd=ROOT,
                                  capture_output=True, text=True, timeout=15, check=False)
            self.assertEqual(lint.returncode, 0, lint.stderr)
        run = subprocess.run([php, "-r", PHP], cwd=ROOT, capture_output=True,
                             text=True, timeout=30, check=False)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("Wompi synthetic PHPUnit scenarios PASS", run.stdout)


if __name__ == "__main__":
    unittest.main()
