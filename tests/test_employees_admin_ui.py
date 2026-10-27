"""Synthetic source-first acceptance for EmployeesManagement (#725)."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PANEL = (ROOT / "frontend/admin/EmployeesManagement.tsx").read_text(encoding="utf-8")
APP = (ROOT / "frontend/admin/AdminApp.tsx").read_text(encoding="utf-8")
BUNDLE = (ROOT / "public/build/admin.js").read_text(encoding="utf-8")
API = (ROOT / "src/Http/Controller/EmployeeController.php").read_text(encoding="utf-8")

TSX_HARNESS = "\nconst fs = require('fs');\nconst {Module} = require('module');\nlet ts;\ntry { ts = require('typescript'); } catch {\n  const {execFileSync} = require('child_process');\n  ts = require(execFileSync('npm', ['root', '-g'], {encoding:'utf8'}).trim() + '/typescript');\n}\nconst file = process.argv[1];\nconst output = ts.transpileModule(fs.readFileSync(file,'utf8'), {\n  compilerOptions: {target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.CommonJS,jsx:ts.JsxEmit.ReactJSX},\n  reportDiagnostics:true,\n});\nconst errors = (output.diagnostics || []).filter(x => x.category === ts.DiagnosticCategory.Error);\nif (errors.length) throw Error('TypeScript transpile errors: ' + errors.map(x => x.messageText).join(', '));\nconst mod = new Module(file, module);\nmod.filename = file;\nmod.require = name => {\n  if(name === 'react') return {};\n  if(name === 'react/jsx-runtime') return {jsx:()=>null,jsxs:()=>null};\n  return require(name);\n};\nmod._compile(output.outputText + '\\nmodule.exports.__validators = {readEmployees,readHistory};', file);\nconst {readEmployees,readHistory} = mod.exports.__validators;\nconst id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';\nconst other = '01ARZ3NDEKTSV4RRFFQ69G5FAW';\nconst eventId = '01ARZ3NDEKTSV4RRFFQ69G5FAX';\nconst person = {id,code:'EMP-01',linked:false};\nconst mark = {id:eventId,employee_id:id,occurred_at:'2026-10-25T12:30:00Z',type:'check_in'};\nconst reject = (fn,reason) => { let blocked = false; try { fn(); } catch {blocked = true;} if(!blocked) throw Error(reason); };\nif(readEmployees({employees:[person]}).length !== 1) throw Error('valid list');\nreject(()=>readEmployees({employees:[person,person]}),'duplicate list accepted');\nreject(()=>readEmployees({employees:[{...person,linked:'false'}]}),'invalid link accepted');\nreject(()=>readEmployees({employees:[{...person,id:other.toLowerCase()}]}),'invalid identifier accepted');\nif(readHistory({events:[mark]},id).length !== 1) throw Error('valid history');\nreject(()=>readHistory({events:[mark]},other),'cross-employee history accepted');\nreject(()=>readHistory({events:[{...mark,type:'unknown'}]},id),'invalid mark accepted');\nreject(()=>readHistory({events:Array.from({length:51},()=>mark)},id),'excess history accepted');\nconsole.log('HR synthetic contract: valid and adversarial payloads passed');\n"

BEHAVIOR_HARNESS = r'''

const fs = require('fs');
const {Module} = require('module');
let ts;
try { ts = require('typescript'); } catch {
  const {execFileSync} = require('child_process');
  ts = require(execFileSync('npm', ['root', '-g'], {encoding:'utf8'}).trim() + '/typescript');
}
const file = process.argv[1];
const output = ts.transpileModule(fs.readFileSync(file,'utf8'), {
  compilerOptions: {target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.CommonJS,jsx:ts.JsxEmit.ReactJSX}
});
let hooks = [], cursor = 0;
const react = {
  useState(initial) {
    const id = cursor++;
    if (!Object.prototype.hasOwnProperty.call(hooks,id)) {
      hooks[id] = id === 0 ? {status:'ready',employees:[]} :
        id === 3 ? 'EMP-01' :
        id === 4 ? '01ARZ3NDEKTSV4RRFFQ69G5FAW' : initial;
    }
    return [hooks[id], next => {hooks[id] = typeof next === 'function' ? next(hooks[id]) : next;}];
  },
  useRef(initial) {
    const id = cursor++;
    if (!Object.prototype.hasOwnProperty.call(hooks,id)) hooks[id] = {current:initial};
    return hooks[id];
  },
  useEffect() {}
};
const jsx = (type,props) => ({type,props});
const mod = new Module(file,module);
mod.filename = file;
mod.require = name => name === 'react' ? react :
  name === 'react/jsx-runtime' ? {jsx,jsxs:jsx,Fragment:'fragment'} : require(name);
mod._compile(output.outputText,file);
const findForm = node => {
  if (Array.isArray(node)) return node.map(findForm).find(Boolean);
  if (!node || typeof node !== 'object') return null;
  if (node.type === 'form') return node;
  return findForm(node.props?.children);
};
const person = {id:'01ARZ3NDEKTSV4RRFFQ69G5FAV',code:'EMP-01',linked:false};
const flush = async () => {for (let i=0;i<30;i++) await Promise.resolve();};
async function scenario(mode) {
  hooks = []; cursor = 0;
  let resolveCreate;
  const requests = [];
  global.fetch = (url,options) => {
    requests.push({url,method:options.method});
    if (url === '/api/v1/hr/employees') {
      return new Promise(resolve => {resolveCreate = resolve;});
    }
    if (String(url).endsWith('/link')) {
      if (mode === 'failed-link') return Promise.resolve({status:409,ok:false});
      if (mode === 'invalid-link-json') return Promise.resolve({
        status:200,ok:true,json:async()=>{throw Error('malformed link json');}
      });
    }
    throw Error('unexpected request: ' + url);
  };
  const tree = mod.exports.EmployeesManagement({branchId:'branch-fixture',csrfToken:'synthetic-csrf'});
  const form = findForm(tree);
  if (!form) throw Error('form missing');
  const event = {preventDefault(){}};
  form.props.onSubmit(event);
  form.props.onSubmit(event);
  if (requests.length !== 1) throw Error('double POST while pending: '+requests.length);
  resolveCreate({
    status:201,ok:true,
    json:async () => mode === 'invalid-create-json'
      ? Promise.reject(Error('invalid create json'))
      : {employee:person}
  });
  await flush();
  const expectedCalls = mode === 'invalid-create-json' ? 1 : 2;
  if (requests.length !== expectedCalls) throw Error(mode+': requests='+requests.length);
  if (hooks[3] !== '') throw Error(mode+': code must be cleared after 201');
  if (mode === 'invalid-create-json') {
    if (hooks[0].employees.length !== 0 || !hooks[6].includes('Recarga la lista'))
      throw Error('unverifiable create receipt falsely confirmed or unsafe to retry');
  } else if (hooks[0].employees.length !== 1
      || hooks[0].employees[0].id !== person.id
      || hooks[0].employees[0].linked !== false) {
    throw Error(mode+': confirmed create lost after failed optional link');
  }
}
(async()=> {
  await scenario('failed-link');
  await scenario('invalid-link-json');
  await scenario('invalid-create-json');
  console.log('HR behavioral flow: double submit, failed link, invalid JSON and ambiguous 201 passed');
})().catch(error=>{console.error(error);process.exitCode=1;});

'''


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
        # Administrative context routes into the synthetic/fail-closed HR panel.
        self.assertIn("import { EmployeesManagement } from './EmployeesManagement';", APP)
        self.assertIn("href: '/admin#employees-management'", APP)
        self.assertIn("<EmployeesManagement", APP)
        self.assertIn("branchId={context.data.active_branch.id}", APP)
        self.assertIn("csrfToken={accessToken}", APP)
        self.assertIn("Empleados y asistencia", BUNDLE)
        self.assertIn("Crear empleado", BUNDLE)
        # The compiled entrypoint must actually carry the fail-closed HR API contract.
        self.assertIn("/api/v1/hr/employees", BUNDLE)

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

    def test_mounted_only_with_fail_closed_server_boundary(self):
        """The admin navigation uses the branch and the backend still refuses absent stores."""
        self.assertIn("<EmployeesManagement", APP)
        self.assertIn("key={'employees-' + context.data.active_branch.id}", APP)
        self.assertIn("Persistencia de empleados no habilitada.", API)


    def test_creation_behavior_with_mocked_react_and_fetch(self):
        """Execute the mutation with synthetic hooks and controlled network receipts."""
        import subprocess
        result = subprocess.run(
            ["node", "-e", BEHAVIOR_HARNESS, str(ROOT / "frontend/admin/EmployeesManagement.tsx")],
            cwd=ROOT, text=True, capture_output=True, timeout=60, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_mutation_dedup_and_lost_link_receipt(self):
        """A confirmed create is not lost when the optional link rejects or sends invalid JSON."""
        self.assertIn("writer.current !== null", PANEL)
        self.assertNotIn("writer.current?.abort(); writer.current = controller;", PANEL)
        self.assertIn("writer.current = null;", PANEL)
        self.assertIn("if (writer.current === controller) writer.current = null;", PANEL)
        self.assertIn("let createdEmployee: Employee | null = null;", PANEL)
        self.assertIn("createdEmployee = employee;", PANEL)
        self.assertIn("if (createdEmployee !== null)", PANEL)
        self.assertIn("!s.employees.some(e => e.id === createdEmployee?.id)", PANEL)
        self.assertIn("Empleado creado; no se pudo confirmar el vínculo opcional.", PANEL)
        self.assertIn("let createReceiptReceived = false;", PANEL)
        self.assertIn("createReceiptReceived = true;", PANEL)
        self.assertIn("El servidor respondió 201, pero no se pudo verificar el empleado.", PANEL)
        self.assertIn("El servidor respondió 201, pero no se pudo leer el recibo.", PANEL)
        self.assertIn("else if (createReceiptReceived)", PANEL)


    def test_branch_switch_and_invalid_link_preserve_ui(self):
        """Aborted operations release the form and invalid linking keeps the created row."""
        self.assertIn("setSaving(false); setCode(''); setUserId(''); setNotice('');", PANEL)
        self.assertIn("// A previous branch's aborted mutation must not leave the new form locked.", PANEL)
        self.assertIn("Empleado creado; vínculo de usuario no verificable.", PANEL)
        self.assertIn("setState(s => s.status === 'ready' ? { ...s, employees: [...s.employees, employee] } : s);", PANEL)


if __name__ == "__main__":
    unittest.main()
