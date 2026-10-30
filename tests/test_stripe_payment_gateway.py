"""Factory V3 acceptance: run real PHP Stripe adapter with isolated test doubles."""
from __future__ import annotations

import pathlib
import shutil
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = r'''
namespace PHPUnit\Framework {
  class TestCase {
    public static function assertSame(mixed $a, mixed $b): void { if ($a !== $b) throw new \RuntimeException('assertSame failed'); }
    public static function assertNull(mixed $v): void { if ($v !== null) throw new \RuntimeException('assertNull failed'); }
    public static function assertTrue(mixed $v): void { if ($v !== true) throw new \RuntimeException('assertTrue failed'); }
    public static function assertFalse(mixed $v): void { if ($v !== false) throw new \RuntimeException('assertFalse failed'); }
    public static function fail(string $message): never { throw new \RuntimeException($message); }
  }
}
namespace {
  require 'src/Application/Payments/PaymentGateway.php';
  require 'src/Application/Payments/PaymentGatewayRegistry.php';
  require 'src/Infrastructure/Payments/Stripe/StripePaymentGateway.php';
  require 'tests/php/Infrastructure/Payments/Stripe/StripePaymentGatewayTest.php';
  $t = new \App\Tests\Infrastructure\Payments\Stripe\StripePaymentGatewayTest();
  foreach (['testApprovedRejectedRefundAndInvalidWebhookSignature',
            'testFailClosedOnCrossTenantAmbiguityAndNonTestKeys'] as $method) {
    $t->$method();
    $cleanup = new \ReflectionMethod($t, 'tearDown');
    $cleanup->invoke($t);
  }
  echo "Stripe synthetic PHPUnit scenarios PASS\n";
}
'''


class StripePaymentAcceptanceTests(unittest.TestCase):
    def test_approved_rejected_refunded_and_invalid_signature(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "A real PHP runtime is required")
        sources = [
            "src/Application/Payments/PaymentGateway.php",
            "src/Application/Payments/PaymentGatewayRegistry.php",
            "src/Infrastructure/Payments/Stripe/StripePaymentGateway.php",
            "tests/php/Infrastructure/Payments/Stripe/StripePaymentGatewayTest.php",
        ]
        for name in sources:
            path = ROOT / name
            self.assertTrue(path.is_file(), f"Missing PHP source: {name}")
            lint = subprocess.run([php, "-l", str(path)], cwd=ROOT,
                                  capture_output=True, text=True, timeout=15, check=False)
            self.assertEqual(0, lint.returncode, lint.stderr)
        result = subprocess.run([php, "-r", PHP], cwd=ROOT, capture_output=True,
                                text=True, timeout=30, check=False)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("Stripe synthetic PHPUnit scenarios PASS", result.stdout)


if __name__ == "__main__":
    unittest.main()
