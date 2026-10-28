/// <reference types="vite/client" />
import { useState, type FormEvent } from 'react';

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
    <section id="risk-management" className="catalog-panel" aria-labelledby="risk-title">
      <div className="section-heading"><div>
        <span className="eyebrow">M12 · Exclusivo construcción</span>
        <h2 id="risk-title">Riesgos empresariales</h2>
        <p role="alert">SOLO DATOS FICTICIOS. No usar datos personales, empresas reales ni evaluar cumplimiento legal.</p>
        <p className="muted">Simulación efímera: sin API, sin persistencia y sin auditoría de servidor.</p>
      </div></div>
      {notice && <output role="status" aria-live="polite">{notice}</output>}
      <div className="catalog-grid">
        <section className="catalog-card" aria-labelledby="risk-list-title">
          <h3 id="risk-list-title">Riesgos de demostración</h3>
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
          {visible.length === 0 ? <p role="status">Sin riesgos ficticios para estos filtros.</p> : (
            <ul>{visible.map(row => <li key={row.ref}>
              <button type="button" aria-pressed={selected === row.ref}
                onClick={() => view(row.ref)}>{row.ref}</button>
              {' · '}{row.category} · {row.state}
            </li>)}</ul>
          )}
        </section>
        {canCreate && (
          <section className="catalog-card" aria-labelledby="risk-create-title">
            <h3 id="risk-create-title">Crear riesgo de prueba</h3>
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
          </section>
        )}
        <section className="catalog-card" aria-labelledby="risk-detail-title">
          <h3 id="risk-detail-title">Detalle y revisión</h3>
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
        </section>
        <section className="catalog-card" aria-labelledby="risk-audit-title">
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

export function RiskManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <Workspace key={props.tenantId + '|' + props.companyId + '|' + props.branchId} {...props} />;
}
