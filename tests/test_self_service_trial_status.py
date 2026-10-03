#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/Commercial/SelfServiceTrialStatus.php"
VERSION = ROOT / "config/version.php"


class SelfServiceTrialStatusTests(unittest.TestCase):
    def scenario(self, name: str) -> dict:
        php = r"""
require 'vendor/autoload.php';

$status = new \App\Application\Commercial\SelfServiceTrialStatus();
$lookupQuoteId = '01AAAAAAAAAAAAAAAAAAAAAAAA';
$requesterTenantId = 'tenant-alpha';
$applicationTenantId = 'tenant-alpha';
$application = [
    'quote_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA',
    'email' => 'trial@example.test',
    'consent_recorded_at' => '2026-10-02T12:00:00.000000Z',
    'status' => 'pending_owner_review',
];
$review = [
    'status' => 'approved',
    'quote_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA',
    'tenant_id' => 'tenant-alpha',
    'trial' => [
        'status' => 'configured',
        'subscription' => [
            'state' => 'trialing',
            'plan' => [
                'key' => 'business',
                'name' => 'Negocio',
                'version' => '2026-10',
                'currency' => 'COP',
                'monthly_amount' => '99000',
                'annual_amount' => '990000',
                'quote_required' => true,
            ],
            'last_changed_at' => '2026-10-02T13:00:00.000000Z',
            'trial_started_at' => '2026-10-02T13:00:00.000000Z',
            'trial_ends_at' => '2026-10-16T13:00:00.000000Z',
        ],
    ],
];

switch ($argv[1]) {
    case 'approved':
        break;
    case 'pending':
        $review = null;
        break;
    case 'rejected':
        $review = [
            'status' => 'rejected',
            'quote_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA',
            'tenant_id' => 'tenant-alpha',
        ];
        break;
    case 'unknown_lookup':
        $lookupQuoteId = '01BBBBBBBBBBBBBBBBBBBBBBBB';
        break;
    case 'unauthorized':
        $requesterTenantId = 'tenant-beta';
        break;
    case 'cross_tenant_review':
        $review['tenant_id'] = 'tenant-beta';
        break;
    case 'mismatched_review':
        $review['quote_id'] = '01BBBBBBBBBBBBBBBBBBBBBBBB';
        break;
    case 'malformed_window':
        $review['trial']['subscription']['trial_ends_at'] =
            '2026-10-02T12:59:59.000000Z';
        break;
    case 'internal_state':
        $review['trial']['subscription']['state'] = 'active';
        break;
    default:
        throw new \RuntimeException('Escenario desconocido.');
}

try {
    $result = $status->read(
        $lookupQuoteId,
        $requesterTenantId,
        $applicationTenantId,
        $application,
        $review,
    );
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
            ["php", "-r", php, "--", name],
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

    def test_status_exposes_only_opaque_application_state_and_trial_window_when_authorized(self) -> None:
        pending = self.scenario("pending")
        self.assertTrue(pending["ok"])
        self.assertEqual({"status": "pending_owner_review"}, pending["result"])

        rejected = self.scenario("rejected")
        self.assertTrue(rejected["ok"])
        self.assertEqual({"status": "rejected"}, rejected["result"])

        approved = self.scenario("approved")
        self.assertTrue(approved["ok"])
        self.assertEqual(
            {
                "status": "approved",
                "trial_started_at": "2026-10-02T13:00:00.000000Z",
                "trial_ends_at": "2026-10-16T13:00:00.000000Z",
            },
            approved["result"],
        )
        serialized = json.dumps(approved["result"], sort_keys=True)
        for forbidden in (
            "email",
            "quote_id",
            "tenant_id",
            "plan",
            "monthly_amount",
            "annual_amount",
            "currency",
            "last_changed_at",
        ):
            self.assertNotIn(forbidden, serialized)

        source = SOURCE.read_text(encoding="utf-8")
        self.assertNotIn("CommercialCatalogReader", source)
        self.assertNotIn("PlatformCommercialTenantSummary", source)
        self.assertIn(
            "'version' => '0.1.118'",
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

    def test_unknown_or_unauthorized_status_lookup_fails_closed(self) -> None:
        expected = {
            "unknown_lookup": "Solicitud de trial no encontrada.",
            "unauthorized": "Consulta de trial no autorizada.",
            "cross_tenant_review": "Resultado de revisión de trial incoherente.",
            "mismatched_review": "Resultado de revisión de trial incoherente.",
            "malformed_window": "Ventana de trial incoherente.",
            "internal_state": "Trial aprobado sin ventana autorizada.",
        }
        for scenario, message in expected.items():
            with self.subTest(scenario=scenario):
                response = self.scenario(scenario)
                self.assertFalse(response["ok"])
                self.assertEqual("DomainException", response["class"])
                self.assertEqual(message, response["message"])


if __name__ == "__main__":
    unittest.main()
