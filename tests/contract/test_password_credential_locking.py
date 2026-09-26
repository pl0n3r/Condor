from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


class PasswordCredentialLockingContractTests(unittest.TestCase):
    def test_credential_mutations_use_consistent_user_then_reset_lock_order(self):
        for relative in (
            "src/Application/Identity/RequestPasswordReset.php",
            "src/Application/Identity/CompletePasswordReset.php",
            "src/Application/Identity/ChangeOwnPassword.php",
        ):
            source = (ROOT / relative).read_text(encoding="utf-8")
            user_lock = source.find("SELECT id FROM condor_user")
            reset_lock = source.find("SELECT id FROM condor_password_reset")
            self.assertGreaterEqual(user_lock, 0, relative)
            self.assertGreater(reset_lock, user_lock, relative)
            self.assertIn("FOR UPDATE", source)

    def test_authenticated_change_revalidates_current_password_after_lock(self):
        source = (
            ROOT / "src/Application/Identity/ChangeOwnPassword.php"
        ).read_text(encoding="utf-8")
        self.assertLess(
            source.find("$this->lockUser($user)"),
            source.find("isPasswordValid("),
        )


if __name__ == "__main__":
    unittest.main()
