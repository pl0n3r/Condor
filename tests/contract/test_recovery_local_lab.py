"""Contrato del laboratorio local de recuperación (Condor #1929 / #1590).

No arranca Docker: fija las invariantes de seguridad que el ensayo local debe
conservar (solo loopback, imagen fijada, secretos fuera de Git, mínimo
privilegio, reutilización de los scripts canónicos y guarda anti-producción).
"""
import re, subprocess, unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
LAB = ROOT / "ops/recovery-local"


def read(name):
    return (LAB / name).read_text(encoding="utf-8")


class RecoveryLocalLabContract(unittest.TestCase):
    def test_mariadb_is_pinned_to_production_series_and_loopback_only(self):
        c = read("compose.yaml")
        self.assertRegex(c, r"image: mariadb:11\.8@sha256:[0-9a-f]{64}")
        ports = re.findall(r'-\s*"([^"]+:\d+)"', c)
        self.assertTrue(ports)
        for p in ports:
            self.assertTrue(p.startswith("127.0.0.1:"), p)
        for limit in ("mem_limit:", "cpus:", "healthcheck:", "no-new-privileges:true", 'cap_drop: ["ALL"]'):
            self.assertIn(limit, c)

    def test_secrets_come_from_files_outside_git(self):
        c = read("compose.yaml")
        self.assertIn("MARIADB_ROOT_PASSWORD_FILE", c)
        self.assertNotRegex(c, r"MARIADB_(ROOT_)?PASSWORD:\s")
        self.assertIn("${CONDOR_RECOVERY_SECRETS_DIR:?", c)
        lab = read("lab.sh")
        self.assertIn('CONDOR_RECOVERY_HOME:-$HOME/.condor-recovery-local', lab)
        self.assertIn("openssl rand -hex 32", lab)
        tracked = subprocess.run(["git", "ls-files", "ops/recovery-local"], cwd=ROOT, capture_output=True, text=True).stdout
        self.assertNotIn("secrets", tracked)

    def test_restore_user_is_limited_to_disposable_databases(self):
        init = read("init/10-restore-user.sh")
        self.assertIn("condor\\\\_restore\\\\_%", init)
        self.assertNotRegex(init, r"ON \*\.\*")

    def test_drill_reuses_canonical_recovery_scripts(self):
        d = read("drill.sh")
        for piece in ("scripts/backup-database.sh", "scripts/recovery-production.py encrypt",
                      "scripts/recovery-production.py decrypt", "scripts/recovery-production.py checksum",
                      "scripts/verify-backup-restore.sh", "shasum -a 256 -c"):
            self.assertIn(piece, d)
        self.assertIn('target_db="condor_restore_test"', d)
        self.assertIn("CHECKSUM TABLE", d)

    def test_anti_production_guard_rejects_remote_hosts_and_foreign_databases(self):
        d = read("drill.sh")
        self.assertIn('u.hostname in ("127.0.0.1", "localhost")', d)
        self.assertIn(r'condor_restore_[a-z0-9_]{1,40}', d)
        for case in ("fail_guard_remote_host", "fail_guard_prod_db_name", "fail_target_not_disposable",
                     "fail_missing_destructive_flag", "fail_tampered_ciphertext", "fail_wrong_passphrase",
                     "fail_privilege_other_db", "fail_truncated_backup", "target_intact_after_failures"):
            self.assertIn(case, d)

    def test_evidence_is_scoped_as_synthetic_local_only(self):
        d = read("drill.sh")
        self.assertIn('"data": "synthetic-only"', d)
        self.assertIn("No acredita backup productivo", d)
        self.assertNotIn("app_password\": ", d)


if __name__ == "__main__":
    unittest.main()
