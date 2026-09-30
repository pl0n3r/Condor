from __future__ import annotations

import importlib.util
import json
import os
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "recovery_production", ROOT / "scripts" / "recovery-production.py"
)
assert SPEC and SPEC.loader
recovery = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(recovery)


class RecoveryProductionRolloutTests(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        self.backup = self.base / "condor-prod-20260930T040000Z-test.sql.gz"
        self.backup.write_bytes(b"condor recovery deterministic fixture\n")
        self.manifest_path = self.base / "manifest.json"
        self.report_path = self.base / "report.json"

    def prepare_manifest(self, *, cold: bool = False):
        with mock.patch.object(
            recovery,
            "utc_now",
            return_value=datetime(2026, 9, 30, 4, 0, 0, tzinfo=timezone.utc),
        ):
            return recovery.prepare(
                offsite_connection_ref="controlbot:connection/condor-primary-offsite",
                cold_copy_connection_ref=(
                    "controlbot:connection/condor-drive-cold" if cold else None
                ),
                output=self.manifest_path,
                backup_file=self.backup,
            )

    @staticmethod
    def primary_receipt(manifest):
        desc = manifest["primary_request"]["descriptor"]
        return {
            "capability": "recovery.object-storage.upload",
            "operation": "upload",
            "descriptor_id": desc["descriptor_id"],
            "object_ref": desc["object_ref"],
            "checksum_sha256": desc["checksum_sha256"],
            "immutable_version_ref": "objver.20260930.001",
            "evidence_ref": "evidence.condor.primary.001",
        }

    @staticmethod
    def cold_receipt(manifest):
        desc = manifest["cold_copy_request"]["descriptor"]
        return {
            "capability": "recovery.google-drive.upload",
            "operation": "upload",
            "descriptor_id": desc["descriptor_id"],
            "object_ref": desc["object_ref"],
            "checksum_sha256": desc["checksum_sha256"],
            "remote_version_ref": "drivever.20260930.001",
            "evidence_ref": "evidence.condor.drive.001",
        }

    def write_receipt(self, value, name="receipt.json"):
        path = self.base / name
        path.write_text(json.dumps(value), encoding="utf-8")
        return path

    def test_offsite_connection_preflight_fails_closed_without_secret_echo(self):
        leaked = "super-sensitive-value"
        invalid = f"https://user:{leaked}@storage.invalid"
        with self.assertRaises(recovery.RecoveryRolloutError) as ctx:
            recovery.prepare(
                offsite_connection_ref=invalid,
                output=self.manifest_path,
                backup_file=self.backup,
            )
        self.assertNotIn(leaked, str(ctx.exception))
        self.assertFalse(self.manifest_path.exists())

        with self.assertRaises(recovery.RecoveryRolloutError):
            recovery.prepare(
                offsite_connection_ref="",
                output=self.manifest_path,
                backup_file=self.backup,
            )

    def test_backup_checksum_and_primary_offsite_evidence_contract(self):
        manifest = self.prepare_manifest()
        expected = recovery.sha256_file(self.backup)
        self.assertEqual(expected, manifest["checksum_sha256"])
        desc = manifest["primary_request"]["descriptor"]
        self.assertEqual("object_storage", desc["provider"])
        self.assertEqual("primary_offsite", desc["role"])
        self.assertFalse(desc["execute"])
        self.assertEqual(expected, desc["checksum_sha256"])

        receipt = self.primary_receipt(manifest)
        evidence = recovery.validate_primary_receipt(manifest, receipt)
        self.assertEqual("objver.20260930.001", evidence["immutable_version_ref"])

        receipt["checksum_sha256"] = "0" * 64
        with self.assertRaises(recovery.RecoveryRolloutError):
            recovery.validate_primary_receipt(manifest, receipt)

    def test_restore_target_is_disposable_and_source_is_untouched(self):
        manifest = self.prepare_manifest()
        receipt_path = self.write_receipt(self.primary_receipt(manifest))
        source = "mysql://prod-user:prod-value@db.invalid/condor"
        disposable = "mysql://restore-user:restore-value@db.invalid/condor_restore_verify"
        captured = {}

        def fake_run(command, **kwargs):
            captured["command"] = command
            captured["env"] = kwargs["env"].copy()
            return mock.Mock(returncode=0)

        with mock.patch.dict(
            os.environ,
            {
                "DATABASE_URL": source,
                "CONDOR_DISPOSABLE_RESTORE_DATABASE_URL": disposable,
                "CONDOR_RESTORE_EXPECT_TENANT_SLUG": "sentinel",
                "CONDOR_RESTORE_EXPECT_USER_EMAIL": "sentinel@example.invalid",
            },
            clear=False,
        ), mock.patch.object(recovery.subprocess, "run", side_effect=fake_run), mock.patch.object(
            recovery.time, "monotonic", side_effect=[10.0, 12.0]
        ), mock.patch.object(
            recovery,
            "utc_now",
            return_value=datetime(2026, 9, 30, 4, 0, 10, tzinfo=timezone.utc),
        ):
            report = recovery.finalize(
                manifest_path=self.manifest_path,
                primary_receipt_path=receipt_path,
                output=self.report_path,
            )

        self.assertEqual(
            ["sh", "scripts/verify-backup-restore.sh", str(self.backup.resolve())],
            captured["command"],
        )
        self.assertEqual(disposable, captured["env"]["DATABASE_URL"])
        self.assertNotEqual(source, captured["env"]["DATABASE_URL"])
        self.assertEqual("1", captured["env"]["CONDOR_ALLOW_DESTRUCTIVE_RESTORE"])
        self.assertEqual("disposable", report["restore"]["target"])

        with mock.patch.dict(
            os.environ,
            {
                "DATABASE_URL": source,
                "CONDOR_DISPOSABLE_RESTORE_DATABASE_URL": source,
            },
            clear=False,
        ):
            with self.assertRaises(recovery.RecoveryRolloutError):
                recovery.run_restore(self.backup)

    def test_rpo_rto_and_freshness_evidence_are_canonical(self):
        manifest = self.prepare_manifest()
        receipt_path = self.write_receipt(self.primary_receipt(manifest))
        with mock.patch.dict(
            os.environ,
            {
                "DATABASE_URL": "mysql://source.invalid/condor",
                "CONDOR_DISPOSABLE_RESTORE_DATABASE_URL": "mysql://restore.invalid/condor_restore_verify",
            },
            clear=False,
        ), mock.patch.object(
            recovery.subprocess, "run", return_value=mock.Mock(returncode=0)
        ), mock.patch.object(
            recovery.time, "monotonic", side_effect=[20.0, 23.25]
        ), mock.patch.object(
            recovery,
            "utc_now",
            return_value=datetime(2026, 9, 30, 4, 0, 20, tzinfo=timezone.utc),
        ):
            report = recovery.finalize(
                manifest_path=self.manifest_path,
                primary_receipt_path=receipt_path,
                output=self.report_path,
                max_rpo_seconds=60,
            )

        self.assertEqual("HEALTHY", report["status"])
        self.assertEqual("2026-09-30T04:00:20Z", report["checked_at"])
        self.assertEqual("fresh", report["freshness"]["status"])
        self.assertEqual(20.0, report["freshness"]["rpo_seconds"])
        self.assertEqual(3.25, report["restore"]["rto_seconds"])
        serialized = json.dumps(report)
        self.assertNotIn("controlbot:connection/", serialized)
        self.assertNotIn("mysql://", serialized)

        with mock.patch.dict(
            os.environ,
            {
                "DATABASE_URL": "mysql://source.invalid/condor",
                "CONDOR_DISPOSABLE_RESTORE_DATABASE_URL": "mysql://restore.invalid/condor_restore_verify",
            },
            clear=False,
        ), mock.patch.object(
            recovery.subprocess, "run", return_value=mock.Mock(returncode=0)
        ), mock.patch.object(
            recovery.time, "monotonic", side_effect=[30.0, 31.0]
        ), mock.patch.object(
            recovery,
            "utc_now",
            return_value=datetime(2026, 9, 30, 5, 0, 0, tzinfo=timezone.utc),
        ):
            with self.assertRaises(recovery.RecoveryRolloutError):
                recovery.finalize(
                    manifest_path=self.manifest_path,
                    primary_receipt_path=receipt_path,
                    output=self.report_path,
                    max_rpo_seconds=60,
                )

    def test_google_drive_is_optional_cold_copy_only(self):
        manifest = self.prepare_manifest(cold=True)
        primary = manifest["primary_request"]["descriptor"]
        cold = manifest["cold_copy_request"]["descriptor"]
        self.assertEqual("primary_offsite", primary["role"])
        self.assertEqual("object_storage", primary["provider"])
        self.assertEqual("cold_copy", cold["role"])
        self.assertEqual("google_drive", cold["provider"])
        self.assertEqual(primary["checksum_sha256"], cold["checksum_sha256"])

        receipt = self.cold_receipt(manifest)
        evidence = recovery.validate_cold_receipt(manifest, receipt)
        self.assertEqual("drivever.20260930.001", evidence["remote_version_ref"])

    def test_rollout_has_no_secret_payload_product_deploy_or_destructive_production_sql(self):
        workflow = (ROOT / ".github/workflows/recovery-production.yml").read_text(encoding="utf-8")
        script = (ROOT / "scripts/recovery-production.py").read_text(encoding="utf-8")
        docs = (ROOT / "docs/recovery-production.md").read_text(encoding="utf-8")

        self.assertIn("workflow_dispatch:", workflow)
        self.assertNotRegex(
            workflow,
            r"(?im)^\s+(?:password|token|dsn|service_account|signed_url):\s*$",
        )
        self.assertNotIn("DROP DATABASE", workflow)
        self.assertNotIn("DELETE FROM", workflow)
        self.assertNotIn("ops/factory/adapter.py deploy", workflow)
        self.assertNotIn("post-deploy.sh", workflow)
        self.assertNotIn("DROP DATABASE", script)
        self.assertNotIn("DELETE FROM", script)
        self.assertIn("primary_offsite", docs)
        self.assertIn("cold-copy", docs)
        self.assertIn("fail-closed", docs)


if __name__ == "__main__":
    unittest.main()
