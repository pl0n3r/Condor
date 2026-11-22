"""Contrato del laboratorio local de recuperación (Condor #1929 / #1590).

No arranca Docker: fija las invariantes de seguridad que el ensayo local debe
conservar (solo loopback, imagen fijada, secretos fuera de Git, mínimo
privilegio, reutilización de los scripts canónicos y guarda anti-producción).
"""
import os, re, subprocess, tempfile, unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
LAB = ROOT / "ops/recovery-local"


def read(name):
    return (LAB / name).read_text(encoding="utf-8")


def assert_ports_loopback(compose):
    """Parse service ports in short or long Compose YAML form; reject unknowns."""
    entries = []
    current = None
    in_ports = False
    for line in compose.splitlines():
        if not in_ports:
            if re.fullmatch(r"    ports:\s*", line):
                in_ports = True
            continue
        if line.strip() and len(line) - len(line.lstrip()) <= 4:
            break
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        item = re.fullmatch(r"\s{6}-\s*(.*)", line)
        if item:
            value = item.group(1).strip()
            current = {}
            entries.append(current)
            if value.startswith("target:"):
                current["target"] = value.split(":", 1)[1].strip()
            else:
                current["short"] = value.strip("'\"")
        elif current is not None:
            field = re.fullmatch(r"\s{8}([a-z_]+):\s*(.*)", line)
            if not field:
                raise AssertionError("Sintaxis de puerto desconocida")
            current[field.group(1)] = field.group(2).strip().strip("'\"")
        else:
            raise AssertionError("Sintaxis de puerto desconocida")
    if not entries:
        raise AssertionError("No hay publicación de puertos declarada")
    for entry in entries:
        if "short" in entry:
            host = entry["short"].split(":", 1)[0]
        else:
            if not all(x in entry for x in ("target", "published")):
                raise AssertionError("Mapa de puerto incompleto")
            host = entry.get("host_ip")
        if host != "127.0.0.1":
            raise AssertionError(f"Publicación no-loopback: {host!r}")

class RecoveryLocalLabContract(unittest.TestCase):
    def test_mariadb_is_pinned_to_production_series_and_loopback_only(self):
        c = read("compose.yaml")
        self.assertRegex(c, r"image: mariadb:11\.8@sha256:[0-9a-f]{64}")
        assert_ports_loopback(c)
        # Un puerto inseguro de comillas simples no debe pasar inadvertido.
        with self.assertRaises(AssertionError):
            assert_ports_loopback(c.replace(
                "    ports:\n", "    ports:\n      - '0.0.0.0:3318:3306'\n", 1))
        assert_ports_loopback("    ports:\n      - target: 3306\n"
                              "        published: 3317\n        host_ip: 127.0.0.1\n")
        with self.assertRaises(AssertionError):
            assert_ports_loopback("    ports:\n      - target: 3306\n"
                                  "        published: 3317\n        host_ip: 0.0.0.0\n")
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


    def test_repeat_rejects_invalid_values(self):
        for value in ("0", "not-a-number", "-3"):
            result = subprocess.run(["bash", str(LAB / "drill.sh"), "--repeat", value],
                                    cwd=ROOT, capture_output=True, text=True, timeout=8)
            self.assertNotEqual(result.returncode, 0, value)
            self.assertIn("entero positivo", result.stderr)

    def test_failures_propagate_outside_run_once(self):
        # Cliente SQL ficticio: no utiliza Docker ni credenciales verdaderas.
        with tempfile.TemporaryDirectory() as temp:
            home = Path(temp)
            secrets = home / "secrets"
            secrets.mkdir()
            for name in ("app_password", "encryption_passphrase"):
                (secrets / name).write_text("synthetic-only", encoding="utf-8")
            fake_bin = home / "bin"
            fake_bin.mkdir()
            mariadb = fake_bin / "mariadb"
            mariadb.write_text("#!/bin/sh\nexit 27\n", encoding="utf-8")
            mariadb.chmod(0o755)
            env = os.environ.copy()
            env.update({"CONDOR_RECOVERY_HOME": str(home),
                        "PATH": str(fake_bin) + os.pathsep + env["PATH"]})
            result = subprocess.run(["bash", str(LAB / "drill.sh"), "--repeat", "1"],
                                    cwd=ROOT, env=env, capture_output=True,
                                    text=True, timeout=20)
            self.assertEqual(result.returncode, 1, result.stderr)
            self.assertNotIn("[PASS] seed_synthetic", result.stdout)

    def test_rejects_secret_paths_inside_checkout(self):
        env = os.environ.copy()
        env["CONDOR_RECOVERY_HOME"] = str(ROOT / "tests")
        result = subprocess.run(["bash", str(LAB / "lab.sh"), "up"], cwd=ROOT,
                                env=env, capture_output=True, text=True, timeout=8)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("no puede vivir dentro del checkout", result.stderr)

    def test_ciphertext_mutation_always_changes_original_byte(self):
        drill = read("drill.sh")
        self.assertIn("original[0] ^ 0x01", drill)
        for byte in (0, 1, 255):
            self.assertNotEqual(byte, byte ^ 0x01)

    def test_evidence_is_scoped_as_synthetic_local_only(self):
        d = read("drill.sh")
        self.assertIn('"data": "synthetic-only"', d)
        self.assertIn("No acredita backup productivo", d)
        self.assertNotIn("app_password\": ", d)

    # --- Runner efímero del Mac para el drill productivo (#1952) ---
    def _guard(self, **env):
        base = {"PATH": "/usr/bin:/bin", "GITHUB_REPOSITORY": "pl0n3r/Condor",
                "GITHUB_WORKFLOW_REF": "pl0n3r/Condor/.github/workflows/recovery-production.yml@refs/heads/main",
                "GITHUB_EVENT_NAME": "workflow_dispatch", "GITHUB_ACTOR": "pl0n3r",
                "GITHUB_TRIGGERING_ACTOR": "pl0n3r", "GITHUB_REF": "refs/heads/main"}
        base.update(env)
        return subprocess.run(["bash", str(LAB / "runner-job-guard.sh")], env=base, capture_output=True, text=True).returncode

    def test_runner_guard_only_accepts_owner_dispatch_of_drill_on_main(self):
        self.assertEqual(self._guard(), 0)
        for bad in ({"GITHUB_WORKFLOW_REF": "pl0n3r/Condor/.github/workflows/ci.yml@refs/heads/main"},
                    {"GITHUB_WORKFLOW_REF": "pl0n3r/Condor/.github/workflows/recovery-production.yml@refs/pull/9/merge"},
                    {"GITHUB_EVENT_NAME": "pull_request"}, {"GITHUB_EVENT_NAME": "pull_request_target"},
                    {"GITHUB_ACTOR": "intruso"}, {"GITHUB_TRIGGERING_ACTOR": "intruso"},
                    {"GITHUB_REPOSITORY": "intruso/Condor"}, {"GITHUB_REF": "refs/heads/feature"}):
            self.assertNotEqual(self._guard(**bad), 0, bad)

    def test_drill_workflow_is_pinned_to_owner_main_dispatch_and_exclusive_label(self):
        w = (ROOT / ".github/workflows/recovery-production.yml").read_text(encoding="utf-8")
        self.assertIn("runs-on: [self-hosted, macOS, ARM64, condor-recovery]", w)
        self.assertIn("github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main' && github.actor == github.repository_owner", w)
        self.assertNotIn("pull_request", w.split("jobs:")[0])
        self.assertNotIn("secrets.DATABASE_URL", w)
        self.assertIn("bash ops/recovery-local/remote-backup.sh", w)
        self.assertIn("bash ops/recovery-local/disposable-url.sh", w)
        self.assertIn("Borrar artefactos en claro del runner", w)
        for other in (ROOT / ".github/workflows").glob("*.yml"):
            if other.name != "recovery-production.yml":
                runs_on = [l for l in other.read_text(encoding="utf-8").splitlines() if "runs-on" in l or "self-hosted" in l]
                self.assertFalse([l for l in runs_on if "condor-recovery" in l or "self-hosted" in l], other.name)

    def test_runner_is_ephemeral_pinned_and_guarded(self):
        r = read("runner.sh")
        self.assertIn("--ephemeral", r)
        self.assertRegex(r, r'runner_sha256="[0-9a-f]{64}"')
        self.assertIn("shasum -a 256 -c", r)
        self.assertIn("ACTIONS_RUNNER_HOOK_JOB_STARTED", r)
        self.assertIn("runner-job-guard.sh", r)
        self.assertIn("--labels condor-recovery", r)
        self.assertNotIn("svc.sh", r)

    def test_remote_backup_keeps_database_url_on_the_server(self):
        b = read("remote-backup.sh")
        self.assertIn("StrictHostKeyChecking=yes", b)
        self.assertIn("scripts/backup-database.sh", b)
        self.assertIn("rm -rf --", b)
        self.assertNotIn("echo \"$DATABASE_URL", b)

    def test_disposable_url_rejects_non_lab_targets(self):
        script = str(LAB / "disposable-url.sh")
        ok = subprocess.run(["bash", script, "mysql://condor_restore:x@127.0.0.1:3317/condor_restore_test"], capture_output=True, text=True)
        self.assertEqual(ok.returncode, 0)
        for bad in ("mysql://condor_restore:x@db.example.com:3317/condor_restore_test",
                    "mysql://condor_restore:x@127.0.0.1:3306/condor_restore_test",
                    "mysql://root:x@127.0.0.1:3317/condor_restore_test",
                    "mysql://condor_restore:x@127.0.0.1:3317/condor"):
            r = subprocess.run(["bash", script, bad], capture_output=True, text=True)
            self.assertNotEqual(r.returncode, 0, bad)
            self.assertEqual(r.stdout, "")


if __name__ == "__main__":
    unittest.main()
