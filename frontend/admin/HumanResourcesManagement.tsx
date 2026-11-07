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
  const [panel, setPanel] = useState<'create' | 'detail' | null>(null);
  const [recordRef, setRecordRef] = useState('');
  const [collaboratorRef, setCollaboratorRef] = useState('');
  const [positionRef, setPositionRef] = useState('');
  const [kind, setKind] = useState<HrKind>('vinculacion');
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState('');
  const reader = useRef<AbortController | null>(null);
  const writer = useRef<AbortController | null>(null);
  const mounted = useRef(true);
  const path = '/api/v1/branches/' + encodeURIComponent(branchId) + '/hr/records';

  async function load() {
    if (!canView) { setView({ status: 'denied', records: [] }); return; }
    reader.current?.abort();
    const request = new AbortController();
    reader.current = request;
    setView({ status: 'loading', records: [] });
    try {
      const response = await fetch(path, { credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json' }, signal: request.signal });
      if (request.signal.aborted || !mounted.current) return;
      if (!response.ok) {
        const status = await failure(response);
        if (!request.signal.aborted && mounted.current) setView({ status, records: [] });
        return;
      }
      const payload: unknown = await response.json();
      if (!request.signal.aborted && mounted.current) {
        setView({ status: 'ready', records: parseRecords(payload, tenantId, companyId) });
      }
    } catch {
      if (!request.signal.aborted && mounted.current) setView({ status: 'error', records: [] });
    }
  }

  useEffect(() => {
    mounted.current = true;
    void load();
    return () => { mounted.current = false; reader.current?.abort(); writer.current?.abort(); };
    // The component is remounted on every scope change by the key below.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function submit(url: string, method: 'POST' | 'PATCH', body: object) {
    if (!sessionCsrf || view.status !== 'ready' || saving || writer.current) return;
    const request = new AbortController();
    writer.current = request;
    setSaving(true); setNotice('');
    try {
      const response = await fetch(url, { method, credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json',
          'X-CSRF-Token': sessionCsrf }, body: JSON.stringify(body), signal: request.signal });
      if (!mounted.current || request.signal.aborted) return;
      if (!response.ok) {
        const denied = await failure(response);
        if (!mounted.current || request.signal.aborted) return;
        if (denied === 'denied' || denied === 'disabled') setView({ status: denied, records: [] });
        else setNotice('No se aplicó el cambio. Revisa permisos, versión y referencias.');
        return;
      }
      // A successful write must be reloaded from server authority; never retry it automatically.
      setNotice('Cambio confirmado por el servidor. Actualizando registros…');
      setRecordRef(''); setCollaboratorRef(''); setPositionRef('');
      await load();
      if (mounted.current && !request.signal.aborted) setPanel(null);
    } catch {
      if (mounted.current && !request.signal.aborted)
        setNotice('No se pudo confirmar el resultado. Recarga antes de intentar otra operación.');
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
  const visible = records.filter(row => row.record_ref.includes(search.trim().toLowerCase())
    || row.position_ref.includes(search.trim().toLowerCase()));
  const current = records.find(row => row.record_ref === selected);

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
    <p role="note">Entorno de construcción: usar únicamente referencias inventadas. No ingresar información personal real.</p>
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
        </Toolbar>
        <DataTable<HrRecord> caption="Registros laborales de la empresa"
          rows={visible} rowKey={row => row.id}
          onOpen={row => { setSelected(row.record_ref); setPanel('detail'); }}
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
      <SidePanel open={panel === 'detail' && !!current}
        title="Detalle del registro" onClose={() => setPanel(null)}>
        {current ? <>
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
        </> : <p>Selecciona un registro para consultar su detalle.</p>}
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
        </form>
      </SidePanel>
    </>}
    {notice && <output role="status" aria-live="polite">{notice}</output>}
  </section>;
}

/** The production surface stays closed until owner/go-live gates authorize it. */
export function HumanResourcesManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <Workspace key={props.tenantId + '|' + props.companyId + '|' + props.branchId + '|' + props.permissions.slice().sort((a, b) => a.localeCompare(b)).join(',')} {...props} />;
}
