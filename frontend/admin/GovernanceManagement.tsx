/// <reference types="vite/client" />
import { useEffect, useState, type FormEvent } from 'react';
import { PageHeader, MetricStrip, Toolbar, DataTable, SidePanel, StatusPill } from './ui/Kit';

/** M15: simulación local de gobierno corporativo; nunca es un libro de actas real. */
type GovernanceState = 'BORRADOR' | 'REQUIERE_REVISION' | 'EN_REVISION';
type GovernanceKind = 'shareholder' | 'faculty' | 'authorization' | 'decision';
type GovernanceRow = Readonly<{
  reference: string;
  bodyRef: string;
  representativeRef: string;
  kind: GovernanceKind;
  tenantId: string;
  companyId: string;
  branchId: string;
  state: GovernanceState;
}>;
type AuditEvent = Readonly<{ scope: string; reference: string; action: 'created' | 'viewed' | 'review_requested' | 'review_started' }>;
type Props = Readonly<{ tenantId: string; companyId: string; branchId: string; permissions: string[] }>;

const governanceRefPattern = /^fixture:governance\/[a-z][a-z0-9_-]{0,47}$/;
const bodyRefPattern = /^fixture:body\/[a-z][a-z0-9_-]{0,47}$/;
const representativeRefPattern = /^fixture:representative\/[a-z][a-z0-9_-]{0,47}$/;
const kinds: GovernanceKind[] = ['shareholder', 'faculty', 'authorization', 'decision'];
const states: GovernanceState[] = ['BORRADOR', 'REQUIERE_REVISION', 'EN_REVISION'];
const serverRefPattern = /^fixture:(shareholder|faculty|authorization|decision)\/[a-z][a-z0-9_-]{0,47}$/;

class GovernanceHttpError extends Error {
  constructor(readonly status: number) {
    super(status === 401 || status === 403 ? 'Acceso denegado a gobierno ficticio.'
      : 'No fue posible completar la operación ficticia.');
  }
}

/** Únicamente mismo origen y cookie de sesión; no persiste nada en el navegador. */
function governanceRequest(method: 'GET' | 'POST' | 'PATCH', url: string,
  body?: Readonly<Record<string, string>>): Promise<unknown> {
  return new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    request.open(method, url);
    request.withCredentials = true;
    request.setRequestHeader('Accept', 'application/json');
    if (body) {
      request.setRequestHeader('Content-Type', 'application/json');
      const token = document.getElementById('condor-admin-root')?.dataset.accessToken ?? '';
      request.setRequestHeader('X-CSRF-Token', token);
    }
    request.onerror = () => reject(new GovernanceHttpError(0));
    request.onload = () => {
      if (request.status < 200 || request.status >= 300) {
        reject(new GovernanceHttpError(request.status));
        return;
      }
      try { resolve(JSON.parse(request.responseText) as unknown); }
      catch { reject(new GovernanceHttpError(0)); }
    };
    request.send(body ? JSON.stringify(body) : null);
  });
}

function serverRecord(value: unknown, tenantId: string, companyId: string,
  branchId: string): GovernanceRow {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    throw new GovernanceHttpError(0);
  }
  const item = value as Record<string, unknown>;
  if (Object.keys(item).sort((left, right) => left.localeCompare(right)).join(',') !== 'kind,ref,state'
    || typeof item.ref !== 'string' || !serverRefPattern.test(item.ref)
    || typeof item.kind !== 'string' || !kinds.includes(item.kind as GovernanceKind)
    || !item.ref.startsWith('fixture:' + item.kind + '/')
    || (item.state !== 'REQUIERE_REVISION' && item.state !== 'EN_REVISION')) {
    throw new GovernanceHttpError(0);
  }
  return {
    reference: item.ref, kind: item.kind as GovernanceKind, state: item.state,
    tenantId, companyId, branchId, bodyRef: '', representativeRef: '',
  };
}

function governancePath(branchId: string): string {
  return '/api/v1/branches/' + encodeURIComponent(branchId) + '/governance';
}

function isFixture(value: string, pattern: RegExp): boolean {
  return pattern.test(value) && !/[0-9]{7,}/.test(value);
}

function Workspace({ tenantId, companyId, branchId, permissions }: Props) {
  const scope = tenantId + '|' + companyId + '|' + branchId;
  const canView = companyId !== '__unassigned__' && (permissions.includes('inventory.view') || permissions.includes('settings.view'));
  const canCreate = canView && (permissions.includes('inventory.create') || permissions.includes('settings.create'));
  const canReview = canView && (permissions.includes('inventory.update') || permissions.includes('settings.update'));
  const [records, setRecords] = useState<GovernanceRow[]>([]);
  const [events, setEvents] = useState<AuditEvent[]>([]);
  const [reference, setReference] = useState('');
  const [bodyRef, setBodyRef] = useState('');
  const [representativeRef, setRepresentativeRef] = useState('');
  const [kind, setKind] = useState<GovernanceKind>('shareholder');
  const [selected, setSelected] = useState('');
  const [creating, setCreating] = useState(false);
  const [search, setSearch] = useState('');
  const [kindFilter, setKindFilter] = useState<'TODOS' | GovernanceKind>('TODOS');
  const [stateFilter, setStateFilter] = useState<'TODOS' | GovernanceState>('TODOS');
  const [notice, setNotice] = useState('');
  const [panelError, setPanelError] = useState('');
  const [remoteState, setRemoteState] = useState<'loading' | 'ready' | 'error' | 'denied'>('loading');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let active = true;
    setRecords([]);
    setSelected('');
    setNotice('');
    if (!canView) { setRemoteState('denied'); return; }
    setRemoteState('loading');
    void governanceRequest('GET', governancePath(branchId))
      .then(result => {
        if (!active) return;
        const response = result as { records?: unknown };
        if (!Array.isArray(response?.records) || response.records.length > 100) {
          throw new GovernanceHttpError(0);
        }
        setRecords(response.records.map(row => serverRecord(row, tenantId, companyId, branchId)));
        setRemoteState('ready');
      })
      .catch((error: unknown) => {
        if (active) setRemoteState(error instanceof GovernanceHttpError
          && (error.status === 401 || error.status === 403) ? 'denied' : 'error');
      });
    return () => { active = false; };
  }, [tenantId, companyId, branchId, canView]);

  function audit(item: string, action: AuditEvent['action']) {
    setEvents(previous => [...previous, { scope, reference: item, action }].slice(-100));
  }

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const draft = { reference, bodyRef, representativeRef, kind, state: 'BORRADOR' as const };
    const ref = 'fixture:' + draft.kind + '/' + draft.reference.slice('fixture:governance/'.length);
    if (!canCreate || busy || !isFixture(draft.reference, governanceRefPattern)
      || !isFixture(draft.bodyRef, bodyRefPattern)
      || !isFixture(draft.representativeRef, representativeRefPattern)
      || !kinds.includes(kind) || records.length >= 100
      || records.some(row => row.reference === ref && row.tenantId === tenantId
        && row.companyId === companyId && row.branchId === branchId)) {
      setNotice('Rechazado: revisa permisos, referencias ficticias y duplicados.');
      setPanelError('Rechazado: revisa permisos, referencias ficticias y duplicados.');
      return;
    }
    setBusy(true);
    try {
      const response = await governanceRequest('POST', governancePath(branchId), { kind, ref }) as { record: unknown };
      const created = serverRecord(response.record, tenantId, companyId, branchId);
      setRecords(previous => [...previous, created]);
      audit(reference, 'created');
      setSelected(created.reference);
      setCreating(false);
      setPanelError('');
      setReference(''); setBodyRef(''); setRepresentativeRef('');
      setNotice('Registro ficticio confirmado por el servidor. Sin valor jurídico.');
    } catch (error) {
      const message = error instanceof GovernanceHttpError && [401, 403].includes(error.status)
        ? 'Acceso denegado para crear registros.' : 'Error de servidor: no se creó el registro.';
      setNotice(message);
      setPanelError(message);
    } finally { setBusy(false); }
  }

  async function view(ref: string) {
    if (!canView || busy || !serverRefPattern.test(ref) || !records.some(row =>
      row.reference === ref && row.tenantId === tenantId
      && row.companyId === companyId && row.branchId === branchId)) return;
    const [recordKind, slug] = ref.slice('fixture:'.length).split('/');
    setBusy(true);
    try {
      const response = await governanceRequest('GET', governancePath(branchId)
        + '/' + encodeURIComponent(recordKind) + '/' + encodeURIComponent(slug)) as { record: unknown };
      const validated = serverRecord(response.record, tenantId, companyId, branchId);
      setRecords(previous => previous.map(row => row.reference === ref ? validated : row));
      setSelected(ref);
      setPanelError('');
      audit(ref, 'viewed');
    } catch (error) {
      setNotice(error instanceof GovernanceHttpError && [401, 403].includes(error.status)
        ? 'Acceso denegado para consultar la actuación.' : 'No se pudo consultar la actuación.');
    } finally { setBusy(false); }
  }

  async function changeState(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const current = records.find(row => row.reference === selected
      && row.tenantId === tenantId && row.companyId === companyId && row.branchId === branchId);
    if (!canReview || busy || !current || !serverRefPattern.test(selected)
      || current.state === 'EN_REVISION') {
      setNotice('No se cambió el estado: falta permiso o la transición no aplica.');
      setPanelError('No se cambió el estado: falta permiso o la transición no aplica.');
      return;
    }
    const next: GovernanceState = current.state === 'BORRADOR' ? 'REQUIERE_REVISION' : 'EN_REVISION';
    const [recordKind, slug] = selected.slice('fixture:'.length).split('/');
    setBusy(true);
    try {
      const response = await governanceRequest('PATCH', governancePath(branchId)
        + '/' + encodeURIComponent(recordKind) + '/' + encodeURIComponent(slug) + '/state',
      { state: next }) as { record: unknown };
      const updated = serverRecord(response.record, tenantId, companyId, branchId);
      setRecords(previous => previous.map(row =>
        row.reference === selected && row.tenantId === tenantId
          && row.companyId === companyId && row.branchId === branchId ? updated : row));
      audit(selected, current.state === 'BORRADOR' ? 'review_requested' : 'review_started');
      setPanelError('');
      setNotice('Cambio de estado confirmado por Symfony; nunca es aprobación legal.');
    } catch (error) {
      const message = error instanceof GovernanceHttpError && [401, 403].includes(error.status)
        ? 'Acceso denegado para cambiar el estado.' : 'No se pudo cambiar el estado.';
      setNotice(message);
      setPanelError(message);
    } finally { setBusy(false); }
  }

  if (!canView) return (
    <section id="governance-management" aria-label="Gobierno corporativo sintético">
      <p role="alert">No tienes acceso a esta simulación o no hay razón social activa.</p>
    </section>
  );

  if (remoteState === 'loading') return <p role="status" aria-live="polite">Cargando actuaciones ficticias del servidor…</p>;
  if (remoteState === 'denied') return <p role="alert">Acceso denegado por el servidor para esta sede.</p>;
  if (remoteState === 'error') return <p role="alert">Error al consultar gobierno ficticio. Comprueba la bandera de construcción.</p>;

  const scoped = records.filter(row => row.tenantId === tenantId
    && row.companyId === companyId && row.branchId === branchId);
  const visible = scoped.filter(row =>
    (kindFilter === 'TODOS' || row.kind === kindFilter)
    && (stateFilter === 'TODOS' || row.state === stateFilter)
    && (search.trim() === '' || row.reference.includes(search.toLowerCase().trim())));
  const current = scoped.find(row => row.reference === selected);

  return (
    <section id="governance-management" className="ui-page catalog-panel" aria-label="Gobierno corporativo · simulación M15">
      <PageHeader title="Gobierno corporativo · simulación M15" description="Actuaciones ficticias, revisión explícita y permisos vigentes." actions={canCreate ? <button type="button" className="button" onClick={() => { setSelected(''); setPanelError(''); setCreating(true); }}>Crear actuación de prueba</button> : undefined} />
      <MetricStrip label="Indicadores de Gobierno corporativo · simulación M15" items={[{ label: 'Registros', value: scoped.length }, { label: 'Resultados', value: visible.length }, { label: 'Eventos de esta sesión', value: events.filter(item => item.scope === scope).length }]} />
      <p role="alert">SOLO DATOS FICTICIOS. Sin valor jurídico ni facultades reales.</p>
      <p className="muted">Servidor Symfony autenticado; sesión ficticia sin efecto jurídico ni guardado en este navegador.</p>
      {notice && <output role="status" aria-live="polite">{notice}</output>}
      <div className="ui-grid-2 catalog-grid">
        <section className="ui-card catalog-card" aria-labelledby="governance-list-title">
          <h3 id="governance-list-title">Actuaciones ficticias</h3>
          <Toolbar trailing={<span className="ui-count">{visible.length} de {scoped.length}</span>}>
          <label>Buscar referencia
            <input aria-label="Buscar actuación ficticia" value={search} maxLength={80}
              onChange={event => setSearch(event.target.value)} />
          </label>
          <label>Tipo de órgano
            <select aria-label="Filtrar por órgano" value={kindFilter}
              onChange={event => setKindFilter(event.target.value as typeof kindFilter)}>
              <option value="TODOS">Todos</option>
              {kinds.map(item => <option key={item} value={item}>{item}</option>)}
            </select>
          </label>
          <label>Estado
            <select aria-label="Filtrar por estado" value={stateFilter}
              onChange={event => setStateFilter(event.target.value as typeof stateFilter)}>
              <option value="TODOS">Todos</option>
              {states.map(item => <option key={item} value={item}>{item}</option>)}
            </select>
          </label>
          </Toolbar>
          <DataTable caption="Listado de Gobierno corporativo · simulación M15" rows={visible} rowKey={row => row.reference} onOpen={row => view(row.reference)} empty="Sin registros ficticios para estos filtros." columns={[{ key: 'ref', header: 'Referencia', render: row => <span className="ui-cell-main"><strong>{row.reference}</strong></span> }, { key: 'state', header: 'Estado', render: row => <StatusPill tone={row.state === 'EN_REVISION' ? 'info' : row.state === 'REQUIERE_REVISION' ? 'warn' : 'neutral'}>{row.state}</StatusPill> }]} />
        </section>
        {canCreate && (
          <SidePanel open={creating && canCreate} title="Crear actuación de prueba" onClose={() => { setCreating(false); setPanelError(''); }}>
          {panelError && <output role="alert" aria-live="assertive" className="alert alert-error">{panelError}</output>}
          <form className="catalog-editor" onSubmit={create}>
              <label>Referencia ficticia
                <input required maxLength={64} pattern="fixture:governance/[a-z][a-z0-9_-]{0,47}"
                  placeholder="fixture:governance/demo" value={reference}
                  onChange={event => setReference(event.target.value)} />
              </label>
              <p className="muted">Las referencias auxiliares siguientes solo validan el formulario; el servidor guarda únicamente tipo y referencia ficticia.</p>
              <label>Órgano ficticio
                <input required maxLength={64} pattern="fixture:body/[a-z][a-z0-9_-]{0,47}"
                  placeholder="fixture:body/demo" value={bodyRef}
                  onChange={event => setBodyRef(event.target.value)} />
              </label>
              <label>Representante ficticio
                <input required maxLength={64} pattern="fixture:representative/[a-z][a-z0-9_-]{0,47}"
                  placeholder="fixture:representative/demo" value={representativeRef}
                  onChange={event => setRepresentativeRef(event.target.value)} />
              </label>
              <label>Clase
                <select value={kind} onChange={event => setKind(event.target.value as GovernanceKind)}>
                  {kinds.map(item => <option key={item} value={item}>{item}</option>)}
                </select>
              </label>
              <button type="submit" disabled={busy}>Registrar solicitud ficticia</button>
            </form>

        </SidePanel>
        )}
        <SidePanel open={Boolean(current) && !creating} title="Detalle y revisión manual" onClose={() => { setSelected(''); setPanelError(''); }}>
          {panelError && <output role="alert" aria-live="assertive" className="alert alert-error">{panelError}</output>}
          {current ? <>
            <dl>
              <dt>Referencia</dt><dd>{current.reference}</dd>
              <dt>Origen</dt><dd>Sesión sintética del servidor (sin nombres ni representantes)</dd>
              <dt>Clase</dt><dd>{current.kind}</dd>
              <dt>Estado</dt><dd>{current.state}</dd>
            </dl>
            {canReview && current.state !== 'EN_REVISION' && (
              <form className="catalog-editor" onSubmit={changeState}>
                <button type="submit" disabled={busy}>{current.state === 'BORRADOR'
                  ? 'Solicitar revisión ficticia' : 'Iniciar revisión ficticia'}</button>
              </form>
            )}
            <p className="muted">Ningún acta, poder o decisión se aprueba ni cierra automáticamente.</p>
          </> : <p role="status">Selecciona una actuación ficticia para el detalle.</p>}

        </SidePanel>
        <section className="ui-card catalog-card" aria-labelledby="governance-audit-title">
          <h3 id="governance-audit-title">Bitácora de demostración (no oficial)</h3>
          <p className="muted">Las operaciones reales exigen permisos y auditoría de servidor.</p>
          {events.filter(row => row.scope === scope).length === 0
            ? <p role="status">Sin operaciones de prueba.</p>
            : <ul>{events.filter(row => row.scope === scope).map((row, index) => (
              <li key={index}>{row.action} · {row.reference}</li>
            ))}</ul>}
        </section>
      </div>
    </section>
  );
}

/** Nunca habilitar este simulador para clientes reales. */
export function GovernanceManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <Workspace key={props.tenantId + '|' + props.companyId + '|' + props.branchId}
    {...props} />;
}
