from __future__ import annotations

import json
import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
MAIL_TREATMENTS = frozenset({
    "condor_password_reset",
    "account_invitation",
    "notification_delivery",
})
PLACEHOLDER_DSN = "smtps://resend:CLAVE_DE_PRUEBA@smtp.resend.com:465"
RE_SEND_KEY = re.compile(r"\bre_[A-Za-z0-9]{16,}\b")
SMTP_RESEND = re.compile(r"smtps?://resend:([^@\s]+)@smtp\.resend\.com(?::[0-9]+)?")


def contains_resend_credential(text: str) -> bool:
    if RE_SEND_KEY.search(text):
        return True
    return any(
        m.group(1) != "CLAVE_DE_PRUEBA" for m in SMTP_RESEND.finditer(text)
    )


class ResendMailConfigContractTests(unittest.TestCase):
    def test_resend_is_declared_only_for_transacional_mail_treatments(self) -> None:
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual(data["phase"], "construccion")
        self.assertEqual(
            data["d063_attestation"],
            {"nothing_live": True, "no_real_customer_data": True},
        )

        treatments = {item["id"]: item for item in data["treatments"]}
        self.assertTrue(MAIL_TREATMENTS.issubset(treatments))
        for name, treatment in treatments.items():
            with self.subTest(treatment=name):
                expected = (
                    ["resend"] if name in MAIL_TREATMENTS
                    else ["sentry"] if name == "error_incidents"
                    else []
                )
                self.assertEqual(expected, treatment["providers"])
                self.assertEqual("review_required", treatment["basis"])
                self.assertEqual("review_required", treatment["consent"])

        # La contraseña almacenada y la solicitud de trial no son envíos SMTP.
        self.assertNotIn("resend", treatments["account_password"]["providers"])
        self.assertNotIn(
            "resend", treatments["self_service_trial_application"]["providers"]
        )

    def test_privacy_registry_matches_provider_declarations(self) -> None:
        registry = (
            ROOT / "docs/privacidad/registro-tratamientos.md"
        ).read_text(encoding="utf-8")
        for name in MAIL_TREATMENTS:
            with self.subTest(treatment=name):
                section = registry.split("## " + name + "\n", 1)[1].split("\n## ", 1)[0]
                self.assertIn("- Proveedores: " + chr(96) + "resend" + chr(96), section)
        for name in ("account_password", "self_service_trial_application"):
            section = registry.split("## " + name + "\n", 1)[1].split("\n## ", 1)[0]
            self.assertIn("- Proveedores: ninguno_declarado", section)

    def test_example_is_smtps_placeholder_and_env_stays_disabled(self) -> None:
        runbook = (ROOT / "docs/correo-resend-runbook.md").read_text(encoding="utf-8")
        env = (ROOT / ".env.example").read_text(encoding="utf-8")
        gateway = (
            ROOT / "src/Infrastructure/Notification/"
            "SymfonyMailerTransactionalEmailGateway.php"
        ).read_text(encoding="utf-8")
        self.assertIn(PLACEHOLDER_DSN, runbook)
        self.assertIn(PLACEHOLDER_DSN, env)
        self.assertIn("CONDOR_MAIL_PROVIDER=resend", runbook)
        self.assertIn("/adminpl0n3r", runbook)
        self.assertIn("#1726", runbook)
        self.assertIn("#1727", runbook)
        self.assertIn("#1728", runbook)
        self.assertIn("100 al día", runbook)
        self.assertIn("3.000 correos al mes", runbook)
        for key in ("CONDOR_MAILER_DSN", "CONDOR_MAIL_FROM", "CONDOR_MAIL_PROVIDER"):
            self.assertRegex(env, r"(?m)^" + key + r'=""$')
        self.assertIn("providerIsDocumented", gateway)
        self.assertIn("'smtps'", gateway)

    def test_no_real_resend_credentials_appear_in_claimed_artifacts(self) -> None:
        paths = (
            "datos.yml",
            "docs/privacidad/registro-tratamientos.md",
            "docs/correo-resend-runbook.md",
            "docs/salida-secretos-accesos.md",
            ".env.example",
            "tests/contract/test_resend_mail_config_contract.py",
        )
        for path in paths:
            with self.subTest(path=path):
                contents = (ROOT / path).read_text(encoding="utf-8")
                self.assertFalse(contains_resend_credential(contents))

    def test_negative_credential_and_undocumented_provider_are_rejected(self) -> None:
        self.assertTrue(contains_resend_credential("re_" + "A" * 32))
        self.assertTrue(
            contains_resend_credential(
                PLACEHOLDER_DSN.replace("CLAVE_DE_PRUEBA", "token-de-ejemplo-real")
            )
        )
        self.assertFalse(contains_resend_credential(PLACEHOLDER_DSN))
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        allowed = {"resend", "sentry"}
        for treatment in data["treatments"]:
            self.assertTrue(set(treatment["providers"]).issubset(allowed))


if __name__ == "__main__":
    unittest.main()
