/// <reference types="vite/client" />
import { useEffect, useState, type FormEvent } from 'react';
import { Card, DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';

/** M04: interfaz de registros persistidos, no activa nómina ni pagos reales. */
export type PayrollRecord = Readonly<{
  ref: string;
  tenantId: string;
  companyId: string;
  employeeRef: string;
  periodRef: string;
  state: 'BORRADOR' | 'EN_REVISION';
  version: number;
}>;
type Result = { status: 'disabled' | 'denied' | 'error' }
  | { status: 'ready'; records: PayrollRecord[] }
  | { status: 'ready'; record: PayrollRecord };

export type PayrollRequest = Readonly<{
  enabled: boolean;
  allowed: boolean;
  tenantId: string;
  companyId: string;
  branchId: string;
  method?: 'GET' | 'POST' | 'PATCH';
  ref?: string;
  employeeRef?: string;
  periodRef?: string;
  state?: PayrollRecord['state'];
  expectedVersion?: number;
  csrf?: string;
}>;
type Props = Readonly<{
  enabled: boolean;
  tenantId: string;
  companyId: string;
  branchId: string;
  permissions: string[];
  csrfToken: string;
}>;

const opaque = /^[a-z][a-z0-9_-]{0,63}$/;

function normalizeRow(value: unknown, tenant: string, company: string): PayrollRecord | null {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) return null;
  const row = value as Record<string, unknown>;
  if (row.tenant_id !== tenant || row.company_id !== company
    || typeof row.record_ref !== 'string' || !opaque.test(row.record_ref)
    || typeof row.employee_ref !== 'string' || !opaque.test(row.employee_ref)
    || typeof row.period_ref !== 'string' || !opaque.test(row.period_ref)
    || (row.state !== 'BORRADOR' && row.state !== 'EN_REVISION')) return null;
  const revision = Number(row.version);
  if (!Number.isInteger(revision) || revision < 1 || revision > 1_000_000) return null;
  return { ref: row.record_ref, tenantId: tenant, companyId: company,
    employeeRef: row.employee_ref, periodRef: row.period_ref,
    state: row.state, version: revision };
}

/** Fail-closed: sin flag/permiso/CSRF/scope válido no se emite HTTP. */
export async function payrollRequest(options: PayrollRequest, transport: typeof fetch = fetch): Promise<Result> {
  if (!options.enabled) return { status: 'disabled' };
  if (!options.allowed) return { status: 'denied' };
  if (!options.branchId || !options.tenantId || !options.companyId
    || options.companyId === '__unassigned__') return { status: 'denied' };
  const method = options.method ?? 'GET';
  if (options.ref !== undefined && !opaque.test(options.ref)) return { status: 'error' };
  if (method === 'POST' && (!options.csrf || !options.ref || !options.employeeRef
    || !options.periodRef || !opaque.test(options.employeeRef)
    || !opaque.test(options.periodRef))) return { status: 'error' };
  if (method === 'PATCH' && (!options.csrf || !options.ref
    || !['BORRADOR', 'EN_REVISION'].includes(options.state ?? '')
    || !Number.isInteger(options.expectedVersion)
    || (options.expectedVersion ?? 0) < 1)) return { status: 'error' };
  if (method !== 'GET' && method !== 'POST' && method !== 'PATCH') return { status: 'error' };
  const endpoint = '/api/v1/branches/' + encodeURIComponent(options.branchId) + '/payroll/real-records';
  const url = options.ref && method !== 'POST'
    ? endpoint + '/' + encodeURIComponent(options.ref) + (method === 'PATCH' ? '/state' : '') : endpoint;
  const body = method === 'POST' ? { record_ref: options.ref,
    employee_ref: options.employeeRef, period_ref: options.periodRef }
    : { state: options.state, expected_version: options.expectedVersion };

  try {
    const response = await transport(url, {
      method, credentials: 'same-origin', cache: 'no-store',
      headers: { Accept: 'application/json', ...(method === 'GET' ? {}
        : { 'Content-Type': 'application/json', 'X-CSRF-Token': options.csrf ?? '' }) },
      ...(method === 'GET' ? {} : { body: JSON.stringify(body) }),
    });
    if (response.status === 401 || response.status === 403) return { status: 'denied' };
    if (response.status === 404) return { status: 'disabled' };
    if (!response.ok) return { status: 'error' };
    const payload: unknown = await response.json();
    if (payload === null || typeof payload !== 'object' || Array.isArray(payload)) return { status: 'error' };
    const parsed = payload as Record<string, unknown>;
    if (method === 'GET' && options.ref === undefined) {
      if (!Array.isArray(parsed.records) || parsed.records.length > 100) return { status: 'error' };
      const rows = parsed.records.map(item => normalizeRow(item, options.tenantId, options.companyId));
      if (rows.some(item => item === null)) return { status: 'error' };
      const safeRows = rows as PayrollRecord[];
      if (new Set(safeRows.map(row => row.ref)).size !== safeRows.length) return { status: 'error' };
      return { status: 'ready', records: safeRows };
    }
    const record = normalizeRow(parsed.record, options.tenantId, options.companyId);
    if (!record || (options.ref && record.ref !== options.ref)) return { status: 'error' };
    return { status: 'ready', record };
  } catch {
    return { status: 'error' };
  }
}

export function PayrollManagement(props: Props) {
  const { enabled, tenantId, companyId, branchId, permissions, csrfToken } = props;
  const canView = permissions.includes('users.view') && companyId !== '__unassigned__';
  const canCreate = canView && permissions.includes('users.create');
  const canUpdate = canView && permissions.includes('users.update');
  const [status, setStatus] = useState<'loading' | 'ready' | 'error' | 'denied' | 'disabled'>('loading');
  const [rows, setRows] = useState<PayrollRecord[]>([]);
  const [notice, setNotice] = useState('');
  const [panel, setPanel] = useState<'create' | 'detail' | null>(null);
  const [selected, setSelected] = useState('');
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState<'TODOS' | PayrollRecord['state']>('TODOS');
  const [ref, setRef] = useState('');
  const [employee, setEmployee] = useState('');
  const [period, setPeriod] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!enabled || !canView) return;
    let mounted = true;
    setStatus('loading');
    void payrollRequest({ enabled, allowed: canView, tenantId, companyId, branchId })
      .then(result => {
        if (!mounted) return;
        if (result.status === 'ready' && 'records' in result) {
          setRows(result.records);
          setStatus('ready');
        } else setStatus(result.status === 'ready' ? 'error' : result.status);
      });
    return () => { mounted = false; };
  }, [enabled, canView, tenantId, companyId, branchId]);

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    const result = await payrollRequest({ enabled, allowed: canCreate,
      tenantId, companyId, branchId, method: 'POST', ref, employeeRef: employee,
      periodRef: period, csrf: csrfToken });
    setBusy(false);
    if (result.status === 'ready' && 'record' in result) {
      setRows(previous => [...previous, result.record]);
      setSelected(result.record.ref); setPanel('detail'); setStatus('ready');
      setRef(''); setEmployee(''); setPeriod('');
      setNotice('Registro guardado. No se ha liquidado ni pagado nómina.');
    } else {
      if (result.status === 'denied') setStatus('denied');
      if (result.status === 'disabled') setStatus('disabled');
      setNotice('No se creó el registro. Revisa permiso, referencias y conexión.');
    }
  }

  async function transition(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const current = rows.find(row => row.ref === selected);
    if (!current || busy) return;
    const state = current.state === 'BORRADOR' ? 'EN_REVISION' : 'BORRADOR';
    setBusy(true);
    const result = await payrollRequest({ enabled, allowed: canUpdate,
      tenantId, companyId, branchId, method: 'PATCH', ref: current.ref,
      state, expectedVersion: current.version, csrf: csrfToken });
    setBusy(false);
    if (result.status === 'ready' && 'record' in result) {
      setRows(previous => previous.map(row => row.ref === result.record.ref ? result.record : row));
      setNotice('Estado actualizado y versionado en el servidor.');
    } else {
      if (result.status === 'denied') setStatus('denied');
      if (result.status === 'disabled') setStatus('disabled');
      setNotice('No se pudo guardar la transición. Actualiza el módulo antes de reintentar.');
    }
  }

  if (!enabled) return <section id="payroll-management" className="catalog-panel ui-page" aria-label="Nómina">
    <PageHeader title="Nómina" description="El módulo aún no está habilitado." />
    <p role="status">La gestión persistente de Nómina permanece desactivada por el servidor. No se han consultado registros.</p>
  </section>;
  if (!canView || status === 'denied') return <section id="payroll-management" className="catalog-panel ui-page" aria-label="Nómina">
    <PageHeader title="Nómina" />
    <p role="alert">Acceso denegado: se requiere empresa activa y permiso de consulta.</p>
  </section>;
  if (status === 'disabled') return <section id="payroll-management" className="catalog-panel ui-page" aria-label="Nómina">
    <PageHeader title="Nómina" />
    <p role="alert">El servidor no tiene habilitada esta función.</p>
  </section>;

  const visible = rows.filter(row => (filter === 'TODOS' || row.state === filter)
    && [row.ref, row.employeeRef, row.periodRef].some(value =>
      value.includes(search.trim().toLowerCase())));
  const current = rows.find(row => row.ref === selected);
  return <section id="payroll-management" className="catalog-panel ui-page" aria-label="Nómina">
    <PageHeader title="Nómina" description="Registros internos de nómina por razón social y sede."
      actions={canCreate ? <button type="button" className="button" onClick={() => setPanel('create')}>Nuevo registro</button> : undefined} />
    <p className="muted">Referencias opacas únicamente. Esta interfaz no liquida, aprueba ni ejecuta pagos.</p>
    {notice && <output role="status" aria-live="polite">{notice}</output>}
    {status === 'loading' && <p role="status">Cargando registros de nómina…</p>}
    {status === 'error' && <p role="alert">No fue posible cargar los registros. Comprueba tu conexión.</p>}
    {status === 'ready' && <>
      <MetricStrip label="Indicadores de nómina" items={[
        { label: 'Registros', value: rows.length },
        { label: 'Borradores', value: rows.filter(row => row.state === 'BORRADOR').length },
        { label: 'En revisión', value: rows.filter(row => row.state === 'EN_REVISION').length },
      ]} />
      <Card title="Registros por empresa">
        <Toolbar trailing={<output aria-live="polite">{visible.length} resultados</output>}>
          <label>Buscar referencias
            <input type="search" value={search} onChange={event => setSearch(event.target.value)} />
          </label>
          <label>Estado
            <select value={filter} onChange={event => setFilter(event.target.value as typeof filter)}>
              <option value="TODOS">Todos</option>
              <option value="BORRADOR">Borrador</option>
              <option value="EN_REVISION">En revisión</option>
            </select>
          </label>
        </Toolbar>
        <DataTable<PayrollRecord> caption="Registros de nómina por razón social"
          rows={visible} rowKey={row => row.ref}
          onOpen={row => { setSelected(row.ref); setPanel('detail'); }}
          columns={[
            { key: 'ref', header: 'Registro', render: row => row.ref },
            { key: 'period', header: 'Periodo', render: row => row.periodRef },
            { key: 'employee', header: 'Colaborador', render: row => row.employeeRef },
            { key: 'state', header: 'Estado', render: row => <StatusPill tone={row.state === 'BORRADOR' ? 'neutral' : 'warn'}>
              {row.state === 'BORRADOR' ? 'Borrador' : 'En revisión'}
            </StatusPill> },
          ]}
          empty={<p role="status">No hay registros para este filtro.</p>} />
      </Card>
    </>}
    <SidePanel open={panel === 'create' && canCreate && status === 'ready'}
      title="Nuevo registro de nómina" onClose={() => setPanel(null)}>
      <form className="catalog-editor" onSubmit={event => void create(event)}>
        <p className="muted">Utiliza únicamente alias sin datos identificables.</p>
        <label>Referencia del registro
          <input required pattern="[a-z][a-z0-9_\-]{0,63}" maxLength={64} value={ref}
            onChange={event => setRef(event.target.value)} placeholder="registro_demo" />
        </label>
        <label>Referencia del colaborador
          <input required pattern="[a-z][a-z0-9_\-]{0,63}" maxLength={64} value={employee}
            onChange={event => setEmployee(event.target.value)} placeholder="colaborador_demo" />
        </label>
        <label>Periodo
          <input required pattern="[a-z][a-z0-9_\-]{0,63}" maxLength={64} value={period}
            onChange={event => setPeriod(event.target.value)} placeholder="periodo_demo" />
        </label>
        <button type="submit" disabled={busy}>{busy ? 'Guardando…' : 'Guardar registro'}</button>
      </form>
    </SidePanel>
    <SidePanel open={panel === 'detail' && !!current && status === 'ready'}
      title="Detalle de nómina" onClose={() => setPanel(null)}>
      {current ? <>
        <dl><dt>Registro</dt><dd>{current.ref}</dd>
          <dt>Periodo</dt><dd>{current.periodRef}</dd>
          <dt>Colaborador</dt><dd>{current.employeeRef}</dd>
          <dt>Estado</dt><dd>{current.state === 'BORRADOR' ? 'Borrador' : 'En revisión'}</dd>
          <dt>Versión auditada</dt><dd>{current.version}</dd></dl>
        {canUpdate && <form onSubmit={event => void transition(event)}>
          <button type="submit" disabled={busy}>{busy ? 'Guardando…'
            : current.state === 'BORRADOR' ? 'Enviar a revisión' : 'Devolver a borrador'}</button>
        </form>}
      </> : <p role="status">Selecciona un registro para consultar sus datos.</p>}
    </SidePanel>
  </section>;
}
