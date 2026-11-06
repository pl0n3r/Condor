/// <reference types="vite/client" />
import { useLayoutEffect, useState, type FormEvent } from 'react';
import { PageHeader, MetricStrip, Toolbar, DataTable, SidePanel, StatusPill } from './ui/Kit';

/**
 * M12 · Riesgos empresariales. Simulador local de construcción, no una evaluación
 * legal o profesional: jamás envía ni persiste información del usuario.
 */
type RiskState = 'PENDIENTE' | 'REQUIERE_REVISION';
type RiskCategory = 'operativo' | 'financiero' | 'legal' | 'tecnologico';
type RiskRecord = Readonly<{
  tenantId: string;
  companyId: string;
  branchId: string;
  ref: string;
  category: RiskCategory;
  probability: number;
  impact: number;
  state: RiskState;
}>;
type AuditEvent = Readonly<{
  scope: string;
  riskRef: string;
  action: 'created' | 'viewed' | 'state_changed';
  actorRef: string;
  state: RiskState;
}>;
type Props = Readonly<{
  tenantId: string;
  companyId: string;
  branchId: string;
  permissions: string[];
}>;

const riskRefPattern = /^fixture:risk\/[a-z][a-z0-9_-]{0,47}$/;
const actorRefPattern = /^fixture:actor\/[a-z][a-z0-9_-]{0,47}$/;
const digitSequencePattern = /[0-9]{7,}/;
const categories: RiskCategory[] = ['operativo', 'financiero', 'legal', 'tecnologico'];
const states: RiskState[] = ['PENDIENTE', 'REQUIERE_REVISION'];
const validFixture = (reference: string, pattern: RegExp): boolean =>
  pattern.test(reference) && !digitSequencePattern.test(reference);
const searchPrefix = 'fixture:risk/';
const validSearch = (value: string): boolean =>
  (searchPrefix.startsWith(value)
    || (value.startsWith(searchPrefix) && validFixture(value, riskRefPattern)))
  && !digitSequencePattern.test(value);

function Workspace({ tenantId, companyId, branchId, permissions }: Props) {
  // Company comes exclusively from the authenticated admin context; never from form input.
  // Existing inventory permissions gate this pre-live offline demo until M12 RBAC exists.
  const canView = companyId !== '__unassigned__' && permissions.includes('inventory.view');
  const canCreate = canView && permissions.includes('inventory.create');
  const canUpdate = canView && permissions.includes('inventory.update');
  const scope = tenantId + '|' + companyId + '|' + branchId;

  const [records, setRecords] = useState<RiskRecord[]>([]);
  const [history, setHistory] = useState<AuditEvent[]>([]);
  const [riskRef, setRiskRef] = useState('');
  const [actorRef, setActorRef] = useState('');
  const [reviewerRef, setReviewerRef] = useState('');
  const [category, setCategory] = useState<RiskCategory>('operativo');
  const [probability, setProbability] = useState('1');
  const [impact, setImpact] = useState('1');
  const [selected, setSelected] = useState('');
  const [creating, setCreating] = useState(false);
  const [search, setSearch] = useState('');
  const [categoryFilter, setCategoryFilter] = useState<'TODAS' | RiskCategory>('TODAS');
  const [stateFilter, setStateFilter] = useState<'TODOS' | RiskState>('TODOS');
  const [notice, setNotice] = useState('');

  function audit(ref: string, action: AuditEvent['action'], actor: string, state: RiskState) {
    setHistory(previous => [...previous, { scope, riskRef: ref, action, actorRef: actor, state }].slice(-100));
  }

  const scoped = records.filter(row => row.tenantId === tenantId
    && row.companyId === companyId && row.branchId === branchId);
  const visible = scoped.filter(row =>
    (stateFilter === 'TODOS' || row.state === stateFilter)
    && (categoryFilter === 'TODAS' || row.category === categoryFilter)
    && row.ref.includes(search.trim().toLowerCase()));
  const detail = scoped.find(row => row.ref === selected);

  function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const validScore = (value: string) => /^[1-5]$/.test(value);
    if (!canCreate || !validFixture(riskRef, riskRefPattern)
      || !validFixture(actorRef, actorRefPattern)
      || !categories.includes(category)
      || !validScore(probability) || !validScore(impact)
      || scoped.length >= 50 || scoped.some(row => row.ref === riskRef)) {
      setNotice('No se creó: verifica permiso, cupo, referencias ficticias y valores de 1 a 5.');
      return;
    }
    const record: RiskRecord = {
      tenantId, companyId, branchId, ref: riskRef, category,
      probability: Number(probability), impact: Number(impact), state: 'PENDIENTE',
    };
    setRecords(previous => [...previous, record]);
    audit(riskRef, 'created', actorRef, 'PENDIENTE');
    setSelected(riskRef);
    setCreating(false);
    setRiskRef('');
    setActorRef('');
    setNotice('Riesgo ficticio creado solo en memoria. Requiere revisión profesional.');
  }

  function view(ref: string) {
    const row = scoped.find(item => item.ref === ref);
    if (!canView || !validFixture(ref, riskRefPattern) || !row) return;
    setSelected(ref);
    audit(ref, 'viewed', 'fixture:actor/consulta', row.state);
  }

  function changeState(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canUpdate || !detail || !validFixture(reviewerRef, actorRefPattern)
      || !states.includes(detail.state)) {
      setNotice('Revisión denegada: permiso, actor ficticio o registro inválido.');
      return;
    }
    // No terminal states exist. An explicit human operation is required on every transition.
    const nextState: RiskState = detail.state === 'PENDIENTE'
      ? 'REQUIERE_REVISION' : 'PENDIENTE';
    setRecords(previous => previous.map(row =>
      row.tenantId === tenantId && row.companyId === companyId
      && row.branchId === branchId && row.ref === detail.ref
        ? { ...row, state: nextState } : row));
    audit(detail.ref, 'state_changed', reviewerRef, nextState);
    setReviewerRef('');
    setNotice('Cambio ficticio explícito registrado. No constituye aprobación ni cierre.');
  }

  if (!canView) {
    return (
      <section id="risk-management" aria-label="Riesgos empresariales (pruebas)">
        <p role="alert">No tienes permiso para consultar riesgos o una razón social activa.</p>
      </section>
    );
  }

  return (
    <section id="risk-management" className="ui-page catalog-panel" aria-label="Riesgos empresariales">
      <PageHeader title="Riesgos empresariales" description="Escenarios ficticios sin evaluaciones legales ni datos reales." actions={canCreate ? <button type="button" className="button" onClick={() => { setSelected(''); setCreating(true); }}>Crear riesgo de prueba</button> : undefined} />
      <MetricStrip label="Indicadores de Riesgos empresariales" items={[{ label: 'Registros', value: scoped.length }, { label: 'Resultados', value: visible.length }, { label: 'Eventos de esta sesión', value: history.filter(item => item.scope === scope).length }]} />
      <p role="alert">SOLO DATOS FICTICIOS. No usar datos personales, empresas reales ni evaluar cumplimiento legal.</p>
      <p className="muted">Simulación efímera: sin API, sin persistencia y sin auditoría de servidor.</p>
      {notice && <output role="status" aria-live="polite">{notice}</output>}
      <div className="ui-grid-2 catalog-grid">
        <section className="ui-card catalog-card" aria-labelledby="risk-list-title">
          <h3 id="risk-list-title">Riesgos de demostración</h3>
          <Toolbar trailing={<span className="ui-count">{visible.length} de {scoped.length}</span>}>
          <label>Buscar referencia ficticia
            <input type="search" value={search} onChange={event => {
              const value = event.target.value.toLowerCase();
              if (validSearch(value)) setSearch(value);
              else setNotice("Busca solo referencias ficticias: no ingreses datos personales.");
            }}
              placeholder="fixture:risk/demo" />
          </label>
          <label>Filtrar estado
            <select value={stateFilter} onChange={event =>
              setStateFilter(event.target.value as typeof stateFilter)}>
              <option value="TODOS">Todos</option>
              {states.map(item => <option key={item} value={item}>{item}</option>)}
            </select>
          </label>
          <label>Filtrar categoría
            <select value={categoryFilter} onChange={event =>
              setCategoryFilter(event.target.value as typeof categoryFilter)}>
              <option value="TODAS">Todas</option>
              {categories.map(item => <option key={item} value={item}>{item}</option>)}
            </select>
          </label>
          </Toolbar>
          <DataTable caption="Listado de Riesgos empresariales" rows={visible} rowKey={row => row.ref} onOpen={row => view(row.ref)} empty="Sin registros ficticios para estos filtros." columns={[{ key: 'ref', header: 'Referencia', render: row => <span className="ui-cell-main"><strong>{row.ref}</strong></span> }, { key: 'state', header: 'Estado', render: row => <StatusPill tone={row.state === 'EN_REVISION' ? 'info' : row.state === 'REQUIERE_REVISION' || row.state === 'CIERRE_REQUIERE_REVISION' ? 'warn' : 'neutral'}>{row.state}</StatusPill> }]} />
        </section>
        {canCreate && (
          <SidePanel open={creating && canCreate} title="Crear riesgo de prueba" onClose={() => setCreating(false)}>
          <form className="catalog-editor" onSubmit={create}>
              <label>Referencia de riesgo ficticio
                <input required maxLength={70} pattern="fixture:risk/[a-z][a-z0-9_\-]{0,47}"
                  value={riskRef} placeholder="fixture:risk/demo"
                  onChange={event => setRiskRef(event.target.value)} />
              </label>
              <label>Actor ficticio (sin identificación)
                <input required maxLength={70} pattern="fixture:actor/[a-z][a-z0-9_\-]{0,47}"
                  value={actorRef} placeholder="fixture:actor/operador"
                  onChange={event => setActorRef(event.target.value)} />
              </label>
              <label>Categoría ilustrativa
                <select value={category} onChange={event =>
                  setCategory(event.target.value as RiskCategory)}>
                  {categories.map(item => <option key={item} value={item}>{item}</option>)}
                </select>
              </label>
              <label>Probabilidad ilustrativa (1 a 5)
                <input type="number" min={1} max={5} step={1} required value={probability}
                  onChange={event => setProbability(event.target.value)} />
              </label>
              <label>Impacto ilustrativo (1 a 5)
                <input type="number" min={1} max={5} step={1} required value={impact}
                  onChange={event => setImpact(event.target.value)} />
              </label>
              <button type="submit">Crear riesgo ficticio</button>
            </form>
          
        </SidePanel>
        )}
        <SidePanel open={Boolean(detail) && !creating} title="Detalle y revisión" onClose={() => setSelected('')}>
          {detail ? <>
            <dl>
              <dt>Referencia</dt><dd>{detail.ref}</dd>
              <dt>Categoría</dt><dd>{detail.category}</dd>
              <dt>Probabilidad</dt><dd>{detail.probability}</dd>
              <dt>Impacto</dt><dd>{detail.impact}</dd>
              <dt>Puntaje orientativo</dt><dd>{detail.probability * detail.impact} (sin valor legal)</dd>
              <dt>Estado</dt><dd>{detail.state}</dd>
            </dl>
            {canUpdate && <form className="catalog-editor" onSubmit={changeState}>
              <label>Actor ficticio de revisión
                <input required maxLength={70} pattern="fixture:actor/[a-z][a-z0-9_\-]{0,47}"
                  value={reviewerRef} placeholder="fixture:actor/revisor"
                  onChange={event => setReviewerRef(event.target.value)} />
              </label>
              <button type="submit">Registrar transición ficticia explícita</button>
            </form>}
            <p className="muted">Ningún riesgo se aprueba ni se cierra automáticamente.</p>
          </> : <p role="status">Selecciona un riesgo para consultar su detalle.</p>}
        
        </SidePanel>
        <section className="ui-card catalog-card" aria-labelledby="risk-audit-title">
          <h3 id="risk-audit-title">Bitácora de demostración (no oficial)</h3>
          <p className="muted">La bitácora local no sustituye la auditoría de servidor.</p>
          {history.filter(item => item.scope === scope).length === 0
            ? <p role="status">Sin operaciones ficticias registradas.</p>
            : <ol>{history.filter(item => item.scope === scope).map((item, index) =>
              <li key={index}>{item.riskRef} · {item.action} · {item.actorRef} · {item.state}</li>)}</ol>}
        </section>
      </div>
    </section>
  );
}


// M12 S04: vista HTTP de pruebas. El simulador local histórico sigue aislado
// en un <details> separado y nunca se presenta como recibo del servidor.
type HttpRisk = Readonly<{
  risk_ref: string; category: RiskCategory | 'reputacional';
  probability: number; impact: number;
  state: RiskState | 'EN_REVISION'; scenario_ref?: string;
}>;
type HttpLoad = { kind: 'loading' | 'denied' }
  | { kind: 'error'; message: string }
  | { kind: 'ready'; risks: HttpRisk[] };
const scenarioRefPattern = /^fixture:risk-scenario\/[a-z][a-z0-9_-]{0,47}$/;
const httpCategories = [...categories, 'reputacional'] as const;
const httpStates = [...states, 'EN_REVISION'] as const;

function isHttpRisk(value: unknown): value is HttpRisk {
  if (!value || typeof value !== 'object') return false;
  const item = value as Partial<HttpRisk>;
  return typeof item.risk_ref === 'string'
    && validFixture(item.risk_ref, riskRefPattern)
    && httpCategories.some(category => category === item.category)
    && httpStates.some(state => state === item.state)
    && typeof item.probability === 'number'
    && Number.isInteger(item.probability) && item.probability >= 1
    && item.probability <= 5
    && typeof item.impact === 'number'
    && Number.isInteger(item.impact) && item.impact >= 1
    && item.impact <= 5
    && (item.category !== 'reputacional' || (
      typeof item.scenario_ref === 'string'
      && validFixture(item.scenario_ref, scenarioRefPattern)
    ));
}

type HttpReply = Readonly<{ status: number; body: unknown }>;
function requestRisk(
  url: string, method: 'GET' | 'POST' | 'PATCH' = 'GET',
  payload?: object, csrfToken?: string,
): { promise: Promise<HttpReply>; abort: () => void } {
  const xhr = new XMLHttpRequest();
  const promise = new Promise<HttpReply>((resolve, reject) => {
    xhr.open(method, url, true);
    xhr.withCredentials = true;
    xhr.timeout = 15000;
    xhr.setRequestHeader('Accept', 'application/json');
    if (payload) {
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.setRequestHeader('X-CSRF-Token', csrfToken ?? '');
    }
    xhr.onload = () => {
      // Symfony puede devolver HTML en un 401/403/404. La denegación
      // se clasifica por status HTTP, nunca por la forma del cuerpo.
      if ([401, 403, 404].includes(xhr.status)) {
        resolve({ status: xhr.status, body: null });
        return;
      }
      try {
        resolve({ status: xhr.status, body: JSON.parse(xhr.responseText) as unknown });
      } catch {
        reject(new Error('Respuesta JSON inválida'));
      }
    };
    xhr.onerror = () => reject(new Error('Error de red'));
    xhr.ontimeout = () => reject(new Error('Tiempo de espera agotado'));
    xhr.onabort = () => reject(new Error('Petición cancelada'));
    xhr.send(payload ? JSON.stringify(payload) : null);
  });
  return { promise, abort: () => xhr.abort() };
}

function RiskHttpWorkspace({
  tenantId, companyId, branchId, permissions,
}: Props) {
  const canView = permissions.includes('settings.view')
    && companyId !== '__unassigned__' && branchId !== '__unassigned__';
  const canCreate = canView && permissions.includes('settings.create');
  const canUpdate = canView && permissions.includes('settings.update');
  const endpoint = '/api/v1/branches/' + encodeURIComponent(branchId) + '/risks';
  const [load, setLoad] = useState<HttpLoad>({ kind: 'loading' });
  const [revision, setRevision] = useState(0);
  const [category, setCategory] = useState<'tecnologico' | 'reputacional'>('tecnologico');
  const [ref, setRef] = useState('fixture:risk/demo');
  const [scenario, setScenario] = useState('fixture:risk-scenario/demo');
  const [probability, setProbability] = useState('1');
  const [impact, setImpact] = useState('1');
  const [selected, setSelected] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);

  useLayoutEffect(() => {
    if (!canView) {
      setLoad({ kind: 'denied' });
      return;
    }
    let alive = true;
    const request = requestRisk(endpoint);
    setLoad({ kind: 'loading' });
    void request.promise.then(({ status, body }) => {
      if (!alive) return;
      if ([401, 403, 404].includes(status)) {
        setLoad({ kind: 'denied' });
      } else if (status === 200 && body && typeof body === 'object'
        && 'risks' in body && Array.isArray(body.risks)
        && body.risks.length <= 50 && body.risks.every(isHttpRisk)) {
        setLoad({ kind: 'ready', risks: body.risks });
      } else {
        setLoad({ kind: 'error', message: 'Respuesta HTTP inválida o no disponible.' });
      }
    }).catch(() => {
      if (alive) setLoad({ kind: 'error', message: 'No se pudo consultar la API de riesgos.' });
    });
    return () => { alive = false; request.abort(); };
  }, [tenantId, companyId, branchId, endpoint, canView, revision]);

  // La API obtiene tenant y sede de la sesión del servidor: nunca los
  // recibe del formulario. Todas las escrituras incluyen el CSRF de /admin.
  async function mutate(method: 'POST' | 'PATCH', url: string, payload: object) {
    if (busy || !canView || (method === 'POST' ? !canCreate : !canUpdate)) return;
    const token = document.querySelector('#condor-admin-root')?.getAttribute('data-access-token');
    if (!token) {
      setNotice('Sin token CSRF de la sesión: escritura denegada.');
      return;
    }
    setBusy(true);
    setNotice('');
    try {
      const response = await requestRisk(url, method, payload, token).promise;
      if ([401, 403, 404].includes(response.status)) {
        setLoad({ kind: 'denied' });
      } else if ((method === 'POST' ? response.status === 201 : response.status === 200)
        && response.body && typeof response.body === 'object'
        && 'risk' in response.body && isHttpRisk(response.body.risk)) {
        setRevision(value => value + 1);
        setNotice('Cambio ficticio confirmado por el servidor; no es aprobación.');
      } else {
        setNotice('El servidor rechazó la operación o devolvió datos inválidos.');
      }
    } catch {
      setNotice('Error de red al operar sobre el riesgo ficticio.');
    } finally {
      setBusy(false);
    }
  }

  const risks = load.kind === 'ready' ? load.risks : [];
  const chosen = risks.find(row => row.risk_ref === selected);
  return (
    <section className="catalog-panel" aria-labelledby="risk-http-title">
      <h2 id="risk-http-title">M12 S04 · riesgos ficticios desde API</h2>
      <p className="muted">Solo construcción. Los permisos y la sede se validan en el servidor.
        Sin certificación jurídica, aprobación ni cierre automático.</p>
      {load.kind === 'loading' && <output role="status" aria-live="polite">Cargando riesgos ficticios…</output>}
      {load.kind === 'denied' && <p role="alert">Permiso denegado o sede ajena (HTTP 401/403/404).</p>}
      {load.kind === 'error' && <div role="alert">{load.message}
        <button type="button" onClick={() => setRevision(value => value + 1)}>Reintentar</button>
      </div>}
      {load.kind === 'ready' && risks.length === 0 && <p role="status">No hay riesgos ficticios en esta sede.</p>}
      {load.kind === 'ready' && risks.length > 0 && <ul>{risks.map(row => <li key={row.risk_ref}>
        <button type="button" onClick={() => setSelected(row.risk_ref)}>{row.risk_ref}</button>
        {' · '}{row.category} · {row.state}
        {row.scenario_ref && <> · escenario: {row.scenario_ref}</>}
      </li>)}</ul>}
      {load.kind === 'ready' && chosen && <section aria-label="Estado del riesgo seleccionado">
        <p>{chosen.risk_ref}: {chosen.state} · probabilidad {chosen.probability}
          {' · '}impacto {chosen.impact}</p>
        {canUpdate && <button type="button" disabled={busy}
          onClick={() => void mutate('PATCH',
            endpoint + '/' + encodeURIComponent(chosen.risk_ref.slice('fixture:risk/'.length)) + '/state',
            { state: chosen.state === 'PENDIENTE' ? 'EN_REVISION' : 'REQUIERE_REVISION' })}>
          Registrar revisión ficticia en servidor
        </button>}
      </section>}
      {load.kind === 'ready' && canCreate && <form className="catalog-editor" onSubmit={event => {
        event.preventDefault();
        if (!validFixture(ref, riskRefPattern)
          || (category === 'reputacional' && !validFixture(scenario, scenarioRefPattern))
          || !/^[1-5]$/.test(probability) || !/^[1-5]$/.test(impact)) {
          setNotice('Solo se admiten referencias y puntajes ficticios válidos.');
          return;
        }
        void mutate('POST', endpoint, {
          risk_ref: ref, category, probability: Number(probability),
          impact: Number(impact),
          ...(category === 'reputacional' ? { scenario_ref: scenario } : {}),
        });
      }}>
        <h3>Crear riesgo de prueba en sesión HTTP</h3>
        <label>Tipo
          <select value={category} onChange={event =>
            setCategory(event.target.value === 'reputacional' ? 'reputacional' : 'tecnologico')}>
            <option value="tecnologico">Tecnológico</option>
            <option value="reputacional">Reputacional</option>
          </select>
        </label>
        <label>Referencia ficticia
          <input required value={ref} onChange={event => setRef(event.target.value)}
            pattern="fixture:risk/[a-z][a-z0-9_\-]{0,47}" />
        </label>
        {category === 'reputacional' && <label>Escenario ficticio tipado
          <input required value={scenario} onChange={event => setScenario(event.target.value)}
            pattern="fixture:risk-scenario/[a-z][a-z0-9_\-]{0,47}" />
        </label>}
        <label>Probabilidad 1 a 5
          <input type="number" min={1} max={5} value={probability}
            onChange={event => setProbability(event.target.value)} />
        </label>
        <label>Impacto 1 a 5
          <input type="number" min={1} max={5} value={impact}
            onChange={event => setImpact(event.target.value)} />
        </label>
        <button type="submit" disabled={busy}>Crear escenario ficticio</button>
      </form>}
      {notice && <output role="status" aria-live="polite">{notice}</output>}
    </section>
  );
}

export function RiskManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <div key={props.tenantId + '|' + props.companyId + '|' + props.branchId}>
    <RiskHttpWorkspace {...props} />
    <details><summary>Simulador local histórico (sin API, evidencia separada)</summary>
      <Workspace {...props} />
    </details>
  </div>;
}
