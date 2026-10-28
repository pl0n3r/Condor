#!/usr/bin/env python3
"""Contratos sintéticos D-005, D-009, D-012 y D-014 para Condor #1182.

No acredita un deploy, una reserva remota ni la integración REST de todos
los títulos de GitHub. Esas evidencias deben comprobarse por separado.
"""
from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from scripts import release_identity_guard as release
from scripts.ci_throughput_report import (
    WORKFLOW_NAME, build_report, comparable_runs, regression_result,
)

ROOT = Path(__file__).resolve().parents[1]
SHA = "a" * 40
SYNTHETIC_SECRET = "SYNTHETIC_SECRET_NEVER_LOG"


def version_root(directory: str, version: str = "0.1.195") -> Path:
    root = Path(directory)
    (root / "config").mkdir(exist_ok=True)
    (root / "config/version.php").write_text(
        "<?php\n\ndeclare(strict_types=1);\n\n"
        f"return [\n    'version' => '{version}',\n];\n",
        encoding="utf-8",
    )
    return root


def git_history(_root: Path, *args: str) -> str:
    if args == ("tag", "--list"):
        return "v0.1.194\n"
    raise AssertionError(f"Git sintético no autorizado: {args!r}")


def sample(run_id: int, event: str = "pull_request",
           conclusion: str = "success") -> dict:
    return {
        "id": run_id, "name": WORKFLOW_NAME, "event": event,
        "conclusion": conclusion, "head_sha": "b" * 40,
        "run_started_at": "2026-10-28T10:00:00Z",
        "updated_at": "2026-10-28T10:00:20Z",
    }


class ContrasteDL3Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        # D-005: ejecutar la guarda real de versión nueva, no un regex aislado.
        with tempfile.TemporaryDirectory() as folder:
            with patch.object(release, "git", side_effect=git_history):
                identity = release.validate(
                    version_root(folder), "release (V 0.1.195)", SHA
                )
        self.assertEqual(identity, ("v0.1.195", "v0.1.194"))

        # D-009: gramática transversal de títulos; no implica que cada
        # endpoint GitHub tenga ya su integración de validación.
        for artifact in ("Issue", "PR", "Release", "Milestone"):
            with self.subTest(artifact=artifact):
                self.assertIsNotNone(
                    release.TITLE_RE.search(f"{artifact} ficticio (V 0.1.195)")
                )

        # D-012: el wiring real serializa por issue y rechaza impersonación.
        # El workflow delega la atomicidad al coordinador Factory@v1.
        workflow = (
            ROOT / ".github/workflows/coordinacion-trabajo.yml"
        ).read_text(encoding="utf-8")
        self.assertIn("cancel-in-progress: false", workflow)
        self.assertIn(
            "group: coordinacion-" + "$" + "{{ github.event.issue.number",
            workflow,
        )
        self.assertIn(
            "github.event.sender.login == github.event.comment.user.login",
            workflow,
        )
        self.assertIn("github.event.comment.body == '/tomar'", workflow)
        self.assertIn(
            "python3 .factory/scripts/coordinar_trabajo.py comentario",
            workflow,
        )

        # D-014: la baseline usa únicamente runs exitosos del mismo evento.
        history = {"workflow_runs": [
            sample(1), sample(2, "push"),
            sample(3, conclusion="failure"), sample(4),
        ]}
        selected = comparable_runs(history, 99, "pull_request")
        self.assertEqual({x["run_id"] for x in selected}, {1, 4})
        baseline = [{"duration_seconds": 20} for _ in range(3)]
        self.assertEqual(
            regression_result(36, baseline, "success")["status"],
            "regression",
        )

    def test_falla_cerrado(self) -> None:
        # D-005/D-009: se rechazan títulos sin versión canónica, variantes
        # minúsculas, sufijos ajenos y versiones que difieren del artefacto.
        with tempfile.TemporaryDirectory() as folder:
            root = version_root(folder)
            with patch.object(release, "git", side_effect=git_history):
                for title in (
                    "release sin versión", "release (v 0.1.195)",
                    "release (V 0.1.194)", "release (V 0.1.195) extra",
                ):
                    with self.subTest(title=title):
                        with self.assertRaises(release.GuardError):
                            release.validate(root, title, SHA)
                with self.assertRaises(release.GuardError):
                    release.validate(root, "release (V 0.1.195)", "invalid")

        # D-005: una versión ya utilizada no se puede volver a publicar.
        def duplicate(_root: Path, *args: str) -> str:
            if args == ("tag", "--list"):
                return "v0.1.195\n"
            if args == ("rev-list", "-n", "1", "v0.1.195"):
                return "b" * 40
            raise AssertionError(args)

        with tempfile.TemporaryDirectory() as folder:
            with patch.object(release, "git", side_effect=duplicate):
                with self.assertRaisesRegex(
                    release.GuardError, "already exists"
                ):
                    release.validate(
                        version_root(folder), "release (V 0.1.195)", SHA
                    )

        # D-014: nunca se comparan pushes o fallos con el evento del PR.
        history = {"workflow_runs": [
            sample(1, "push"), sample(2, conclusion="failure"),
        ]}
        self.assertEqual(comparable_runs(history, 99, "pull_request"), [])
        baseline = [{"duration_seconds": 20} for _ in range(3)]
        self.assertEqual(
            regression_result(60, baseline, "failure")["status"],
            "not_evaluated",
        )

    def test_sin_datos_reales(self) -> None:
        # D-014: el reporte ignora propiedades no permitidas incluso si una
        # API upstream devolviese un secreto ficticio.
        meta = {
            "run_id": 101, "source_sha": SHA, "event": "pull_request",
            "conclusion": "success",
            "started_at": "2026-10-28T10:00:00Z",
            "updated_at": "2026-10-28T10:00:40Z",
            "raw_token": SYNTHETIC_SECRET,
        }
        pages = [{"jobs": [{
            "name": "Validar gobierno", "conclusion": "success",
            "started_at": meta["started_at"],
            "completed_at": meta["updated_at"],
            "authorization": SYNTHETIC_SECRET,
        }]}]
        report = build_report(pages, {"workflow_runs": []}, meta)
        self.assertEqual(report["source_sha"], SHA)
        self.assertNotIn(SYNTHETIC_SECRET, json.dumps(report))
        self.assertNotIn("raw_token", report)
        self.assertNotIn("authorization", report["jobs"][0])

    def test_estados_unknown_explicitos(self) -> None:
        # D-005: un archivo de versión ilegible falla cerrado.
        with tempfile.TemporaryDirectory() as folder:
            root = version_root(folder)
            (root / "config/version.php").write_text(
                "<?php // sin return canónico\n", encoding="utf-8"
            )
            with self.assertRaisesRegex(
                release.GuardError, "exactly one canonical version"
            ):
                release.validate(root, "release (V 0.1.195)", SHA)

        # D-014: sin baseline completa no se declara velocidad aceptable.
        self.assertEqual(
            regression_result(
                120, [{"duration_seconds": 20}], "success"
            )["status"],
            "insufficient_baseline",
        )
        self.assertEqual(comparable_runs(None, 99, "pull_request"), [])
        self.assertEqual(
            comparable_runs({"workflow_runs": "UNKNOWN"}, 99, "pull_request"),
            [],
        )


if __name__ == "__main__":
    unittest.main()
