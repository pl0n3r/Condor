#!/usr/bin/env python3
"""Contrato estructural de la privacidad de Sentry (#647).

No ejecuta PHP ni sustituye a PHPUnit: verifica que las garantías de saneamiento
y las pruebas de comportamiento que las demuestran existen. El comportamiento real
se ejecuta en el check «Backend PHP / MariaDB» sobre el SHA exacto.
"""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SANITIZER = ROOT / "src/Infrastructure/Observability/SentryEventSanitizer.php"
PHP_TEST = ROOT / "tests/php/Infrastructure/Observability/SentryEventSanitizerTest.php"
SENTRY_CONFIG = ROOT / "config/packages/sentry.php"
DOC = ROOT / "docs/sentry-datos-minimos.md"


class SentryPrivacyContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sanitizer = SANITIZER.read_text(encoding="utf-8")
        cls.php_test = PHP_TEST.read_text(encoding="utf-8")
        cls.config = SENTRY_CONFIG.read_text(encoding="utf-8")
        cls.doc = DOC.read_text(encoding="utf-8")

    def test_sensitive_exception_and_event_message_are_denied(self):
        # Mensajes de excepción y del evento nunca salen: se sustituyen o el evento no se envía.
        self.assertIn("public const REDACTED = '[redacted]';", self.sanitizer)
        self.assertIn("$exception->setValue(self::REDACTED);", self.sanitizer)
        self.assertIn("$event->setMessage(self::REDACTED, [], self::REDACTED);", self.sanitizer)
        # Fail-closed: cualquier fallo de saneamiento descarta el evento.
        self.assertRegex(self.sanitizer, r"catch \(\\Throwable\) \{[^}]*return null;")
        self.assertIn("if ((string) $event->getType() !== 'event') {\n            return null;", self.sanitizer)
        # La prueba real serializa con el SDK y busca credenciales, correo e ids sintéticos.
        for needle in (
            "testSensitiveExceptionAndEventMessageAreDenied",
            "new PayloadSerializer(new Options())",
            "assertStringNotContainsString($secret, $payload",
            "ana.perez@example.test",
            "Sup3rS3cretPassw0rd",
        ):
            self.assertIn(needle, self.php_test)
        # El DSN sigue vacío por defecto y la configuración no cambia de comportamiento.
        self.assertIn("'env(SENTRY_DSN)',\n        '',", self.config)
        self.assertIn("'send_default_pii' => false,", self.config)
        self.assertIn("'before_send' => SentryEventSanitizer::class,", self.config)

    def test_metadata_and_path_secrets_are_denied(self):
        for call in (
            "$event->setBreadcrumb([]);", "$event->setExtra([]);", "$event->setTags([]);",
            "$event->setFingerprint([]);", "$event->setTransaction(null);",
            "$event->setServerName(null);", "$event->setUser(null);",
        ):
            self.assertIn(call, self.sanitizer)
        # Lista cerrada de cabeceras y de contextos, no lista de bloqueo.
        self.assertIn("private const ALLOWED_HEADERS = ['x-request-id', 'content-type', 'accept'];", self.sanitizer)
        self.assertIn("private const TRACE_KEYS = ['trace_id', 'span_id', 'parent_span_id'];", self.sanitizer)
        self.assertNotIn("SENSITIVE_HEADERS", self.sanitizer)
        self.assertIn("new \\ReflectionProperty(Event::class, 'contexts')", self.sanitizer)
        # El path de la URL se reduce segmento a segmento y un esquema ajeno no pasa.
        self.assertIn("preg_match('/^[a-z]+(?:-[a-z]+)*$/D', $segment)", self.sanitizer)
        self.assertRegex(self.sanitizer, r"\['http', 'https'\]")
        for needle in (
            "testMetadataAndPathSecretsAreDenied",
            "'deeper' => ['password'",
            "setContext('checkout'",
            "/usuarios/[redacted]/token/[redacted]/factura/[redacted]",
            "javascript:alert(",
        ):
            self.assertIn(needle, self.php_test)

    def test_minimal_safe_telemetry_is_preserved(self):
        # La clase, la traza sin variables, release, entorno, nivel y marca de tiempo se conservan.
        self.assertNotIn("setRelease(", self.sanitizer)
        self.assertNotIn("setEnvironment(", self.sanitizer)
        self.assertNotIn("setLevel(", self.sanitizer)
        self.assertNotIn("$exception->setType(", self.sanitizer)
        self.assertIn("$frame->setVars([]);", self.sanitizer)
        for needle in (
            "testMinimalSafeTelemetryIsPreserved",
            "condor@0.1.194+abcdef0",
            "DomainException::class, $event->getExceptions()[0]->getType()",
            "assertSame([], $frames[0]->getVars());",
            "Event::createTransaction()",
        ):
            self.assertIn(needle, self.php_test)
        # Los tres casos preexistentes siguen cubiertos.
        for legacy in ("testRemovesSensitiveRequestDataBeforeSending",
                       "testRemovesVarsFromEventAndExceptionStacktraces",
                       "testLeavesQuerylessUrlStable"):
            self.assertIn(legacy, self.php_test)
        self.assertEqual(len(re.findall(r"public function test\w+\(", self.php_test)), 6)
        doc = self.doc.lower()
        for needle in ("fail-closed", "sentry_dsn", "[redacted]", "#617"):
            self.assertIn(needle, doc)


if __name__ == "__main__":
    unittest.main()
