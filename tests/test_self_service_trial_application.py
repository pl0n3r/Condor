#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/Commercial/SelfServiceTrialApplication.php"
DATA = ROOT / "datos.yml"
VERSION = ROOT / "config/version.php"
QUOTE_ID = "01AAAAAAAAAAAAAAAAAAAAAAAA"


class SelfServiceTrialApplicationTests(unittest.TestCase):
    def source(self) -> str:
        return SOURCE.read_text(encoding="utf-8")

    def data(self) -> dict:
        return json.loads(DATA.read_text(encoding="utf-8"))

    def trial_application_treatment(self) -> dict:
        return next(
            treatment
            for treatment in self.data()["treatments"]
            if treatment["id"] == "self_service_trial_application"
        )

    def submit_scenario(self, scenario: str) -> dict:
        php = r"""
require 'vendor/autoload.php';

function makeQuote(
    \DateTimeImmutable $validUntil,
    string $status = 'draft',
): \App\Domain\Commercial\Entity\Quote {
    $reflection = new \ReflectionClass(
        \App\Domain\Commercial\Entity\Quote::class,
    );
    $quote = $reflection->newInstanceWithoutConstructor();

    foreach ([
        'id' => '01AAAAAAAAAAAAAAAAAAAAAAAA',
        'status' => $status,
        'validUntil' => $validUntil,
    ] as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setValue($quote, $value);
    }

    return $quote;
}

$submittedAt = new \DateTimeImmutable('2026-10-02T12:00:00+00:00');
$app = new \App\Application\Commercial\SelfServiceTrialApplication();
$quote = makeQuote($submittedAt->modify('+1 day'));
$payload = [
    'consent' => true,
    'email' => ' User@Example.COM ',
];

switch ($argv[1]) {
    case 'unknown_quote':
        $quote = null;
        break;
    case 'expired_quote':
        $quote = makeQuote($submittedAt->modify('-1 second'));
        break;
    case 'missing_consent':
        unset($payload['consent']);
        break;
    case 'false_consent':
        $payload['consent'] = false;
        break;
    case 'extra_fields':
        $payload['name'] = 'No permitido';
        break;
    case 'success':
        break;
    default:
        throw new \RuntimeException('Escenario desconocido.');
}

try {
    $result = $app->submit($quote, $payload, $submittedAt);
    echo json_encode(
        ['ok' => true, 'result' => $result],
        JSON_THROW_ON_ERROR,
    );
} catch (\Throwable $exception) {
    echo json_encode(
        [
            'ok' => false,
            'class' => $exception::class,
            'message' => $exception->getMessage(),
        ],
        JSON_THROW_ON_ERROR,
    );
}
"""
        completed = subprocess.run(
            ["php", "-r", php, "--", scenario],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(
            0,
            completed.returncode,
            completed.stdout + completed.stderr,
        )
        return json.loads(completed.stdout)

    def test_application_binds_canonical_quote_and_explicit_consent_without_creating_subscription(self) -> None:
        response = self.submit_scenario("success")
        self.assertTrue(response["ok"])
        self.assertEqual(
            {
                "quote_id": QUOTE_ID,
                "email": "user@example.com",
                "consent_recorded_at": "2026-10-02T12:00:00.000000Z",
                "status": "pending_owner_review",
            },
            response["result"],
        )

        source = self.source()
        self.assertNotIn("Subscription", source)
        self.assertNotIn("PlatformCommercialTrialCreator", source)
        for forbidden in ("plan_key", "plan_version_id", "duration_days", "target_state"):
            self.assertNotIn(forbidden, source)

        treatment = self.trial_application_treatment()
        self.assertEqual(
            ["email", "trial_quote_id", "trial_consent_recorded_at"],
            treatment["fields"],
        )
        self.assertEqual("self_service_trial_application", treatment["purpose"])
        self.assertEqual("review_required", treatment["basis"])
        self.assertEqual("review_required", treatment["consent"])
        self.assertEqual([], treatment["providers"])
        self.assertEqual("review_required", treatment["retention"])

        customer_contact = next(
            treatment
            for treatment in self.data()["treatments"]
            if treatment["id"] == "customer_contact"
        )
        self.assertNotIn("trial_quote_id", customer_contact["fields"])
        self.assertNotIn(
            "trial_consent_recorded_at",
            customer_contact["fields"],
        )
        self.assertIn(
            "'version' => '0.1.116'",
            VERSION.read_text(encoding="utf-8"),
        )

        syntax = subprocess.run(
            ["php", "-l", str(SOURCE)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, syntax.returncode, syntax.stdout + syntax.stderr)

    def test_unknown_quote_missing_consent_or_extra_personal_fields_fail_closed(self) -> None:
        expected = {
            "unknown_quote": "Quote comercial no disponible para solicitud de trial.",
            "expired_quote": "Quote comercial no disponible para solicitud de trial.",
            "missing_consent": "Consentimiento explícito requerido.",
            "false_consent": "Consentimiento explícito requerido.",
            "extra_fields": "Campos de solicitud de trial no permitidos.",
        }

        for scenario, message in expected.items():
            with self.subTest(scenario=scenario):
                response = self.submit_scenario(scenario)
                self.assertFalse(response["ok"])
                self.assertEqual("DomainException", response["class"])
                self.assertEqual(message, response["message"])


if __name__ == "__main__":
    unittest.main()
