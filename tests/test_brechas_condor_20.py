#!/usr/bin/env python3
"""Condor #1215: synthetic, fail-closed tests for D-050-S03..S06 and D-051.

A local contract is not evidence of a real mail provider, tenant-specific
extension, progressive admin UI, production, or a merged code/test/PR triad.
"""
from __future__ import annotations

import ast
import json
from functools import lru_cache
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
MATRIX = "docs/matriz-cobertura.md"
CONTRAST = "docs/contraste-cd_d3.md"
SPEC = "ESPECIFICACIONES.md"
PREF = "src/Application/Notification/NotificationPreferenceService.php"
CATALOG = "src/Domain/Notification/NotificationCatalog.php"
EMAIL = "src/Application/Notification/TransactionalEmailGateway.php"
NULL_EMAIL = "src/Infrastructure/Notification/NullTransactionalEmailGateway.php"
BILLING = "src/Application/Commercial/BillingAdapter.php"
BILLING_REQUEST = "src/Application/Commercial/BillingRequest.php"
BILLING_RESULT = "src/Application/Commercial/BillingResult.php"
BILLING_TEST = "tests/php/Application/Commercial/BillingAdapterContractTest.php"
DI = "config/services.yaml"
CLAUSES = {
    "D-050-S03": ("Correo transaccional", "#### Correo transaccional",
                  PREF, "—", ("contrato/adaptador", "outbox persistente",
                               "tokens de activación")),
    "D-050-S04": ("Notificaciones", "#### Notificaciones",
                  PREF, "—", ("in_app", "email", "seguridad/operación",
                               "usuario + evento + canal")),
    "D-050-S05": ("Adaptabilidad", "#### Adaptabilidad",
                  PREF, "—", ("no se hardcodean", "configuración",
                               "extensión opcional")),
    "D-050-S06": ("UX progresiva", "#### UX progresiva",
                  PREF, "—", ("/adminpl0n3r", "staff de plataforma",
                               "controles sin contrato server-side")),
    "D-051": ("Integraciones mediante adaptadores",
              "### D-051 — Integraciones mediante adaptadores",
              BILLING, BILLING_TEST,
              ("Application/", "Infrastructure/", "Null", "binding", "PII")),
}
SOURCES = (MATRIX, CONTRAST, SPEC, PREF, CATALOG, EMAIL, NULL_EMAIL,
           BILLING, BILLING_REQUEST, BILLING_RESULT, BILLING_TEST, DI)

# Execute only the audited, local PHP DTOs and notification catalog.
# No Symfony kernel, DB, network, production providers or credentials.
PHP_PROBE = r'''
require 'src/Domain/Notification/NotificationCatalog.php';
require 'src/Application/Commercial/BillingRequest.php';
require 'src/Application/Commercial/BillingResult.php';
require 'src/Application/Commercial/BillingAdapter.php';
use App\Domain\Notification\NotificationCatalog as C;
use App\Application\Commercial\BillingRequest as R;
use App\Application\Commercial\BillingResult as B;
use App\Application\Commercial\BillingAdapter as A;
function rejected(callable $f): bool {
  try {$f(); return false;} catch (\DomainException $e) {return true;}
}
$input=[
 'tenant_ref'=>'tenant:synthetic-a',
 'subscription_ref'=>'subscription:synthetic-a',
 'quote_ref'=>'quote:synthetic-a',
 'amount_minor'=>199900, 'currency'=>'COP',
 'idempotency_key'=>'billing:synthetic-a',
];
$request=R::fromArray($input);
$adapter=new class implements A {
 public function submit(R $r): B {
  return B::fromArray(['outcome'=>'prepared','provider_ref'=>null,
    'evidence_ref'=>'billing:evidence:synthetic']);
 }
};
echo json_encode([
 'channels'=>C::channels(),
 'mandatory_invitation'=>C::isMandatory(C::EVENT_ACCOUNT_INVITATION),
 'mandatory_password_reset'=>C::isMandatory(C::EVENT_PASSWORD_RESET),
 'optional_order'=>C::isMandatory(C::EVENT_ORDER_CREATED),
 'invalid_event'=>rejected(fn()=>C::normalizeEvent('synthetic.unknown')),
 'invalid_channel'=>rejected(fn()=>C::normalizeChannel('sms')),
 'billing_request'=>$request->toArray(),
 'billing_result'=>$adapter->submit($request)->toArray(),
 'sensitive_request'=>rejected(fn()=>R::fromArray($input+['token'=>'synthetic'])),
 'provider_request'=>rejected(fn()=>R::fromArray(array_replace(
   $input,['idempotency_key'=>'provider:synthetic']))),
 'invalid_result'=>rejected(fn()=>B::fromArray([
   'outcome'=>'paid','provider_ref'=>null,
   'evidence_ref'=>'billing:evidence:synthetic'])),
],JSON_THROW_ON_ERROR);
'''


def sources() -> dict[str, str]:
    """Read only the checked-out, repository-controlled inputs."""
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in SOURCES}


def rows(document: str, kind: str) -> dict[str, tuple[str, ...]]:
    """An absent, duplicated, malformed or promoted source row fails closed."""
    if kind not in ("matrix", "contrast"):
        raise ValueError("UNKNOWN: invalid document kind")
    found = {}
    for key, (title, _heading, candidate, test, _phrases) in CLAUSES.items():
        prefix = ("| " + chr(96) + key + chr(96) + " ·"
                  if kind == "matrix" else "| " + key + " |")
        matches = [line for line in document.splitlines()
                   if line.startswith(prefix)]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: absent or ambiguous " + key)
        cells = tuple(c.strip() for c in matches[0].strip("|").split("|"))
        if kind == "matrix":
            expected = chr(96) + key + chr(96) + " · " + chr(96) + SPEC + chr(96)
            valid = (len(cells) == 5 and cells[0] == expected and cells[1] == title
                     and cells[2] == chr(96) + "NO_VERIFICABLE" + chr(96)
                     and "Falta evidencia integral " + key in cells[3]
                     and "tríada código/test/PR MERGED" in cells[3]
                     and "contraste-cd_d3.md" in cells[3]
                     and cells[4] == "#1")
        else:
            valid = (len(cells) == 6 and cells[0] == key
                     and cells[1] == "NO_VERIFICABLE" and cells[2] == candidate
                     and cells[3] == test and cells[4] == "—"
                     and "Falta acreditar integralmente" in cells[5]
                     and "tríada código/test/PR MERGED" in cells[5])
        if not valid:
            raise ValueError("UNKNOWN: malformed row " + key)
        found[key] = cells
    return found


def section(document: str, heading: str) -> str:
    lines = document.splitlines()
    matches = [i for i, line in enumerate(lines) if line == heading]
    if len(matches) != 1:
        return ""
    start = matches[0]
    level = len(heading) - len(heading.lstrip("#"))
    for i in range(start + 1, len(lines)):
        m = re.match(r"^(#{1,6})\s+", lines[i])
        if m and len(m.group(1)) <= level:
            return "\n".join(lines[start + 1:i])
    return "\n".join(lines[start + 1:])


def classify(key: str, content: dict[str, str]) -> str:
    """Partial code and normative text prove only an explicit missing triad."""
    if key not in CLAUSES or any(not content.get(p) for p in SOURCES):
        return UNKNOWN
    try:
        matrix = rows(content[MATRIX], "matrix")
        contrast = rows(content[CONTRAST], "contrast")
    except ValueError:
        return UNKNOWN
    title, heading, candidate, test, phrases = CLAUSES[key]
    if (not all(fragment in section(content[SPEC], heading) for fragment in phrases)
            or matrix[key][1] != title or contrast[key][2] != candidate
            or (test != "—" and not content.get(test))):
        return UNKNOWN
    if key in ("D-050-S03", "D-050-S04"):
        if (not all(s in content[PREF] for s in
                    ("NotificationCatalog::normalizeEvent",
                     "NotificationCatalog::normalizeChannel",
                     "NotificationCatalog::isMandatory"))
                or "interface TransactionalEmailGateway" not in content[EMAIL]
                or "implements TransactionalEmailGateway" not in content[NULL_EMAIL]
                or "return false;" not in content[NULL_EMAIL]):
            return UNKNOWN
    if key == "D-051":
        if (not all(s in content[BILLING] for s in
                    ("interface BillingAdapter",
                     "function submit(BillingRequest $request): BillingResult"))
                or "implements TransactionalEmailGateway" not in content[NULL_EMAIL]
                or "testSensitiveProviderSpecificOrUnknownPayloadFailsClosed"
                not in content[BILLING_TEST]
                or "alias: App\\Infrastructure\\Notification\\SymfonyMailerTransactionalEmailGateway"
                not in content[DI]):
            return UNKNOWN
    return GAP


def operational(_key: object, _claim: object = None) -> str:
    """The local fixture cannot certify a real provider, user UI or deploy."""
    return UNKNOWN


@lru_cache(maxsize=1)
def smoke() -> dict:
    if not shutil.which("php"):
        raise AssertionError("UNKNOWN: PHP CLI unavailable")
    result = subprocess.run(
        ["php", "-r", PHP_PROBE], cwd=ROOT, text=True, capture_output=True,
        timeout=20, check=False,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError("UNKNOWN: PHP fixture failed: " + result.stderr[-500:])
    parsed = json.loads(result.stdout)
    if not isinstance(parsed, dict):
        raise AssertionError("UNKNOWN: PHP fixture shape")
    return parsed


class BrechasCondor20Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: five IDs, actual PHP behaviors and unaccredited gaps."""
        content = sources()
        self.assertEqual(set(rows(content[MATRIX], "matrix")), set(CLAUSES))
        self.assertEqual(set(rows(content[CONTRAST], "contrast")), set(CLAUSES))
        for key in CLAUSES:
            with self.subTest(key=key):
                self.assertEqual(GAP, classify(key, content))
                self.assertEqual(UNKNOWN, operational(key, content))
        evidence = smoke()
        self.assertEqual(evidence["channels"], ["email", "in_app"])
        self.assertTrue(evidence["mandatory_invitation"])
        self.assertTrue(evidence["mandatory_password_reset"])
        self.assertFalse(evidence["optional_order"])
        self.assertTrue(evidence["invalid_event"])
        self.assertTrue(evidence["invalid_channel"])
        self.assertEqual(evidence["billing_result"], {
            "outcome": "prepared", "provider_ref": None,
            "evidence_ref": "billing:evidence:synthetic",
        })
        self.assertEqual(evidence["billing_request"]["currency"], "COP")
        self.assertTrue(evidence["sensitive_request"])
        self.assertTrue(evidence["provider_request"])
        self.assertTrue(evidence["invalid_result"])
        # An unconfigured or synthetically prepared provider is never delivery proof.
        self.assertEqual(UNKNOWN, operational("D-050-S03", {
            "provider_result": evidence["billing_result"], "mail": "sent",
        }))

    def test_falla_cerrado(self) -> None:
        """AC-02: missing, duplicate, falsely promoted or mutated evidence."""
        content = sources()
        for path, kind in ((MATRIX, "matrix"), (CONTRAST, "contrast")):
            for key in CLAUSES:
                prefix = ("| " + chr(96) + key + chr(96) + " ·"
                          if kind == "matrix" else "| " + key + " |")
                row = next(s for s in content[path].splitlines()
                           if s.startswith(prefix))
                for candidate in (
                    content[path].replace(row, "", 1),
                    content[path] + "\n" + row,
                    content[path].replace(
                        row, row.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1),
                ):
                    with self.subTest(key=key, path=path):
                        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                            rows(candidate, kind)
        for key, (_title, heading, candidate, _test, _phrases) in CLAUSES.items():
            with self.subTest(key=key):
                self.assertEqual(UNKNOWN, classify(key, {}))
                changed = dict(content)
                changed[SPEC] = changed[SPEC].replace(heading, "#### missing", 1)
                self.assertEqual(UNKNOWN, classify(key, changed))
                changed = dict(content)
                changed[candidate] = ""
                self.assertEqual(UNKNOWN, classify(key, changed))
        self.assertEqual(UNKNOWN, classify("D-050-S07", content))

    def test_sin_datos_reales(self) -> None:
        """AC-03: in-memory PHP DTOs; no HTTP, DB, live provider or secrets."""
        tree = ast.parse(Path(__file__).read_text(encoding="utf-8"))
        imports = {a.name.split(".")[0] for n in ast.walk(tree)
                   if isinstance(n, ast.Import) for a in n.names}
        imports |= {n.module.split(".")[0] for n in ast.walk(tree)
                    if isinstance(n, ast.ImportFrom) and n.module}
        self.assertFalse(imports & {"requests", "urllib", "httpx", "socket",
                                    "selenium", "playwright", "pymysql", "mysql"})
        for banned in ("curl_", "file_get_contents", "PDO", "shell_exec", "http://", "https://"):
            self.assertNotIn(banned, PHP_PROBE)
        self.assertIn("synthetic", json.dumps(smoke()))
        self.assertEqual(set(SOURCES), {
            MATRIX, CONTRAST, SPEC, PREF, CATALOG, EMAIL, NULL_EMAIL,
            BILLING, BILLING_REQUEST, BILLING_RESULT, BILLING_TEST, DI,
        })

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: local/CI claims must not become operational truth."""
        content = sources()
        for key in CLAUSES:
            with self.subTest(key=key):
                self.assertEqual(GAP, classify(key, content))
                for claim in (None, {}, True, {"ci": "success"},
                              {"mail": "sent"}, {"ux": "verified"},
                              {"tenant_extension": "verified"},
                              {"state": "VALIDATED_IN_PRODUCTION"},
                              {"code": True, "test": True, "pr": "merged"}):
                    self.assertEqual(UNKNOWN, operational(key, claim))


if __name__ == "__main__":
    unittest.main()
