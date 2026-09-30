"""Aceptación ejecutable Condor #350."""
import importlib.util, json, re, tempfile, unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location("recovery_production",ROOT/"scripts/recovery-production.py")
MOD=importlib.util.module_from_spec(SPEC); SPEC.loader.exec_module(MOD)
def read(path): return (ROOT/path).read_text(encoding="utf-8")

def evidence_row(operation="upload", *, object_ref="obj:1", version_ref="version:1"):
    return {
        "capability":"recovery.object-storage."+operation,
        "operation":operation,
        "descriptor_id":"a"*64,
        "object_ref":object_ref,
        "checksum_sha256":"b"*64,
        "immutable_version_ref":version_ref,
        "evidence_ref":"evidence:"+operation,
    }

class RecoveryProductionRolloutTests(unittest.TestCase):
    def test_offsite_connection_preflight_fails_closed_without_secret_echo(self):
        self.assertRaises(MOD.RecoveryError,MOD.safe_ref,"https://token=supersecret",MOD.ALIAS)
        w=read(".github/workflows/recovery-production.yml")
        self.assertIn("Recovery live no provisionado",w); self.assertNotIn('echo "$RECOVERY_S3_SECRET_ACCESS_KEY"',w)

    def test_backup_checksum_and_primary_offsite_evidence_contract(self):
        d=MOD.descriptor("upload","a"*64,"condor:db:001","controlbot:connection/recovery-primary")
        self.assertEqual((d["descriptor"]["provider"],d["descriptor"]["role"]),("object_storage","primary_offsite"))
        self.assertEqual(len(d["descriptor"]["descriptor_id"]),64)
        w=read(".github/workflows/recovery-production.yml")
        self.assertIn("encrypt --source",w)
        self.assertIn("45f5571ac32fbe07d11e696098f4e5902fac8c7f",w)
        self.assertNotIn("23df010f4e23d466fe5dcd2ceaa76446223501c2",w)
        self.assertIn("FACTORYRUNNER_CONNECTION_RECOVERY_PRIMARY_OBJECT_LOCK_DAYS",w)
        self.assertIn('created_at="$(date +%s)"',w)
        self.assertNotIn('stat -c %Y "$plain"',w)
        self.assertLess(w.index('created_at="$(date +%s)"'),w.index('BACKUP_DIR="$root" sh scripts/backup-database.sh'))

    def test_restore_target_is_disposable_and_source_is_untouched(self):
        w=read(".github/workflows/recovery-production.yml")
        for token in ("RECOVERY_DISPOSABLE_DATABASE_URL","CONDOR_ALLOW_DESTRUCTIVE_RESTORE=1","verify-backup-restore.sh"):
            self.assertIn(token,w)
        self.assertNotIn('DATABASE_URL="$DATABASE_URL" CONDOR_ALLOW_DESTRUCTIVE_RESTORE=1',w)

    def test_factory_330_is_canonical_rpo_rto_evaluator(self):
        w=read(".github/workflows/recovery-production.yml")
        s=read("scripts/recovery-production.py")
        self.assertIn("bb732b0feb2b5f45b9d9f96efe257c289e76bbc9",w)
        self.assertIn("from recovery.drill import build_restore_drill_plan, evaluate_restore_drill",w)
        self.assertIn("drill-inputs",w)
        self.assertNotIn("VERIFIED_WITHIN_TARGETS",w+s)
        self.assertNotIn('"DEGRADED"',s)
        self.assertNotIn("rpo_observed_seconds",s)
        self.assertIn("Latencia de protección (no RPO)",w)

    def test_drill_inputs_use_recovery_point_incident_and_real_checks(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td); old=MOD.ROOT; MOD.ROOT=root
            try:
                names=[]
                for op in ("upload","verify","materialize"):
                    p=root/(op+".json"); p.write_text(json.dumps(evidence_row(op)),encoding="utf-8"); names.append(p.name)
                args=type("A",(),{
                    "checksum":"b"*64,"upload_evidence":names[0],"verify_evidence":names[1],
                    "materialize_evidence":names[2],"run_id":"12345",
                    "backup_created_at":100,"offsite_verified_at":120,"incident_at":400,
                    "started_at":410,"completed_at":500,"health_ok":"true","smoke_ok":"true",
                    "integrity_ok":"true","rpo_target":900,"rto_target":3600,
                })
                payload=MOD.drill_inputs(args)
            finally: MOD.ROOT=old
        self.assertEqual(payload["manifest"]["target"],{"rpo_minutes":15,"rto_minutes":60})
        self.assertEqual(payload["verified_backup"]["status"],"VERIFIED")
        self.assertEqual(payload["verified_backup"]["created_at"],"1970-01-01T00:01:40Z")
        self.assertEqual(payload["evidence"]["incident_at"],"1970-01-01T00:06:40Z")
        self.assertTrue(payload["evidence"]["health_ok"])
        self.assertTrue(payload["evidence"]["smoke_ok"])
        self.assertTrue(payload["evidence"]["integrity_ok"])
        self.assertEqual(payload["protection_latency_seconds"],20)
        self.assertNotIn("status",payload)

    def test_health_smoke_and_integrity_are_observed_on_disposable_app(self):
        w=read(".github/workflows/recovery-production.yml")
        for token in (
            "php -S 127.0.0.1:18080","http://127.0.0.1:18080/health",
            "http://127.0.0.1:18080/","schema_up_to_date","integrity_ok=true",
            "health_ok=true","smoke_ok=true",
        ):
            self.assertIn(token,w)
        self.assertIn('DATABASE_URL="$RECOVERY_DISPOSABLE_DATABASE_URL"',w)

    def test_artifact_names_reject_paths_and_broken_symlinks(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td); link=root/"target"; link.symlink_to(root/"missing")
            old=MOD.ROOT; MOD.ROOT=root
            try:
                for value in ("../escape","/tmp/escape","nested/file"):
                    self.assertRaises(MOD.RecoveryError,MOD.confined,value,existing=False)
                self.assertRaises(MOD.RecoveryError,MOD.confined,link.name,existing=False)
            finally: MOD.ROOT=old

    def test_drill_inputs_reject_mixed_object_or_version_evidence(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td); old=MOD.ROOT; MOD.ROOT=root
            common={
                "checksum":"b"*64,"run_id":"12345","backup_created_at":100,
                "offsite_verified_at":120,"incident_at":400,"started_at":410,
                "completed_at":500,"health_ok":"true","smoke_ok":"true",
                "integrity_ok":"true","rpo_target":900,"rto_target":3600,
            }
            try:
                for field,bad in (("object_ref","obj:2"),("version_ref","version:2")):
                    names=[]
                    for i,op in enumerate(("upload","verify","materialize")):
                        kwargs={field:bad} if i==2 else {}
                        p=root/(field+op+".json"); p.write_text(json.dumps(evidence_row(op,**kwargs)),encoding="utf-8"); names.append(p.name)
                    args=type("A",(),{**common,"upload_evidence":names[0],"verify_evidence":names[1],"materialize_evidence":names[2]})
                    self.assertRaises(MOD.RecoveryError,MOD.drill_inputs,args)
            finally: MOD.ROOT=old

    def test_evidence_rejects_non_string_references(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td); old=MOD.ROOT; MOD.ROOT=root
            try:
                for key,bad in (("object_ref",None),("immutable_version_ref",17),("evidence_ref",False)):
                    row=evidence_row()
                    row[key]=bad
                    path=root/(key+".json")
                    path.write_text(json.dumps(row),encoding="utf-8")
                    self.assertRaises(MOD.RecoveryError,MOD.evidence,path.name,"b"*64)
            finally:
                MOD.ROOT=old

    def test_google_drive_is_optional_cold_copy_only(self):
        text=read("docs/recovery-production.md")
        self.assertIn("Google Drive",text); self.assertIn("cold-copy opcional",text)
        self.assertNotIn("drive.googleapis.com",read(".github/workflows/recovery-production.yml"))

    def test_rollout_has_no_secret_payload_product_deploy_or_destructive_production_sql(self):
        w=read(".github/workflows/recovery-production.yml"); s=read("scripts/recovery-production.py")
        for forbidden in ("workflow_call:","schedule:","deploy-factory","DROP DATABASE","signed_url","service-account"):
            self.assertNotIn(forbidden,w+s)
        self.assertIsNone(re.search(r"(?<![A-Z0-9_])RECOVERY_ARTIFACT_ROOT(?![A-Z0-9_])",w+s))
        self.assertIn("actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02",w)
        self.assertIn("retention-days: 30",w)
        for evidence_name in ("recovery-report.json","upload-evidence.json","verify-evidence.json","materialize-evidence.json"):
            self.assertIn(evidence_name,w)
        self.assertIn("RECOVERY_PROVIDER_PRIVACY_REF",w)
        self.assertIn("RECOVERY_S3_OBJECT_LOCK_DAYS",w)
        self.assertIn("FACTORYRUNNER_CONNECTION_RECOVERY_PRIMARY_OBJECT_LOCK_DAYS",w)

if __name__=="__main__": unittest.main()
