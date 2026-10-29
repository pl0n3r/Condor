#!/usr/bin/env python3
"""Condor #1330: real PHP acceptance, synthetic data, no production claim.

An A classification tests a LOCAL sub-contract only. B requires real
DNS/TLS, hosting, release or full browser/notification evidence. No row
is promoted to CUBIERTO and no production system is contacted.
"""
from __future__ import annotations

import re
import shutil
import subprocess
import unittest
from pathlib import Path

from scripts.ci_change_classifier import classify

ROOT = Path(__file__).resolve().parents[1]
PHPUNIT = ROOT / "vendor/bin/simple-phpunit"
CONFIG = ROOT / "phpunit.xml.dist"
UNKNOWN = "UNKNOWN"

OFFLINE = {
    "D-049-S01": "TenantResolver distinguishes platform slug and exact verified tenant host.",
    "D-049-S02": "TenantDomain normalization and real DB unique active primary enforcement.",
    "D-049-S04": "Unknown/unverified Host rejected and cross-tenant request cannot escape scope.",
    "D-049-S05": "StorefrontPresentation uses verified primary canonical or safe slug fallback.",
    "D-049-S06": "Storefront admin owner/reader permission, CSRF and tenant isolation.",
    "D-049-S07": "Real Twig/Symfony SSR serves only the selected tenant and safe canonical.",
    "D-050-S01": "Platform staff grants tenant-scoped CRUD, revocation and owner-only management.",
}
EXTERNAL = {
    "D-048-S02": "Functional progress needs a real user-visible runtime and end-to-end observation.",
    "D-049": "Complete domain storefront needs authentic ownership, DNS/TLS and deployment.",
    "D-049-S03": "DNS control, propagation and HTTPS certificate need independent real receipts.",
    "D-049-S08": "Hostinger multi-domain TLS and portability require verified hosting capability.",
    "D-050": "Full invitations, mail and notification delivery requires broader real integration.",
}
DECLARATIVE = {}
ABSENT = {}
ALL = frozenset(OFFLINE) | frozenset(EXTERNAL)

# Existing PHPUnit methods run the genuine production PHP implementations.
# Run them, do not substitute an assertion about the method's source text.
POSITIVE = (
    ("tests/php/Infrastructure/Tenancy/TenantResolverTest.php",
     ("testItResolvesVerifiedDomainsWithoutCrossingTenants",)),
    ("tests/php/Application/Storefront/StorefrontPresentationTest.php",
     ("testMissingProfileOrVerifiedPrimaryFailsClosedToCondor",
      "testVerifiedPrimaryAndPublicProfileAreRenderedWithoutTrustingHtml",
      "testAmbiguousVerifiedPrimariesNeverClaimAExternalCanonical",
      "testQueriesAreScopedByExactTenant")),
    ("tests/php/Http/StorefrontAdminControllerTest.php",
     ("testOwnerCanEditIdentityAndSeeItOnPublicSlug",
      "testVerifiedPrimaryDomainServesSameTenantAndCanonical",
      "testTenantCannotPersistTwoVerifiedPrimaryDomains",
      "testVerifiedDomainDoesNotLeakAnotherTenantProfile")),
    ("tests/php/Application/Identity/PlatformStaffAuthorizationTest.php",
     ("testStaffUsesExplicitTenantAndGlobalCrudGrantsWithoutMembership",
      "testOwnerGetsCatalogPermissionsButNormalUserGetsNone")),
    ("tests/php/Application/Identity/PlatformStaffManagerTest.php",
     ("testInvitationGrantReplacementAndRevocationAreTenantScoped",
      "testTenantAdminCannotReplacePlatformStaffGrants",
      "testTenantAdminCannotInvitePlatformStaff")),
)
NEGATIVE = (
    ("tests/php/Http/StorefrontAdminControllerTest.php",
     ("testOwnerPostRejectsMissingOrInvalidCsrfWithoutChangingProfile",
      "testLegacyWriterCannotBypassPrimaryDomainUniqueness",
      "testLegacyWriterCannotPromoteSecondVerifiedDomain",
      "testUnverifiedCustomHostFailsClosed",
      "testUnknownCustomHostFailsClosed",
      "testDelegatedViewPermissionStaysReadOnly",
      "testDelegatedUpdatePermissionCannotEditTenantWideStorefront")),
    ("tests/php/Http/PlatformStaffControllerTest.php",
     ("testNormalUserCannotManagePlatformStaff",
      "testInviteRequiresCsrfAndRejectsUnknownTenantAtomically")),
)


def run_php_contract(path: str, methods: tuple[str, ...]) -> bool:
    """Execute real local PHP code; unavailable dependencies never pass."""
    if not path.startswith("tests/php/") or not path.endswith("Test.php"):
        raise AssertionError("UNKNOWN: unauthorized fixture path")
    if not methods or any(not re.fullmatch(r"test[A-Za-z0-9_]+", m) for m in methods):
        raise AssertionError("UNKNOWN: invalid selected test")
    target = ROOT / path
    if not target.is_file() or not PHPUNIT.is_file() or not CONFIG.is_file():
        raise AssertionError("UNKNOWN: PHP fixture/dependencies missing")
    source = target.read_text(encoding="utf-8")
    if any("function " + name + "(" not in source for name in methods):
        raise AssertionError("UNKNOWN: real PHP method absent")
    if not shutil.which("php"):
        raise AssertionError("UNKNOWN: PHP interpreter unavailable")
    selection = "|".join(re.escape(m) for m in methods)
    result = subprocess.run(
        [str(PHPUNIT), "--configuration", str(CONFIG),
         "--filter", selection, str(target)],
        cwd=ROOT, capture_output=True, text=True, check=False, timeout=240,
    )
    # Fail without echoing potentially identifying test fixture/environment data.
    if result.returncode:
        raise AssertionError("UNKNOWN: real PHP behavior failed in " + path)
    output = result.stdout + result.stderr
    if not re.search(r"\bOK\s*\(\s*[1-9]\d*\s+tests?\b|\bTests:\s*[1-9]\d*\b", output):
        raise AssertionError("UNKNOWN: no real PHP tests executed")
    return True


class CoberturaCondor11Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: execute real PHP methods for 7 partial storefront/staff rows."""
        self.assertEqual(12, len(ALL))
        self.assertEqual(7, len(OFFLINE))
        self.assertEqual(5, len(EXTERNAL))
        self.assertEqual({}, DECLARATIVE)
        self.assertEqual({}, ABSENT)
        for test_file, methods in POSITIVE:
            with self.subTest(file=test_file):
                self.assertTrue(run_php_contract(test_file, methods))

    def test_falla_cerrado(self) -> None:
        """AC-02: reject forged matrix, unknown host, CSRF, scope and privileges."""
        for test_file, methods in NEGATIVE:
            with self.subTest(file=test_file):
                self.assertTrue(run_php_contract(test_file, methods))
        for name, methods in (
            ("../../some-other-file.php", ("testAttack",)),
            (POSITIVE[0][0], ("testFakeNeverExisting",)),
        ):
            with self.assertRaisesRegex(AssertionError, UNKNOWN):
                run_php_contract(name, methods)

    def test_sin_datos_reales(self) -> None:
        """AC-03: synthetic local CI fixtures, no external network or live data."""
        for test_file, methods in (*POSITIVE, *NEGATIVE):
            with self.subTest(file=test_file):
                self.assertTrue(test_file.startswith("tests/php/"))
                self.assertTrue((ROOT / test_file).is_file())
                self.assertTrue(methods)
        self.assertTrue(all(EXTERNAL.values()))
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(source, r"(?m)^\s*(?:import|from)\s+(?:requests|httpx|socket|urllib|pymysql)\b")
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", source)
        selection = classify(["tests/test_cobertura_condor_11.py"], "pull_request")
        self.assertTrue(selection.pruebas_base)
        self.assertIn(selection.modo, {"selectivo", "completo"})


if __name__ == "__main__":
    unittest.main()
