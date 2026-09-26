#!/usr/bin/env python3
"""Behavioral acceptance wrappers for the ControlBot staff API."""

from __future__ import annotations

import json
import os
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class ControlBotStaffAcceptanceTests(unittest.TestCase):
    def phpunit(self, pattern: str) -> None:
        runner = ROOT / "vendor" / "bin" / "simple-phpunit"
        if not runner.exists():
            self.fail("vendor/bin/simple-phpunit no está disponible para evidencia conductual")
        completed = subprocess.run(
            [str(runner), "--filter", pattern, "tests/php/Http/ControlBotStaffControllerTest.php"],
            cwd=ROOT,
            env=os.environ.copy(),
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, completed.returncode, completed.stdout + completed.stderr)

    def test_signature_replay_rate_limit_and_staff_only(self) -> None:
        self.phpunit("testSignedSummaryIsStaffOnlyAndReplayIsRejected|testFutureTimestampNonceCoversFullValidityWindow|testRateLimitFailsClosedAfterConfiguredWindow")

    def test_controlbot_actions_write_local_audit(self) -> None:
        self.phpunit("testPlatformOwnerCannotBeMutatedByControlBot|testAuditFailureRollsBackStaffMutation")

    def test_ops_routes_are_404_without_controlbot_key(self) -> None:
        self.phpunit("testOpsRoutesAre404WithoutControlbotKey")

    def test_controlbot_staff_treatment_is_declared_without_legal_guessing(self) -> None:
        payload = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        item = next(x for x in payload["treatments"] if x["id"] == "controlbot_staff_operations")
        self.assertEqual("review_required", item["basis"])
        self.assertEqual("review_required", item["retention"])
        self.assertEqual([], item["providers"])

    def test_staff_creation_uses_invitation_without_password_payload(self) -> None:
        self.phpunit("testStaffCreationFailsClosedWithoutDeliveryAdapter|testStaffCreationDeliversInvitationWithoutExposingToken")

    def test_password_reset_fails_closed_until_issue_191_is_integrated(self) -> None:
        self.phpunit("testPasswordResetFailsClosedUntilIssue191IsIntegrated")


if __name__ == "__main__":
    unittest.main()
