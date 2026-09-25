from __future__ import annotations

import json
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


class SentryConfigContractTests(unittest.TestCase):
    def test_sentry_is_privacy_minimized_and_release_identified(self) -> None:
        config = (ROOT / "config/packages/sentry.php").read_text(encoding="utf-8")

        self.assertIn("AppVersion", config)
        self.assertIn("releaseSha()", config)
        self.assertIn("SentryEventSanitizer::class", config)
        self.assertIn("'send_default_pii' => false", config)
        self.assertIn("'max_request_body_size' => 'none'", config)
        self.assertIn("'traces_sample_rate' => 0.0", config)
        self.assertNotIn("config/version.php", config)

    def test_sentry_stays_disabled_until_dsn_is_explicitly_configured(self) -> None:
        config = (ROOT / "config/packages/sentry.php").read_text(encoding="utf-8")

        self.assertIn(
            "$container->parameters()->set(\n        'env(SENTRY_DSN)',\n        '',\n    );",
            config,
        )
        self.assertNotIn("ingest.us.sentry.io", config)

    def test_sentry_is_documented_as_error_incident_provider(self) -> None:
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        incident = next(
            item for item in data["treatments"]
            if item["id"] == "error_incidents"
        )
        self.assertEqual(incident["providers"], ["sentry"])

        for path in (
            "docs/privacidad/politica-tratamiento.md",
            "docs/privacidad/aviso-privacidad.md",
            "docs/privacidad/registro-tratamientos.md",
        ):
            with self.subTest(path=path):
                content = (ROOT / path).read_text(encoding="utf-8")
                self.assertIn("sentry", content.lower())


if __name__ == "__main__":
    unittest.main()
