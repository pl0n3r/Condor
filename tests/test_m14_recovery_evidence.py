"""M14-S05/S07: comprobante sintético; nunca prueba recuperación productiva."""

import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import unittest
from datetime import datetime, timezone


ROOT = Path(__file__).resolve().parents[1]
BACKUP_SCRIPT = ROOT / "scripts/backup-persistent-files.py"
DOCUMENT = ROOT / "docs/verificacion/gest-m14-operacion.md"
SYNTHETIC_SHA = "a" * 40  # Identidad ficticia, no asociada a ningún deploy.


def validate_synthetic_receipt(receipt, expected_sha):
    """Rechaza elevar un ejercicio desconectado a evidencia de producción."""
    required = {
        "version", "mode", "head_sha", "observed_at_utc", "environment",
        "authority", "window", "backup", "restore", "continuity",
        "result", "s05", "s07",
    }
    if not isinstance(receipt, dict) or set(receipt) != required:
        raise ValueError("recibo incompleto")
    if type(receipt["version"]) is not int or receipt["version"] != 1:
        raise ValueError("versión inválida")
    if receipt["mode"] != "SIMULADO" or receipt["result"] != "SOLO_ENSAYO_OFFLINE":
        raise ValueError("fuera del ámbito de simulación")
    if not isinstance(expected_sha, str) or re.fullmatch(r"[a-f0-9]{40}", expected_sha) is None:
        raise ValueError("SHA esperado inválido")
    if receipt["head_sha"] != expected_sha or receipt["environment"] != "sandbox descartable":
        raise ValueError("identidad o entorno incorrecto")
    try:
        when = datetime.fromisoformat(receipt["observed_at_utc"])
    except (TypeError, ValueError) as error:
        raise ValueError("fecha inválida") from error
    if when.tzinfo is None or when.utcoffset().total_seconds() != 0:
        raise ValueError("fecha no UTC")
    if receipt["authority"] is not None or receipt["window"] is not None:
        raise ValueError("no hay autorización operativa para un fixture")
    if receipt["s05"] != "NO_VERIFICABLE_REAL" or receipt["s07"] != "NO_VERIFICABLE_REAL":
        raise ValueError("un ensayo no acredita restauración/continuidad real")

    backup, restore, continuity = receipt["backup"], receipt["restore"], receipt["continuity"]
    if not all(isinstance(x, dict) for x in (backup, restore, continuity)):
        raise ValueError("bloques inválidos")
    if set(backup) != {"status", "sha256", "file_count"} or backup["status"] != "created":
        raise ValueError("copia incompleta")
    if not isinstance(backup["sha256"], str) or re.fullmatch(r"[a-f0-9]{64}", backup["sha256"]) is None:
        raise ValueError("hash inválido")
    if type(backup["file_count"]) is not int or backup["file_count"] <= 0:
        raise ValueError("inventario inválido")
    if set(restore) != {"status", "sha256", "file_count", "bytes_match", "private_modes"}:
        raise ValueError("recuperación incompleta")
    if (restore["status"] != "restored" or restore["sha256"] != backup["sha256"]
            or restore["file_count"] != backup["file_count"]
            or restore["bytes_match"] is not True or restore["private_modes"] is not True):
        raise ValueError("falta prueba de restore íntegro y privado")
    if set(continuity) != {"status", "service_observed", "rto_seconds", "rpo_seconds"}:
        raise ValueError("continuidad incompleta")
    if (continuity["status"] != "NOT_OBSERVED" or continuity["service_observed"] is not False
            or continuity["rto_seconds"] is not None or continuity["rpo_seconds"] is not None):
        raise ValueError("no se ha observado recuperación HTTP/DB")
    return True


def synthetic_receipt(backup, restored, byte_match, private, sha=SYNTHETIC_SHA):
    return {
        "version": 1, "mode": "SIMULADO", "head_sha": sha,
        "observed_at_utc": datetime.now(timezone.utc).isoformat(),
        "environment": "sandbox descartable", "authority": None, "window": None,
        "backup": {"status": backup["status"], "sha256": backup["sha256"], "file_count": backup["file_count"]},
        "restore": {"status": restored["status"], "sha256": restored["sha256"],
                    "file_count": restored["file_count"], "bytes_match": byte_match, "private_modes": private},
        "continuity": {"status": "NOT_OBSERVED", "service_observed": False,
                       "rto_seconds": None, "rpo_seconds": None},
        "result": "SOLO_ENSAYO_OFFLINE", "s05": "NO_VERIFICABLE_REAL", "s07": "NO_VERIFICABLE_REAL",
    }


class M14RecoveryEvidenceTests(unittest.TestCase):
    def good_receipt(self):
        backup = {"status": "created", "sha256": "b" * 64, "file_count": 2}
        restored = {"status": "restored", "sha256": "b" * 64, "file_count": 2}
        return synthetic_receipt(backup, restored, True, True)

    def test_synthetic_evidence_cannot_be_presented_as_real(self):
        receipt = self.good_receipt()
        self.assertTrue(validate_synthetic_receipt(receipt, SYNTHETIC_SHA))
        self.assertEqual(receipt["s05"], "NO_VERIFICABLE_REAL")
        self.assertEqual(receipt["s07"], "NO_VERIFICABLE_REAL")

    def test_rejects_missing_identity_fake_green_and_unapproved_environment(self):
        for key, value in (("head_sha", "f" * 40), ("s05", "GREEN"),
                           ("s07", "VERIFICADO"), ("mode", "OBSERVACION_AUTORIZADA"),
                           ("observed_at_utc", "2026-11-06T10:00:00"),
                           ("environment", "production"), ("authority", "owner")):
            receipt = self.good_receipt()
            receipt[key] = value
            with self.subTest(field=key), self.assertRaises(ValueError):
                validate_synthetic_receipt(receipt, SYNTHETIC_SHA)
        receipt = self.good_receipt()
        del receipt["backup"]
        with self.assertRaises(ValueError):
            validate_synthetic_receipt(receipt, SYNTHETIC_SHA)

    def test_rejects_inconsistent_hash_restore_permissions_and_fake_rto(self):
        for group, key, value in (("backup", "sha256", "short"),
                                  ("backup", "file_count", 0),
                                  ("restore", "sha256", "c" * 64),
                                  ("restore", "bytes_match", False),
                                  ("restore", "private_modes", False),
                                  ("continuity", "service_observed", True),
                                  ("continuity", "rto_seconds", 3)):
            receipt = self.good_receipt()
            receipt[group][key] = value
            with self.subTest(field=key), self.assertRaises(ValueError):
                validate_synthetic_receipt(receipt, SYNTHETIC_SHA)

    def test_real_backup_script_round_trip_is_only_offline_file_evidence(self):
        with tempfile.TemporaryDirectory(prefix="condor-m14-sim-") as folder:
            root = Path(folder)
            (root / ".condor-recovery-synthetic-only").write_text("synthetic-only\n", encoding="utf-8")
            source = root / "condor-files-source"
            (source / "uploads").mkdir(parents=True)
            samples = {"uploads/caso-inventado.txt": "Comercial Pereira S.A.S. (ficticia)",
                       "continuidad.txt": "Operación simulada, sin servicio real"}
            for name, content in samples.items():
                path = source / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(content, encoding="utf-8")
            env = {**os.environ, "CONDOR_RECOVERY_SANDBOX_ROOT": str(root)}

            def execute(mode):
                process = subprocess.run([sys.executable, str(BACKUP_SCRIPT), mode],
                                         cwd=root, env=env, capture_output=True, text=True, check=False)
                self.assertEqual(process.returncode, 0, process.stderr)
                return json.loads(process.stdout)

            backup = execute("backup")
            restored = execute("restore")
            archive = next((root / "condor-files-private").glob("*.tar.gz"))
            digest = hashlib.sha256(archive.read_bytes()).hexdigest()
            destination = root / "condor-files-restored"
            self.assertEqual(backup["sha256"], digest)
            bytes_match = all((source / name).read_bytes() == (destination / name).read_bytes()
                              for name in samples)
            private = ((archive.stat().st_mode & 0o077) == 0 and
                       all((destination / name).stat().st_mode & 0o077 == 0 for name in samples))
            receipt = synthetic_receipt(backup, restored, bytes_match, private)
            self.assertTrue(validate_synthetic_receipt(receipt, SYNTHETIC_SHA))
            self.assertTrue(bytes_match)
            self.assertTrue(private)
            self.assertEqual(receipt["continuity"]["status"], "NOT_OBSERVED")

    def test_runbook_does_not_promote_checks_into_real_recovery(self):
        doc = DOCUMENT.read_text(encoding="utf-8").lower()
        for phrase in ("no_verificable", "observacion_autorizada", "head_sha",
                       "restore", "rto", "rpo", "autoridad", "permisos", "stop"):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, doc)
        self.assertNotIn("s05/s07 verificados", doc)


if __name__ == "__main__":
    unittest.main()
