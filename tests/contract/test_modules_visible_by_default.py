"""#1940: ejecución real del bootstrap, ON por defecto y OFF explícito."""
import json
import os
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FLAGS = (
    "HR_REAL_DATA_ENABLED",
    "PAYROLL_REAL_DATA_ENABLED",
    "CONTRACTS_REAL_DATA_ENABLED",
    "SOCIAL_SECURITY_REAL_DATA_ENABLED",
    "FINANCE_REAL_DATA_ENABLED",
    "OHS_REAL_DATA_ENABLED",
    "LEGAL_COMPLIANCE_REAL_DATA_ENABLED",
    "GOVERNANCE_REAL_DATA_ENABLED",
    "OPERATIONS_PROCESSES_REAL_DATA_ENABLED",
    "CUSTOMER_SERVICE_REAL_DATA_ENABLED",
    "ASSETS_REAL_DATA_ENABLED",
)


class ModulesVisibleByDefaultTests(unittest.TestCase):
    def boot(self, overrides=None, prelude=""):
        env = os.environ.copy()
        for flag in FLAGS:
            env.pop(flag, None)
        # Solo proceso PHP local; sin red, datos, credenciales o migraciones.
        env.update({
            "APP_ENV": "test",
            "APP_DEBUG": "0",
            "APP_SECRET": "synthetic-test-secret-for-module-visibility-only",
            "DATABASE_URL": "mysql://127.0.0.1/condor_bootstrap_test",
        })
        env.update(overrides or {})
        script = (
            prelude
            + "require " + json.dumps(str(ROOT / "config/bootstrap.php")) + ";"
            + "$flags = " + json.dumps(list(FLAGS)) + ";"
            + "$values = [];"
            + "foreach ($flags as $flag) {"
            + " $values[$flag] = ["
            + " 'process' => getenv($flag),"
            + " 'env' => $_ENV[$flag] ?? null,"
            + " 'server' => $_SERVER[$flag] ?? null"
            + " ];"
            + "}"
            + "echo json_encode($values, JSON_THROW_ON_ERROR);"
        )
        result = subprocess.run(
            ["php", "-r", script], cwd=ROOT, env=env,
            capture_output=True, text=True, timeout=20, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def test_all_eleven_default_to_true_in_process_env_and_server(self):
        values = self.boot()
        self.assertEqual(set(values), set(FLAGS))
        for flag in FLAGS:
            with self.subTest(flag=flag):
                self.assertEqual(values[flag], {
                    "process": "true", "env": "true", "server": "true",
                })

    def test_every_explicit_false_remains_disabled(self):
        values = self.boot({flag: "false" for flag in FLAGS})
        for flag in FLAGS:
            with self.subTest(flag=flag):
                self.assertEqual(values[flag]["process"], "false")

    def test_mixed_emergency_off_keeps_other_modules_visible(self):
        overrides = {FLAGS[0]: "false", FLAGS[1]: "0", FLAGS[2]: ""}
        values = self.boot(overrides)
        for flag, value in overrides.items():
            with self.subTest(flag=flag):
                self.assertEqual(values[flag]["process"], value)
        for flag in FLAGS[3:]:
            with self.subTest(flag=flag):
                self.assertEqual(values[flag]["process"], "true")

    def test_defined_env_superglobal_is_not_replaced(self):
        values = self.boot(prelude="$_ENV['HR_REAL_DATA_ENABLED'] = 'false';")
        self.assertEqual(values["HR_REAL_DATA_ENABLED"]["env"], "false")
        self.assertFalse(values["HR_REAL_DATA_ENABLED"]["process"])
        self.assertEqual(values["PAYROLL_REAL_DATA_ENABLED"]["process"], "true")


if __name__ == "__main__":
    unittest.main()
