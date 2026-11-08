/// <reference types="vite/client" />
import { useEffect, useState, type FormEvent } from 'react';
import { DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';

/** Interfaz de Gobierno corporativo pre-live: no habilita tratamientos ni aprueba actos. */
type State = 'PENDIENTE' | 'EN_REVISION' | 'REQUIERE_REVISION';
type Kind = 'ACTA' | 'ACUERDO' | 'POLITICA' | 'REVISION';
export type GovernanceRecord = Readonly<{
  ref: string; kind: Kind; state: State; version: number;
  tenantId: string; companyId: string; occurredAt: string;
}>;
type Result = { status: 'disabled' | 'denied' | 'error' }
  | { status: 'ready'; records: GovernanceRecord[]; nextCursor: string | null }
  | { status: 'ready'; record: GovernanceRecord };
type Method = 'GET' | 'POST' | 'PATCH';
export type GovernanceRequest = Readonly<{
  enabled: boolean; allowed: boolean; tenantId: string; companyId: string; branchId: string;
  method?: Method; ref?: string; after?: string; kind?: Kind; state?: State;
  expectedVersion?: number; csrf?: string;
}>;
type Props = Readonly<{
  enabled: boolean; tenantId: string; companyId: string; branchId: string;
  permissions: string[]; csrfToken: string;
}>;
const opaque = /^[a-z][a-z0-9_-]{0,63}$/;
const kinds: readonly Kind[] = ['ACTA', 'ACUERDO', 'POLITICA', 'REVISION'];
const states: readonly State[] = ['PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION'];
const kindNames: Record<Kind, string> = {
  ACTA: 'Acta', ACUERDO: 'Acuerdo', POLITICA: 'Política', REVISION: 'Revisión',
};
const stateNames: Record<State, string> = {
  PENDIENTE: 'Pendiente', EN_REVISION: 'En revisión',
  REQUIERE_REVISION: 'Requiere revisión',
};
const validRef = (value: string): boolean => opaque.test(value) && !/[0-9]{7,}/.test(value);
const transitions: Record<State, readonly State[]> = {
  PENDIENTE: ['EN_REVISION', 'REQUIERE_REVISION'],
  EN_REVISION: ['REQUIERE_REVISION'],
  REQUIERE_REVISION: ['EN_REVISION'],
};

function normalizeRecord(value: unknown, tenantId: string, companyId: string): GovernanceRecord | null {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) return null;
  const row = value as Record<string, unknown>;
  if (row.tenant_id !== tenantId || row.company_id !== companyId
    || typeof row.record_ref !== 'string' || !validRef(row.record_ref)
    || !kinds.includes(row.kind as Kind) || !states.includes(row.state as State)
    || typeof row.occurred_at !== 'string'
    || !/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/.test(row.occurred_at)) return null;
  const version = Number(row.version);
  if (!Number.isInteger(version) || version < 1 || version > 1_000_000) return null;
  return { ref: row.record_ref, kind: row.kind as Kind, state: row.state as State,
    version, tenantId, companyId, occurredAt: row.occurred_at };
}

/** La autoridad de flag, permiso y ámbito es del servidor. OFF nunca llega al transporte. */
export async function governanceRequest(
  options: GovernanceRequest, transport: typeof fetch = fetch,
): Promise<Result> {
  if (!options.enabled) return { status: 'disabled' };
  if (!options.allowed || !options.tenantId || !options.companyId
    || !options.branchId || options.companyId === '__unassigned__') return { status: 'denied' };
  const method = options.method ?? 'GET';
  if (options.ref !== undefined && !validRef(options.ref)) return { status: 'error' };
  if (options.after !== undefined && !validRef(options.after)) return { status: 'error' };
  if (method === 'POST' && (!options.csrf || !options.ref
    || !kinds.includes(options.kind as Kind))) return { status: 'error' };
  if (method === 'PATCH' && (!options.csrf || !options.ref
    || !states.includes(options.state as State)
    || !Number.isInteger(options.expectedVersion)
    || (options.expectedVersion ?? 0) < 1
    || (options.expectedVersion ?? 0) >= 1_000_000)) return { status: 'error' };
  if (method !== 'GET' && method !== 'POST' && method !== 'PATCH') return { status: 'error' };
  const endpoint = '/api/v1/branches/' + encodeURIComponent(options.branchId)
    + '/governance-real/records';
  const suffix = method === 'GET' && !options.ref && options.after
    ? '?after=' + encodeURIComponent(options.after) : '';
  const url = options.ref && method !== 'POST'
    ? endpoint + '/' + encodeURIComponent(options.ref) + (method === 'PATCH' ? '/state' : '')
    : endpoint + suffix;
  const body = method === 'POST' ? { record_ref: options.ref, kind: options.kind }
    : { state: options.state, expected_version: options.expectedVersion };
  try {
    const response = await transport(url, {
      method, credentials: 'same-origin', cache: 'no-store',
      headers: { Accept: 'application/json', ...(method === 'GET' ? {}
        : { 'Content-Type': 'application/json', 'X-CSRF-Token': options.csrf ?? '' }) },
      ...(method === 'GET' ? {} : { body: JSON.stringify(body) }),
    });
    if ([401, 403].includes(response.status)) return { status: 'denied' };
    if (response.status === 404 && method !== 'GET') return { status: 'disabled' };
    if (response.status === 404 && method === 'GET' && !options.ref) return { status: 'disabled' };
    if (!response.ok) return { status: 'error' };
    const payload: unknown = await response.json();
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) return { status: 'error' };
    const data = payload as Record<string, unknown>;
    if (method === 'GET' && !options.ref) {
      if (!Array.isArray(data.records) || data.records.length > 100
        || !(data.next_cursor === null || (typeof data.next_cursor === 'string' && validRef(data.next_cursor)))) {
        return { status: 'error' };
      }
      const parsed = data.records.map(x => normalizeRecord(x, options.tenantId, options.companyId));
      if (parsed.some(x => x === null)) return { status: 'error' };
      const records = parsed as GovernanceRecord[];
      if (new Set(records.map(x => x.ref)).size !== records.length
        || (options.after && records.some(x => x.ref.localeCompare(options.after ?? '') <= 0))
        || (data.next_cursor && data.next_cursor !== records.at(-1)?.ref)
        || (records.length === 0 && data.next_cursor)) return { status: 'error' };
      return { status: 'ready', records, nextCursor: data.next_cursor as string | null };
    }
    const record = normalizeRecord(data.record, options.tenantId, options.companyId);
    if (!record || (options.ref && record.ref !== options.ref)) return { status: 'error' };
    return { status: 'ready', record };
  } catch {
    return { status: 'error' };
  }
}

export function GovernanceManagement({
  enabled, tenantId, companyId, branchId, permissions, csrfToken,
}: Props) {
  const canView = permissions.includes('inventory.view') && companyId !== '__unassigned__';
  const canCreate = canView && permissions.includes('inventory.create');
  const canUpdate = canView && permissions.includes('inventory.update');
  const [status, setStatus] = useState<'loading' | 'ready' | 'disabled' | 'denied' | 'error'>('loading');
  const [records, setRecords] = useState<GovernanceRecord[]>([]);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [panel, setPanel] = useState<'create' | 'detail' | null>(null);
  const [selected, setSelected] = useState('');
  const [reference, setReference] = useState('');
  const [kind, setKind] = useState<Kind>('ACTA');
  const [nextState, setNextState] = useState<State>('EN_REVISION');
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState<'TODOS' | State>('TODOS');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!enabled || !canView) return;
    let active = true;
    setStatus('loading');
    setRecords([]);
    setNextCursor(null);
    setPanel(null);
    void governanceRequest({ enabled, allowed: canView, tenantId, companyId, branchId })
      .then(result => {
        if (!active) return;
        if (result.status === 'ready' && 'records' in result) {
          setRecords(result.records);
          setNextCursor(result.nextCursor);
          setStatus('ready');
        } else setStatus(result.status === 'ready' ? 'error' : result.status);
      });
    return () => { active = false; };
  }, [enabled, canView, tenantId, companyId, branchId]);

  async function loadMore() {
    if (!enabled || busy || !nextCursor || status !== 'ready') return;
    setBusy(true);
    const result = await governanceRequest({
      enabled, allowed: canView, tenantId, companyId, branchId, after: nextCursor,
    });
    setBusy(false);
    if (result.status === 'ready' && 'records' in result) {
      setRecords(previous => {
        const seen = new Set(previous.map(row => row.ref));
        return [...previous, ...result.records.filter(row => !seen.has(row.ref))];
      });
      setNextCursor(result.nextCursor);
    } else {
      setNotice('No se pudo cargar la siguiente página. Intenta nuevamente.');
      if (result.status === 'denied' || result.status === 'disabled') setStatus(result.status);
    }
  }

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    const result = await governanceRequest({
      enabled, allowed: canCreate, tenantId, companyId, branchId,
      method: 'POST', ref: reference, kind, csrf: csrfToken,
    });
    setBusy(false);
    if (result.status === 'ready' && 'record' in result) {
      setRecords(previous => [...previous.filter(row => row.ref !== result.record.ref), result.record]);
      setReference('');
      setSelected(result.record.ref);
      setNextState('EN_REVISION');
      setPanel('detail');
      setNotice('Instrumento registrado. La revisión jurídica sigue pendiente.');
    } else {
      if (result.status === 'denied' || result.status === 'disabled') setStatus(result.status);
      setNotice('No se pudo crear el instrumento. Revisa permisos y referencia.');
    }
  }

  async function view(ref: string) {
    if (busy || !canView) return;
    setBusy(true);
    const result = await governanceRequest({
      enabled, allowed: canView, tenantId, companyId, branchId, ref,
    });
    setBusy(false);
    if (result.status === 'ready' && 'record' in result) {
      setRecords(previous => previous.map(row => row.ref === ref ? result.record : row));
      setSelected(ref);
      setNextState(transitions[result.record.state][0] ?? result.record.state);
      setPanel('detail');
      setNotice('');
    } else {
      if (result.status === 'denied' || result.status === 'disabled') setStatus(result.status);
      setNotice('No se pudo abrir el instrumento. Recarga la lista.');
    }
  }

  async function transition(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const current = records.find(row => row.ref === selected);
    if (!current || busy || !canUpdate || !transitions[current.state].includes(nextState)) return;
    setBusy(true);
    const result = await governanceRequest({
      enabled, allowed: canUpdate, tenantId, companyId, branchId,
      method: 'PATCH', ref: current.ref, state: nextState,
      expectedVersion: current.version, csrf: csrfToken,
    });
    setBusy(false);
    if (result.status === 'ready' && 'record' in result) {
      setRecords(previous => previous.map(row => row.ref === current.ref ? result.record : row));
      setNextState(transitions[result.record.state][0] ?? result.record.state);
      setNotice('Estado registrado con nueva versión de auditoría. No constituye aprobación legal.');
    } else {
      if (result.status === 'denied' || result.status === 'disabled') setStatus(result.status);
      setNotice('No se guardó el cambio. Actualiza el registro y comprueba sus permisos.');
    }
  }

  if (!enabled) return <section className="catalog-panel ui-page" id="governance-management" aria-label="Gobierno corporativo">
    <PageHeader title="Gobierno corporativo" description="El módulo aún no está habilitado." />
    <p role="status">La gestión persistente permanece desactivada por el servidor. No se han consultado ni mostrado registros.</p>
  </section>;
  if (!canView || status === 'denied') return <section className="catalog-panel ui-page" id="governance-management">
    <PageHeader title="Gobierno corporativo" />
    <p role="alert">Acceso denegado: selecciona una razón social y solicita permiso de consulta.</p>
  </section>;
  if (status === 'disabled') return <section className="catalog-panel ui-page" id="governance-management">
    <PageHeader title="Gobierno corporativo" />
    <p role="alert">El servidor no tiene habilitada esta función. No se consultaron registros.</p>
  </section>;

  const visible = records.filter(row => (filter === 'TODOS' || row.state === filter)
    && (row.ref.includes(search.trim().toLowerCase())
      || kindNames[row.kind].toLowerCase().includes(search.trim().toLowerCase())));
  const current = records.find(row => row.ref === selected);
  return <section className="catalog-panel ui-page" id="governance-management" aria-label="Gobierno corporativo">
    <PageHeader title="Gobierno corporativo"
      description="Instrumentos y revisiones por razón social. Solo registros auditables."
      actions={canCreate && status === 'ready'
        ? <button type="button" className="button" onClick={() => { setPanel('create'); setNotice(''); }}>Nuevo instrumento</button>
        : undefined} />
    <p className="muted">La captura de actas, acuerdos y políticas exige referencias opacas. No concede aprobación jurídica.</p>
    {notice && <output role="status" aria-live="polite">{notice}</output>}
    {status === 'loading' && <p role="status">Cargando instrumentos de gobierno corporativo…</p>}
    {status === 'error' && <p role="alert">No fue posible cargar los instrumentos. Comprueba tu conexión.</p>}
    {status === 'ready' && <>
      <MetricStrip label="Indicadores de gobierno corporativo" items={[
        { label: 'Instrumentos cargados', value: records.length },
        { label: 'En revisión', value: records.filter(row => row.state === 'EN_REVISION').length },
        { label: 'Requieren revisión', value: records.filter(row => row.state === 'REQUIERE_REVISION').length },
      ]} />
      <Toolbar trailing={<output aria-live="polite">{visible.length} resultados cargados</output>}>
        <label>Buscar referencia o categoría
          <input type="search" value={search} onChange={event => setSearch(event.target.value)}
            placeholder="Buscar instrumento" />
        </label>
        <label>Estado
          <select value={filter} onChange={event => setFilter(event.target.value as typeof filter)}>
            <option value="TODOS">Todos los estados</option>
            {states.map(state => <option key={state} value={state}>{stateNames[state]}</option>)}
          </select>
        </label>
      </Toolbar>
      <DataTable<GovernanceRecord> caption="Instrumentos de la razón social activa"
        rows={visible} rowKey={row => row.ref}
        onOpen={row => { void view(row.ref); }}
        empty={<p role="status">No hay instrumentos para estos filtros. Utiliza “Nuevo instrumento” si tienes permiso.</p>}
        columns={[
          { key: 'reference', header: 'Referencia', render: row => <strong>{row.ref}</strong> },
          { key: 'kind', header: 'Tipo', render: row => kindNames[row.kind] },
          { key: 'state', header: 'Estado', render: row => <StatusPill tone={row.state === 'EN_REVISION' ? 'info'
            : row.state === 'REQUIERE_REVISION' ? 'warn' : 'neutral'}>{stateNames[row.state]}</StatusPill> },
          { key: 'version', header: 'Versión', render: row => row.version },
        ]} />
      {nextCursor && <div className="ui-page-actions">
        <button type="button" className="button button-secondary" disabled={busy}
          onClick={() => { void loadMore(); }}>{busy ? 'Cargando…' : 'Cargar más instrumentos'}</button>
      </div>}
    </>}
    <SidePanel open={panel === 'create' && canCreate && status === 'ready'}
      title="Nuevo instrumento" onClose={() => setPanel(null)}>
      <form className="catalog-editor" onSubmit={event => { void create(event); }}>
        <p className="muted">Utiliza un código interno, no nombres, correos ni documentos de identidad.</p>
        <label>Referencia interna
          <input required maxLength={64} pattern="[a-z][a-z0-9_-]{0,63}" placeholder="acta_asamblea"
            value={reference} onChange={event => setReference(event.target.value)} />
        </label>
        <label>Categoría
          <select value={kind} onChange={event => setKind(event.target.value as Kind)}>
            {kinds.map(value => <option key={value} value={value}>{kindNames[value]}</option>)}
          </select>
        </label>
        <button type="submit" className="button" disabled={busy || !validRef(reference)}>
          {busy ? 'Guardando…' : 'Guardar instrumento'}
        </button>
      </form>
    </SidePanel>
    <SidePanel open={panel === 'detail' && current !== undefined && status === 'ready'}
      title="Detalle del instrumento" onClose={() => setPanel(null)}>
      {current ? <>
        <dl>
          <dt>Referencia</dt><dd>{current.ref}</dd>
          <dt>Categoría</dt><dd>{kindNames[current.kind]}</dd>
          <dt>Estado</dt><dd><StatusPill tone={current.state === 'EN_REVISION' ? 'info' : 'warn'}>
            {stateNames[current.state]}</StatusPill></dd>
          <dt>Versión auditada</dt><dd>{current.version}</dd>
          <dt>Fecha de registro</dt><dd>{current.occurredAt}</dd>
        </dl>
        {canUpdate && <form className="catalog-editor" onSubmit={event => { void transition(event); }}>
          <label>Siguiente estado
            <select value={nextState} onChange={event => setNextState(event.target.value as State)}>
              {transitions[current.state].map(state => <option key={state} value={state}>
                {stateNames[state]}</option>)}
            </select>
          </label>
          <button type="submit" className="button" disabled={busy || !transitions[current.state].includes(nextState)}>
            {busy ? 'Guardando…' : 'Registrar cambio de estado'}
          </button>
        </form>}
      </> : <p role="status">Selecciona un instrumento para consultar su detalle.</p>}
    </SidePanel>
  </section>;
}
