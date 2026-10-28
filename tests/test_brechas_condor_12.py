"""Regresiones offline de las cinco brechas D-041-S09 a D-043-S02 (#1207).

Solo pruebas sintéticas: no acreditan go-live, despliegue ni estado
VALIDATED_IN_PRODUCTION de un entorno real. La matriz sigue siendo
NO_VERIFICABLE hasta la reconciliación independiente.
"""

from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = ROOT / "scripts"
if str(SCRIPTS) not in sys.path:
    sys.path.insert(0, str(SCRIPTS))

IDS = {
    "D-041-S09": "Lenguaje y microcopy",
    "D-042-S05": "Métrica de avance",
    "D-043": "Entrega, release identity y validación de producción",
    "D-043-S01": "Estados canónicos de una entrega",
    "D-043-S02": "Release identity",
}
VERSION = "0.1.195"
SHA = "a" * 40


def load_script(name: str):
    """Importa lógica pura existente; no invoca CLI, HTTP ni un observador."""
    spec = importlib.util.spec_from_file_location(name, SCRIPTS / f"{name}.py")
    if spec is None or spec.loader is None:
        raise AssertionError(f"No existe el script local {name}")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


release = load_script("release_evidence")
pending = load_script("d043_pending_releases")
phase = load_script("d043_phase_resolution")


def matrix_rows() -> dict[str, list[str]]:
    """Obtiene solo las filas reclamadas, sin reescribir ni inferir la matriz."""
    rows: dict[str, list[str]] = {}
    for line in (ROOT / "docs" / "matriz-cobertura.md").read_text(
        encoding="utf-8"
    ).splitlines():
        if not line.startswith("| `"):
            continue
        cells = [cell.strip() for cell in line.strip("|").split("|")]
        row_id = cells[0].split(" · ", 1)[0].strip(" `")
        if row_id in IDS:
            if row_id in rows:
                raise AssertionError(f"Fila duplicada: {row_id}")
            rows[row_id] = cells
    return rows


def manifest(changed_paths: tuple[str, ...] = ("README.md",)) -> dict:
    return release.build_manifest_explicit(
        version=VERSION, sha=SHA, changed_paths=changed_paths
    )


def observation(
    *, state: str = "VALIDATED_IN_PRODUCTION", sha: str = SHA,
    missing: str | None = None,
) -> dict:
    checks = {
        name: {
            "ok": True,
            "clase": "ok",
            "detalle": "Fixture sintética, no observación de producción.",
        }
        for name in release.public_checks_for_version(VERSION)
        if name != missing
    }
    return {
        "estado": state,
        "version_esperada": VERSION,
        "sha_esperado": sha,
        "comprobaciones": checks,
    }


def bot_comment(body: str, login: str = "github-actions[bot]") -> dict:
    return {"user": {"login": login}, "body": body}


def deploy_body(version: str, sha: str) -> str:
    return (
        f"🚧 DEPLOY_OBSERVED automático: producción sirve V {version} ({sha}), "
        "pero no se declara validada."
    )


def validated_body(version: str, sha: str) -> str:
    return (
        f"✅ VALIDATED_IN_PRODUCTION automático: producción sirve V {version} "
        f"({sha}) y los smoke checks de solo lectura pasaron."
    )


class BrechasCondor12Tests(unittest.TestCase):
    """Criterios máquina AC-01 a AC-04, con casos negativos para las cinco filas."""

    def test_contrato_principal(self) -> None:
        """AC-01: ninguna fila queda muda; contratos de UI, avance y release."""
        rows = matrix_rows()
        self.assertEqual(set(rows), set(IDS))
        for row_id, title in IDS.items():
            with self.subTest(brecha=row_id):
                cells = rows[row_id]
                self.assertIn(title, cells[1])
                self.assertEqual(cells[2], "`NO_VERIFICABLE`")
                self.assertIn(row_id, cells[3])
                self.assertIn("Falta evidencia integral", cells[3])

        spec = (ROOT / "ESPECIFICACIONES.md").read_text(encoding="utf-8")
        login = (ROOT / "templates" / "security" / "login.html.twig").read_text(
            encoding="utf-8"
        )

        # D-041-S09: microcopy visible, acción, error comprensible y control CSRF.
        self.assertIn("#### Lenguaje y microcopy", spec)
        self.assertIn("español de Colombia natural, profesional y directo", spec)
        self.assertIn("Ingresar a Condor", login)
        self.assertIn('role="alert"', login)
        self.assertIn("No pudimos iniciar sesión con esos datos.", login)
        self.assertIn("¿Olvidaste tu contraseña?", login)
        self.assertIn('name="_csrf_token"', login)

        # D-042-S05: no inferir progreso de porcentaje de archivos.
        self.assertIn("#### Métrica de avance", spec)
        self.assertIn("slices integrados y validados", spec)
        self.assertIn("no por porcentaje subjetivo", spec)
        self.assertIn("evidencia trazable en el roadmap", spec)

        # D-043 / S01 / S02: estado e identidad exactos en el módulo real.
        self.assertIn("### D-043 — Entrega, release identity", spec)
        for state in (
            "VALIDATED_IN_CODE", "DEPLOY_OBSERVED", "VALIDATED_IN_PRODUCTION"
        ):
            self.assertIn(state, spec)
        built = manifest()
        self.assertEqual(built["version_source"], "config/version.php")
        self.assertEqual((built["version"], built["sha"]), (VERSION, SHA))
        release.validate_manifest(built)
        self.assertEqual(
            release.validated_release_identity(built), (VERSION, SHA)
        )

    def test_falla_cerrado(self) -> None:
        """AC-02: identidad ambigua, humo faltante y transición incompleta."""
        built = manifest()
        ok = release.finalize(built, observation(), ())
        self.assertEqual(ok["estado"], "VALIDATED_IN_PRODUCTION")

        wrong_sha = release.finalize(built, observation(sha="b" * 40), ())
        self.assertEqual(wrong_sha["estado"], "NO_OBSERVADO")
        self.assertFalse(wrong_sha["identity_match"])

        missing_smoke = release.finalize(
            built, observation(missing="admin_login"), ()
        )
        self.assertEqual(missing_smoke["estado"], "DEPLOY_OBSERVED")
        self.assertFalse(missing_smoke["public_checks"]["admin_login"]["ok"])

        only_deploy = release.finalize(
            built, observation(state="DEPLOY_OBSERVED"), ()
        )
        self.assertEqual(only_deploy["estado"], "DEPLOY_OBSERVED")

        for invalid_sha in ("", "not-a-sha", "b" * 39, "B" * 40):
            with self.subTest(sha=invalid_sha):
                with self.assertRaises(release.EvidenceError):
                    release.build_manifest_explicit(
                        version=VERSION,
                        sha=invalid_sha,
                        changed_paths=("README.md",),
                    )

        # La misma versión no puede corresponder a dos SHAs acreditados.
        with self.assertRaises(pending.PendingReleaseError):
            pending.reduce_pending_releases([
                bot_comment(deploy_body(VERSION, SHA)),
                bot_comment(deploy_body(VERSION, "b" * 40)),
            ])

    def test_sin_datos_reales(self) -> None:
        """AC-03: datos artificiales, sin red y sin secretos en la evidencia."""
        built = manifest()
        synthetic = observation()
        evidence = release.finalize(built, synthetic, ())
        self.assertEqual(evidence["version"], VERSION)
        self.assertEqual(evidence["sha"], SHA)
        self.assertEqual(evidence["schema"], release.SCHEMA)
        self.assertEqual(set(evidence["public_checks"]), set(built["public_checks"]))

        # Ni claves privadas ni datos personales son parte del contrato.
        serialized = json.dumps(
            evidence, ensure_ascii=False, sort_keys=True
        ).lower()
        for field in ("password", "authorization", "access_token", "database_url",
                      "email", "telefono", "documento_identidad"):
            self.assertNotIn(field, serialized)

        # Los eventos de personas no pueden pasar por evidencia de bot.
        human = bot_comment(validated_body(VERSION, SHA), login="persona")
        self.assertEqual(pending.reduce_pending_releases([human]), [])
        self.assertEqual(pending.canonical_event(human), None)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: la falta de evidencia queda UNKNOWN, nunca cumplimiento."""
        rows = matrix_rows()
        for row_id in IDS:
            with self.subTest(brecha=row_id):
                # NO_VERIFICABLE no es SIN_COBERTURA ni VALIDADO.
                self.assertEqual(rows[row_id][2], "`NO_VERIFICABLE`")
                self.assertNotIn("VALIDATED_IN_PRODUCTION", rows[row_id][2])

        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / "datos.yml"
            self.assertEqual(
                phase.resolve_phase(source, "pl0n3r/Condor"),
                phase.PHASE_UNKNOWN,
            )
            source.write_text(
                '{"version":1,"project":"pl0n3r/Condor",'
                '"phase":"live","phase":"construccion"}',
                encoding="utf-8",
            )
            self.assertEqual(
                phase.resolve_phase(source, "pl0n3r/Condor"),
                phase.PHASE_UNKNOWN,
            )
            source.write_text(
                '{"version":1,"project":"otro/proyecto","phase":"live"}',
                encoding="utf-8",
            )
            self.assertEqual(
                phase.resolve_phase(source, "pl0n3r/Condor"),
                phase.PHASE_UNKNOWN,
            )

        # Una validación sintética de otro SHA no cubre la release pendiente.
        pending_release = bot_comment(deploy_body(VERSION, SHA))
        unrelated = bot_comment(validated_body("0.1.196", "b" * 40))
        self.assertEqual(
            pending.reduce_pending_releases([pending_release, unrelated]),
            [{"version": VERSION, "sha": SHA}],
        )
        # Ni un mensaje humano sobre la MISMA release valida el estado.
        spoofed = bot_comment(validated_body(VERSION, SHA), login="persona")
        self.assertEqual(
            pending.reduce_pending_releases([pending_release, spoofed]),
            [{"version": VERSION, "sha": SHA}],
        )
        self.assertEqual(
            pending.reduce_pending_releases([
                pending_release, bot_comment(validated_body(VERSION, SHA))
            ]),
            [],
        )


if __name__ == "__main__":
    unittest.main()
