#!/usr/bin/env python3
"""#781 AC-01: contrato HTTP real del contexto de tenant y permisos.

Se reutilizan WebTestCase de Symfony sobre dos tenants sintéticos. No se
sustituyen las respuestas HTTP por inspecciones estáticas ni se accede a live.
"""

from __future__ import annotations

import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/ApiContextController.php"
BRANCH_TEST = ROOT / "tests/php/Http/BranchAccessControllerTest.php"
ERROR_TEST = ROOT / "tests/php/Http/ApiErrorContractTest.php"
PHPUNIT = ROOT / "vendor/bin/simple-phpunit"
CONFIG = ROOT / "phpunit.xml.dist"

# Cada método usa Symfony WebTestCase y fixtures creados con sufijos aleatorios.
# Incluye accesos negados entre tenants, sedes ocultas, roles efectivos y 401.
HTTP_CASES = (
    "testContextReturnsBranchesAndEffectivePermissions",
    "testNonOwnerOnlySeesAssignedBranchesInContext",
    "testDelegatedContextDoesNotLeakHiddenLegalEntity",
    "testContextKeepsTenantWhenNoBranchIsAccessible",
    "testOwnerContextExposesAuthorizedLegalEntities",
    "testUnauthenticatedApiRequestReturnsJson401WithRequestId",
    "testCrossTenantIdsAreNotResolved",
)


class ContextoApiHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        """Ejecuta negativos HTTP contra Symfony, no fixtures inventados."""
        for path in (CONTROLLER, BRANCH_TEST, ERROR_TEST, CONFIG, PHPUNIT):
            self.assertTrue(path.is_file(), f"Componente HTTP ausente: {path}")

        controller = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("'/api/v1/context'", controller)
        self.assertIn("methods: ['GET']", controller)
        self.assertIn("'tenant' => $tenant", controller)
        self.assertIn("canAccessBranch(", controller)
        self.assertIn("AccessDeniedHttpException", controller)
        self.assertIn("'permissions' => $authorization->permissions(", controller)

        sources = (
            BRANCH_TEST.read_text(encoding="utf-8")
            + ERROR_TEST.read_text(encoding="utf-8")
        )
        for name in HTTP_CASES:
            self.assertIn("function " + name + "(", sources)

        # Sin el runtime no se afirma que el contrato HTTP se haya verificado.
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI requerido para HTTP de Symfony")

        result = subprocess.run(
            [
                str(PHPUNIT),
                "--configuration",
                str(CONFIG),
                "--filter",
                "|".join(HTTP_CASES),
            ],
            cwd=ROOT,
            capture_output=True,
            text=True,
            check=False,
            timeout=240,
        )
        output = result.stdout + result.stderr
        self.assertEqual(0, result.returncode, output)
        self.assertRegex(
            output,
            rf"OK\s*\(\s*{len(HTTP_CASES)} tests?,",
            "Las siete pruebas HTTP de contexto/aislamiento no se ejecutaron",
        )


if __name__ == "__main__":
    unittest.main()
