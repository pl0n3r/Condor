#!/usr/bin/env python3
from __future__ import annotations

import unittest

from scripts.ci_change_classifier import classify


class ChangeClassifierTests(unittest.TestCase):
    def test_docs_only_pr_skips_heavy_gates(self) -> None:
        result = classify(["README.md"], "pull_request")
        self.assertTrue(result.documentacion)
        self.assertTrue(result.github)
        self.assertFalse(result.pruebas_base)
        self.assertFalse(result.backend)
        self.assertFalse(result.e2e)

    def test_backend_change_runs_backend_base_and_e2e(self) -> None:
        result = classify(["src/Http/Controller/AdminController.php"], "pull_request")
        self.assertTrue(result.pruebas_base)
        self.assertTrue(result.backend)
        self.assertTrue(result.e2e)

    def test_ci_change_fails_safe_to_full_validation(self) -> None:
        result = classify([".github/workflows/ci.yml"], "pull_request")
        self.assertTrue(result.pruebas_base)
        self.assertTrue(result.backend)
        self.assertTrue(result.e2e)

    def test_release_observer_change_runs_full_validation(self) -> None:
        result = classify([".github/workflows/observar-release.yml"], "pull_request")
        self.assertTrue(result.pruebas_base)
        self.assertTrue(result.backend)
        self.assertTrue(result.e2e)

    def test_runtime_entrypoint_unknown_to_classifier_runs_full_stack(self) -> None:
        result = classify(["bin/console"], "pull_request")
        self.assertTrue(result.pruebas_base)
        self.assertTrue(result.backend)
        self.assertTrue(result.e2e)

    def test_unknown_root_file_runs_full_stack(self) -> None:
        result = classify(["runtime.conf"], "pull_request")
        self.assertTrue(result.pruebas_base)
        self.assertTrue(result.backend)
        self.assertTrue(result.e2e)

    def test_migration_marks_release_transition(self) -> None:
        result = classify(["migrations/Version20260921010000.php"], "pull_request")
        self.assertTrue(result.transicion_release)

    def test_runtime_config_marks_release_transition(self) -> None:
        result = classify(["config/packages/security.yaml"], "pull_request")
        self.assertTrue(result.transicion_release)

    def test_console_command_marks_release_transition(self) -> None:
        result = classify(["src/Console/ReconcileOwnerCommand.php"], "pull_request")
        self.assertTrue(result.transicion_release)

    def test_controller_or_route_marks_release_transition(self) -> None:
        result = classify(["src/Http/Controller/AdminController.php"], "pull_request")
        self.assertTrue(result.transicion_release)

    def test_identity_role_marks_release_transition(self) -> None:
        result = classify(["src/Domain/Identity/Entity/Role.php"], "pull_request")
        self.assertTrue(result.transicion_release)

    def test_service_marks_release_transition(self) -> None:
        result = classify(["src/Application/Billing/InvoiceService.php"], "pull_request")
        self.assertTrue(result.transicion_release)

    def test_entity_invariant_marks_release_transition(self) -> None:
        result = classify(["src/Domain/Catalog/Entity/Product.php"], "pull_request")
        self.assertTrue(result.transicion_release)

    def test_provisioning_script_marks_release_transition(self) -> None:
        result = classify(["scripts/provision_platform_owner.py"], "pull_request")
        self.assertTrue(result.transicion_release)

    def test_bin_console_marks_release_transition(self) -> None:
        result = classify(["bin/console"], "pull_request")
        self.assertTrue(result.transicion_release)

    def test_ordinary_application_change_does_not_force_transition(self) -> None:
        result = classify(["src/Application/Catalog/ProductQuery.php"], "pull_request")
        self.assertFalse(result.transicion_release)

    def test_docs_do_not_mark_release_transition(self) -> None:
        result = classify(["README.md"], "pull_request")
        self.assertFalse(result.transicion_release)

    def test_push_always_runs_full_stack(self) -> None:
        result = classify(["README.md"], "push")
        self.assertTrue(result.pruebas_base)
        self.assertTrue(result.backend)
        self.assertTrue(result.e2e)

    def test_empty_diff_fails_safe(self) -> None:
        result = classify([], "pull_request")
        self.assertTrue(result.pruebas_base)
        self.assertTrue(result.backend)
        self.assertTrue(result.e2e)


if __name__ == "__main__":
    unittest.main()
