"""Aceptación ejecutable Condor #350."""
import importlib.util, json, tempfile, unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location("recovery_production",ROOT/"scripts/recovery-production.py")
MOD=importlib.util.module_from_spec(SPEC); SPEC.loader.exec_module(MOD)
def read(path): return (ROOT/path).read_text(encoding="utf-8")

class RecoveryProductionRolloutTests(unittest.TestCase):
    def test_offsite_connection_preflight_fails_closed_without_secret_echo(self):
        self.assertRaises(MOD.RecoveryError,MOD.safe_ref,"https://token=supersecret",MOD.ALIAS)
        w=read(".github/workflows/recovery-production.yml")
        self.assertIn("Recovery live no provisionado",w); self.assertNotIn('echo "$RECOVERY_S3_SECRET_ACCESS_KEY"',w)

    def test_backup_checksum_and_primary_offsite_evidence_contract(self):
        d=MOD.descriptor("upload","a"*64,"condor:db:001","controlbot:connection/recovery-primary")
        self.assertEqual((d["descriptor"]["provider"],d["descriptor"]["role"]),("object_storage","primary_offsite"))
        self.assertEqual(len(d["descriptor"]["descriptor_id"]),64)
        self.assertIn("encrypt --source",read(".github/workflows/recovery-production.yml"))

    def test_restore_target_is_disposable_and_source_is_untouched(self):
        w=read(".github/workflows/recovery-production.yml")
        for token in ("RECOVERY_DISPOSABLE_DATABASE_URL","CONDOR_ALLOW_DESTRUCTIVE_RESTORE=1","verify-backup-restore.sh"): self.assertIn(token,w)
        self.assertNotIn('DATABASE_URL="$DATABASE_URL" CONDOR_ALLOW_DESTRUCTIVE_RESTORE=1',w)

    def test_rpo_rto_and_freshness_evidence_are_canonical(self):
        row={"capability":"recovery.object-storage.upload","operation":"upload","descriptor_id":"a"*64,"object_ref":"obj:1","checksum_sha256":"b"*64,"immutable_version_ref":"version:1","evidence_ref":"evidence:1"}
        with tempfile.TemporaryDirectory() as td:
            root=Path(td); paths=[]
            for op in ("upload","verify","materialize"):
                p=root/op; p.write_text(json.dumps({**row,"operation":op,"capability":"recovery.object-storage."+op}),encoding="utf-8"); paths.append(p.name)
            values=dict(checksum="b"*64,upload_evidence=paths[0],verify_evidence=paths[1],materialize_evidence=paths[2],backup_created_at=10,offsite_verified_at=20,restore_started_at=30,restore_finished_at=40,observed_at=50,rpo_target=15,rto_target=15,freshness_target=15)
            old=MOD.ROOT; MOD.ROOT=root
            try:
                fresh=MOD.report(type("A",(),values))
                stale=MOD.report(type("A",(),{**values,"observed_at":100}))
            finally: MOD.ROOT=old
        self.assertEqual((fresh["status"],fresh["freshness"],fresh["rpo_observed_seconds"],fresh["rto_observed_seconds"]),("VERIFIED_WITHIN_TARGETS","fresh",10,10))
        self.assertEqual((stale["status"],stale["freshness"],stale["freshness_age_seconds"]),("DEGRADED","stale",60))

    def test_artifact_names_reject_paths_and_broken_symlinks(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td); link=root/"target"; link.symlink_to(root/"missing")
            old=MOD.ROOT; MOD.ROOT=root
            try:
                for value in ("../escape","/tmp/escape","nested/file"):
                    self.assertRaises(MOD.RecoveryError,MOD.confined,value,existing=False)
                self.assertRaises(MOD.RecoveryError,MOD.confined,link.name,existing=False)
            finally: MOD.ROOT=old

    def test_report_rejects_mixed_object_or_version_evidence(self):
        base={"capability":"recovery.object-storage.upload","operation":"upload","descriptor_id":"a"*64,"object_ref":"obj:1","checksum_sha256":"b"*64,"immutable_version_ref":"version:1","evidence_ref":"evidence:1"}
        with tempfile.TemporaryDirectory() as td:
            root=Path(td); old=MOD.ROOT; MOD.ROOT=root
            values=dict(checksum="b"*64,backup_created_at=10,offsite_verified_at=20,restore_started_at=30,restore_finished_at=40,observed_at=50,rpo_target=15,rto_target=15,freshness_target=15)
            try:
                for field,bad in (("object_ref","obj:2"),("immutable_version_ref","version:2")):
                    paths=[]
                    for i,op in enumerate(("upload","verify","materialize")):
                        row={**base,"operation":op,"capability":"recovery.object-storage."+op}
                        if i==2: row[field]=bad
                        p=root/(field+op); p.write_text(json.dumps(row),encoding="utf-8"); paths.append(p.name)
                    args=type("A",(),{**values,"upload_evidence":paths[0],"verify_evidence":paths[1],"materialize_evidence":paths[2]})
                    self.assertRaises(MOD.RecoveryError,MOD.report,args)
            finally: MOD.ROOT=old

    def test_google_drive_is_optional_cold_copy_only(self):
        text=read("docs/recovery-production.md")
        self.assertIn("Google Drive",text); self.assertIn("cold-copy opcional",text)
        self.assertNotIn("drive.googleapis.com",read(".github/workflows/recovery-production.yml"))

    def test_rollout_has_no_secret_payload_product_deploy_or_destructive_production_sql(self):
        w=read(".github/workflows/recovery-production.yml"); s=read("scripts/recovery-production.py")
        for forbidden in ("workflow_call:","schedule:","deploy-factory","DROP DATABASE","signed_url","service-account","RECOVERY_ARTIFACT_ROOT"):
            self.assertNotIn(forbidden,w+s)
        self.assertIn("RECOVERY_PROVIDER_PRIVACY_REF",w)
        self.assertIn("45f5571ac32fbe07d11e696098f4e5902fac8c7f",w)
        self.assertNotIn("23df010f4e23d466fe5dcd2ceaa76446223501c2",w)
        self.assertIn("RECOVERY_S3_OBJECT_LOCK_DAYS",w)
        self.assertIn("FACTORYRUNNER_CONNECTION_RECOVERY_PRIMARY_OBJECT_LOCK_DAYS",w)

if __name__=="__main__": unittest.main()
