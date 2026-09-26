from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[2]


class PasswordRecoveryContractTests(unittest.TestCase):
    def test_public_routes_do_not_place_raw_token_in_url(self):
        controller = (ROOT / "src/Http/Controller/PasswordSecurityController.php").read_text()
        self.assertIn("'/admin/restablecer-contrasena'", controller)
        self.assertNotRegex(controller, r"restablecer-contrasena/\{token\}")

        template = (ROOT / "templates/security/password_reset_form.html.twig").read_text()
        self.assertIn("window.location.hash", template)
        self.assertIn("history.replaceState", template)
        self.assertIn('name="token"', template)

    def test_reset_pages_are_no_referrer_and_no_store(self):
        controller = (ROOT / "src/Http/Controller/PasswordSecurityController.php").read_text()
        self.assertIn("'Referrer-Policy', 'no-referrer'", controller)
        self.assertIn("'Cache-Control', 'no-store, private'", controller)

    def test_security_config_allows_only_reset_entrypoints_publicly(self):
        config = (ROOT / "config/packages/security.yaml").read_text()
        self.assertIn("^/admin/recuperar-contrasena$", config)
        self.assertIn("^/admin/restablecer-contrasena$", config)
        self.assertLess(
            config.index("^/admin/restablecer-contrasena$"),
            config.index("^/admin\n"),
        )


if __name__ == "__main__":
    unittest.main()
