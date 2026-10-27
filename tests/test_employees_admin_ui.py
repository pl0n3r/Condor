"""Synthetic source-first acceptance for EmployeesManagement (#725)."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PANEL = (ROOT / "frontend/admin/EmployeesManagement.tsx").read_text(encoding="utf-8")
API = (ROOT / "src/Http/Controller/EmployeeController.php").read_text(encoding="utf-8")

class EmpleadosUiAdminTests(unittest.TestCase):
    def test_acceptance(self):
        """Create, optional link, attendance, empty/error/denied without real PII."""
        for fragment in (
            "export function EmployeesManagement", "const endpoint = '/api/v1/hr/employees'",
            "body: JSON.stringify({ code: employeeCode })",
            "body: JSON.stringify({ user_id: linkedUserId })", "'/link'",
            "'/attendance'", "Ver asistencia", "Crear empleado",
            "No hay empleados registrados.", "Sin eventos de asistencia.",
            "Cargando empleados…", "No tienes permiso para gestionar empleados.",
            "No fue posible cargar empleados.", "RR. HH. no está habilitado en este entorno.",
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, PANEL)
        self.assertIn("status: status(response)", PANEL)
        self.assertIn("credentials: 'same-origin'", PANEL)
        self.assertIn("'X-CSRF-Token': csrfToken", PANEL)
        self.assertIn("Membership::ROLE_OWNER", API)
        self.assertIn("CurrentTenantForUser", API)
        self.assertIn("private function requireCsrf", API)
        self.assertIn("private function ownedEmployee", API)

    def test_validation_and_stale_responses_fail_closed(self):
        for fragment in (
            "value.events.length > 50", "e.employee_id === id",
            "new Set(employees.map(e => e.id)).size !== employees.length",
            "response.status !== 201", "linked.employee.id !== employee.id",
            "!linked.employee.linked", "current !== sequence.current",
            "controller.signal.aborted", "controller.abort()",
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, PANEL)
        for banned in ("localStorage", "document.cookie", "fingerprint_template"):
            self.assertNotIn(banned, PANEL)

    def test_no_premature_mount_before_bundle_lease(self):
        app = (ROOT / "frontend/admin/AdminApp.tsx").read_text(encoding="utf-8")
        self.assertNotIn("EmployeesManagement", app)
        self.assertIn("Persistencia de empleados no habilitada.", API)

if __name__ == "__main__":
    unittest.main()
