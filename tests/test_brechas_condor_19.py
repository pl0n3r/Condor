#!/usr/bin/env python3
"""Condor #1214: cobertura offline de D-049-S07..D-050-S02.

Aserciones sobre fuentes y casos PHP sintéticos ya existentes. Una fuente
parcial, un test local o un PR MERGED no prueban el storefront integral,
TLS/Hostinger, staff/invitaciones completos ni un despliegue productivo.
"""
from __future__ import annotations

import ast
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
BT = chr(96)
MATRIX = "docs/matriz-cobertura.md"
CONTRAST = "docs/contraste-cd_d3.md"
SPEC = "ESPECIFICACIONES.md"
RESOLVER = "src/Infrastructure/Tenancy/TenantResolver.php"
RESOLVER_TEST = "tests/php/Infrastructure/Tenancy/TenantResolverTest.php"
STAFF = "src/Http/Controller/PlatformStaffController.php"
STAFF_TEST = "tests/php/Http/PlatformStaffControllerTest.php"
NOTIFICATION = "src/Application/Notification/NotificationPreferenceService.php"
INVITATION = "src/Application/Identity/AcceptAccountInvitation.php"
INVITATION_ENTITY = "src/Domain/Identity/Entity/AccountInvitation.php"
THIS_TEST = "tests/test_brechas_condor_19.py"
SOURCES = frozenset((MATRIX, CONTRAST, SPEC, RESOLVER, RESOLVER_TEST,
                     STAFF, STAFF_TEST, NOTIFICATION, INVITATION,
                     INVITATION_ENTITY, THIS_TEST))

# El estado integral de cada fila se mantiene sin acreditar. Este leaf no
# modifica la matriz, no consulta red/Hostinger y no registra datos personales.
GAPS = {
    "D-049-S07": ("Storefront mínimo inicial", "#### Storefront mínimo inicial",
                  ("Twig/Symfony", "slug", "404/host desconocido", "tenants")),
    "D-049-S08": ("Hosting y portabilidad", "#### Hosting y portabilidad",
                  ("Hostinger shared hosting", "múltiples dominios", "TLS", "AWS")),
    "D-050": ("Staff de plataforma, invitaciones y notificaciones configurables",
              "### D-050 — Staff de plataforma, invitaciones y notificaciones configurables",
              ("ROLE_PLATFORM_OWNER", "ROLE_PLATFORM_STAFF", "invitaciones", "notificaciones")),
    "D-050-S01": ("Staff de plataforma", "#### Staff de plataforma",
                  ("tenant + módulo + acciones CRUD", "server-side", "suplantación")),
    "D-050-S02": ("Invitaciones y activación", "#### Invitaciones y activación",
                  ("inactivas", "hash irreversible", "consumo atómico", "rate limiting")),
}

# Contrato de evidencia de slices parciales. No equivale a cumplimiento
# completo, aun cuando el archivo de prueba PHP exista y contenga asserts.
ANCHORS = {
    "D-049-S07": {
        RESOLVER: ("if (self::isPlatformHost($host))", "'verified' => true",
                   "return $domain instanceof TenantDomain ? $domain->tenant() : null;"),
        RESOLVER_TEST: ("testItResolvesVerifiedDomainsWithoutCrossingTenants",
                        "self::assertNull($unverified)", "self::assertNotSame("),
    },
    "D-049-S08": {
        RESOLVER: ("'hostname' => $host", "'verified' => true"),
        RESOLVER_TEST: ("testItResolvesVerifiedDomainsWithoutCrossingTenants",
                        "self::assertNull($unverified)"),
    },
    "D-050": {
        NOTIFICATION: ("NotificationCatalog::isMandatory($eventKey)",
                       "->getRepository(NotificationPreference::class)",
                       "->persist($delivery)"),
        STAFF_TEST: ("testOwnerInvitesStaffWithHashedSingleUseTokenAndActivatesAccount",
                     "testNormalUserCannotManagePlatformStaff"),
        INVITATION: ("wrapInTransaction(", "->consume($now);"),
    },
    "D-050-S01": {
        STAFF: ("User::ROLE_PLATFORM_STAFF", "grantDefinitions(",
                "platform_staff_invite_revoke"),
        STAFF_TEST: ("testNormalUserCannotManagePlatformStaff",
                     "self::assertResponseStatusCodeSame(403)",
                     "testInviteRequiresCsrfAndRejectsUnknownTenantAtomically",
                     "self::assertResponseStatusCodeSame(422)"),
    },
    "D-050-S02": {
        INVITATION: ("wrapInTransaction(", "FOR UPDATE", "->isUsableAt($now)",
                     "hash('sha256', $rawToken)", "->activate();", "->consume($now);"),
        INVITATION_ENTITY: ("expiresAt", "consumedAt", "revokedAt"),
        STAFF_TEST: ("testOwnerInvitesStaffWithHashedSingleUseTokenAndActivatesAccount",
                     "hash('sha256', $rawToken)", "self::assertResponseStatusCodeSame(410)"),
    },
}

CONTRAST_SOURCE = {
    "D-049-S07": (RESOLVER, RESOLVER_TEST),
    "D-049-S08": (RESOLVER, RESOLVER_TEST),
    "D-050": (NOTIFICATION, "—"),
    "D-050-S01": (NOTIFICATION, "—"),
    "D-050-S02": (INVITATION, "—"),
}


def source(relative_path: str) -> str:
    """Lectura estrictamente de archivos permitidos del checkout local."""
    if relative_path not in SOURCES:
        raise ValueError("UNKNOWN: archivo fuera del alcance")
    return (ROOT / relative_path).read_text(encoding="utf-8")


def matrix_rows(raw: str) -> dict[str, tuple[str, ...]]:
    """Exige cinco IDs únicos, íntegros y explícitamente no verificables."""
    rows = {}
    for identifier, (title, *_rest) in GAPS.items():
        prefix = f"| {BT}{identifier}{BT} · {BT}ESPECIFICACIONES.md{BT} |"
        matches = [line for line in raw.splitlines() if line.startswith(prefix)]
        if len(matches) != 1:
            raise ValueError(f"UNKNOWN: fila ausente/duplicada {identifier}")
        cells = tuple(x.strip() for x in matches[0].strip("|").split("|"))
        if (len(cells) != 5 or cells[0] != f"{BT}{identifier}{BT} · {BT}ESPECIFICACIONES.md{BT}"
                or cells[1] != title or cells[2] != f"{BT}NO_VERIFICABLE{BT}"
                or f"Falta evidencia integral {identifier}" not in cells[3]
                or "tríada código/test/PR MERGED" not in cells[3]
                or "contraste-cd_d3.md" not in cells[3] or cells[4] != "#1"):
            raise ValueError(f"UNKNOWN: matriz ambigua {identifier}")
        rows[identifier] = cells
    return rows


def contrast_rows(raw: str) -> dict[str, tuple[str, ...]]:
    """Contrasta las rutas candidatas sin convertirlas en acreditación."""
    rows = {}
    for identifier, (code_path, test_path) in CONTRAST_SOURCE.items():
        matches = [line for line in raw.splitlines()
                   if line.startswith(f"| {identifier} |")]
        if len(matches) != 1:
            raise ValueError(f"UNKNOWN: contraste ausente/duplicado {identifier}")
        cells = tuple(x.strip() for x in matches[0].strip("|").split("|"))
        if (len(cells) != 6 or cells[:5] !=
                (identifier, "NO_VERIFICABLE", code_path, test_path, "—")
                or identifier not in cells[5] or "Falta acreditar integralmente" not in cells[5]):
            raise ValueError(f"UNKNOWN: contraste ambiguo {identifier}")
        rows[identifier] = cells
    return rows


def operational_verdict(identifier: str, claim: object = None) -> str:
    """Las afirmaciones sintéticas nunca prueban producción ni completitud."""
    if identifier not in GAPS:
        raise ValueError(f"UNKNOWN: ID inesperado {identifier}")
    return UNKNOWN


class BrechasCondor19Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: cinco filas exactas con contrato y evidencia parcial por ID."""
        rows = matrix_rows(source(MATRIX))
        contrasts = contrast_rows(source(CONTRAST))
        specification = source(SPEC)
        self.assertEqual(set(GAPS), set(rows))
        self.assertEqual(set(GAPS), set(contrasts))
        for identifier, (_title, heading, clauses) in GAPS.items():
            with self.subTest(identifier=identifier):
                self.assertIn(heading, specification)
                for clause in clauses:
                    self.assertIn(clause, specification)
                self.assertEqual(operational_verdict(identifier, rows[identifier]), UNKNOWN)
                for path, fragments in ANCHORS[identifier].items():
                    content = source(path)
                    for fragment in fragments:
                        self.assertIn(fragment, content, f"{identifier}: {path}: {fragment}")

    def test_falla_cerrado(self) -> None:
        """AC-02: filas ausentes, duplicadas, promovidas o sin ruta fallan."""
        matrix = source(MATRIX)
        contrast = source(CONTRAST)
        identifier = "D-050-S02"
        line = next(x for x in matrix.splitlines()
                    if x.startswith(f"| {BT}{identifier}{BT} · {BT}ESPECIFICACIONES.md{BT} |"))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_rows(matrix.replace(line, ""))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_rows(matrix + "\n" + line)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_rows(matrix.replace(line, line.replace(
                f"{BT}NO_VERIFICABLE{BT}", f"{BT}CUBIERTO{BT}")))
        contrast_line = next(x for x in contrast.splitlines()
                             if x.startswith(f"| {identifier} |"))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            contrast_rows(contrast.replace(contrast_line, ""))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            contrast_rows(contrast + "\n" + contrast_line)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            source("../.env")

    def test_sin_datos_reales(self) -> None:
        """AC-03: fixtures locales, sin red, secretos, clientes o procesos."""
        fixture = {"host": "tenant-a.example.test", "tenant": "sintetico-a",
                   "token": "0" * 64, "staff": "operador@example.test"}
        self.assertTrue(fixture["host"].endswith(".example.test"))
        self.assertTrue(fixture["staff"].endswith("@example.test"))
        self.assertEqual(operational_verdict("D-050-S02", fixture), UNKNOWN)
        tree = ast.parse(source(THIS_TEST))
        imports = {alias.name.split(".", 1)[0]
                   for node in ast.walk(tree) if isinstance(node, ast.Import)
                   for alias in node.names}
        imports.update(node.module.split(".", 1)[0]
                       for node in ast.walk(tree)
                       if isinstance(node, ast.ImportFrom) and node.module)
        self.assertTrue(imports.isdisjoint({"socket", "urllib", "requests",
                                            "subprocess", "http", "sqlite3",
                                            "pymysql", "mysql"}))
        self.assertEqual(len(GAPS), 5)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: fuente, tests o relatos de merge no certifican live."""
        assertions = [None, {}, {"status": "GREEN"},
                      {"pr": "MERGED", "validate": "success"},
                      {"tls": "claimed", "hostinger": "claimed"}]
        for identifier in GAPS:
            for evidence in assertions:
                with self.subTest(identifier=identifier, evidence=evidence):
                    self.assertEqual(operational_verdict(identifier, evidence), UNKNOWN)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            operational_verdict("D-050-S03", {"pr": "MERGED"})


if __name__ == "__main__":
    unittest.main()
