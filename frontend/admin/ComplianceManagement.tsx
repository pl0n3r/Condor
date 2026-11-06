/// <reference types="vite/client" />
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { PageHeader, MetricStrip, Toolbar, DataTable, SidePanel, StatusPill } from './ui/Kit';

/** SG-SST M03: prototipo exclusivamente sintético en memoria, sin red ni efectos legales. */
type Estado = 'PENDIENTE' | 'EN_REVISION' | 'CIERRE_REQUIERE_REVISION';
type Registro = Readonly<{
  tenantId: string; companyId: string; branchId: string;
  ref: string; evidenceRef: string; actorRef: string; state: Estado;
}>;
type Audit = Readonly<{ scope: string; ref: string; action: 'created' | 'viewed' | 'state_changed'; actorRef?: string }>;
type Props = Readonly<{
  tenantId: string; companyId: string; branchId: string; permissions: string[];
}>;
const recordRefPattern = /^fixture:occupational\/[a-z][a-z0-9_-]{0,47}$/;
const evidenceRefPattern = /^fixture:evidence\/[a-z][a-z0-9_-]{0,47}$/;
const actorRefPattern = /^fixture:actor\/[a-z][a-z0-9_-]{0,47}$/;
const estados: Estado[] = ['PENDIENTE', 'EN_REVISION', 'CIERRE_REQUIERE_REVISION'];

function Workspace({ tenantId, companyId, branchId, permissions }: Props) {
  const canView = companyId !== '__unassigned__' && permissions.includes('inventory.view');
  const canCreate = canView && permissions.includes('inventory.create');
  const canUpdate = canView && permissions.includes('inventory.update');
  const scope = tenantId + '|' + companyId + '|' + branchId;
  const [records, setRecords] = useState<Registro[]>([]);
  const [history, setHistory] = useState<Audit[]>([]);
  const [ref, setRef] = useState('');
  const [evidenceRef, setEvidenceRef] = useState('');
  const [actorRef, setActorRef] = useState('');
  const [reviewActorRef, setReviewActorRef] = useState('');
  const [selected, setSelected] = useState('');
  const [creating, setCreating] = useState(false);
  const [search, setSearch] = useState('');
  const [stateFilter, setStateFilter] = useState<'TODOS' | Estado>('TODOS');
  const [notice, setNotice] = useState('');

  function audit(item: string, action: Audit['action'], actor: string | null = null) {
    setHistory(previous => [...previous, { scope, ref: item, action, actorRef: actor ?? undefined }].slice(-100));
  }
  function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || !recordRefPattern.test(ref)
      || !evidenceRefPattern.test(evidenceRef) || !actorRefPattern.test(actorRef)
      || records.length >= 100 || records.some(row => row.ref === ref
        && row.tenantId === tenantId && row.companyId === companyId && row.branchId === branchId)) {
      setNotice('No se guardó: permiso insuficiente, duplicado o referencia no ficticia.');
      return;
    }
    setRecords(previous => [...previous, {
      tenantId, companyId, branchId, ref, evidenceRef, actorRef, state: 'PENDIENTE',
    }]);
    audit(ref, 'created', actorRef);
    setSelected(ref);
    setCreating(false);
    setRef(''); setEvidenceRef(''); setActorRef('');
    setNotice('Registro ficticio creado en memoria. No tiene valor legal.');
  }
  function view(item: string) {
    if (!canView || !recordRefPattern.test(item)
      || !records.some(row => row.ref === item && row.tenantId === tenantId
        && row.companyId === companyId && row.branchId === branchId)) return;
    setSelected(item);
    audit(item, 'viewed');
  }
  function changeState(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const selectedRow = records.find(row => row.ref === selected && row.tenantId === tenantId
      && row.companyId === companyId && row.branchId === branchId);
    const valid = selectedRow !== undefined && (
      (selectedRow.state === 'PENDIENTE' && nextState === 'EN_REVISION')
      || (selectedRow.state === 'EN_REVISION' && nextState === 'CIERRE_REQUIERE_REVISION')
    );
    if (!canUpdate || !actorRefPattern.test(reviewActorRef) || !valid || nextState === null) {
      setNotice('Transición rechazada: permiso, actor ficticio o estado inválido.');
      return;
    }
    setRecords(previous => previous.map(row => row.ref === selected
      && row.tenantId === tenantId && row.companyId === companyId && row.branchId === branchId
      ? { ...row, state: nextState } : row));
    audit(selected, 'state_changed', reviewActorRef);
    setNotice('Transición ficticia registrada. No existe cierre automático.');
  }

  if (!canView) return (
    <section id="compliance-management" aria-label="SG-SST sintético">
      <p role="alert">No tienes permiso de SG-SST o una razón social activa.</p>
    </section>
  );
  const scoped = records.filter(row => row.tenantId === tenantId
    && row.companyId === companyId && row.branchId === branchId);
  const filtered = scoped.filter(row => (stateFilter === 'TODOS' || row.state === stateFilter)
    && row.ref.includes(search.trim().toLowerCase()));
  const detail = scoped.find(row => row.ref === selected);
  const nextState: Estado | null = detail?.state === 'PENDIENTE' ? 'EN_REVISION'
    : detail?.state === 'EN_REVISION' ? 'CIERRE_REQUIERE_REVISION' : null;

  return (
    <section id="compliance-management" className="ui-page catalog-panel" aria-label="Seguridad y salud en el trabajo · pruebas">
      <PageHeader title="Seguridad y salud en el trabajo · pruebas" description="Registros sintéticos sin certificación legal ni datos personales." actions={canCreate ? <button type="button" className="button" onClick={() => { setSelected(''); setCreating(true); }}>Nuevo registro ficticio</button> : undefined} />
      <MetricStrip label="Indicadores de Seguridad y salud en el trabajo · pruebas" items={[{ label: 'Registros', value: scoped.length }, { label: 'Resultados', value: filtered.length }, { label: 'Eventos de esta sesión', value: history.filter(item => item.scope === scope).length }]} />
      <p role="alert">SOLO DATOS FICTICIOS. Sin cumplimiento legal certificado, personas reales ni auditoría de servidor.</p>
      <p className="muted">Simulación efímera: se borra al cambiar de empresa o recargar.</p>
      {notice && <output role="status" aria-live="polite">{notice}</output>}
      <div className="ui-grid-2 catalog-grid">
        <section className="ui-card catalog-card" aria-labelledby="compliance-list-title">
          <h3 id="compliance-list-title">Registros de demostración</h3>
          <Toolbar trailing={<span className="ui-count">{filtered.length} de {scoped.length}</span>}>
          <label>Buscar referencia ficticia
            <input value={search}
              onChange={event => setSearch(event.target.value)} />
          </label>
          <label>Filtrar estado
            <select value={stateFilter}
              onChange={event => setStateFilter(event.target.value as typeof stateFilter)}>
              <option value="TODOS">Todos</option>
              {estados.map(item => <option key={item} value={item}>{item}</option>)}
            </select>
          </label>
          </Toolbar>
          <DataTable caption="Listado de Seguridad y salud en el trabajo · pruebas" rows={filtered} rowKey={row => row.ref} onOpen={row => view(row.ref)} empty="Sin registros ficticios para estos filtros." columns={[{ key: 'ref', header: 'Referencia', render: row => <span className="ui-cell-main"><strong>{row.ref}</strong></span> }, { key: 'state', header: 'Estado', render: row => <StatusPill tone={row.state === 'EN_REVISION' ? 'info' : row.state === 'REQUIERE_REVISION' || row.state === 'CIERRE_REQUIERE_REVISION' ? 'warn' : 'neutral'}>{row.state}</StatusPill> }]} />
        </section>
        {canCreate && (
          <SidePanel open={creating && canCreate} title="Nuevo registro ficticio" onClose={() => setCreating(false)}>
          <form className="catalog-editor" onSubmit={create}>
              <label>Referencia ficticia
                <input required maxLength={70} pattern="fixture:occupational/[a-z][a-z0-9_\-]{0,47}"
                  value={ref} placeholder="fixture:occupational/demo"
                  onChange={event => setRef(event.target.value)} />
              </label>
              <label>Evidencia ficticia
                <input required maxLength={70} pattern="fixture:evidence/[a-z][a-z0-9_\-]{0,47}"
                  value={evidenceRef} placeholder="fixture:evidence/demo"
                  onChange={event => setEvidenceRef(event.target.value)} />
              </label>
              <label>Actor ficticio, sin identificación
                <input required maxLength={70} pattern="fixture:actor/[a-z][a-z0-9_\-]{0,47}"
                  value={actorRef} placeholder="fixture:actor/demo"
                  onChange={event => setActorRef(event.target.value)} />
              </label>
              <button type="submit">Crear registro de prueba</button>
            </form>
          
        </SidePanel>
        )}
        <SidePanel open={Boolean(detail) && !creating} title="Detalle y revisión" onClose={() => setSelected('')}>
          {detail ? <>
            <dl>
              <dt>Referencia</dt><dd>{detail.ref}</dd>
              <dt>Evidencia</dt><dd>{detail.evidenceRef}</dd>
              <dt>Actor ficticio</dt><dd>{detail.actorRef}</dd>
              <dt>Estado</dt><dd>{detail.state}</dd>
            </dl>
            {canUpdate && detail.state !== 'CIERRE_REQUIERE_REVISION' && (
              <form className="catalog-editor" onSubmit={changeState}>
                <label>Actor de revisión ficticio
                  <input required maxLength={70} pattern="fixture:actor/[a-z][a-z0-9_\-]{0,47}"
                    value={reviewActorRef} placeholder="fixture:actor/revisor"
                    onChange={event => setReviewActorRef(event.target.value)} />
                </label>
                <label>Acción explícita de prueba
                  <select key={detail.state} defaultValue={nextState ?? ''}>
                    <option value={nextState ?? ''}>{nextState === 'EN_REVISION' ? 'Iniciar revisión' : 'Solicitar revisión de cierre'}</option>
                  </select>
                </label>
                <button type="submit">Registrar transición ficticia explícita</button>
              </form>
            )}
            <p className="muted">Ningún estado se aprueba ni se cierra automáticamente.</p>
          </> : <p role="status">Selecciona un registro para ver su detalle.</p>}
        
        </SidePanel>
        <section className="ui-card catalog-card" aria-labelledby="compliance-audit-title">
          <h3 id="compliance-audit-title">Bitácora de demostración (no oficial)</h3>
          <p className="muted">La bitácora local no sustituye la auditoría de servidor.</p>
          {history.length === 0 ? <p role="status">Sin operaciones de prueba.</p> : (
            <ol>{history.map((item, index) => <li key={index}>{item.ref} · {item.action}{item.actorRef ? ' · ' + item.actorRef : ''}</li>)}</ol>
          )}
        </section>
      </div>
    </section>
  );
}


/** M03 HTTP: server-authorized synthetic records; never an SG-SST compliance attestation. */
const m03Kinds = [
  'PERFIL_ESTANDARES', 'PELIGRO', 'CONTROL', 'INSPECCION', 'INCIDENTE',
  'INVESTIGACION', 'EPP', 'CAPACITACION', 'EMERGENCIA', 'COMITE_VIGIA',
] as const;
type M03Kind = typeof m03Kinds[number];
type M03Record = Readonly<{
  record_ref: string; source_ref: string; kind: M03Kind;
  state: 'PENDIENTE' | 'EN_REVISION' | 'REQUIERE_REVISION';
  synthetic_only: true; requires_professional_review: true;
  automatically_approved: false; automatically_closed: false;
}>;
type M03Load = { status: 'loading' | 'error' | 'denied' }
  | { status: 'ready'; rows: M03Record[] };
const m03Ref = /^fixture:sgsst-record\/[a-z][a-z0-9_-]{0,47}$/;
const m03Source = /^fixture:sgsst-source\/[a-z][a-z0-9_-]{0,47}$/;

function m03ValidRecord(input: unknown): input is M03Record {
  if (!input || typeof input !== 'object') return false;
  const row = input as Record<string, unknown>;
  return typeof row.record_ref === 'string' && m03Ref.test(row.record_ref)
    && typeof row.source_ref === 'string' && m03Source.test(row.source_ref)
    && !/[0-9]{7,}/.test(row.record_ref + row.source_ref)
    && m03Kinds.includes(row.kind as M03Kind)
    && ['PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION'].includes(String(row.state))
    && row.synthetic_only === true && row.requires_professional_review === true
    && row.automatically_approved === false && row.automatically_closed === false;
}

function OccupationalHttpPanel({ branchId, permissions }: Pick<Props, 'branchId' | 'permissions'>) {
  const canView = permissions.includes('settings.view');
  const canCreate = canView && permissions.includes('settings.create');
  const canUpdate = canView && permissions.includes('settings.update');
  const path = '/api/v1/branches/' + encodeURIComponent(branchId) + '/sgsst/records';
  const currentRequest = useRef(0);
  const [load, setLoad] = useState<M03Load>({ status: 'loading' });
  const [kind, setKind] = useState<M03Kind>('PERFIL_ESTANDARES');
  const [recordRef, setRecordRef] = useState('fixture:sgsst-record/demo');
  const [sourceRef, setSourceRef] = useState('fixture:sgsst-source/demo');
  const [selected, setSelected] = useState<M03Record | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');

  async function request(method: 'GET' | 'POST' | 'PATCH', url: string,
    fields?: Record<string, string>): Promise<unknown> {
    const csrf = document.getElementById('condor-admin-root')?.dataset.accessToken ?? '';
    const response = await fetch (url, {
      method, credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...(fields ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf } : {}),
      },
      ...(fields ? { body: JSON.stringify(fields) } : {}),
    });
    if (response.status === 401 || response.status === 403) throw new Error('DENIED');
    if (!response.ok) throw new Error('UNAVAILABLE');
    return await response.json() as unknown;
  }

  async function reload(): Promise<void> {
    const id = ++currentRequest.current;
    setLoad({ status: 'loading' });
    try {
      const payload = await request('GET', path);
      if (id !== currentRequest.current) return;
      if (!payload || typeof payload !== 'object' || !('records' in payload)
        || !('synthetic_only' in payload) || payload.synthetic_only !== true
        || !Array.isArray(payload.records) || payload.records.length > 100
        || !payload.records.every(m03ValidRecord)) throw new Error('UNAVAILABLE');
      setLoad({ status: 'ready', rows: payload.records });
    } catch (error) {
      if (id !== currentRequest.current) return;
      setLoad({ status: error instanceof Error && error.message === 'DENIED'
        ? 'denied' : 'error' });
    }
  }

  useEffect(() => {
    setSelected(null);
    setNotice('');
    if (canView) void reload();
    return () => { currentRequest.current += 1; };
  }, [branchId, canView]);

  async function create(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    if (!canCreate || busy) return;
    if (!m03Ref.test(recordRef) || !m03Source.test(sourceRef)
      || /[0-9]{7,}/.test(recordRef + sourceRef)) {
      setNotice('Solo se admiten referencias ficticias válidas.');
      return;
    }
    setBusy(true);
    setNotice('');
    try {
      const result = await request('POST', path, {
        record_ref: recordRef, source_ref: sourceRef, kind,
      });
      if (!result || typeof result !== 'object' || !('record' in result)
        || !m03ValidRecord(result.record) || result.record.record_ref !== recordRef) {
        throw new Error('UNAVAILABLE');
      }
      setSelected(result.record);
      setNotice('Registro ficticio creado en el servidor, sin valor legal.');
      await reload();
    } catch (error) {
      setNotice('No se pudo crear el registro ficticio.');
      if (error instanceof Error && error.message === 'DENIED') setLoad({ status: 'denied' });
    } finally { setBusy(false); }
  }

  async function changeState(next: 'EN_REVISION' | 'REQUIERE_REVISION'): Promise<void> {
    if (!canUpdate || busy || !selected || !m03ValidRecord(selected)) return;
    setBusy(true);
    setNotice('');
    try {
      const slug = selected.record_ref.slice('fixture:sgsst-record/'.length);
      const result = await request('PATCH', path + '/' + encodeURIComponent(slug) + '/state',
        { state: next });
      if (!result || typeof result !== 'object' || !('record' in result)
        || !m03ValidRecord(result.record) || result.record.record_ref !== selected.record_ref
        || result.record.state !== next) throw new Error('UNAVAILABLE');
      setSelected(result.record);
      setNotice('Revisión ficticia registrada; no existe aprobación ni cierre automático.');
      await reload();
    } catch (error) {
      setNotice('No se pudo cambiar el estado ficticio.');
      if (error instanceof Error && error.message === 'DENIED') setLoad({ status: 'denied' });
    } finally { setBusy(false); }
  }

  if (!import.meta.env.DEV) return null;
  return (
    <section id="sgsst-http-management" className="catalog-panel" aria-label="SG-SST HTTP sintético">
      <h2>SG-SST · integración de prueba M03</h2>
      <p role="alert">SOLO DATOS FICTICIOS. No certifica cumplimiento legal ni permite datos laborales reales.</p>
      {!canView && <p role="alert">Permiso denegado: se requiere settings.view.</p>}
      {canView && load.status === 'loading' && <p role="status">Cargando registros SG-SST ficticios…</p>}
      {canView && load.status === 'denied' && <p role="alert">Acceso denegado por el servidor.</p>}
      {canView && load.status === 'error' && <p role="alert">Error: API SG-SST de prueba no disponible.</p>}
      {notice && <output role="status" aria-live="polite">{notice}</output>}
      {canView && load.status === 'ready' && <>
        <h3>Diez submódulos ficticios</h3>
        <p className="muted">{m03Kinds.join(' · ')}</p>
        {load.rows.length === 0
          ? <p role="status">Sin registros SG-SST ficticios.</p>
          : <ul>{load.rows.map(row => <li key={row.record_ref}>
            <button type="button" onClick={() => setSelected(row)}>
              {row.kind} · {row.record_ref} · {row.state}
            </button>
          </li>)}</ul>}
        {canCreate && <form onSubmit={(event) => { void create(event); }}>
          <label>Submódulo
            <select value={kind} onChange={event => setKind(event.target.value as M03Kind)}>
              {m03Kinds.map(value => <option key={value} value={value}>{value}</option>)}
            </select>
          </label>
          <label>Referencia del registro ficticio
            <input required value={recordRef} maxLength={70}
              onChange={event => setRecordRef(event.target.value)} />
          </label>
          <label>Referencia de fuente ficticia
            <input required value={sourceRef} maxLength={70}
              onChange={event => setSourceRef(event.target.value)} />
          </label>
          <button type="submit" disabled={busy}>Crear registro ficticio</button>
        </form>}
        {selected && <div role="group" aria-label="Revisión ficticia">
          <p>{selected.record_ref}: {selected.state}. Revisión profesional requerida.</p>
          {canUpdate && <>
            <button type="button" disabled={busy}
              onClick={() => { void changeState('EN_REVISION'); }}>En revisión</button>
            <button type="button" disabled={busy}
              onClick={() => { void changeState('REQUIERE_REVISION'); }}>Requiere revisión</button>
          </>}
        </div>}
      </>}
    </section>
  );
}

export function ComplianceManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <>
    <OccupationalHttpPanel key={'http-' + props.tenantId + '|' + props.branchId}
      branchId={props.branchId} permissions={props.permissions} />
    <details open><summary>Simulación local anterior (sin conexión HTTP)</summary>
      <Workspace key={props.tenantId + '|' + props.companyId + '|' + props.branchId} {...props} />
    </details>
  </>;
}

/** M06: independent synthetic compliance-evidence facade, distinct from SG-SST. */
export function ComplianceEvidenceManagement({ branchId, permissions, csrfToken }: Readonly<{
  branchId: string; permissions: string[]; csrfToken : string;
}>) {
type EvidenceState = 'PENDIENTE' | 'EN_REVISION' | 'REQUIERE_REVISION';
type EvidenceType = 'EVIDENCIA' | 'EVALUACION';
type Evidence = Readonly<{
  evidence_ref: string;
  obligation_ref: string;
  type: EvidenceType;
  state: EvidenceState;
}>;
type LoadState =
  | { status: 'loading' }
  | { status: 'ready'; rows: Evidence[] }
  | { status: 'denied' }
  | { status: 'error' };

const evidencePattern = /^fixture:evidence\/[a-z][a-z0-9_-]{0,47}$/;
const obligationPattern = /^fixture:obligation\/[a-z][a-z0-9_-]{0,47}$/;
const reviewStates: EvidenceState[] = ['PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION'];
const reviewTypes: EvidenceType[] = ['EVIDENCIA', 'EVALUACION'];
const displayStates: Record<EvidenceState, string> = {
  PENDIENTE: 'Pendiente',
  EN_REVISION: 'En revisión',
  REQUIERE_REVISION: 'Requiere revisión',
};

function validFixture(value: string, pattern: RegExp): boolean {
  return pattern.test(value) && !/[0-9]{7,}/.test(value);
}

function validEvidence(value: unknown): value is Evidence {
  if (value === null || typeof value !== 'object') return false;
  const row = value as Record<string, unknown>;
  return typeof row.evidence_ref === 'string'
    && validFixture(row.evidence_ref, evidencePattern)
    && typeof row.obligation_ref === 'string'
    && validFixture(row.obligation_ref, obligationPattern)
    && reviewTypes.includes(row.type as EvidenceType)
    && reviewStates.includes(row.state as EvidenceState);
}

function pathFor(branchId: string): string {
  // La identidad de empresa no se pide al formulario; el servidor resuelve el tenant.
  return '/api/v1/branches/' + encodeURIComponent(branchId) + '/compliance/evidence';
}

async function requestJson(url: string, method = 'GET',
  payload?: Record<string, string>, csrfToken?: string): Promise<unknown> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (payload) {
    headers['Content-Type'] = 'application/json';
    headers['X-CSRF-Token'] = csrfToken ?? '';
  }
  const response = await fetch (url, {
    method,
    credentials: 'same-origin',
    headers,
    ...(payload ? { body: JSON.stringify(payload) } : {}),
  });
  if (response.status === 401 || response.status === 403) throw new Error('DENIED');
  if (!response.ok) throw new Error('UNAVAILABLE');
  return await response.json() as unknown;
}


  const canView = permissions.includes('settings.view');
  const canCreate = canView && permissions.includes('settings.create');
  const canUpdate = canView && permissions.includes('settings.update');
  const basePath = pathFor(branchId);
  const requestId = useRef(0);
  const detailId = useRef(0);
  const [loadState, setLoadState] = useState<LoadState>({ status: 'loading' });
  const [selected, setSelected] = useState<Evidence | null>(null);
  const [filter, setFilter] = useState<'TODOS' | EvidenceState>('TODOS');
  const [search, setSearch] = useState('');
  const [reference, setReference] = useState('');
  const [obligation, setObligation] = useState('');
  const [type, setType] = useState<EvidenceType>('EVIDENCIA');
  const [nextState, setNextState] = useState<'EN_REVISION' | 'REQUIERE_REVISION'>('EN_REVISION');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ error: boolean; message: string } | null>(null);

  async function reload(): Promise<void> {
    const current = ++requestId.current;
    try {
      const payload = await requestJson(basePath);
      if (current !== requestId.current) return;
      if (!payload || typeof payload !== 'object' || !('evidence' in payload)) {
        throw new Error('UNAVAILABLE');
      }
      const rows = payload.evidence;
      if (!Array.isArray(rows) || rows.length > 100 || !rows.every(validEvidence)) {
        throw new Error('UNAVAILABLE');
      }
      setLoadState({ status: 'ready', rows });
    } catch (error) {
      if (current !== requestId.current) return;
      setLoadState(error instanceof Error && error.message === 'DENIED'
        ? { status: 'denied' } : { status: 'error' });
    }
  }

  useEffect(() => {
    setSelected(null);
    setNotice(null);
    if (canView) void reload();
    return () => { requestId.current += 1; detailId.current += 1; };
  }, [branchId, canView]);

  async function view(ref: string): Promise<void> {
    if (!canView || !validFixture(ref, evidencePattern)) return;
    const current = ++detailId.current;
    setNotice(null);
    try {
      const slug = ref.slice('fixture:evidence/'.length);
      const payload = await requestJson(basePath + '/' + encodeURIComponent(slug));
      if (current !== detailId.current) return;
      if (!payload || typeof payload !== 'object' || !('evidence' in payload)
        || !validEvidence(payload.evidence) || payload.evidence.evidence_ref !== ref) {
        throw new Error('UNAVAILABLE');
      }
      setSelected(payload.evidence);
    } catch {
      if (current === detailId.current) {
        setSelected(null);
        setNotice({ error: true, message: 'No fue posible consultar el detalle ficticio.' });
      }
    }
  }

  async function create(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    if (!canCreate || busy) return;
    if (!validFixture(reference, evidencePattern)
      || !validFixture(obligation, obligationPattern)
      || !reviewTypes.includes(type)) {
      setNotice({ error: true, message: 'Utiliza únicamente referencias ficticias válidas.' });
      return;
    }
    setBusy(true);
    setNotice(null);
    try {
      const result = await requestJson(basePath, 'POST', {
        evidence_ref: reference, obligation_ref: obligation, type,
      }, csrfToken);
      if (!result || typeof result !== 'object' || !('evidence' in result)
        || !validEvidence(result.evidence)) throw new Error('UNAVAILABLE');
      setReference('');
      setObligation('');
      setSelected(result.evidence);
      setNotice({ error: false, message: 'Evidencia ficticia creada y auditada en servidor.' });
      await reload();
    } catch {
      setNotice({ error: true, message: 'No se pudo registrar el dato de prueba. Verifica los permisos y la referencia.' });
    } finally {
      setBusy(false);
    }
  }

  async function changeState(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    if (!canUpdate || busy || selected === null || !validFixture(selected.evidence_ref, evidencePattern)
      || !['EN_REVISION', 'REQUIERE_REVISION'].includes(nextState)) return;
    setBusy(true);
    setNotice(null);
    try {
      const slug = selected.evidence_ref.slice('fixture:evidence/'.length);
      const result = await requestJson(basePath + '/' + encodeURIComponent(slug) + '/state',
        'PATCH', { state: nextState }, csrfToken);
      if (!result || typeof result !== 'object' || !('evidence' in result)
        || !validEvidence(result.evidence)
        || result.evidence.evidence_ref !== selected.evidence_ref
        || result.evidence.state !== nextState) throw new Error('UNAVAILABLE');
      setSelected(result.evidence);
      setNotice({ error: false, message: 'Cambio ficticio explícito auditado en servidor.' });
      await reload();
    } catch {
      setNotice({ error: true, message: 'No fue posible actualizar el estado de prueba.' });
    } finally {
      setBusy(false);
    }
  }

  if (!import.meta.env.DEV) return null;
  if (!canView) return (
    <section id="compliance-evidence-management" aria-label="Cumplimiento (pruebas)">
      <p role="alert">No tienes permiso para consultar cumplimiento ficticio.</p>
    </section>
  );

  const rows = loadState.status === 'ready' ? loadState.rows : [];
  const filtered = rows.filter((item) =>
    (filter === 'TODOS' || item.state === filter)
    && (search === '' || item.evidence_ref.includes(search.trim().toLowerCase()))
  );

  return (
    <section id="compliance-evidence-management" className="catalog-panel"
      aria-labelledby="compliance-management-title">
      <div className="section-heading"><div>
        <span className="eyebrow">M06 · Solo desarrollo</span>
        <h2 id="compliance-management-title">Cumplimiento legal (pruebas)</h2>
        <p className="muted">
          Registros exclusivamente sintéticos. No certifica cumplimiento legal,
          no contiene datos reales y nunca aprueba o cierra automáticamente.
        </p>
      </div></div>
      {notice && <output role={notice.error ? 'alert' : 'status'}
        aria-live="polite" className={notice.error ? 'alert alert-error' : 'alert alert-success'}>
        {notice.message}
      </output>}
      {loadState.status === 'loading' && <p role="status">Cargando evidencias ficticias…</p>}
      {loadState.status === 'denied' && <p role="alert">Acceso denegado por el servidor.</p>}
      {loadState.status === 'error' && <p role="alert">
        La API de demostración no está disponible. No se ha activado el módulo real.
      </p>}
      {loadState.status === 'ready' && (
        <div className="production-layout">
          <section className="ui-card catalog-card" aria-labelledby="compliance-list-title">
            <h3 id="compliance-list-title">Evidencias sintéticas</h3>
            <label className="field"><span>Filtrar por estado</span>
              <select value={filter} onChange={(event) =>
                setFilter(event.target.value as 'TODOS' | EvidenceState)}>
                <option value="TODOS">Todos los estados</option>
                {reviewStates.map((state) =>
                  <option key={state} value={state}>{displayStates[state]}</option>)}
              </select>
            </label>
            <label className="field"><span>Buscar referencia ficticia</span>
              <input value={search} maxLength={80} placeholder="fixture:evidence/demo"
                onChange={(event) => setSearch(event.target.value)} />
            </label>
            {filtered.length === 0 ? <p role="status">Sin evidencias ficticias para este filtro.</p>
              : <ul>{filtered.map((item) => <li key={item.evidence_ref}>
                <button type="button" onClick={() => { void view(item.evidence_ref); }}>
                  {item.evidence_ref}
                </button>
                {' · '}{displayStates[item.state]}
              </li>)}</ul>}
          </section>
          {canCreate && <section className="ui-card catalog-card" aria-labelledby="compliance-create-title">
            <h3 id="compliance-create-title">Crear evidencia de prueba</h3>
            <form className="catalog-editor" onSubmit={(event) => { void create(event); }}>
              <label className="field"><span>Referencia ficticia de evidencia</span>
                <input required maxLength={80} value={reference}
                  placeholder="fixture:evidence/demo"
                  onChange={(event) => setReference(event.target.value)} />
              </label>
              <label className="field"><span>Obligación ficticia</span>
                <input required maxLength={80} value={obligation}
                  placeholder="fixture:obligation/demo"
                  onChange={(event) => setObligation(event.target.value)} />
              </label>
              <label className="field"><span>Tipo</span>
                <select value={type} onChange={(event) => setType(event.target.value as EvidenceType)}>
                  {reviewTypes.map((kind) => <option key={kind} value={kind}>{kind}</option>)}
                </select>
              </label>
              <button type="submit" className="button" disabled={busy}>
                Registrar evidencia ficticia
              </button>
            </form>
          </section>}
          <section className="ui-card catalog-card" aria-labelledby="compliance-detail-title">
            <h3 id="compliance-detail-title">Detalle y revisión</h3>
            {!selected ? <p role="status">Selecciona una evidencia ficticia.</p> : <>
              <dl>
                <dt>Referencia</dt><dd>{selected.evidence_ref}</dd>
                <dt>Obligación</dt><dd>{selected.obligation_ref}</dd>
                <dt>Tipo</dt><dd>{selected.type}</dd>
                <dt>Estado</dt><dd><strong>{displayStates[selected.state]}</strong></dd>
              </dl>
              {canUpdate ? <form className="catalog-editor"
                onSubmit={(event) => { void changeState(event); }}>
                <label className="field"><span>Nuevo estado explícito</span>
                  <select value={nextState} onChange={(event) =>
                    setNextState(event.target.value as 'EN_REVISION' | 'REQUIERE_REVISION')}>
                    <option value="EN_REVISION">En revisión</option>
                    <option value="REQUIERE_REVISION">Requiere revisión</option>
                  </select>
                </label>
                <button type="submit" className="button" disabled={busy}>
                  Registrar transición ficticia explícita
                </button>
              </form> : <p role="status">Sin permiso para actualizar estados.</p>}
            </>}
          </section>
        </div>
      )}
      <p className="muted">Auditoría de servidor en modo demostración. Ningún estado se cierra automáticamente.</p>
    </section>
  );
}
/** M06: six authorized read-only projections over disposable server-side fixtures. */
const legalSections = [
  ['perfil', 'Perfil legal'], ['catalogo', 'Catálogo normativo'],
  ['matriz', 'Obligaciones y prohibiciones'], ['calendario', 'Calendario'],
  ['evaluaciones', 'Evaluaciones'], ['auditorias', 'Auditorías'],
] as const;
type LegalSection = typeof legalSections[number][0];
type LegalLoad = { status: 'loading' | 'error' | 'denied' }
  | { status: 'ready'; rows: Record<string, unknown>[] };
export function LegalComplianceManagement({ branchId, permissions, csrfToken }: Readonly<{
  branchId: string; permissions: string[]; csrfToken : string;
}>) {
  const allowed = permissions.includes('settings.view');
  const canCreate = permissions.includes('settings.create');
  const canUpdate = permissions.includes('settings.update');
  const base = '/api/v1/branches/' + encodeURIComponent(branchId) + '/legal-compliance/';
  const [section, setSection] = useState<LegalSection>('perfil');
  const [load, setLoad] = useState<LegalLoad>({ status: 'loading' });
  const [version, setVersion] = useState(0);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  useEffect(() => {
    if (!allowed) { setLoad({ status: 'denied' }); return; }
    const controller = new AbortController();
    setLoad({ status: 'loading' });
    void globalThis.fetch.call(globalThis, base + section, { credentials: 'same-origin', signal: controller.signal,
      headers: { Accept: 'application/json' } })
      .then(async response => {
        if (response.status === 401 || response.status === 403) { setLoad({ status: 'denied' }); return; }
        if (!response.ok) throw new Error('UNAVAILABLE');
        const data = await response.json() as { rows?: unknown; synthetic_only?: boolean };
        if (!controller.signal.aborted) setLoad(data.synthetic_only === true && Array.isArray(data.rows)
          ? { status: 'ready', rows: data.rows as Record<string, unknown>[] } : { status: 'error' });
      })
      .catch(() => { if (!controller.signal.aborted) setLoad({ status: 'error' }); });
    return () => controller.abort();
  }, [allowed, base, section, version]);
  async function scenario(mode: 'demo' | 'vacio', method: 'POST' | 'PATCH'): Promise<void> {
    if (busy || !allowed || (method === 'POST' ? !canCreate : !canUpdate)) return;
    setBusy(true); setNotice('');
    try {
      const response = await globalThis.fetch.call(globalThis, base + 'fixtures', {
        method, credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({ scenario: mode }),
      });
      if (!response.ok) throw new Error('DENIED');
      setVersion(previous => previous + 1);
      setNotice('Escenario ficticio actualizado. Sin efecto legal ni operativo.');
    } catch { setNotice('No fue posible cambiar el escenario: acceso denegado o servicio no disponible.'); }
    finally { setBusy(false); }
  }
  return <section className="catalog-panel" aria-labelledby="legal-m06-title">
    <h2 id="legal-m06-title">Cumplimiento legal · vista de pruebas M06</h2>
    <p role="alert">Solo referencias ficticias. No certifica cumplimiento ni habilita operación.</p>
    <label>Submódulo
      <select value={section} onChange={event => setSection(event.target.value as LegalSection)}>
        {legalSections.map(([value, title]) => <option key={value} value={value}>{title}</option>)}
      </select>
    </label>
    {allowed && <div>
      {canCreate && <button type="button" disabled={busy}
        onClick={() => { void scenario('demo', 'POST'); }}>Cargar demostración sintética</button>}
      {canUpdate && <button type="button" disabled={busy}
        onClick={() => { void scenario('vacio', 'PATCH'); }}>Vaciar demostración</button>}
    </div>}
    {notice && <p role="status">{notice}</p>}
    {load.status === 'loading' && <p role="status">Cargando vista ficticia…</p>}
    {load.status === 'denied' && <p role="alert">Acceso denegado: falta permiso de lectura.</p>}
    {load.status === 'error' && <p role="alert">No fue posible consultar el módulo de prueba.</p>}
    {load.status === 'ready' && (load.rows.length === 0
      ? <p role="status">Sin datos ficticios en este submódulo.</p>
      : <ul>{load.rows.map((row, index) => <li key={index}>
          <dl>{Object.entries(row).map(([key, value]) => <div key={key}>
            <dt>{key.replaceAll('_', ' ')}</dt><dd><code>{JSON.stringify(value)}</code></dd>
          </div>)}</dl>
        </li>)}</ul>)}
    <p>Las evaluaciones y auditorías requieren revisión profesional; ningún cierre es automático.</p>
  </section>;
}
