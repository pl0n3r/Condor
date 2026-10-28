#!/usr/bin/env python3
"""Condor #1200: cinco brechas con evidencia local, sin atestación productiva.

Las pruebas estáticas/sintéticas no prueban despliegues, todas las carreras V3,
cuota real de GitHub ni telemetría histórica. Cada veredicto integral es UNKNOWN.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
AGENTS = ROOT / "AGENTES.md"
README = ROOT / "README.md"
POST_DEPLOY = ROOT / "scripts/post-deploy.sh"
TELEMETRY = ROOT / ".github/workflows/ci-throughput-telemetry.yml"
LOAD_TEST = ROOT / "tests/test_github_load_contract.py"

GAPS = {
    "AGENTES-07-06": ("AGENTES.md", "si backup o validación fallan, se falla cerrado."),
    "AGENTES-08-03": ("AGENTES.md", "La capacidad se decide por"),
    "AGENTES-08-04": ("AGENTES.md", "Pausar el despacho de trabajo nuevo"),
    "AGENTES-08-05": ("AGENTES.md", "La telemetría CI se muestrea"),
    "D-006": ("ESPECIFICACIONES.md", "README como snapshot del deploy"),
}
UNKNOWN = "UNKNOWN"
LOCAL_PARTIAL = "LOCAL_PARTIAL"


def coverage_rows(text: object) -> dict[str, tuple[str, ...]]:
    """No admitir filas ausentes, duplicadas, promovidas o ambiguas."""
    if not isinstance(text, str):
        raise ValueError("UNKNOWN: matriz ausente")
    found = {}
    pattern = re.compile(r"^\|\s*`(AGENTES-07-06|AGENTES-08-0[345]|D-006)`\s*·")
    for line in text.splitlines():
        match = pattern.match(line)
        if not match:
            continue
        gap = match.group(1)
        cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        source, title = GAPS[gap]
        expected = f"`{gap}` · `{source}`"
        if (gap in found or len(cells) != 5 or not all(cells)
                or cells[0] != expected or title not in cells[1]
                or cells[2] != "`NO_VERIFICABLE`"
                or gap != "D-006" and "Falta evidencia integral" not in cells[3]
                or not cells[4].startswith("#")):
            raise ValueError("UNKNOWN: fila inválida o repetida: " + gap)
        found[gap] = cells
    if set(found) != set(GAPS):
        raise ValueError("UNKNOWN: faltan filas de cobertura")
    return found


def quota_dispatch(remaining: object, global_limit: object = None) -> str:
    """Política sintética; no lee ni sustituye los límites de la API real."""
    if type(remaining) is not int or remaining < 0:
        return UNKNOWN
    if global_limit is not None and (type(global_limit) is not int or global_limit < 0):
        return UNKNOWN
    threshold = min(1200, global_limit) if global_limit is not None else 1200
    return "PAUSE" if remaining < threshold else LOCAL_PARTIAL


def synthetic_release(snapshot: object, receipt: object) -> str:
    """Coincidencia exacta de identidad en fixtures, nunca verificación live."""
    if not isinstance(snapshot, dict) or not isinstance(receipt, dict):
        return UNKNOWN
    if set(snapshot) != {"version", "sha"} or set(receipt) != {"version", "sha", "result", "source"}:
        return UNKNOWN
    version, sha = snapshot["version"], snapshot["sha"]
    if (not isinstance(version, str) or not re.fullmatch(r"\d+\.\d+\.\d+", version)
            or not isinstance(sha, str) or not re.fullmatch(r"[0-9a-f]{40}", sha)
            or receipt["source"] != "synthetic" or receipt["result"] != "success"
            or receipt["version"] != version or receipt["sha"] != sha):
        return UNKNOWN
    return LOCAL_PARTIAL


def local_signals(sources: object) -> dict[str, str]:
    """Detecta guardas en archivos públicos; faltante nunca implica éxito."""
    if (not isinstance(sources, dict) or set(sources) != {"agents", "readme", "post", "telemetry", "load"}
            or any(not isinstance(s, str) or not s.strip() for s in sources.values())):
        return {gap: UNKNOWN for gap in GAPS}
    agents, readme, post = sources["agents"], sources["readme"], sources["post"]
    telemetry, load = sources["telemetry"], sources["load"]
    head = telemetry.split("\npermissions:", 1)[0]
    signals = {
        "AGENTES-07-06": ("si backup o validación fallan, se falla cerrado" in agents
                              and "validate_construction_migrations" in post
                              and "cleanup_backup" in post
                              and "caché no modificada" in post),
        "AGENTES-08-03": ("claims de archivo disjuntos" in agents
                              and "reserva V3 válida" in agents
                              and "sin tope fijo de agentes por repo" in agents
                              and "test_agents_capacity_is_claim_based_not_fixed" in load),
        "AGENTES-08-04": ("1200 solicitudes" in agents
                              and "límite menor" in agents
                              and quota_dispatch(1199) == "PAUSE"
                              and quota_dispatch(1200) == LOCAL_PARTIAL),
        "AGENTES-08-05": ("telemetría CI se muestrea una vez por hora" in agents
                              and "schedule:" in head and "cron: '37 * * * *'" in head
                              and "workflow_run:" not in head
                              and "actions/artifacts?name=$artifact_name" in telemetry
                              and "test_telemetry_is_hourly_not_per_ci_run" in load),
        "D-006": ("README" in readme and "snapshot" in readme.casefold()
                  and "CI verde no equivale" in readme
                  and "config/version.php" in readme),
    }
    return {gap: LOCAL_PARTIAL if signal else UNKNOWN for gap, signal in signals.items()}


def integral_status(gap: str, evidence: object = None) -> str:
    """No existe recibo autenticado E2E en el alcance de esta hoja offline."""
    if gap not in GAPS:
        return UNKNOWN
    return UNKNOWN


class BrechasCondor05Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.rows = coverage_rows(cls.matrix)
        cls.sources = {"agents": AGENTS.read_text(encoding="utf-8"),
                       "readme": README.read_text(encoding="utf-8"),
                       "post": POST_DEPLOY.read_text(encoding="utf-8"),
                       "telemetry": TELEMETRY.read_text(encoding="utf-8"),
                       "load": LOAD_TEST.read_text(encoding="utf-8")}
        cls.local = local_signals(cls.sources)

    def test_contrato_principal(self) -> None:
        """AC-01: cada brecha tiene aserción local y cierre integral conservador."""
        self.assertEqual(set(self.rows), set(GAPS))
        for gap in GAPS:
            with self.subTest(gap=gap):
                self.assertEqual(self.rows[gap][2], "`NO_VERIFICABLE`")
                self.assertEqual(self.local[gap], LOCAL_PARTIAL)
                self.assertEqual(integral_status(gap, self.local[gap]), UNKNOWN)
        sha = "a" * 40
        receipt = {"version": "0.1.195", "sha": sha,
                   "result": "success", "source": "synthetic"}
        self.assertEqual(synthetic_release({"version": "0.1.195", "sha": sha}, receipt), LOCAL_PARTIAL)
        self.assertEqual(integral_status("D-006", receipt), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: filas corruptas, cuota ambigua y SHA divergente no pasan."""
        line = next(s for s in self.matrix.splitlines() if s.startswith("| `D-006`"))
        for invalid in (self.matrix.replace(line + "\n", ""),
                        self.matrix + "\n" + line,
                        self.matrix.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO")),
                        self.matrix.replace(line, "| `D-006` | corrupta |")):
            with self.subTest(case=len(invalid)):
                with self.assertRaises(ValueError):
                    coverage_rows(invalid)
        for key in self.sources:
            with self.subTest(removed=key):
                broken = dict(self.sources)
                broken.pop(key)
                self.assertEqual(set(local_signals(broken).values()), {UNKNOWN})
        for value in (None, -1, "1199", 1199.0, True, {}, []):
            self.assertEqual(quota_dispatch(value), UNKNOWN)
        self.assertEqual(quota_dispatch(1199), "PAUSE")
        self.assertEqual(quota_dispatch(1200), LOCAL_PARTIAL)
        self.assertEqual(quota_dispatch(600, 500), LOCAL_PARTIAL)
        self.assertEqual(quota_dispatch(499, 500), "PAUSE")
        self.assertEqual(quota_dispatch(1199, "500"), UNKNOWN)
        base = {"version": "0.1.195", "sha": "a" * 40}
        for invalid in (None, {}, {"result": "success"},
                        {"version": "0.1.195", "sha": "b" * 40,
                         "result": "success", "source": "synthetic"},
                        {"version": "0.1.195", "sha": "a" * 40,
                         "result": "failure", "source": "synthetic"},
                        {"version": "0.1.195", "sha": "a" * 40,
                         "result": "success", "source": "production"}):
            self.assertEqual(synthetic_release(base, invalid), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: solo archivos versionados y recibos sintéticos no secretos."""
        self.assertTrue(all(path.is_file() for path in
                            (MATRIX, AGENTS, README, POST_DEPLOY, TELEMETRY, LOAD_TEST)))
        fixture = {"run": "synthetic-ci-001", "lease": "synthetic-v3-001",
                   "backup": "synthetic-backup-failed", "release": "synthetic-release-001"}
        self.assertTrue(all(v.startswith("synthetic-") for v in fixture.values()))
        self.assertNotIn("@", " ".join(fixture.values()))
        for gap in GAPS:
            self.assertEqual(integral_status(gap, fixture), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: no promover CI, mock de cuota ni recibos sin autoridad a live."""
        forged = (None, "", {}, {"conclusion": "success"},
                  {"checks": "success", "sonar": "success"},
                  {"claims": "disjoint", "reservation": "V3"},
                  {"github_remaining": 1200},
                  {"hourly": True, "unique_artifacts": True},
                  {"version": "0.1.195", "sha": "a" * 40},
                  {"state": "VALIDATED_IN_PRODUCTION", "signed": True})
        for gap in GAPS:
            with self.subTest(gap=gap):
                self.assertEqual(self.rows[gap][2], "`NO_VERIFICABLE`")
                for evidence in forged:
                    self.assertEqual(integral_status(gap, evidence), UNKNOWN)
        self.assertEqual(integral_status("D-999", {"pass": True}), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
