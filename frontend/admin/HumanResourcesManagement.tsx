/// <reference types="vite/client" />
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Card, DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';

type Props = Readonly<{
  tenantId: string;
  companyId: string;
  branchId: string;
  permissions: string[];
  /** Session token supplied by the authorized admin shell, never persisted here. */
  csrfToken?: string;
}>;
type HrKind = 'vinculacion' | 'novedad' | 'movimiento_interno';
type HrState = 'BORRADOR' | 'EN_REVISION';
type HrRecord = Readonly<{
  id: string;
  tenant_id: string;
  company_id: string;
  record_ref: string;
  collaborator_ref: string;
  position_ref: string;
  kind: HrKind;
  state: HrState;
  version: number;
}>;
type DetailView = { status: 'idle' | 'loading' | 'ready' | 'error' | 'denied' | 'disabled'; record?: HrRecord };
type RequestScope = Readonly<{ enabled: boolean; allowed: boolean; tenantId: string; companyId: string; branchId: string; signal?: AbortSignal }>;

type View =
  | { status: 'loading'; records: HrRecord[] }
  | { status: 'ready'; records: HrRecord[] }
  | { status: 'denied' | 'disabled' | 'error'; records: [] };

const ulid = /^[0-9A-HJKMNP-TV-Z]{26}$/;
const alias = /^[a-z][a-z0-9_-]{0,63}$/;
const kinds: HrKind[] = ['vinculacion', 'novedad', 'movimiento_interno'];
const labels: Record<HrKind, string> = {
  vinculacion: 'Vinculación', novedad: 'Novedad', movimiento_interno: 'Movimiento interno',
};
const isObject = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value);

/** Never render a row from another legal entity, even if the server regresses. */
function parseRecords(payload: unknown, tenant: string, company: string): HrRecord[] {
  if (!isObject(payload) || !Array.isArray(payload.records) || payload.records.length > 100) {
    throw new Error('Respuesta de RR. HH. no verificable');
  }
  const seen = new Set<string>();
  for (const item of payload.records as unknown[]) {
    if (!isObject(item) || item.tenant_id !== tenant || item.company_id !== company
      || typeof item.id !== 'string' || !ulid.test(item.id)
      || typeof item.record_ref !== 'string' || !alias.test(item.record_ref)
      || typeof item.collaborator_ref !== 'string' || !alias.test(item.collaborator_ref)
      || typeof item.position_ref !== 'string' || !alias.test(item.position_ref)
      || !kinds.includes(item.kind as HrKind)
      || (item.state !== 'BORRADOR' && item.state !== 'EN_REVISION')
      || !Number.isSafeInteger(item.version) || (item.version as number) < 1
      || seen.has(item.record_ref)) {
      throw new Error('Registro laboral fuera de alcance o inválido');
    }
    seen.add(item.record_ref);
  }
  return payload.records as HrRecord[];
}

async function failure(response: Response): Promise<'denied' | 'disabled' | 'error'> {
  if (response.status === 401 || response.status === 403) return 'denied';
  if (response.status === 404) {
    try {
      const body: unknown = await response.json();
      if (isObject(body) && body.error === 'feature_unavailable') return 'disabled';
    } catch { /* An unknown 404 is not evidence of a disabled feature. */ }
  }
  return 'error';
}

/** Only a server-rendered, explicit true enables client-side HR requests. Missing means OFF. */
export function isHrRealDataEnabled(root: HTMLElement | null): boolean {
  return root?.dataset.hrRealDataEnabled === 'true';
}

/** Reads the real server API only if the explicit server feature gate is open. */
export async function readHrRecords(
  scope: RequestScope, transport: typeof fetch = fetch,
): Promise<View> {
  if (!scope.enabled) return { status: 'disabled', records: [] };
  if (!scope.allowed) return { status: 'denied', records: [] };
  try {
    const response = await transport('/api/v1/branches/' + encodeURIComponent(scope.branchId) + '/hr/records', {
      credentials: 'same-origin', cache: 'no-store',
      headers: { Accept: 'application/json' }, signal: scope.signal,
    });
    if (!response.ok) return { status: await failure(response), records: [] };
    const payload: unknown = await response.json();
    return { status: 'ready', records: parseRecords(payload, scope.tenantId, scope.companyId) };
  } catch {
    return { status: 'error', records: [] };
  }
}

/** Fetches the canonical detail, never reuses a potentially stale list snapshot. */
export async function readHrDetail(
  scope: RequestScope, ref: string, transport: typeof fetch = fetch,
): Promise<DetailView> {
  if (!scope.enabled) return { status: 'disabled' };
  if (!scope.allowed) return { status: 'denied' };
  if (!alias.test(ref)) return { status: 'error' };
  try {
    const response = await transport('/api/v1/branches/' + encodeURIComponent(scope.branchId)
      + '/hr/records/' + encodeURIComponent(ref), {
      credentials: 'same-origin', cache: 'no-store',
      headers: { Accept: 'application/json' }, signal: scope.signal,
    });
    if (!response.ok) return { status: await failure(response) };
    const payload: unknown = await response.json();
    if (!isObject(payload)) throw new Error('Detalle de RR. HH. no verificable');
    const rows = parseRecords({ records: [payload.record] }, scope.tenantId, scope.companyId);
    if (rows[0]?.record_ref !== ref) throw new Error('Detalle fuera de alcance');
    return { status: 'ready', record: rows[0] };
  } catch {
    return { status: 'error' };
  }
}

/** Writes only with server-gated scope, authorization and a session CSRF token. */
export async function writeHrRecord(
  scope: RequestScope, method: 'POST' | 'PATCH', url: string, body: object,
  csrfToken: string, transport: typeof fetch = fetch,
): Promise<'saved' | 'denied' | 'disabled' | 'error'> {
  if (!scope.enabled) return 'disabled';
  if (!scope.allowed) return 'denied';
  if (!csrfToken) return 'error';
  try {
    const response = await transport(url, {
      method, credentials: 'same-origin', cache: 'no-store',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken },
      body: JSON.stringify(body), signal: scope.signal,
    });
    if (!response.ok) return failure(response);
    const payload: unknown = await response.json();
    if (!isObject(payload)) return 'error';
    const rows = parseRecords({ records: [payload.record] }, scope.tenantId, scope.companyId);
    return rows.length === 1 ? 'saved' : 'error';
  } catch {
    return 'error';
  }
}

function Workspace({ tenantId, companyId, branchId, permissions, csrfToken }: Props) {
  // The admin entrypoint already receives this server-generated session CSRF
  // token as data-access-token on the same-origin root. Never persist or log it.
  const sessionCsrf = csrfToken || document.getElementById('condor-admin-root')?.dataset.accessToken || '';
  const canView = permissions.includes('users.view') && ulid.test(tenantId)
    && ulid.test(companyId) && ulid.test(branchId);
  const canCreate = canView && permissions.includes('users.create') && !!sessionCsrf;
  const canUpdate = canView && permissions.includes('users.update') && !!sessionCsrf;
  const [view, setView] = useState<View>({ status: 'loading', records: [] });
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState('');
  const [detail, setDetail] = useState<DetailView>({ status: 'idle' });
  const [filterKind, setFilterKind] = useState<'all' | HrKind>('all');
  const [filterState, setFilterState] = useState<'all' | HrState>('all');
  const [panel, setPanel] = useState<'create' | 'detail' | null>(null);
  const [recordRef, setRecordRef] = useState('');
  const [collaboratorRef, setCollaboratorRef] = useState('');
  const [positionRef, setPositionRef] = useState('');
  const [kind, setKind] = useState<HrKind>('vinculacion');
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState('');
  const reader = useRef<AbortController | null>(null);
  const detailReader = useRef<AbortController | null>(null);
  const writer = useRef<AbortController | null>(null);
  const mounted = useRef(true);
  const path = '/api/v1/branches/' + encodeURIComponent(branchId) + '/hr/records';

  async function load() {
    reader.current?.abort();
    const request = new AbortController();
    reader.current = request;
    setView({ status: 'loading', records: [] });
    const result = await readHrRecords({ enabled: true, allowed: canView,
      tenantId, companyId, branchId, signal: request.signal });
    if (!request.signal.aborted && mounted.current) setView(result);
  }

  async function openDetail(ref: string) {
    detailReader.current?.abort();
    const request = new AbortController();
    detailReader.current = request;
    setSelected(ref); setDetail({ status: 'loading' }); setPanel('detail');
    const result = await readHrDetail({ enabled: true, allowed: canView,
      tenantId, companyId, branchId, signal: request.signal }, ref);
    if (request.signal.aborted || !mounted.current) return;
    setDetail(result);
    if (result.status === 'denied' || result.status === 'disabled') {
      setView({ status: result.status, records: [] });
      setPanel(null);
    }
  }

  useEffect(() => {
    mounted.current = true;
    void load();
    return () => { mounted.current = false; reader.current?.abort(); detailReader.current?.abort(); writer.current?.abort(); };
    // The component is remounted on every scope change by the key below.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function submit(url: string, method: 'POST' | 'PATCH', body: object) {
    const allowed = method === 'POST' ? canCreate : canUpdate;
    if (!allowed || !sessionCsrf || view.status !== 'ready' || saving || writer.current) return;
    const request = new AbortController();
    writer.current = request;
    setSaving(true); setNotice('');
    try {
      const status = await writeHrRecord({ enabled: true, allowed,
        tenantId, companyId, branchId, signal: request.signal },
      method, url, body, sessionCsrf);
      if (!mounted.current || request.signal.aborted) return;
      if (status !== 'saved') {
        if (status === 'denied' || status === 'disabled') {
          setView({ status, records: [] }); setPanel(null);
        } else {
          setNotice('No se pudo confirmar el cambio. Revisa permisos, versión y referencias antes de reintentar.');
        }
        return;
      }
      // Read the server authority after every write; never fabricate a successful row.
      setNotice('Cambio confirmado por el servidor. Actualizando registros…');
      setRecordRef(''); setCollaboratorRef(''); setPositionRef('');
      await load();
      if (mounted.current && !request.signal.aborted) {
        detailReader.current?.abort();
        setDetail({ status: 'idle' }); setPanel(null);
      }
    } finally {
      if (writer.current === request) writer.current = null;
      if (mounted.current) setSaving(false);
    }
  }

  function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || !alias.test(recordRef) || !alias.test(collaboratorRef)
      || !alias.test(positionRef) || !kinds.includes(kind)) {
      setNotice('Utiliza referencias internas válidas y una sesión autorizada.'); return;
    }
    void submit(path, 'POST', { record_ref: recordRef, collaborator_ref: collaboratorRef,
      position_ref: positionRef, kind });
  }

  const records = view.status === 'ready' ? view.records : [];
  const visible = records.filter(row => (row.record_ref.includes(search.trim().toLowerCase())
    || row.position_ref.includes(search.trim().toLowerCase()))
    && (filterKind === 'all' || row.kind === filterKind)
    && (filterState === 'all' || row.state === filterState));
  const current = detail.status === 'ready' && detail.record?.record_ref === selected
    ? detail.record : undefined;

  if (!canView) return <section id="human-resources-management" className="catalog-panel ui-page">
    <PageHeader title="Talento humano" description="Gestión de cargos y novedades por empresa y sede." />
    <p role="alert">No tienes permiso para consultar esta empresa o sede.</p>
  </section>;

  return <section id="human-resources-management" className="catalog-panel ui-page" aria-label="Talento humano">
    <PageHeader title="Talento humano · gestión de cargos y novedades"
      description="Registros por empresa y sede, con autorización del servidor y trazabilidad de versiones."
      actions={canCreate && view.status === 'ready'
        ? <button type="button" className="button" onClick={() => setPanel('create')}>Nuevo registro</button>
        : undefined} />
    {view.status === 'loading' && <output>Cargando registros de talento humano…</output>}
    {view.status === 'denied' && <p role="alert">Acceso denegado: no tienes autorización para estos registros.</p>}
    {view.status === 'disabled' && <p role="alert">Talento humano todavía no está habilitado en el servidor.</p>}
    {view.status === 'error' && <Card title="No se pudo consultar talento humano">
      <div role="alert"><p>No se pudo consultar talento humano.</p>
        <button type="button" onClick={() => { void load(); }}>Reintentar consulta</button></div>
    </Card>}
    {view.status === 'ready' && <>
      <MetricStrip label="Indicadores de talento humano" items={[
        { label: 'Registros', value: records.length },
        { label: 'Borradores', value: records.filter(row => row.state === 'BORRADOR').length },
        { label: 'En revisión', value: records.filter(row => row.state === 'EN_REVISION').length },
      ]} />
      <Card title="Registros laborales">
        <Toolbar trailing={<output aria-live="polite">{visible.length} registros</output>}>
          <label>Buscar por referencia o cargo
            <input type="search" value={search} maxLength={64}
              onChange={event => setSearch(event.target.value)} />
          </label>
          <label>Tipo de registro
            <select aria-label="Filtrar por tipo" value={filterKind}
              onChange={event => setFilterKind(event.target.value as 'all' | HrKind)}>
              <option value="all">Todos</option>
              {kinds.map(value => <option value={value} key={value}>{labels[value]}</option>)}
            </select>
          </label>
          <label>Estado
            <select aria-label="Filtrar por estado" value={filterState}
              onChange={event => setFilterState(event.target.value as 'all' | HrState)}>
              <option value="all">Todos</option>
              <option value="BORRADOR">Borrador</option>
              <option value="EN_REVISION">En revisión</option>
            </select>
          </label>
        </Toolbar>
        <DataTable<HrRecord> caption="Registros laborales de la empresa"
          rows={visible} rowKey={row => row.id}
          onOpen={row => { void openDetail(row.record_ref); }}
          columns={[
            { key: 'reference', header: 'Referencia', render: row => row.record_ref },
            { key: 'position', header: 'Cargo', render: row => row.position_ref },
            { key: 'kind', header: 'Clase', render: row => labels[row.kind] },
            { key: 'status', header: 'Estado', render: row =>
              <StatusPill tone={row.state === 'BORRADOR' ? 'neutral' : 'info'}>
                {row.state === 'BORRADOR' ? 'Borrador' : 'En revisión'}
              </StatusPill> },
          ]}
          empty={<output>No hay registros para esta empresa y filtro.</output>} />
      </Card>
      {!sessionCsrf && (permissions.includes('users.create') || permissions.includes('users.update')) &&
        <output>Edición deshabilitada hasta recibir el token de sesión del panel administrativo.</output>}
      <SidePanel open={panel === 'detail'}
        title="Detalle del registro" onClose={() => { detailReader.current?.abort(); setPanel(null); }}>
        {detail.status === 'loading' && <output aria-live="polite">Cargando detalle del registro…</output>}
        {detail.status === 'error' && <p role="alert">No se pudo consultar el detalle. Cierra y vuelve a abrir el registro.</p>}
        {current && <>
          <dl><dt>Referencia</dt><dd>{current.record_ref}</dd>
            <dt>Colaborador</dt><dd>{current.collaborator_ref}</dd>
            <dt>Cargo</dt><dd>{current.position_ref}</dd>
            <dt>Clase</dt><dd>{labels[current.kind]}</dd>
            <dt>Estado</dt><dd><StatusPill tone={current.state === 'BORRADOR' ? 'neutral' : 'info'}>{current.state}</StatusPill></dd>
            <dt>Versión</dt><dd>{current.version}</dd></dl>
          {canUpdate && current.state === 'BORRADOR' && <button type="button" disabled={saving}
            onClick={() => { void submit(path + '/' + encodeURIComponent(current.record_ref) + '/state',
              'PATCH', { state: 'EN_REVISION', expected_version: current.version }); }}>
            Enviar a revisión
          </button>}
          <p className="muted">La revisión no implica aprobación contractual ni cierre automático.</p>
        </>}
        {notice && <output role="status" aria-live="polite">{notice}</output>}
      </SidePanel>
      <SidePanel open={panel === 'create' && canCreate} title="Nuevo registro" onClose={() => setPanel(null)}>
        <form className="catalog-editor" onSubmit={create}>
          <label>Referencia del registro<input required value={recordRef} maxLength={64}
            pattern="[a-z][a-z0-9_-]{0,63}" onChange={event => setRecordRef(event.target.value)} /></label>
          <label>Referencia del colaborador<input required value={collaboratorRef} maxLength={64}
            pattern="[a-z][a-z0-9_-]{0,63}" onChange={event => setCollaboratorRef(event.target.value)} /></label>
          <label>Referencia del cargo<input required value={positionRef} maxLength={64}
            pattern="[a-z][a-z0-9_-]{0,63}" onChange={event => setPositionRef(event.target.value)} /></label>
          <label>Tipo de registro<select value={kind}
            onChange={event => setKind(event.target.value as HrKind)}>
            {kinds.map(value => <option value={value} key={value}>{labels[value]}</option>)}
          </select></label>
          <button type="submit" disabled={saving}>{saving ? 'Guardando…' : 'Guardar registro'}</button>
          {notice && <output role="status" aria-live="polite">{notice}</output>}
        </form>
      </SidePanel>
    </>}
    {notice && <output role="status" aria-live="polite">{notice}</output>}
  </section>;
}

/** No HTTP, person data or artificial test rows while the server gate is absent/off. */
export function HumanResourcesManagement(props: Props) {
  const root = typeof document === 'undefined' ? null : document.getElementById('condor-admin-root');
  if (!isHrRealDataEnabled(root)) return <section id="human-resources-management"
    className="catalog-panel ui-page" aria-label="Talento humano">
    <PageHeader title="Talento humano" description="Gestión de cargos y novedades por empresa y sede." />
    <p role="status">Talento humano todavía no está habilitado en el servidor.</p>
  </section>;
  return <Workspace key={props.tenantId + '|' + props.companyId + '|' + props.branchId + '|' + props.permissions.slice().sort((a, b) => a.localeCompare(b)).join(',')} {...props} />;
}
