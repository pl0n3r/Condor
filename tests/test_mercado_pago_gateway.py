"""Factory V3 acceptance: real PHP Mercado Pago sandbox adapter, offline fixtures."""
from __future__ import annotations

import pathlib
import shutil
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = r'''
namespace PHPUnit\Framework {
    class TestCase {
        public static function __callStatic(string $method, array $args): void {
            $pass = match ($method) {
                'assertSame' => $args[0] === $args[1],
                'assertTrue' => $args[0] === true,
                'assertFalse' => $args[0] === false,
                'assertNull' => $args[0] === null,
                'fail' => false,
                default => throw new \LogicException('Unexpected assertion'),
            };
            if (!$pass) throw new \RuntimeException('PHP assertion failed: ' . $method);
        }
    }
}
namespace {
    require 'src/Application/Payments/PaymentGateway.php';
    require 'src/Application/Payments/PaymentGatewayRegistry.php';
    require 'src/Infrastructure/Payments/MercadoPago/MercadoPagoPaymentGateway.php';
    require 'tests/php/Infrastructure/Payments/MercadoPago/MercadoPagoPaymentGatewayTest.php';
    $test = new \App\Tests\Infrastructure\Payments\MercadoPago\MercadoPagoPaymentGatewayTest();
    foreach ([
        'testApprovedRejectedRefundAndInvalidWebhookSignature',
        'testScopeAndIdempotencyFailClosed',
        'testForgedProviderResponseAndSecretFileFailClosed',
    ] as $method) {
        try { $test->$method(); }
        finally { (new \ReflectionMethod($test, 'tearDown'))->invoke($test); }
    }
    echo "Mercado Pago 3 synthetic PHP tests PASS\n";
}
'''


class MercadoPagoPaymentAcceptanceTests(unittest.TestCase):
    def test_approved_rejected_refunded_and_invalid_signature(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP required; no fake success on unavailable runtime")
        paths = (
            "src/Application/Payments/PaymentGateway.php",
            "src/Application/Payments/PaymentGatewayRegistry.php",
            "src/Infrastructure/Payments/MercadoPago/MercadoPagoPaymentGateway.php",
            "tests/php/Infrastructure/Payments/MercadoPago/MercadoPagoPaymentGatewayTest.php",
        )
        for filename in paths:
            file = ROOT / filename
            self.assertTrue(file.is_file(), f"Missing file: {filename}")
            source = file.read_text(encoding="utf-8")
            for forbidden in ("curl_exec(", "shell_exec(", "APP_USR-REAL", "file_get_contents('https://"):
                self.assertNotIn(forbidden, source)
            lint = subprocess.run((php, "-l", str(file)), cwd=ROOT, text=True,
                                  capture_output=True, timeout=15, check=False)
            self.assertEqual(lint.returncode, 0, lint.stderr)
        run = subprocess.run((php, "-r", PHP), cwd=ROOT, text=True,
                             capture_output=True, timeout=30, check=False)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("Mercado Pago 3 synthetic PHP tests PASS", run.stdout)


if __name__ == "__main__":
    unittest.main()
