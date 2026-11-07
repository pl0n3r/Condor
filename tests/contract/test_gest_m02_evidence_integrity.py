#!/usr/bin/env python3
"""#1793: evidencia offline de M02 S01–S08 (nunca producción real)."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DOC = ROOT / "docs/verificacion/gest-m02.md"
UNIT = "tests/php/Application/Contracts/GestM02PreviewTest.php"
BASIC = "tests/php/Http/ContractsSyntheticHttpAcceptanceTest.php"
LIFE = "tests/php/Http/ContractsLifecyclePreviewControllerTest.php"
UI = "frontend/admin/ContractsManagement.test.mjs"
MERGES = {"1794": "1055555e5357bb1a539d37766c5db2ce275fe2f3",
          "1803": "a8356ab16bf37877fc2b5d7f912d0d9917b1e9af",
          "1811": "3f108784e4870b473f5251ca8856c187bc50dee8"}
UNITS = ["testS01PlantillaVersionadaRequiereRevision",
         "testS02ContratoRequiereVersionDePlantillaYNoValidaFirma",
         "testS03OtrosiRechazaContratoAjenoYMotivoInvalido",
         "testS04EvidenciaFicticiaNoCertificaFirma",
         "testS05RenovacionSoloProponeYRechazaFechaAnterior",
         "testS06AlertaEsCandidataYNoNotificacionReal",
         "testS07TerminacionExigeMotivoYNoCierraContrato",
         "testS08LiquidacionNoCalculaNiApruebaValoresLegales"]
TITLES = ["GET lista: scope de vista, permiso y validación estricta de datos sintéticos",
          "GET detalle, POST y PATCH usan el método, CSRF y solo transiciones ficticias",
          "GET detalle, POST y PATCH usan el método, CSRF y solo transiciones ficticias"]


def validate(doc):
    errors = []
    rows = [[v.strip().strip(chr(96)) for v in line.strip().strip("|").split("|")]
            for line in doc.splitlines() if re.match(r"^\|\s*GEST-M02-S\d\d\s*\|", line)]
    if len(rows) != 8 or [r[0] for r in rows] != ["GEST-M02-S%02d" % x for x in range(1, 9)]:
        errors.append("Faltan filas S01-S08 o hay duplicados")
    sources = {f: (ROOT / f).read_text(encoding="utf-8")
               for f in (UNIT, BASIC, LIFE, UI)}
    for idx, row in enumerate(rows):
        if len(row) != 8:
            errors.append("Fila con columnas incorrectas")
            continue
        basic = idx < 3
        unit = UNIT + "::" + UNITS[idx]
        http = (BASIC + "::testOwnerIsolationAndManualReviewOverActualHttp" if basic
                else LIFE + "::testFiveLifecycleSlicesAreReadOnlyAndNeverLegallyApproved")
        if row[1] != unit or row[2] != http:
            errors.append("Referencia PHP falsa " + row[0])
        for ref in (unit, http):
            path, method = ref.split("::")
            if re.search(r"\bfunction\s+" + re.escape(method) + r"\s*\(", sources[path]) is None:
                errors.append("Metodo inexistente: " + method)
        if basic:
            expected_ui = UI + "::" + TITLES[idx]
            if row[3] != expected_ui or ("test('" + TITLES[idx] + "'") not in sources[UI]:
                errors.append("Test de UI ausente " + row[0])
        elif row[3] != "NO_APLICA":
            errors.append("UI no acreditada " + row[0])
        prs = ("1794", "1803") if basic else ("1811",)
        expected = {(number, MERGES[number]) for number in prs}
        actual = set(re.findall(r"#(\d+)@([0-9a-f]{40})", row[5]))
        if actual != expected or len(row[5].split()) != len(prs):
            errors.append("PR o SHA inventado " + row[0])
        if row[6] != "LOCAL_PARTIAL" or not row[7].startswith("UNKNOWN:"):
            errors.append("Cobertura real inflada " + row[0])
        if any(word in row[7] for word in ("CUBIERTO", "VALIDATED_IN_PRODUCTION", "PRODUCTION_GREEN")):
            errors.append("Estado productivo no probado " + row[0])
    for gate in ("#742", "D-059", "D-063", "datos.yml", "docs/matriz-cobertura.md",
                 "CONDOR_CONTRACTS_SYNTHETIC_API", "APP_ENV=test"):
        if gate not in doc:
            errors.append("Falta límite " + gate)
    return errors


class GestM02EvidenceIntegrityTests(unittest.TestCase):
    def test_ocho_filas_y_fuentes_locales(self):
        self.assertEqual(validate(DOC.read_text(encoding="utf-8")), [])

    def test_negativos_fallan_cerrados(self):
        content = DOC.read_text(encoding="utf-8")
        mutations = [
            content.replace(MERGES["1794"], "0" * 40),
            content.replace("#1794@", "#9999@"),
            content.replace("testOwnerIsolationAndManualReviewOverActualHttp", "testNoExiste"),
            content.replace(TITLES[0], "test UI inexistente"),
            content.replace("| LOCAL_PARTIAL |", "| CUBIERTO |", 1),
            content.replace("| UNKNOWN:", "| VALIDATED_IN_PRODUCTION:", 1),
            "\n".join(x for x in content.splitlines() if not x.startswith("| GEST-M02-S08 |")),
        ]
        for number, invalid in enumerate(mutations):
            with self.subTest(mutation=number):
                self.assertTrue(validate(invalid), "No detectó una evidencia inválida")


if __name__ == "__main__":
    unittest.main()
