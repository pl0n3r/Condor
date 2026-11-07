/// <reference types="vite/client" />
import { useEffect, useState, type FormEvent } from 'react';
import { PageHeader, MetricStrip, Toolbar, DataTable, SidePanel, StatusPill } from './ui/Kit';

/** M13 Calidad: prototipo efímero; solo referencias sintéticas, sin red ni datos reales. */
type Status = 'PENDIENTE' | 'EN_REVISION' | 'CIERRE_REQUIERE_REVISION';
type RecordItem = Readonly<{
  tenantId: string; companyId: string; branchId: string;
  ref: string; findingRef: string; actorRef: string; status: Status;
}>;
type Audit = Readonly<{
  scope: string; ref: string; action: 'created' | 'viewed' | 'state_changed';
  actorRef?: string;
}>;
type Props = Readonly<{
  tenantId: string; companyId: string; branchId: string; permissions: string[];
}>;

const recordPattern = /^fixture:quality\/[a-z][a-z0-9_-]{0,47}$/;
const findingPattern = /^fixture:finding\/[a-z][a-z0-9_-]{0,47}$/;
const actorPattern = /^fixture:actor\/[a-z][a-z0-9_-]{0,47}$/;
const states: Status[] = ['PENDIENTE', 'EN_REVISION', 'CIERRE_REQUIERE_REVISION'];

function Workspace({ tenantId, companyId, branchId, permissions }: Props) {
  const scope = tenantId + '|' + companyId + '|' + branchId;
  const canView = companyId !== '__unassigned__' && permissions.includes('inventory.view');
  const canCreate = canView && permissions.includes('inventory.create');
  const canUpdate = canView && permissions.includes('inventory.update');
  const [records, setRecords] = useState<RecordItem[]>([]);
  const [history, setHistory] = useState<Audit[]>([]);
  const [ref, setRef] = useState('');
  const [findingRef, setFindingRef] = useState('');
  const [actorRef, setActorRef] = useState('');
  const [reviewActorRef, setReviewActorRef] = useState('');
  const [selected, setSelected] = useState('');
  const [creating, setCreating] = useState(false);
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState<'TODOS' | Status>('TODOS');
  const [notice, setNotice] = useState('');
  const [panelError, setPanelError] = useState('');

  const scoped = records.filter(row => row.tenantId === tenantId
    && row.companyId === companyId && row.branchId === branchId);
  const detail = scoped.find(row => row.ref === selected);
  const nextStatus: Status | null = detail?.status === 'PENDIENTE' ? 'EN_REVISION'
    : detail?.status === 'EN_REVISION' ? 'CIERRE_REQUIERE_REVISION' : null;
  const visible = scoped.filter(row => (filter === 'TODOS' || row.status === filter)
    && row.ref.includes(search.trim().toLowerCase()));

  function audit(item: string, action: Audit['action'], actor: string | null = null) {
    setHistory(previous => [...previous, {
      scope, ref: item, action, actorRef: actor ?? undefined,
    }].slice(-100));
  }

  function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || !recordPattern.test(ref)
      || !findingPattern.test(findingRef) || !actorPattern.test(actorRef)
      || scoped.length >= 100 || scoped.some(row => row.ref === ref)) {
      setNotice('No se guardó: permiso insuficiente, duplicado o referencia ficticia inválida.');
      setPanelError('No se guardó: permiso insuficiente, duplicado o referencia ficticia inválida.');
      return;
    }
    setRecords(previous => [...previous, {
      tenantId, companyId, branchId, ref, findingRef, actorRef, status: 'PENDIENTE',
    }]);
    audit(ref, 'created', actorRef);
    setSelected(ref);
    setCreating(false);
    setPanelError('');
    setRef(''); setFindingRef(''); setActorRef('');
    setNotice('Registro ficticio creado únicamente en memoria.');
  }

  function view(item: string) {
    if (!canView || !recordPattern.test(item) || !scoped.some(row => row.ref === item)) return;
    setSelected(item);
    setPanelError('');
    audit(item, 'viewed');
  }

  function changeState(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canUpdate || detail === undefined || nextStatus === null
      || !actorPattern.test(reviewActorRef)
      || !scoped.some(row => row.ref === detail.ref)) {
      setNotice('Transición rechazada: permiso, actor o estado inválido.');
      setPanelError('Transición rechazada: permiso, actor o estado inválido.');
      return;
    }
    const item = detail.ref;
    setRecords(previous => previous.map(row => row.tenantId === tenantId
      && row.companyId === companyId && row.branchId === branchId
      && row.ref === item ? { ...row, status: nextStatus } : row));
    audit(item, 'state_changed', reviewActorRef);
    setReviewActorRef('');
    setPanelError('');
    setNotice('Transición ficticia explícita registrada; sin aprobación o cierre automático.');
  }

  if (!canView) return (
    <section id="quality-management" aria-label="Calidad sintética">
      <p role="alert">No tienes permiso para Calidad o una empresa activa.</p>
    </section>
  );

  return (
    <section id="quality-management" className="ui-page catalog-panel" aria-label="Calidad de la empresa · pruebas">
      <PageHeader title="Calidad de la empresa · pruebas" description="No conformidades de ensayo sin aprobación o cierre automático." actions={canCreate ? <button type="button" className="button" onClick={() => { setSelected(''); setPanelError(''); setCreating(true); }}>Nuevo registro ficticio</button> : undefined} />
      <MetricStrip label="Indicadores de Calidad de la empresa · pruebas" items={[{ label: 'Registros', value: scoped.length }, { label: 'Resultados', value: visible.length }, { label: 'Eventos de esta sesión', value: history.filter(item => item.scope === scope).length }]} />
      <p role="alert">SOLO DATOS FICTICIOS. Sin valor legal ni auditoría de servidor.</p>
      <p className="muted">Sesión local, efímera: no se envía ni almacena nada en un servidor.</p>
      {notice && <output role="status" aria-live="polite">{notice}</output>}
      <div className="ui-grid-2 catalog-grid">
        <section className="ui-card catalog-card" aria-labelledby="quality-list-title">
          <h3 id="quality-list-title">No conformidades de demostración</h3>
          <Toolbar trailing={<span className="ui-count">{visible.length} de {scoped.length}</span>}>
          <label>Buscar referencia ficticia
            <input value={search} onChange={event => setSearch(event.target.value)} />
          </label>
          <label>Filtrar por estado
            <select value={filter}
              onChange={event => setFilter(event.target.value as typeof filter)}>
              <option value="TODOS">Todos</option>
              {states.map(item => <option key={item} value={item}>{item}</option>)}
            </select>
          </label>
          </Toolbar>
          <DataTable caption="Listado de Calidad de la empresa · pruebas" rows={visible} rowKey={row => row.ref} onOpen={row => view(row.ref)} empty="Sin registros ficticios para estos filtros." columns={[{ key: 'ref', header: 'Referencia', render: row => <span className="ui-cell-main"><strong>{row.ref}</strong></span> }, { key: 'state', header: 'Estado', render: row => <StatusPill tone={row.status === 'EN_REVISION' ? 'info' : row.status === 'CIERRE_REQUIERE_REVISION' ? 'warn' : 'neutral'}>{row.status}</StatusPill> }]} />
        </section>
        {canCreate && (
          <SidePanel open={creating && canCreate} title="Nuevo registro ficticio" onClose={() => { setCreating(false); setPanelError(''); }}>
            {panelError && <output role="alert" aria-live="assertive" className="alert alert-error">{panelError}</output>}
          <form className="catalog-editor" onSubmit={create}>
              <label>Referencia ficticia
                <input required maxLength={70} pattern="fixture:quality/[a-z][a-z0-9_-]{0,47}"
                  placeholder="fixture:quality/demo" value={ref}
                  onChange={event => setRef(event.target.value)} />
              </label>
              <label>Hallazgo ficticio
                <input required maxLength={70} pattern="fixture:finding/[a-z][a-z0-9_-]{0,47}"
                  placeholder="fixture:finding/demo" value={findingRef}
                  onChange={event => setFindingRef(event.target.value)} />
              </label>
              <label>Actor ficticio, sin identificación personal
                <input required maxLength={70} pattern="fixture:actor/[a-z][a-z0-9_-]{0,47}"
                  placeholder="fixture:actor/demo" value={actorRef}
                  onChange={event => setActorRef(event.target.value)} />
              </label>
              <button type="submit">Crear registro ficticio</button>
            </form>

        </SidePanel>
        )}
        <SidePanel open={Boolean(detail) && !creating} title="Detalle y revisión explícita" onClose={() => { setPanelError(''); setSelected(''); }}>
            {panelError && <output role="alert" aria-live="assertive" className="alert alert-error">{panelError}</output>}
          {detail ? <>
            <dl>
              <dt>Referencia</dt><dd>{detail.ref}</dd>
              <dt>Hallazgo</dt><dd>{detail.findingRef}</dd>
              <dt>Actor ficticio</dt><dd>{detail.actorRef}</dd>
              <dt>Estado</dt><dd>{detail.status}</dd>
            </dl>
            {canUpdate && nextStatus !== null && (
              <form className="catalog-editor" onSubmit={changeState}>
                <label>Actor de revisión ficticio
                  <input required maxLength={70} pattern="fixture:actor/[a-z][a-z0-9_-]{0,47}"
                    placeholder="fixture:actor/revisor" value={reviewActorRef}
                    onChange={event => setReviewActorRef(event.target.value)} />
                </label>
                <p>Próximo estado: <strong>{nextStatus}</strong></p>
                <button type="submit">Registrar transición explícita</button>
              </form>
            )}
            <p className="muted">No hay transición automática a APROBADO ni CERRADO.</p>
          </> : <p role="status">Selecciona un registro para ver su detalle.</p>}

        </SidePanel>
        <section className="ui-card catalog-card" aria-labelledby="quality-audit-title">
          <h3 id="quality-audit-title">Bitácora ficticia de esta sesión</h3>
          <p className="muted">La bitácora local no sustituye la auditoría del servidor.</p>
          {history.filter(item => item.scope === scope).length === 0
            ? <p role="status">Sin operaciones de prueba.</p>
            : <ol>{history.filter(item => item.scope === scope).map((item, index) => (
              <li key={index}>{item.ref} · {item.action}
                {item.actorRef ? ' · ' + item.actorRef : ''}</li>
            ))}</ol>}
        </section>
      </div>
    </section>
  );
}


// M13 S03 HTTP: separate authenticated workspace; the historical in-memory demo
// remains isolated below. No real customer records, external IO or auto-approval.
type ServerQuality = Readonly<{
  record_ref: string; source_ref: string; kind: 'QUEJA_INTERNA';
  state: 'PENDIENTE' | 'EN_REVISION' | 'REQUIERE_REVISION';
}>;
type RemoteLoad = { kind: 'loading' | 'denied' }
  | { kind: 'error'; message: string }
  | { kind: 'ready'; records: ServerQuality[] };
type HttpReply = Readonly<{ status: number; body: unknown }>;
const remoteRef = /^fixture:quality-record\/[a-z][a-z0-9_-]{0,31}$/;
const sourceRef = /^fixture:quality-source\/[a-z][a-z0-9_-]{0,31}$/;
const longDigits = /[0-9]{8,}/;
const syntheticReference = (value: string, pattern: RegExp): boolean =>
  pattern.test(value) && !longDigits.test(value);

function qualityHttp(
  url: string, method: 'GET' | 'POST' | 'PATCH' = 'GET',
  body?: object, csrf?: string,
): { promise: Promise<HttpReply>; abort: () => void } {
  const request = new XMLHttpRequest();
  const promise = new Promise<HttpReply>((resolve, reject) => {
    request.open(method, url, true);
    request.withCredentials = true;
    request.timeout = 15000;
    request.setRequestHeader('Accept', 'application/json');
    if (body) {
      request.setRequestHeader('Content-Type', 'application/json');
      request.setRequestHeader('X-CSRF-Token', csrf ?? '');
    }
    request.onload = () => {
      if ([401, 403, 404].includes(request.status)) {
        resolve({ status: request.status, body: null });
        return;
      }
      try {
        resolve({ status: request.status, body: JSON.parse(request.responseText) as unknown });
      } catch {
        reject(new Error('El servidor no devolvió JSON válido.'));
      }
    };
    request.onerror = () => reject(new Error('Conexión de red fallida.'));
    request.ontimeout = () => reject(new Error('Se agotó el tiempo de conexión.'));
    request.onabort = () => reject(new Error('Solicitud cancelada.'));
    request.send(body ? JSON.stringify(body) : null);
  });
  return { promise, abort: () => request.abort() };
}

function isQuality(value: unknown): value is ServerQuality {
  if (!value || typeof value !== 'object') return false;
  const row = value as Partial<ServerQuality>;
  return typeof row.record_ref === 'string'
    && typeof row.source_ref === 'string'
    && syntheticReference(row.record_ref, remoteRef)
    && syntheticReference(row.source_ref, sourceRef)
    && row.kind === 'QUEJA_INTERNA'
    && ['PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION'].includes(row.state ?? '');
}

function QualityHttpWorkspace({ tenantId, companyId, branchId, permissions }: Props) {
  const canView = permissions.includes('settings.view')
    && companyId !== '__unassigned__' && branchId !== '__unassigned__';
  const canCreate = canView && permissions.includes('settings.create');
  const canUpdate = canView && permissions.includes('settings.update');
  const endpoint = '/api/v1/branches/' + encodeURIComponent(branchId) + '/quality/records';
  const [load, setLoad] = useState<RemoteLoad>({ kind: 'loading' });
  const [revision, setRevision] = useState(0);
  const [selected, setSelected] = useState('');
  const [recordRef, setRecordRef] = useState('fixture:quality-record/demo');
  const [originRef, setOriginRef] = useState('fixture:quality-source/demo');
  const [notice, setNotice] = useState('');
  const [panelError, setPanelError] = useState('');
  const [busy, setBusy] = useState(false);
  const [httpCreating, setHttpCreating] = useState(false);

  useEffect(() => {
    if (!canView) {
      setLoad({ kind: 'denied' });
      return;
    }
    let alive = true;
    const request = qualityHttp(endpoint);
    setLoad(previous => previous.kind === 'ready' && revision > 0
      ? previous : { kind: 'loading' });
    void request.promise.then(({ status, body }) => {
      if (!alive) return;
      if ([401, 403, 404].includes(status)) {
        setLoad({ kind: 'denied' });
      } else if (status === 200 && body && typeof body === 'object'
        && 'synthetic_only' in body && body.synthetic_only === true
        && 'records' in body && Array.isArray(body.records)
        && body.records.length <= 50 && body.records.every(isQuality)) {
        setLoad({ kind: 'ready', records: body.records });
      } else {
        setLoad({ kind: 'error', message: 'Respuesta HTTP de Calidad inválida.' });
      }
    }).catch(() => {
      if (!alive) return;
      setLoad({ kind: 'error', message: 'No se pudo consultar Calidad.' });
    });
    return () => {
      alive = false;
      request.abort();
    };
  }, [tenantId, companyId, branchId, endpoint, canView, revision]);

  async function mutate(method: 'POST' | 'PATCH', url: string, payload: object) {
    if (busy || !canView || (method === 'POST' ? !canCreate : !canUpdate)) return;
    const token = document.getElementById('condor-admin-root')?.getAttribute('data-access-token');
    if (!token) {
      setNotice('Sin CSRF autenticado: operación denegada.');
      setPanelError('Sin CSRF autenticado: operación denegada.');
      return;
    }
    setBusy(true); setNotice(''); setPanelError('');
    try {
      const response = await qualityHttp(url, method, payload, token).promise;
      if ([401, 403, 404].includes(response.status)) {
        setLoad({ kind: 'denied' });
      } else if (response.status === (method === 'POST' ? 201 : 200)
        && response.body && typeof response.body === 'object'
        && 'synthetic_only' in response.body && response.body.synthetic_only === true
        && 'record' in response.body && isQuality(response.body.record)) {
        setRevision(value => value + 1);
        setHttpCreating(false);
        setNotice('Cambio ficticio confirmado en la sesión HTTP; no es aprobación.');
      } else {
        setNotice('Solicitud rechazada o respuesta no verificable.');
        setPanelError('Solicitud rechazada o respuesta no verificable.');
      }
    } catch {
      setNotice('Error de red. No se confirma ninguna operación.');
      setPanelError('Error de red. No se confirma ninguna operación.');
    } finally {
      setBusy(false);
    }
  }

  const records = load.kind === 'ready' ? load.records : [];
  const detail = records.find(row => row.record_ref === selected);
  const nextState = detail?.state === 'PENDIENTE' ? 'EN_REVISION'
    : detail?.state === 'EN_REVISION' ? 'REQUIERE_REVISION' : null;
  return <section className="ui-page catalog-panel" aria-label="Quejas internas ficticias">
     <PageHeader title="M13 S03 · quejas internas ficticias por API" description="Hallazgos controlados por sede y permiso." actions={load.kind === 'ready' && canCreate ? <button type="button" className="button" onClick={() => { setSelected(''); setPanelError(''); setHttpCreating(true); }}>Crear queja de prueba</button> : undefined} />
     <MetricStrip label="Resumen de calidad sintética" items={[{ label: 'Quejas de prueba', value: records.length }, { label: 'En revisión', value: records.filter(row => row.state === 'EN_REVISION').length }, { label: 'Sin cierre automático', value: 'Sí', tone: 'info' }]} />
    <p role="alert">SOLO DATOS FICTICIOS. Sin PII, aprobación, cierre ni valor legal.</p>
    {load.kind === 'loading' && <output role="status" aria-live="polite">Cargando quejas ficticias…</output>}
    {load.kind === 'denied' && <p role="alert">Permiso denegado o sede ajena (HTTP 401/403/404).</p>}
    {load.kind === 'error' && <div role="alert">{load.message}
      <button type="button" onClick={() => setRevision(value => value + 1)}>Reintentar</button>
    </div>}
    {load.kind === 'ready' && records.length === 0 && <p role="status">No hay quejas internas ficticias.</p>}
    {load.kind === 'ready' && <DataTable caption="Quejas ficticias por sede" rows={records} rowKey={row => row.record_ref} onOpen={row => { setPanelError(''); setSelected(row.record_ref); }} empty="No hay quejas internas ficticias." columns={[{ key: 'ref', header: 'Referencia', render: row => <strong>{row.record_ref}</strong> }, { key: 'source', header: 'Origen', render: row => row.source_ref }, { key: 'state', header: 'Estado', render: row => <StatusPill tone={row.state === 'PENDIENTE' ? 'neutral' : 'warn'}>{row.state}</StatusPill> }]} />}
    {load.kind === 'ready' && detail && <SidePanel open={!httpCreating} title={detail.record_ref} onClose={() => { setSelected(''); setPanelError(''); }}><section aria-label="Queja ficticia seleccionada">
      {panelError && <output role="alert" aria-live="assertive" className="alert alert-error">{panelError}</output>}
      <p>{detail.record_ref} · {detail.source_ref} · {detail.state}</p>
      {canUpdate && nextState && <button type="button" disabled={busy}
        onClick={() => void mutate('PATCH',
          endpoint + '/' + encodeURIComponent(detail.record_ref.slice('fixture:quality-record/'.length)) + '/state',
          { state: nextState })}>Registrar revisión explícita</button>}
    </section></SidePanel>}
     {load.kind === 'ready' && canCreate && <SidePanel open={httpCreating} title="Crear queja de prueba" onClose={() => { setHttpCreating(false); setPanelError(''); }}>
      {panelError && <output role="alert" aria-live="assertive" className="alert alert-error">{panelError}</output>}
      <form className="catalog-editor"
      onSubmit={event => {
        event.preventDefault();
        if (!syntheticReference(recordRef, remoteRef) || !syntheticReference(originRef, sourceRef)) {
          setNotice('Solo referencias fixture tipadas, sin datos reales.');
          setPanelError('Solo referencias fixture tipadas, sin datos reales.');
          return;
        }
        void mutate('POST', endpoint, {
          record_ref: recordRef, source_ref: originRef, kind: 'QUEJA_INTERNA',
        });
      }}>
      <label>Referencia de queja ficticia
        <input required pattern="fixture:quality-record/[a-z][a-z0-9_-]{0,31}"
          value={recordRef} onChange={event => setRecordRef(event.target.value)} />
      </label>
      <label>Fuente ficticia
        <input required pattern="fixture:quality-source/[a-z][a-z0-9_-]{0,31}"
          value={originRef} onChange={event => setOriginRef(event.target.value)} />
      </label>
      <button type="submit" disabled={busy}>Crear queja de prueba</button>
    </form></SidePanel>}
     {notice && <output role="status" aria-live="polite">{notice}</output>}
  </section>;
}

export function QualityManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <div key={[props.tenantId, props.companyId, props.branchId].join('|')}>
    <QualityHttpWorkspace {...props} />
    <details><summary>Simulador local histórico (sin API)</summary>
      <Workspace key={[props.tenantId, props.companyId, props.branchId].join('|')} {...props} />
    </details>
  </div>;
}
