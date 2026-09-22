#!/usr/bin/env python3
from __future__ import annotations

import unittest

from scripts.ci_change_classifier import classify


class ChangeClassifierTests(unittest.TestCase):
    def test_docs_only_pr_uses_quick_mode(self) -> None:
        result = classify(["README.md"], "pull_request")
        self.assertTrue(result.categoria_documentacion)
        self.assertTrue(result.categoria_gobierno)
        self.assertTrue(result.documentacion)
        self.assertEqual(result.modo, "rapido")
        self.assertFalse(result.validacion_completa)
        self.assertFalse(result.pruebas_base)
        self.assertFalse(result.frontend)
        self.assertFalse(result.backend)
        self.assertFalse(result.e2e)
        self.assertEqual(result.motivo, "documentacion-gobierno-sin-runtime")

    def test_governance_only_change_stays_quick(self) -> None:
        result = classify([".github/labels.json"], "pull_request")
        self.assertTrue(result.categoria_gobierno)
        self.assertEqual(result.modo, "rapido")
        self.assertFalse(result.validacion_completa)
        self.assertFalse(result.backend)
        self.assertFalse(result.e2e)

    def test_frontend_runtime_change_runs_full_stack(self) -> None:
        result = classify(["frontend/admin/AdminApp.tsx"], "pull_request")
        self.assertTrue(result.categoria_frontend)
        self.assertTrue(result.validacion_completa)
        self.assertTrue(result.pruebas_base)
        self.assertTrue(result.frontend)
        self.assertTrue(result.backend)
        self.assertTrue(result.e2e)
        self.assertIn("runtime", result.motivo)

    def test_backend_runtime_change_runs_full_stack(self) -> None:
        result = classify(["src/Http/Controller/AdminController.php"], "pull_request")
        self.assertTrue(result.categoria_backend)
        self.assertTrue(result.validacion_completa)
        self.assertTrue(result.frontend)
        self.assertTrue(result.backend)
        self.assertTrue(result.e2e)

    def test_php_test_only_is_selective_not_full_runtime(self) -> None:
        result = classify(["tests/php/Http/AdminControllerTest.php"], "pull_request")
        self.assertTrue(result.categoria_backend)
        self.assertEqual(result.modo, "selectivo")
        self.assertFalse(result.validacion_completa)
        self.assertTrue(result.pruebas_base)
        self.assertFalse(result.frontend)
        self.assertTrue(result.backend)
        self.assertFalse(result.e2e)

    def test_e2e_test_only_runs_frontend_and_e2e_selectively(self) -> None:
        result = classify(["tests/e2e/admin.spec.mjs"], "pull_request")
        self.assertTrue(result.categoria_frontend)
        self.assertEqual(result.modo, "selectivo")
        self.assertFalse(result.validacion_completa)
        self.assertTrue(result.pruebas_base)
        self.assertTrue(result.frontend)
        self.assertFalse(result.backend)
        self.assertTrue(result.e2e)

    def test_migration_is_explicit_category_and_full_stack(self) -> None:
        result = classify(["migrations/Version20260921010000.php"], "pull_request")
        self.assertTrue(result.categoria_migraciones)
        self.assertTrue(result.categoria_backend)
        self.assertTrue(result.validacion_completa)
        self.assertTrue(result.transicion_release)
        self.assertIn("migraciones", result.motivo)

    def test_lockfile_change_is_dependency_category_and_full_stack(self) -> None:
        result = classify(["composer.lock"], "pull_request")
        self.assertTrue(result.categoria_dependencias)
        self.assertTrue(result.validacion_completa)
        self.assertTrue(result.frontend)
        self.assertTrue(result.backend)
        self.assertTrue(result.e2e)
        self.assertIn("dependencias", result.motivo)

    def test_security_change_runs_full_stack(self) -> None:
        result = classify(["config/packages/security.yaml"], "pull_request")
        self.assertTrue(result.categoria_seguridad)
        self.assertTrue(result.validacion_completa)
        self.assertTrue(result.transicion_release)
        self.assertIn("seguridad", result.motivo)

    def test_release_change_runs_full_stack(self) -> None:
        result = classify(["config/version.php"], "pull_request")
        self.assertTrue(result.categoria_release)
        self.assertTrue(result.validacion_completa)
        self.assertTrue(result.transicion_release)
        self.assertIn("release", result.motivo)

    def test_workflow_change_runs_full_stack(self) -> None:
        result = classify([".github/workflows/ci.yml"], "pull_request")
        self.assertTrue(result.categoria_gobierno)
        self.assertTrue(result.validacion_completa)
        self.assertTrue(result.frontend)
        self.assertTrue(result.backend)
        self.assertTrue(result.e2e)
        self.assertIn("workflow", result.motivo)

    def test_composite_action_change_runs_full_stack(self) -> None:
        result = classify([".github/actions/setup/action.yml"], "pull_request")
        self.assertTrue(result.categoria_gobierno)
        self.assertTrue(result.validacion_completa)
        self.assertTrue(result.frontend)
        self.assertTrue(result.backend)
        self.assertTrue(result.e2e)
        self.assertIn("workflow", result.motivo)

    def test_ci_classifier_change_runs_full_stack(self) -> None:
        result = classify(["scripts/ci_change_classifier.py"], "pull_request")
        self.assertTrue(result.categoria_gobierno)
        self.assertTrue(result.validacion_completa)
        self.assertIn("ci-critico", result.motivo)

    def test_unknown_root_file_runs_full_stack(self) -> None:
        result = classify(["runtime.conf"], "pull_request")
        self.assertTrue(result.validacion_completa)
        self.assertEqual(result.motivo, "ruta-desconocida-fail-safe")
        self.assertTrue(result.backend)
        self.assertTrue(result.e2e)

    def test_push_always_runs_full_stack_even_for_docs(self) -> None:
        result = classify(["README.md"], "push")
        self.assertTrue(result.validacion_completa)
        self.assertTrue(result.frontend)
        self.assertTrue(result.backend)
        self.assertTrue(result.e2e)
        self.assertEqual(result.motivo, "main-o-dispatch-validan-stack-completo")

    def test_dispatch_always_runs_full_stack(self) -> None:
        result = classify(["README.md"], "workflow_dispatch")
        self.assertTrue(result.validacion_completa)
        self.assertEqual(result.modo, "completo")

    def test_empty_diff_fails_safe(self) -> None:
        result = classify([], "pull_request")
        self.assertTrue(result.validacion_completa)
        self.assertEqual(result.motivo, "diff-vacio-fail-safe")

    def test_mixed_docs_and_runtime_reports_both_categories(self) -> None:
        result = classify(
            ["README.md", "src/Http/Controller/AdminController.php"],
            "pull_request",
        )
        self.assertTrue(result.categoria_documentacion)
        self.assertTrue(result.categoria_backend)
        self.assertIn("documentacion", result.categorias)
        self.assertIn("backend", result.categorias)
        self.assertTrue(result.validacion_completa)

    def test_runtime_config_marks_release_transition(self) -> None:
        result = classify(["config/packages/framework.yaml"], "pull_request")
        self.assertTrue(result.transicion_release)

    def test_console_command_marks_release_transition(self) -> None:
        result = classify(["src/Console/ReconcileOwnerCommand.php"], "pull_request")
        self.assertTrue(result.transicion_release)

    def test_identity_role_marks_release_transition(self) -> None:
        result = classify(["src/Domain/Identity/Entity/Role.php"], "pull_request")
        self.assertTrue(result.transicion_release)

    def test_service_marks_release_transition(self) -> None:
        result = classify(["src/Application/Billing/InvoiceService.php"], "pull_request")
        self.assertTrue(result.transicion_release)

    def test_provisioning_script_marks_release_transition(self) -> None:
        result = classify(["scripts/provision_platform_owner.py"], "pull_request")
        self.assertTrue(result.transicion_release)
        self.assertTrue(result.categoria_release)
        self.assertTrue(result.validacion_completa)

    def test_tests_do_not_mark_release_transition(self) -> None:
        result = classify(
            ["tests/php/Application/Onboarding/CreateTenantTest.php"],
            "pull_request",
        )
        self.assertFalse(result.transicion_release)

    def test_query_change_does_not_require_release_transition_but_is_runtime(self) -> None:
        result = classify(["src/Application/Catalog/ProductQuery.php"], "pull_request")
        self.assertFalse(result.transicion_release)
        self.assertTrue(result.validacion_completa)

    def test_github_output_metadata_uses_safe_category_names(self) -> None:
        result = classify(["README.md"], "pull_request")
        self.assertEqual(result.categorias, "documentacion,gobierno")
        self.assertNotIn("README.md", result.motivo)


if __name__ == "__main__":
    unittest.main()
