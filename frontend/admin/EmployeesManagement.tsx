import { FormEvent, useEffect, useRef, useState } from 'react';

type Employee = { id: string; code: string; linked: boolean };
type Mark = { id: string; employee_id: string; occurred_at: string; type: 'check_in' | 'check_out' };
type Props = Readonly<{ branchId: string; csrfToken: string }>;
type State = { status: 'loading' | 'ready' | 'denied' | 'disabled' | 'error'; employees: Employee[] };
type History = { status: 'idle' | 'loading' | 'ready' | 'denied' | 'disabled' | 'error'; events: Mark[] };
const endpoint = '/api/v1/hr/employees';
const ulid = /^[0-9A-HJKMNP-TV-Z]{26}$/;
const object = (x: unknown): x is Record<string, unknown> => x !== null && typeof x === 'object' && !Array.isArray(x);
function validEmployee(x: unknown): x is Employee {
  return object(x) && typeof x.id === 'string' && ulid.test(x.id)
    && typeof x.code === 'string' && x.code.trim() !== '' && x.code.length <= 32
    && typeof x.linked === 'boolean';
}
function readEmployees(value: unknown): Employee[] {
  if (!object(value) || !Array.isArray(value.employees) || value.employees.length > 200
    || !value.employees.every(validEmployee)) throw Error('Listado inválido');
  const employees = value.employees as Employee[];
  if (new Set(employees.map(e => e.id)).size !== employees.length) throw Error('IDs duplicados');
  return employees;
}
function readHistory(value: unknown, id: string): Mark[] {
  if (!object(value) || !Array.isArray(value.events) || value.events.length > 50
    || !value.events.every((e: unknown) => object(e) && typeof e.id === 'string'
      && ulid.test(e.id) && e.employee_id === id
      && typeof e.occurred_at === 'string'
      && /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/.test(e.occurred_at)
      && (e.type === 'check_in' || e.type === 'check_out'))) throw Error('Historial inválido');
  return value.events as Mark[];
}
const status = (response: Response): 'denied' | 'disabled' | 'error' =>
  response.status === 401 || response.status === 403 ? 'denied'
    : response.status === 503 ? 'disabled' : 'error';

/** A synthetic/pre-live UI; backend owner, tenant and CSRF policy remains authoritative. */
export function EmployeesManagement({ branchId, csrfToken }: Props) {
  const [state, setState] = useState<State>({ status: 'loading', employees: [] });
  const [history, setHistory] = useState<History>({ status: 'idle', events: [] });
  const [selected, setSelected] = useState('');
  const [code, setCode] = useState('');
  const [userId, setUserId] = useState('');
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState('');
  const sequence = useRef(0);
  const reader = useRef<AbortController | null>(null);
  const writer = useRef<AbortController | null>(null);

  useEffect(() => {
    const current = ++sequence.current;
    const controller = new AbortController();
    reader.current?.abort(); writer.current?.abort();
    writer.current = null;
    reader.current = controller;
    setState({ status: 'loading', employees: [] });
    setHistory({ status: 'idle', events: [] }); setSelected('');
    // A previous branch's aborted mutation must not leave the new form locked.
    setSaving(false); setCode(''); setUserId(''); setNotice('');
    void (async () => {
      try {
        const response = await fetch(endpoint, { credentials: 'same-origin',
          headers: { Accept: 'application/json' }, signal: controller.signal });
        if (current !== sequence.current || controller.signal.aborted) return;
        if (!response.ok) { setState({ status: status(response), employees: [] }); return; }
        const employees = readEmployees(await response.json() as unknown);
        if (current === sequence.current && !controller.signal.aborted) {
          setState({ status: 'ready', employees });
        }
      } catch {
        if (current === sequence.current && !controller.signal.aborted)
          setState({ status: 'error', employees: [] });
      }
    })();
    return () => { sequence.current++; controller.abort(); writer.current?.abort(); };
  }, [branchId]);

  async function showAttendance(id: string) {
    if (!ulid.test(id) || state.status !== 'ready'
      || !state.employees.some(e => e.id === id)) return;
    const current = sequence.current;
    reader.current?.abort();
    const controller = new AbortController();
    reader.current = controller;
    setSelected(id); setHistory({ status: 'loading', events: [] });
    try {
      const response = await fetch(endpoint + '/' + encodeURIComponent(id) + '/attendance', {
        credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal,
      });
      if (current !== sequence.current || controller.signal.aborted) return;
      if (!response.ok) { setHistory({ status: status(response), events: [] }); return; }
      const events = readHistory(await response.json() as unknown, id);
      if (current === sequence.current && !controller.signal.aborted) {
        setHistory({ status: 'ready', events });
      }
    } catch {
      if (current === sequence.current && !controller.signal.aborted)
        setHistory({ status: 'error', events: [] });
    }
  }

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const employeeCode = code.trim(), linkedUserId = userId.trim();
    if (state.status !== 'ready' || saving || writer.current !== null || !csrfToken || employeeCode.length < 1
      || employeeCode.length > 32 || (linkedUserId !== '' && !ulid.test(linkedUserId))) {
      setNotice('Comprueba el código, usuario opcional y permisos.'); return;
    }
    const current = sequence.current, controller = new AbortController();
    // The early guard rejects concurrent writes; the writer is known to be empty.
    writer.current = controller;
    setSaving(true); setNotice('');
    let createdEmployee: Employee | null = null;
    let createReceiptReceived = false;
    try {
      const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({ code: employeeCode }), signal: controller.signal,
      });
      if (current !== sequence.current || controller.signal.aborted) return;
      if (response.status !== 201) {
        setNotice(response.status === 401 || response.status === 403 ? 'Acceso denegado.'
          : response.status === 503 ? 'RR. HH. no está habilitado.'
            : 'El servidor rechazó la creación.'); return;
      }
      createReceiptReceived = true;
      const payload: unknown = await response.json();
      if (current !== sequence.current || controller.signal.aborted) return;
      if (!object(payload) || !validEmployee(payload.employee)) {
        setCode('');
        setNotice('El servidor respondió 201, pero no se pudo verificar el empleado. Recarga la lista antes de reintentar.'); return;
      }
      let employee = payload.employee;
      createdEmployee = employee;
      if (linkedUserId) {
        const link = await fetch(endpoint + '/' + encodeURIComponent(employee.id) + '/link', {
          method: 'POST', credentials: 'same-origin',
          headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
          body: JSON.stringify({ user_id: linkedUserId }), signal: controller.signal,
        });
        if (current !== sequence.current || controller.signal.aborted) return;
        if (!link.ok) {
          setCode('');
          setState(s => s.status === 'ready' ? { ...s, employees: [...s.employees, employee] } : s);
          setNotice('Empleado creado; no se confirmó el vínculo.'); return;
        }
        const linked: unknown = await link.json();
        if (current !== sequence.current || controller.signal.aborted) return;
        if (!object(linked) || !validEmployee(linked.employee)
          || linked.employee.id !== employee.id || !linked.employee.linked) {
          // The create receipt was valid even if linking returned an invalid receipt.
          setCode('');
          setState(s => s.status === 'ready' ? { ...s, employees: [...s.employees, employee] } : s);
          setNotice('Empleado creado; vínculo de usuario no verificable.'); return;
        }
        employee = linked.employee;
      }
      if (current !== sequence.current || controller.signal.aborted) return;
      setState(s => s.status === 'ready' ? { ...s, employees: [...s.employees, employee] } : s);
      setCode(''); setUserId(''); setNotice('Empleado creado.');
    } catch {
      if (current === sequence.current && !controller.signal.aborted) {
        if (createdEmployee !== null) {
          // The create receipt is confirmed; the optional link may have lost its response.
          setCode('');
          setState(s => s.status === 'ready' && !s.employees.some(e => e.id === createdEmployee?.id)
            ? { ...s, employees: [...s.employees, createdEmployee as Employee] } : s);
          setNotice('Empleado creado; no se pudo confirmar el vínculo opcional.');
        } else if (createReceiptReceived) {
          setCode('');
          setNotice('El servidor respondió 201, pero no se pudo leer el recibo. Recarga la lista antes de reintentar.');
        } else {
          setNotice('No se completó la operación.');
        }
      }
    } finally {
      if (writer.current === controller) writer.current = null;
      if (current === sequence.current) setSaving(false);
    }
  }
  return <section id="employees-management" aria-label="Empleados y asistencia">
    <h2>Empleados y asistencia</h2>
    {state.status === 'loading' && <output>Cargando empleados…</output>}
    {state.status === 'denied' && <p role="alert">No tienes permiso para gestionar empleados.</p>}
    {state.status === 'disabled' && <p role="alert">RR. HH. no está habilitado en este entorno.</p>}
    {state.status === 'error' && <p role="alert">No fue posible cargar empleados.</p>}
    {state.status === 'ready' && <>
      <form onSubmit={event => { void create(event); }}>
        <label>Código del empleado<input required maxLength={32} value={code}
          onChange={event => setCode(event.target.value)} /></label>
        <label>Usuario opcional (ULID)<input maxLength={26} value={userId}
          onChange={event => setUserId(event.target.value)} /></label>
        <button disabled={saving || !csrfToken} type="submit">{saving ? 'Guardando…' : 'Crear empleado'}</button>
      </form>
      {state.employees.length === 0 ? <p>No hay empleados registrados.</p>
        : <ul>{state.employees.map(e => <li key={e.id}><strong>{e.code}</strong>
          {e.linked ? ' · Usuario vinculado' : ' · Sin vínculo'}
          <button type="button" onClick={() => { void showAttendance(e.id); }}>Ver asistencia</button>
        </li>)}</ul>}
      {selected && <div aria-live="polite"><h3>Asistencia del empleado</h3>
        {history.status === 'loading' && <output>Cargando asistencia…</output>}
        {history.status === 'denied' && <p role="alert">Sin permiso para consultar asistencia.</p>}
        {history.status === 'disabled' && <p role="alert">Historial no habilitado.</p>}
        {history.status === 'error' && <p role="alert">No fue posible cargar asistencia.</p>}
        {history.status === 'ready' && (history.events.length === 0
          ? <p>Sin eventos de asistencia.</p>
          : <ul>{history.events.map(m => <li key={m.id}>
            {m.type === 'check_in' ? 'Entrada' : 'Salida'} · {m.occurred_at}
          </li>)}</ul>)}
      </div>}
      {notice && <p role="status">{notice}</p>}
    </>}
  </section>;
}
