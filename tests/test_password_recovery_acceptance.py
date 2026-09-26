#!/usr/bin/env python3
"""Contrato ejecutable de recuperación y cambio de contraseña (#191)."""

from __future__ import annotations

import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class PasswordRecoveryAcceptanceTests(unittest.TestCase):
    def read(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_reset_security_contract(self) -> None:
        token = self.read("src/Domain/Identity/Entity/PasswordResetToken.php")
        request = self.read("src/Application/Identity/RequestPasswordReset.php")
        controller = self.read("src/Http/Controller/PasswordSecurityController.php")
        self.assertIn("tokenHash", token)
        self.assertIn("expiresAt", token)
        self.assertIn("consumedAt", token)
        self.assertIn("revokedAt", token)
        self.assertIn("hash('sha256'", request)
        self.assertIn("password_reset_request", controller)
        self.assertIn("no-store, private", controller)
        self.assertIn("no-referrer", controller)

    def test_authenticated_change_and_session_invalidation(self) -> None:
        change = self.read("src/Application/Identity/ChangeOwnPassword.php")
        security = self.read("config/packages/security.yaml")
        regression = self.read("tests/contract/test_password_session_invalidation.py")
        self.assertIn("isPasswordValid", change)
        self.assertIn("passwordHash", security)
        self.assertIn("remember_me", regression)
        self.assertIn("session", regression.lower())

    def test_mailer_is_server_side_and_token_safe(self) -> None:
        gateway = self.read(
            "src/Infrastructure/Notification/SymfonyMailerTransactionalEmailGateway.php"
        )
        env = self.read(".env.example")
        subscriber = self.read(
            "src/Infrastructure/Notification/DeferredTransactionalEmailSubscriber.php"
        )
        self.assertIn("Transport::fromDsn", self.read(
            "src/Infrastructure/Notification/SymfonyMailerFactory.php"
        ))
        self.assertIn("smtp", gateway)
        self.assertIn("providerIsDocumented", gateway)
        self.assertIn('CONDOR_MAILER_DSN=""', env)
        self.assertNotIn("recipient", subscriber.split("error_log", 1)[-1])
        self.assertNotIn("templateData", subscriber.split("error_log", 1)[-1])

    def test_privacy_inventory_covers_password_reset_and_mail_provider(self) -> None:
        data = json.loads(self.read("datos.yml"))
        reset = next(
            treatment
            for treatment in data["treatments"]
            if treatment["id"] == "condor_password_reset"
        )
        self.assertEqual(reset["category"], "authentication")
        self.assertEqual(reset["purpose"], "account_recovery")
        self.assertEqual(reset["retention"], "password_reset_lifecycle")
        self.assertEqual(reset["basis"], "review_required")
        self.assertEqual(reset["consent"], "review_required")
        self.assertEqual(reset["providers"], [])


if __name__ == "__main__":
    unittest.main()
