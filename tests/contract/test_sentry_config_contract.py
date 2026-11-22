from __future__ import annotations

import json
import os
import shutil
import subprocess
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

    def test_owner_sentry_dsn_runtime_contract(self) -> None:
        loader = (ROOT / "src/Infrastructure/Observability/StoredSentryDsnEnvLoader.php").read_text(encoding="utf-8")
        services = (ROOT / "config/services.yaml").read_text(encoding="utf-8")
        config = (ROOT / "config/packages/sentry.php").read_text(encoding="utf-8")
        docs = (ROOT / "docs/sentry-datos-minimos.md").read_text(encoding="utf-8")
        self.assertIn("EnvVarLoaderInterface", loader)
        self.assertIn("EnvVarProcessorInterface", loader)
        self.assertIn("['condor_sentry_dsn' => 'string']", loader)
        self.assertNotIn("['string' => 'string']", loader)
        self.assertIn("'dsn' => '%env(condor_sentry_dsn:SENTRY_DSN)%'", config)
        self.assertIn("        if ('condor_sentry_dsn' === $prefix && 'SENTRY_DSN' === $name)", loader)
        self.assertIn("$_ENV['SENTRY_DSN']", loader)
        self.assertIn("$_SERVER['SENTRY_DSN']", loader)
        self.assertIn("getenv('SENTRY_DSN')", loader)
        self.assertIn("return '';", loader)
        self.assertIn("'SENTRY_DSN' === $name", loader)
        self.assertIn("'sentry.dsn'", loader)
        self.assertIn("sodium_crypto_secretbox_open", loader)
        self.assertIn("hash_hkdf", loader)
        self.assertIn("catch (Throwable)", loader)
        service_block = services.split(
            "  App\\Infrastructure\\Observability\\StoredSentryDsnEnvLoader:\n", 1
        )[1].split("\n  App\\", 1)[0]
        self.assertIn("    autoconfigure: false\n", service_block)
        self.assertIn("- { name: container.env_var_processor }", service_block)
        self.assertNotIn("container.env_var_loader", service_block)
        self.assertNotIn("container.env_var_loader", services)
        self.assertIn("AutowireServiceClosure", loader)
        self.assertIn("'doctrine.dbal.default_connection'", loader)
        self.assertNotIn("!service_closure", services)
        self.assertIn("`SENTRY_DSN` del entorno explícito", docs)
        self.assertIn("incluido el valor vacío", docs)
        self.assertIn("ajuste `sentry.dsn` cifrado", docs)
        self.assertIn("prefijo exclusivo `condor_sentry_dsn`", docs)
        self.assertIn("`container.env_var_loader`", docs)
        self.assertNotIn("almacén cifrado tiene prioridad", docs)
        self.assertNotIn("procesador string", docs)
        self.assertIn("revisión humana", docs)

    def test_compiled_sentry_tags_when_symfony_is_available(self) -> None:
        """A compiled debug container must register only the dedicated processor."""
        php = shutil.which("php")
        if php is None or not (ROOT / "vendor/autoload.php").is_file():
            self.skipTest("Symfony vendor or PHP is not installed in this runner.")

        service = r"App\Infrastructure\Observability\StoredSentryDsnEnvLoader"
        env = dict(os.environ, APP_ENV="test", APP_DEBUG="1", SENTRY_DSN="")
        for tag, expected in (
            ("container.env_var_loader", False),
            ("container.env_var_processor", True),
        ):
            with self.subTest(tag=tag):
                result = subprocess.run(
                    [
                        php, "bin/console", "--env=test", "--no-ansi",
                        "debug:container", f"--tag={tag}",
                    ],
                    cwd=ROOT,
                    env=env,
                    capture_output=True,
                    text=True,
                    timeout=90,
                    check=False,
                )
                output = result.stdout + result.stderr
                self.assertEqual(result.returncode, 0, output)
                self.assertEqual(service in output, expected, output)

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
