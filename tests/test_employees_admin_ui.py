"""Synthetic source-first acceptance for EmployeesManagement (#725)."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PANEL = (ROOT / "frontend/admin/EmployeesManagement.tsx").read_text(encoding="utf-8")
API = (ROOT / "src/Http/Controller/EmployeeController.php").read_text(encoding="utf-8")

TSX_HARNESS = "\nconst fs = require('fs');\nconst {Module} = require('module');\nlet ts;\ntry { ts = require('typescript'); } catch {\n  const {execFileSync} = require('child_process');\n  ts = require(execFileSync('npm', ['root', '-g'], {encoding:'utf8'}).trim() + '/typescript');\n}\nconst file = process.argv[1];\nconst output = ts.transpileModule(fs.readFileSync(file,'utf8'), {\n  compilerOptions: {target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.CommonJS,jsx:ts.JsxEmit.ReactJSX},\n  reportDiagnostics:true,\n});\nconst errors = (output.diagnostics || []).filter(x => x.category === ts.DiagnosticCategory.Error);\nif (errors.length) throw Error('TypeScript transpile errors: ' + errors.map(x => x.messageText).join(', '));\nconst mod = new Module(file, module);\nmod.filename = file;\nmod.require = name => {\n  if(name === 'react') return {};\n  if(name === 'react/jsx-runtime') return {jsx:()=>null,jsxs:()=>null};\n  return require(name);\n};\nmod._compile(output.outputText + '\\nmodule.exports.__validators = {readEmployees,readHistory};', file);\nconst {readEmployees,readHistory} = mod.exports.__validators;\nconst id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';\nconst other = '01ARZ3NDEKTSV4RRFFQ69G5FAW';\nconst eventId = '01ARZ3NDEKTSV4RRFFQ69G5FAX';\nconst person = {id,code:'EMP-01',linked:false};\nconst mark = {id:eventId,employee_id:id,occurred_at:'2026-10-25T12:30:00Z',type:'check_in'};\nconst reject = (fn,reason) => { let blocked = false; try { fn(); } catch {blocked = true;} if(!blocked) throw Error(reason); };\nif(readEmployees({employees:[person]}).length !== 1) throw Error('valid list');\nreject(()=>readEmployees({employees:[person,person]}),'duplicate list accepted');\nreject(()=>readEmployees({employees:[{...person,linked:'false'}]}),'invalid link accepted');\nreject(()=>readEmployees({employees:[{...person,id:other.toLowerCase()}]}),'invalid identifier accepted');\nif(readHistory({events:[mark]},id).length !== 1) throw Error('valid history');\nreject(()=>readHistory({events:[mark]},other),'cross-employee history accepted');\nreject(()=>readHistory({events:[{...mark,type:'unknown'}]},id),'invalid mark accepted');\nreject(()=>readHistory({events:Array.from({length:51},()=>mark)},id),'excess history accepted');\nconsole.log('HR synthetic contract: valid and adversarial payloads passed');\n"

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


    def test_typescript_payloads_with_real_transpiler(self):
        """Run the real TypeScript guards on synthetic and adversarial fixtures."""
        import subprocess
        result = subprocess.run(
            ["node", "-e", TSX_HARNESS, str(ROOT / "frontend/admin/EmployeesManagement.tsx")],
            cwd=ROOT, text=True, capture_output=True, timeout=60, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_no_premature_mount_before_bundle_lease(self):
        app = (ROOT / "frontend/admin/AdminApp.tsx").read_text(encoding="utf-8")
        self.assertNotIn("EmployeesManagement", app)
        self.assertIn("Persistencia de empleados no habilitada.", API)

if __name__ == "__main__":
    unittest.main()
