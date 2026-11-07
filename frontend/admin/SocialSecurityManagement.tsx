/// <reference types="vite/client" />
import { useEffect, useState, type FormEvent } from 'react';
import { Card, DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';

// M05 es una maqueta: no almacena datos, procesa PILA ni representa trámites reales.
type Stage = 'BORRADOR' | 'REQUIERE_REVISION' | 'EN_REVISION';
type ReferenceType = 'affiliation' | 'employee' | 'plan';
type Entry = { id: string; worker: string; plan: string; stage: Stage; scope: string };
type Event = { key: number; message: string; scope: string };
type Props = Readonly<{ tenantId: string; companyId: string; branchId: string; permissions: string[] }>;

const allowed: Readonly<Record<ReferenceType, RegExp>> = {
  affiliation: /^fixture:affiliation\/[a-z][a-z0-9_-]{0,47}$/,
  employee: /^fixture:employee\/[a-z][a-z0-9_-]{0,47}$/,
  plan: /^fixture:plan\/[a-z][a-z0-9_-]{0,47}$/,
};
const steps: Readonly<Record<Stage, Stage | null>> = {
  BORRADOR: 'REQUIERE_REVISION',
  REQUIERE_REVISION: 'EN_REVISION',
  EN_REVISION: null,
};

function synthetic(type: ReferenceType, input: string): boolean {
  return allowed[type].test(input) && !/[0-9]{7,}/.test(input);
}

// Seis previsualizaciones SOLO de laboratorio: ninguna invoca API, PILA o datos reales.
const M05_SYNTHETIC_MODULES = [
  ['GEST-M05-S01', 'Afiliaciones'],
  ['GEST-M05-S02', 'Novedades'],
  ['GEST-M05-S03', 'Aportes'],
  ['GEST-M05-S04', 'Soportes'],
  ['GEST-M05-S05', 'Alertas'],
  ['GEST-M05-S06', 'Preparación PILA'],
] as const;
type M05PreviewState = 'loading' | 'empty' | 'error' | 'permission-denied';
const M05_PREVIEW_TEXT: Record<M05PreviewState, string> = {
  loading: 'Simulación de carga. No se realizan solicitudes.',
  empty: 'Sin registros ficticios. No hay operación habilitada.',
  error: 'Error simulado, sin consultar servicios externos.',
  'permission-denied': 'Acceso denegado para este ámbito de prueba.',
};

function SyntheticSliceStates({ state }: { state: M05PreviewState }) {
  return <ul aria-label="Estados de seis submódulos sintéticos">
    {M05_SYNTHETIC_MODULES.map(([code, label]) =>
      <li key={code} data-m05-slice={code} data-ui-state={state}>
        <strong>{label}</strong>
        <p role={state === 'error' || state === 'permission-denied' ? 'alert' : 'status'}>
          {M05_PREVIEW_TEXT[state]}
        </p>
      </li>)}
  </ul>;
}

function SyntheticCoveragePanel({ denied }: { denied: boolean }) {
  const [previewState, setPreviewState] = useState<M05PreviewState>('empty');
  const state: M05PreviewState = denied ? 'permission-denied' : previewState;
  return <Card title="Estados UX de M05-S01 a S06 · solo laboratorio">
    <p>No hay API para novedades, aportes, soportes, alertas ni PILA. La previsualización no consulta la red.</p>
    <label>Estado de demostración
      <select aria-label="Previsualizar estados M05" value={state} disabled={denied}
        onChange={event => setPreviewState(event.target.value as M05PreviewState)}>
        <option value="loading">Carga simulada</option>
        <option value="empty">Vacío</option>
        <option value="error">Error simulado</option>
        <option value="permission-denied">Permiso denegado</option>
      </select>
    </label>
    <SyntheticSliceStates state={state} />
  </Card>;
}

function Demo({ tenantId, companyId, branchId, permissions }: Props) {
  const scope = [tenantId, companyId, branchId].join('|');
  const canView = companyId !== '__unassigned__' && permissions.includes('users.view');
  const canCreate = canView && permissions.includes('users.create');
  const canReview = canView && permissions.includes('users.update');
  const [items, setItems] = useState<Entry[]>([]);
  const [history, setHistory] = useState<Event[]>([]);
  const [form, setForm] = useState({ id: '', worker: '', plan: '' });
  const [active, setActive] = useState<string | null>(null);
  const [panel, setPanel] = useState<'create' | 'detail' | null>(null);
  const [filter, setFilter] = useState('');
  const [message, setMessage] = useState('');
  const visible = items.filter(item => item.scope === scope);
  const picked = visible.find(item => item.id === active);

  function log(messageText: string) {
    setHistory(previous => [...previous, {
      key: (previous.at(-1)?.key ?? 0) + 1, message: messageText, scope,
    }].slice(-100));
  }

  function register(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const { id, worker, plan } = form;
    const valid = synthetic('affiliation', id) && synthetic('employee', worker)
      && synthetic('plan', plan);
    if (!canCreate || !valid || visible.length >= 100 || visible.some(item => item.id === id)) {
      setMessage('No se creó la afiliación: permisos, referencias ficticias o duplicados inválidos.');
      return;
    }
    setItems(existing => existing.concat({ id, worker, plan, stage: 'BORRADOR', scope }));
    log('Creación manual de ' + id);
    setActive(id); setPanel('detail');
    setForm({ id: '', worker: '', plan: '' });
    setMessage('Registro de demostración en memoria. No tiene valor legal.');
  }

  function inspect(id: string) {
    if (!canView || !synthetic('affiliation', id) || !visible.some(item => item.id === id)) return;
    setActive(id); setPanel('detail');
    log('Consulta manual de ' + id);
  }

  function move(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canReview || !picked || !synthetic('affiliation', picked.id)) return;
    const next = steps[picked.stage];
    if (next === null) return;
    setItems(existing => existing.map(item => item.id === picked.id && item.scope === scope
      ? { ...item, stage: next } : item));
    log('Cambio manual de estado: ' + picked.id + ' → ' + next);
    setMessage('Revisión ficticia registrada. Ningún estado se cierra automáticamente.');
  }

  if (!canView) return <section id="social-security-management" className="catalog-panel ui-page">
    <PageHeader title="Seguridad Social M05 · laboratorio ficticio" />
    <p role="alert">Demostración no disponible: empresa o permiso de consulta ausente.</p>
    <SyntheticCoveragePanel denied={true} />
  </section>;

  const filtered = visible.filter(item => item.id.includes(filter.trim().toLowerCase()));
  const scopedHistory = history.filter(entry => entry.scope === scope);

  return <section id="social-security-management" className="catalog-panel ui-page" aria-label="Seguridad social de demostración">
    <PageHeader title="Seguridad Social M05 · laboratorio ficticio"
      description="Afiliaciones y revisiones de prueba, sin trámites ni información personal."
      actions={canCreate ? <button type="button" className="button" onClick={() => setPanel('create')}>
        Nueva afiliación de prueba
      </button> : undefined} />
    <p role="alert">SOLO DATOS FICTICIOS. Sin valor legal. No introducir información personal.</p>
    <p className="muted">No se realizan afiliaciones, liquidaciones, pagos ni envíos a PILA.</p>
    {message && <output role="status" aria-live="polite">{message}</output>}
    <MetricStrip label="Indicadores de seguridad social ficticia" items={[
      { label: 'Afiliaciones ficticias', value: visible.length },
      { label: 'Borradores', value: visible.filter(item => item.stage === 'BORRADOR').length },
      { label: 'En revisión', value: visible.filter(item => item.stage !== 'BORRADOR').length },
    ]} />
    <Card title="Afiliaciones del ámbito activo">
      <Toolbar trailing={<output aria-live="polite">{filtered.length} resultados</output>}>
        <label>Filtrar por referencia
          <input type="search" aria-label="Filtrar afiliaciones" value={filter} maxLength={68}
            onChange={event => setFilter(event.target.value)} />
        </label>
      </Toolbar>
      <DataTable<Entry> caption="Afiliaciones ficticias de la empresa"
        rows={filtered} rowKey={item => item.id}
        onOpen={item => inspect(item.id)}
        columns={[
          { key: 'id', header: 'Afiliación', render: item => item.id },
          { key: 'worker', header: 'Colaborador', render: item => item.worker },
          { key: 'plan', header: 'Plan', render: item => item.plan },
          { key: 'state', header: 'Estado', render: item =>
            <StatusPill tone={item.stage === 'BORRADOR' ? 'neutral' : 'info'}>{item.stage}</StatusPill> },
        ]}
        empty={<p role="status">{visible.length === 0
          ? 'No hay registros ficticios.' : 'No hay afiliaciones para este filtro.'}</p>} />
    </Card>
    <SyntheticCoveragePanel denied={false} />
    <Card title="Auditoría local de demostración (no oficial)">
      <p>Solo eventos de esta sesión. Las operaciones reales exigen auditoría de servidor.</p>
      {scopedHistory.length === 0
        ? <p role="status">Sin eventos ficticios.</p>
        : <ul>{scopedHistory.map(entry => <li key={entry.key}>{entry.message}</li>)}</ul>}
    </Card>
    <SidePanel open={panel === 'create' && canCreate} title="Nueva afiliación de prueba"
      onClose={() => setPanel(null)}>
      <form className="catalog-editor" onSubmit={register}>
        {(['id', 'worker', 'plan'] as const).map(field => {
          const type: ReferenceType = field === 'id' ? 'affiliation' : field === 'worker' ? 'employee' : 'plan';
          return <label key={field}>{field === 'id' ? 'Afiliación' : field === 'worker' ? 'Colaborador' : 'Plan'} ficticio
            <input required maxLength={68} value={form[field]}
              placeholder={'fixture:' + type + '/demo'}
              onChange={event => setForm(previous => ({ ...previous, [field]: event.target.value }))} />
          </label>;
        })}
        <button type="submit">Guardar borrador ficticio</button>
      </form>
    </SidePanel>
    <SidePanel open={panel === 'detail' && !!picked} title="Revisión manual"
      onClose={() => setPanel(null)}>
      {picked ? <>
        <dl>
          <dt>Afiliación</dt><dd>{picked.id}</dd>
          <dt>Colaborador</dt><dd>{picked.worker}</dd>
          <dt>Plan</dt><dd>{picked.plan}</dd>
          <dt>Estado</dt><dd>
            <StatusPill tone={picked.stage === 'BORRADOR' ? 'neutral' : 'info'}>{picked.stage}</StatusPill>
          </dd>
        </dl>
        {canReview && steps[picked.stage] !== null &&
          <form onSubmit={move}>
            <button type="submit">Registrar siguiente revisión ficticia</button>
          </form>}
      </> : <p role="status">Selecciona una afiliación ficticia.</p>}
      <p className="muted">Sin aprobación, pago, envío ni cierre automático.</p>
    </SidePanel>
  </section>;
}

export function SocialSecurityManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <Demo key={[props.tenantId, props.companyId, props.branchId].join('|')} {...props} />;
}

/** M05: registros de empresa versionados, sin afiliaciones, pagos o trámites PILA. */
export type SocialRealRecord = Readonly<{
  ref: string; tenantId: string; companyId: string;
  category: 'afiliacion' | 'aporte' | 'novedad' | 'soporte';
  state: 'PENDIENTE' | 'REQUIERE_REVISION'; version: number;
}>;

export type SocialRealRequest = Readonly<{
  enabled: boolean; allowed: boolean;
  tenantId: string; companyId: string; branchId: string;
  method?: 'GET' | 'POST' | 'PATCH';
  ref?: string; category?: SocialRealRecord['category'];
  state?: SocialRealRecord['state']; expectedVersion?: number; csrf?: string;
}>;

type SocialRealResult =
  | { status: 'disabled' | 'denied' | 'error' }
  | { status: 'ready'; records: SocialRealRecord[] }
  | { status: 'ready'; record: SocialRealRecord };

type SocialRealProps = Props & { enabled: boolean; csrfToken: string };
const realSocialRef = /^[a-z][a-z0-9_-]{0,63}$/;
const socialCategories: SocialRealRecord['category'][] = ['afiliacion', 'aporte', 'novedad', 'soporte'];
const socialCategoryLabels: Record<SocialRealRecord['category'], string> = {
  afiliacion: 'Afiliación', aporte: 'Aporte', novedad: 'Novedad', soporte: 'Soporte',
};

function verifiedSocialRealRow(value: unknown, tenantId: string, companyId: string): SocialRealRecord | null {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) return null;
  const row = value as Record<string, unknown>;
  if (row.tenant_id !== tenantId || row.company_id !== companyId
    || typeof row.record_ref !== 'string' || !realSocialRef.test(row.record_ref)
    || typeof row.category !== 'string'
    || !socialCategories.includes(row.category as SocialRealRecord['category'])
    || (row.state !== 'PENDIENTE' && row.state !== 'REQUIERE_REVISION')) return null;
  const version = Number(row.version);
  if (!Number.isInteger(version) || version < 1 || version > 1_000_000) return null;
  return {
    ref: row.record_ref, tenantId, companyId,
    category: row.category as SocialRealRecord['category'],
    state: row.state, version,
  };
}

/** FAIL-CLOSED: flag apagado, permiso ausente o scope inválido nunca llegan a fetch. */
export async function socialSecurityRealRequest(
  options: SocialRealRequest, transport: typeof fetch = fetch,
): Promise<SocialRealResult> {
  if (!options.enabled) return { status: 'disabled' };
  if (!options.allowed || !options.tenantId || !options.branchId
    || !options.companyId || options.companyId === '__unassigned__') return { status: 'denied' };
  const method = options.method ?? 'GET';
  if (!['GET', 'POST', 'PATCH'].includes(method)
    || (options.ref !== undefined && !realSocialRef.test(options.ref))) return { status: 'error' };
  if (method === 'POST' && (!options.csrf || !options.ref || !options.category
    || !socialCategories.includes(options.category))) return { status: 'error' };
  if (method === 'PATCH' && (!options.csrf || !options.ref
    || (options.state !== 'PENDIENTE' && options.state !== 'REQUIERE_REVISION')
    || !Number.isInteger(options.expectedVersion)
    || (options.expectedVersion ?? 0) < 1)) return { status: 'error' };

  const root = '/api/v1/branches/' + encodeURIComponent(options.branchId) + '/social-security/records';
  const url = options.ref && method !== 'POST'
    ? root + '/' + encodeURIComponent(options.ref) + (method === 'PATCH' ? '/state' : '')
    : root;
  const payload = method === 'POST'
    ? { record_ref: options.ref, category: options.category }
    : { state: options.state, expected_version: options.expectedVersion };
  try {
    const response = await transport(url, {
      method, credentials: 'same-origin', cache: 'no-store',
      headers: { Accept: 'application/json', ...(method === 'GET' ? {}
        : { 'Content-Type': 'application/json', 'X-CSRF-Token': options.csrf ?? '' }) },
      ...(method === 'GET' ? {} : { body: JSON.stringify(payload) }),
    });
    if (response.status === 401 || response.status === 403) return { status: 'denied' };
    if (response.status === 404) return { status: 'disabled' };
    if (!response.ok) return { status: 'error' };
    const body: unknown = await response.json();
    if (body === null || typeof body !== 'object' || Array.isArray(body)) return { status: 'error' };
    const data = body as Record<string, unknown>;
    if (method === 'GET' && options.ref === undefined) {
      if (!Array.isArray(data.records) || data.records.length > 100) return { status: 'error' };
      const mapped = data.records.map(item => verifiedSocialRealRow(item, options.tenantId, options.companyId));
      if (mapped.some(item => item === null)) return { status: 'error' };
      const rows = mapped as SocialRealRecord[];
      if (new Set(rows.map(item => item.ref)).size !== rows.length) return { status: 'error' };
      return { status: 'ready', records: rows };
    }
    const record = verifiedSocialRealRow(data.record, options.tenantId, options.companyId);
    if (record === null || (options.ref && record.ref !== options.ref)) return { status: 'error' };
    return { status: 'ready', record };
  } catch { return { status: 'error' }; }
}

export function SocialSecurityRealManagement({
  enabled, tenantId, companyId, branchId, permissions, csrfToken,
}: SocialRealProps) {
  const canRead = permissions.includes('users.view') && companyId !== '__unassigned__';
  const canCreate = canRead && permissions.includes('users.create');
  const canUpdate = canRead && permissions.includes('users.update');
  const [status, setStatus] = useState<'loading' | 'ready' | 'denied' | 'disabled' | 'error'>('loading');
  const [rows, setRows] = useState<SocialRealRecord[]>([]);
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [panel, setPanel] = useState<'create' | 'detail' | null>(null);
  const [selected, setSelected] = useState('');
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState<'TODOS' | SocialRealRecord['state']>('TODOS');
  const [ref, setRef] = useState('');
  const [category, setCategory] = useState<SocialRealRecord['category']>('afiliacion');

  useEffect(() => {
    if (!enabled || !canRead) return;
    let active = true;
    setStatus('loading');
    setRows([]);
    setPanel(null);
    void socialSecurityRealRequest({ enabled, allowed: canRead, tenantId, companyId, branchId })
      .then(result => {
        if (!active) return;
        if (result.status === 'ready' && 'records' in result) {
          setRows(result.records);
          setStatus('ready');
        } else setStatus(result.status === 'ready' ? 'error' : result.status);
      });
    return () => { active = false; };
  }, [enabled, canRead, tenantId, companyId, branchId]);

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy || status !== 'ready') return;
    setBusy(true);
    const result = await socialSecurityRealRequest({ enabled, allowed: canCreate, tenantId,
      companyId, branchId, method: 'POST', ref, category, csrf: csrfToken });
    setBusy(false);
    if (result.status === 'ready' && 'record' in result) {
      setRows(previous => [...previous, result.record]);
      setSelected(result.record.ref);
      setPanel('detail');
      setRef('');
      setNotice('Registro guardado. No se ha tramitado ni pagado seguridad social.');
    } else {
      if (result.status === 'denied') setStatus('denied');
      if (result.status === 'disabled') setStatus('disabled');
      setNotice('No se pudo crear. Comprueba el permiso, la referencia y la conexión.');
    }
  }

  async function advance(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const record = rows.find(row => row.ref === selected);
    if (!record || busy || status !== 'ready') return;
    const next = record.state === 'PENDIENTE' ? 'REQUIERE_REVISION' : 'PENDIENTE';
    setBusy(true);
    const result = await socialSecurityRealRequest({ enabled, allowed: canUpdate,
      tenantId, companyId, branchId, method: 'PATCH', ref: record.ref, state: next,
      expectedVersion: record.version, csrf: csrfToken });
    setBusy(false);
    if (result.status === 'ready' && 'record' in result) {
      setRows(previous => previous.map(row => row.ref === result.record.ref ? result.record : row));
      setNotice('Estado versionado y registrado por el servidor.');
    } else {
      if (result.status === 'denied') setStatus('denied');
      if (result.status === 'disabled') setStatus('disabled');
      setNotice('No se pudo cambiar el estado. Actualiza el módulo antes de reintentar.');
    }
  }

  const shell = (message: string, role: 'status' | 'alert') =>
    <section id="social-security-management" className="catalog-panel ui-page" aria-label="Seguridad social">
      <PageHeader title="Seguridad social" />
      <p role={role}>{message}</p>
    </section>;
  if (!enabled) return <section id="social-security-management" className="catalog-panel ui-page"
    aria-label="Seguridad social">
    <PageHeader title="Seguridad social" />
    <p role="status">El módulo de Seguridad social aún no está habilitado. No se consultaron registros.</p>
    {import.meta.env.DEV && <SyntheticCoveragePanel denied={!canRead} />}
  </section>;
  if (!canRead || status === 'denied') return shell('Acceso denegado: empresa, razón social o permiso insuficientes.', 'alert');
  if (status === 'disabled') return shell('El servidor no tiene habilitada esta función.', 'alert');

  const visible = rows.filter(row => (filter === 'TODOS' || row.state === filter)
    && [row.ref, socialCategoryLabels[row.category]].some(x => x.toLowerCase().includes(search.trim().toLowerCase())));
  const current = rows.find(row => row.ref === selected);
  return <section id="social-security-management" className="catalog-panel ui-page" aria-label="Seguridad social">
    <PageHeader title="Seguridad social" description="Registros internos de seguridad social por empresa y razón social."
      actions={canCreate ? <button type="button" onClick={() => setPanel('create')}>Nuevo registro</button> : undefined} />
    <p className="muted">Solo identificadores opacos. No realiza afiliaciones, aportes ni envíos a PILA.</p>
    {notice && <output role="status" aria-live="polite">{notice}</output>}
    {status === 'loading' && <p role="status">Cargando registros de seguridad social…</p>}
    {status === 'error' && <p role="alert">No fue posible cargar los registros.</p>}
    {status === 'ready' && <>
      <MetricStrip label="Resumen de seguridad social" items={[
        { label: 'Registros', value: rows.length },
        { label: 'Pendientes', value: rows.filter(row => row.state === 'PENDIENTE').length },
        { label: 'Requieren revisión', value: rows.filter(row => row.state === 'REQUIERE_REVISION').length },
      ]} />
      <Card title="Registros por razón social">
        <Toolbar trailing={<output aria-live="polite">{visible.length} resultados</output>}>
          <label>Buscar referencia <input type="search" value={search}
            onChange={event => setSearch(event.target.value)} /></label>
          <label>Estado <select value={filter}
            onChange={event => setFilter(event.target.value as typeof filter)}>
            <option value="TODOS">Todos</option>
            <option value="PENDIENTE">Pendiente</option>
            <option value="REQUIERE_REVISION">Requiere revisión</option>
          </select></label>
        </Toolbar>
        <DataTable<SocialRealRecord> caption="Registros de seguridad social" rows={visible}
          rowKey={row => row.ref} onOpen={row => { setSelected(row.ref); setPanel('detail'); }}
          columns={[
            { key: 'ref', header: 'Referencia', render: row => row.ref },
            { key: 'category', header: 'Categoría', render: row => socialCategoryLabels[row.category] },
            { key: 'state', header: 'Estado', render: row =>
              <StatusPill tone={row.state === 'PENDIENTE' ? 'neutral' : 'warn'}>
                {row.state === 'PENDIENTE' ? 'Pendiente' : 'Requiere revisión'}
              </StatusPill> },
          ]} empty={<p role="status">No hay registros para este filtro.</p>} />
      </Card>
    </>}
    <SidePanel open={panel === 'create' && canCreate && status === 'ready'}
      title="Nuevo registro de seguridad social" onClose={() => setPanel(null)}>
      <form onSubmit={event => void create(event)}>
        <p>Usa referencias opacas, nunca datos personales.</p>
        <label>Referencia <input required pattern="[a-z][a-z0-9_\-]{0,63}" maxLength={64}
          value={ref} onChange={event => setRef(event.target.value)} /></label>
        <label>Categoría <select value={category}
          onChange={event => setCategory(event.target.value as SocialRealRecord['category'])}>
          {socialCategories.map(value => <option key={value} value={value}>{socialCategoryLabels[value]}</option>)}
        </select></label>
        <button type="submit" disabled={busy}>{busy ? 'Guardando…' : 'Guardar registro'}</button>
      </form>
    </SidePanel>
    <SidePanel open={panel === 'detail' && current !== undefined && status === 'ready'}
      title="Detalle del registro" onClose={() => setPanel(null)}>
      {current && <><dl>
        <dt>Referencia</dt><dd>{current.ref}</dd>
        <dt>Categoría</dt><dd>{socialCategoryLabels[current.category]}</dd>
        <dt>Estado</dt><dd>{current.state === 'PENDIENTE' ? 'Pendiente' : 'Requiere revisión'}</dd>
        <dt>Versión auditada</dt><dd>{current.version}</dd>
      </dl>{canUpdate && <form onSubmit={event => void advance(event)}>
        <button type="submit" disabled={busy}>{busy ? 'Guardando…'
          : current.state === 'PENDIENTE' ? 'Enviar a revisión' : 'Devolver a pendiente'}</button>
      </form>}</>}
    </SidePanel>
  </section>;
}
