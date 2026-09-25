import importlib.util
import os
import subprocess
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location("factory_adapter", ROOT/"ops/factory/adapter.py")
mod=importlib.util.module_from_spec(SPEC); SPEC.loader.exec_module(mod)

class FactoryDeployAdaptersTests(unittest.TestCase):
    def test_adapter_boundaries(self):
        for name in ("build","backup","migrate","deploy","rollback"):
            path=ROOT/"ops/factory"/name
            self.assertTrue(path.is_file()); self.assertFalse(path.is_symlink())
            self.assertTrue(os.access(path,os.X_OK))
        with patch.dict(os.environ,{"HOSTINGER_RELEASE_ROOT":"/safe/releases-root","GITHUB_SHA":"a"*40},clear=False):
            self.assertEqual(mod.release_root(),"/safe/releases-root"); self.assertEqual(mod.sha(),"a"*40)
        for bad in ("/","relative","/safe/../escape","/safe//double"):
            with self.subTest(bad=bad), patch.dict(os.environ,{"HOSTINGER_RELEASE_ROOT":bad},clear=False):
                with self.assertRaises(mod.AdapterError): mod.release_root()

    def test_backup_failure_prevents_deploy(self):
        failure=subprocess.CalledProcessError(7,["sh","scripts/backup-database.sh"])
        with patch.object(mod,"run",side_effect=failure) as runner:
            with self.assertRaises(subprocess.CalledProcessError):
                mod.backup()
        runner.assert_called_once()
        workflow=(ROOT/".github/workflows/deploy-factory.yml").read_text(encoding="utf-8")
        self.assertIn("uses: pl0n3r/factory/.github/workflows/deploy.yml@v1",workflow)
        self.assertIn("migration_mode: additive",workflow)

    def test_migration_and_smoke_failure(self):
        workflow=(ROOT/".github/workflows/deploy-factory.yml").read_text(encoding="utf-8")
        self.assertIn("FACTORY_DEPLOY_ENABLED == 'true'",workflow)
        adapter=(ROOT/"ops/factory/adapter.py").read_text(encoding="utf-8")
        self.assertIn('STAGES={"build":build,"backup":backup,"migrate":migrate,"deploy":deploy,"rollback":rollback}',adapter)
        self.assertIn('target=$(cat "$prev")',adapter)
        self.assertNotIn("doctrine:migrations:migrate",adapter)
        self.assertIn("scripts/post-deploy.sh",adapter)

    def test_exact_release_or_rollback(self):
        source=(ROOT/"ops/factory/adapter.py").read_text(encoding="utf-8")
        self.assertIn('releases/{commit}',source)
        self.assertIn('--exclude=var/',source)
        self.assertIn('.release-sha',source)
        self.assertIn('shared/var/runtime/app_secret',source)
        self.assertIn('shared/.env',source)
        self.assertIn('current.next',source)
        self.assertIn('.previous',source)
        self.assertNotIn("StrictHostKeyChecking=no",source)
        version=(ROOT/"src/Shared/Version/AppVersion.php").read_text(encoding="utf-8")
        self.assertIn(".release-sha",version)
        workflow=(ROOT/".github/workflows/deploy-factory.yml").read_text(encoding="utf-8")
        self.assertIn("uses: pl0n3r/factory/.github/workflows/deploy.yml@v1",workflow)
        self.assertIn("transport: hostinger-ssh",workflow)
        self.assertIn("HOSTINGER_KNOWN_HOSTS",workflow)

    def test_runner_state_and_secrets_are_not_copied(self):
        source=(ROOT/"ops/factory/adapter.py").read_text(encoding="utf-8")
        for excluded in ("--exclude=.git/","--exclude=.env","--exclude=.env.*","--exclude=var/"):
            self.assertIn(excluded,source)
        self.assertIn('chmod 600 "$rel/.release-sha.tmp"',source)
        self.assertIn('[ -L "$shared/.env" ]; then exit 33',source)

if __name__=="__main__": unittest.main()
