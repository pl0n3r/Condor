from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


class PasswordSessionInvalidationContractTests(unittest.TestCase):
    def test_current_session_is_regenerated_after_authenticated_change(self):
        controller = (
            ROOT / "src/Http/Controller/PasswordSecurityController.php"
        ).read_text(encoding="utf-8")
        self.assertIn("getSession()->migrate(true)", controller)

    def test_remember_me_cannot_be_enabled_without_explicit_revocation_design(self):
        security = (
            ROOT / "config/packages/security.yaml"
        ).read_text(encoding="utf-8")
        self.assertNotIn(
            "remember_me:",
            security,
            "Si se activa remember_me, #191 debe implementar revocación explícita de esos tokens.",
        )

    def test_password_change_mutates_the_hash_used_by_symfony_session_refresh(self):
        service = (
            ROOT / "src/Application/Identity/ChangeOwnPassword.php"
        ).read_text(encoding="utf-8")
        self.assertIn("setPasswordHash(", service)
        self.assertIn("hashPassword(", service)


if __name__ == "__main__":
    unittest.main()
