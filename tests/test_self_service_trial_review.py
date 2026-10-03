#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/Commercial/SelfServiceTrialReview.php"
CANONICAL_CREATOR = (
    ROOT / "src/Application/Commercial/PlatformCommercialTrialCreator.php"
)
LIFECYCLE = ROOT / "src/Domain/Commercial/SubscriptionLifecycle.php"
VERSION = ROOT / "config/version.php"


class SelfServiceTrialReviewTests(unittest.TestCase):
    def scenario(self, name: str) -> dict:
        php = r"""
namespace App\Application\Commercial {
    final class PlatformCommercialTrialCreator
    {
        public array $calls = [];

        public function create(string $tenantId): array
        {
            $this->calls[] = $tenantId;

            return [
                'status' => 'configured',
                'subscription' => [
                    'state' => 'trialing',
                    'plan' => ['key' => 'business'],
                ],
            ];
        }
    }
}

namespace {
    require 'vendor/autoload.php';

    $creator = new \App\Application\Commercial\PlatformCommercialTrialCreator();
    $review = new \App\Application\Commercial\SelfServiceTrialReview($creator);
    $owner = new \App\Domain\Identity\Entity\User(
        'owner-review@example.test',
        'Owner',
        [\App\Domain\Identity\Entity\User::ROLE_PLATFORM_OWNER],
    );
    $normal = new \App\Domain\Identity\Entity\User(
        'normal-review@example.test',
        'Normal',
    );

    $reviewer = $owner;
    $applicationTenantId = 'tenant-alpha';
    $targetTenantId = 'tenant-alpha';
    $decision = 'approve';
    $application = [
        'quote_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA',
        'email' => 'trial@example.test',
        'consent_recorded_at' => '2026-10-02T12:00:00.000000Z',
        'status' => 'pending_owner_review',
    ];

    switch ($argv[1]) {
        case 'success':
            break;
        case 'reject':
            $decision = 'reject';
            break;
        case 'duplicate':
            $application['status'] = 'approved';
            break;
        case 'previously_rejected':
            $application['status'] = 'rejected';
            break;
        case 'cross_tenant':
            $targetTenantId = 'tenant-beta';
            break;
        case 'non_owner':
            $reviewer = $normal;
            break;
        default:
            throw new \RuntimeException('Escenario desconocido.');
    }

    try {
        $result = $review->review(
            $reviewer,
            $applicationTenantId,
            $targetTenantId,
            $application,
            $decision,
        );
        echo json_encode(
            [
                'ok' => true,
                'result' => $result,
                'calls' => $creator->calls,
            ],
            JSON_THROW_ON_ERROR,
        );
    } catch (\Throwable $exception) {
        echo json_encode(
            [
                'ok' => false,
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'calls' => $creator->calls,
            ],
            JSON_THROW_ON_ERROR,
        );
    }
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

    def test_owner_approval_uses_existing_business_trial_contract_without_client_controlled_plan_or_duration(self) -> None:
        response = self.scenario("success")
        self.assertTrue(response["ok"])
        self.assertEqual(["tenant-alpha"], response["calls"])
        self.assertEqual("approved", response["result"]["status"])
        self.assertEqual("tenant-alpha", response["result"]["tenant_id"])
        self.assertEqual(
            "01AAAAAAAAAAAAAAAAAAAAAAAA",
            response["result"]["quote_id"],
        )
        self.assertEqual("configured", response["result"]["trial"]["status"])
        self.assertEqual(
            "trialing",
            response["result"]["trial"]["subscription"]["state"],
        )
        self.assertEqual(
            "business",
            response["result"]["trial"]["subscription"]["plan"]["key"],
        )

        source = SOURCE.read_text(encoding="utf-8")
        self.assertIn("PlatformCommercialTrialCreator", source)
        self.assertIn(
            "$this->trialCreator->create($targetTenantId)",
            source,
        )
        for forbidden in (
            "plan_version_id",
            "duration_days",
            "target_state",
        ):
            self.assertNotIn(forbidden, source)
        self.assertNotIn("14", source)

        canonical = CANONICAL_CREATOR.read_text(encoding="utf-8")
        lifecycle = LIFECYCLE.read_text(encoding="utf-8")
        self.assertIn("=== 'business'", canonical)
        self.assertIn("SubscriptionState::Trialing", canonical)
        self.assertIn("TRIAL_DURATION_DAYS = 14", lifecycle)
        self.assertIn(
            "'version' => '0.1.117'",
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

    def test_rejected_duplicate_or_cross_tenant_handoff_fails_closed(self) -> None:
        rejected = self.scenario("reject")
        self.assertTrue(rejected["ok"])
        self.assertEqual("rejected", rejected["result"]["status"])
        self.assertEqual([], rejected["calls"])

        expected = {
            "duplicate": "Solicitud de trial ya resuelta o no pendiente.",
            "previously_rejected": "Solicitud de trial ya resuelta o no pendiente.",
            "cross_tenant": "Handoff de trial entre empresas no permitido.",
            "non_owner": (
                "Solo el dueño de plataforma puede revisar solicitudes de trial."
            ),
        }
        for scenario, message in expected.items():
            with self.subTest(scenario=scenario):
                response = self.scenario(scenario)
                self.assertFalse(response["ok"])
                self.assertEqual("DomainException", response["class"])
                self.assertEqual(message, response["message"])
                self.assertEqual([], response["calls"])


if __name__ == "__main__":
    unittest.main()
