#!/usr/bin/env python3
"""Regresiones offline de Condor #1327 con comportamiento real D-043.

El navegador/hosting real de D-041 y el despliegue integral D-043 exigen
evidencia independiente; estas pruebas no certifican producción.
"""
from __future__ import annotations

import socket
import subprocess
import unittest
from unittest.mock import patch

from scripts import d043_pending_releases as pending
from scripts import release_evidence as release

SHA = "a" * 40
OTHER_SHA = "b" * 40
VERSION = "0.1.195"
MIGRATION = "migrations/Version20450101000000.php"
ADD_MIGRATION = """<?php
final class Version20450101000000 {
    public function up(Schema $schema): void {
        $this->addSql('CREATE TABLE synthetic_notes (id INT NOT NULL)');
    }
    public function down(Schema $schema): void {}
}
"""
DROP_MIGRATION = ADD_MIGRATION.replace(
    "CREATE TABLE synthetic_notes (id INT NOT NULL)",
    "DROP TABLE synthetic_notes",
)


def _bot_event(state: str, sha: str = SHA, version: str = VERSION) -> dict:
    if state == "pending":
        message = f"🚧 DEPLOY_OBSERVED automático: producción sirve V {version} ({sha}), prueba sintética."
    else:
        message = f"✅ VALIDATED_IN_PRODUCTION automático: producción sirve V {version} ({sha}) prueba sintética."
    return {"user": {"login": pending.BOT_LOGIN}, "body": message}


def _manifest() -> dict:
    return release.build_manifest_explicit(
        version=VERSION, sha=SHA, changed_paths=[MIGRATION]
    )


def _observation(*, sha: str = SHA, complete: bool = True) -> dict:
    checks = {
        name: {"ok": True, "clase": "ok", "detalle": "fixture sintético"}
        for name in release.public_checks_for_version(VERSION)
    }
    checks["schema"] = {"ok": True}
    checks["post_deploy_status"] = {
        "ok": complete,
        "phase": "complete" if complete else "pending",
        "result": "success" if complete else "pending",
    }
    return {
        "estado": "DEPLOY_OBSERVED",
        "version_esperada": VERSION,
        "sha_esperado": sha,
        "comprobaciones": checks,
    }


class CoberturaCondor08Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """D-043-S01/S02/S05: ejecutar reducer, identidad y migraciones reales."""
        event = _bot_event("pending")
        self.assertEqual(
            pending.reduce_pending_releases([event]),
            [{"version": VERSION, "sha": SHA}],
        )
        self.assertEqual(
            pending.reduce_pending_releases([event, _bot_event("validated")]),
            [],
        )
        manifest = _manifest()
        release.validate_manifest(manifest)
        self.assertEqual((manifest["version"], manifest["sha"]), (VERSION, SHA))
        self.assertEqual(manifest["version_source"], "config/version.php")
        requirements = {
            item["id"]: item["required"]
            for item in manifest["transition"]["checks"]
        }
        self.assertTrue(requirements["migraciones"])
        self.assertTrue(requirements["cache"])
        safety = release.development_transition_safety(
            [MIGRATION],
            [{"path": MIGRATION, "sha": SHA, "source": ADD_MIGRATION}],
        )
        self.assertTrue(safety["migraciones"], safety)
        self.assertEqual(safety["migration_class"], "additive")
        observed = release.development_auto_validation(
            manifest, _observation(), phase="construccion",
            transition_safety=safety,
        )
        self.assertTrue(observed["development_auto_validation"]["eligible"])
        self.assertEqual(
            observed["development_auto_validation"]["reason"],
            "exact_evidence_complete",
        )

    def test_falla_cerrado(self) -> None:
        """Sin identidad, autorización de bot, evidencia o SQL seguro, no promover."""
        with self.assertRaises(release.EvidenceError):
            release.build_manifest_explicit(
                version=VERSION, sha="no-es-un-sha",
                changed_paths=[MIGRATION],
            )
        with self.assertRaises(pending.PendingReleaseError):
            pending.reduce_pending_releases([
                _bot_event("pending"),
                _bot_event("pending", sha=OTHER_SHA),
            ])
        forged = {
            **_bot_event("validated"),
            "user": {"login": "operador-sintetico"},
        }
        self.assertEqual(
            pending.reduce_pending_releases([_bot_event("pending"), forged]),
            [{"version": VERSION, "sha": SHA}],
        )
        manifest = _manifest()
        missing = release.development_transition_safety([MIGRATION], [])
        self.assertFalse(missing["migraciones"])
        self.assertEqual(missing["migration_class"], "human")
        destructive = release.development_transition_safety(
            [MIGRATION],
            [{"path": MIGRATION, "sha": SHA, "source": DROP_MIGRATION}],
        )
        self.assertFalse(destructive["migraciones"])
        self.assertEqual(destructive["migration_class"], "human")
        for safety in (missing, destructive):
            with self.subTest(reason=safety["migration_reason"]):
                denied = release.development_auto_validation(
                    manifest, _observation(), phase="construccion",
                    transition_safety=safety,
                )
                self.assertFalse(denied["development_auto_validation"]["eligible"])
                self.assertEqual(denied["estado"], "DEPLOY_OBSERVED")
        safe = release.development_transition_safety(
            [MIGRATION],
            [{"path": MIGRATION, "sha": SHA, "source": ADD_MIGRATION}],
        )
        for phase, observation in (
            ("live", _observation()),
            ("construccion", _observation(sha=OTHER_SHA)),
            ("construccion", _observation(complete=False)),
        ):
            with self.subTest(phase=phase, observed=observation["sha_esperado"]):
                denied = release.development_auto_validation(
                    manifest, observation, phase=phase,
                    transition_safety=safe,
                )
                self.assertFalse(denied["development_auto_validation"]["eligible"])
                self.assertNotEqual(denied["estado"], "VALIDATED_IN_PRODUCTION")

    def test_sin_datos_reales(self) -> None:
        """Los fixtures son sintéticos y no ejecutan red, shell, DB o deploy."""
        with patch.object(
            socket, "create_connection",
            side_effect=AssertionError("red inesperada"),
        ), patch.object(
            subprocess, "run",
            side_effect=AssertionError("comando inesperado"),
        ):
            self.assertEqual(
                pending.reduce_pending_releases([_bot_event("pending")]),
                [{"version": VERSION, "sha": SHA}],
            )
            self.assertEqual(_manifest()["version"], VERSION)
            synthetic = release.development_transition_safety(
                [MIGRATION],
                [{"path": MIGRATION, "sha": SHA, "source": ADD_MIGRATION}],
            )
            self.assertEqual(synthetic["migration_class"], "additive")
            self.assertEqual(synthetic["migration_files"][0]["path"], MIGRATION)

    def test_ci_gate_contract(self) -> None:
        """AC-04: el clasificador real incluye tests Python en CI."""
        from scripts.ci_change_classifier import classify

        selection = classify(["tests/test_cobertura_condor_08.py"], "pull_request")
        self.assertTrue(selection.pruebas_base)
        self.assertIn(selection.modo, {"selectivo", "completo"})


if __name__ == "__main__":
    unittest.main()
