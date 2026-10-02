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

    def test_application_binds_canonical_quote_and_explicit_consent_without_creating_subscription(self) -> None:
        source = self.source()
        self.assertIn("?Quote $quote", source)
        self.assertIn("$quote->id()", source)
        self.assertIn("$quote->status() !== 'draft'", source)
        self.assertIn("$quote->validUntil() < $submittedAt", source)
        self.assertIn("private const ALLOWED_FIELDS = ['consent', 'email'];", source)
        self.assertIn("'status' => 'pending_owner_review'", source)
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
        source = self.source()
        self.assertIn("!$quote instanceof Quote", source)
        self.assertIn("($payload['consent'] ?? null) !== true", source)
        self.assertIn("$keys !== self::ALLOWED_FIELDS", source)
        self.assertIn("FILTER_VALIDATE_EMAIL", source)
        self.assertIn("throw new DomainException", source)
        self.assertNotIn("'name'", source)
        self.assertNotIn("'phone'", source)
        self.assertNotIn("'notes'", source)


if __name__ == "__main__":
    unittest.main()
