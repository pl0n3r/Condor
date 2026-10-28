/// <reference types="vite/client" />
import { useEffect, useRef, useState, type FormEvent } from 'react';

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
    <section id="compliance-management" className="catalog-panel" aria-labelledby="compliance-title">
      <div className="section-heading"><div>
        <h2 id="compliance-title">Seguridad y salud en el trabajo · pruebas</h2>
        <p role="alert">SOLO DATOS FICTICIOS. Sin cumplimiento legal certificado, personas reales ni auditoría de servidor.</p>
        <p className="muted">Simulación efímera: se borra al cambiar de empresa o recargar.</p>
      </div></div>
      {notice && <output role="status" aria-live="polite">{notice}</output>}
      <div className="catalog-grid">
        <section className="catalog-card" aria-labelledby="compliance-list-title">
          <h3 id="compliance-list-title">Registros de demostración</h3>
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
          {filtered.length === 0 ? <p role="status">Sin registros ficticios para estos filtros.</p> : (
            <ul>{filtered.map(row => <li key={row.ref}>
              <button type="button" onClick={() => view(row.ref)}>{row.ref} · {row.state}</button>
            </li>)}</ul>
          )}
        </section>
        {canCreate && (
          <section className="catalog-card" aria-labelledby="compliance-create-title">
            <h3 id="compliance-create-title">Nuevo registro ficticio</h3>
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
          </section>
        )}
        <section className="catalog-card" aria-labelledby="compliance-detail-title">
          <h3 id="compliance-detail-title">Detalle y revisión</h3>
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
        </section>
        <section className="catalog-card" aria-labelledby="compliance-audit-title">
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

export function ComplianceManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <Workspace key={props.tenantId + '|' + props.companyId + '|' + props.branchId} {...props} />;
}

/** M06: independent synthetic compliance-evidence facade, distinct from SG-SST. */
export function ComplianceEvidenceManagement({ branchId, permissions, csrfToken }: Readonly<{
  branchId: string; permissions: string[]; csrfToken: string;
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
  const response = await fetch(url, {
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
          <section className="catalog-card" aria-labelledby="compliance-list-title">
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
          {canCreate && <section className="catalog-card" aria-labelledby="compliance-create-title">
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
          <section className="catalog-card" aria-labelledby="compliance-detail-title">
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
