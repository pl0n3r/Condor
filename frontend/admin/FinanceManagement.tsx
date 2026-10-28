/// <reference types="vite/client" />
import { useState, type FormEvent } from 'react';

/** M07: panel de prueba aislado: sin HTTP, persistencia ni operaciones financieras. */
type FinanceState = 'BORRADOR' | 'REQUIERE_REVISION' | 'EN_REVISION';
type FinanceKind = 'INGRESO' | 'EGRESO' | 'CUENTA_POR_COBRAR' | 'CUENTA_POR_PAGAR' | 'BANCO' | 'PRESUPUESTO';
type FinanceRecord = Readonly<{
  reference: string;
  tenantId: string;
  companyId: string;
  branchId: string;
  periodRef: string;
  conceptRef: string;
  kind: FinanceKind;
  amountFixture: '0.00' | '100.00' | '250.00';
  state: FinanceState;
}>;
type AuditEvent = Readonly<{ sequence: number; scope: string; reference: string; action: 'created' | 'viewed' | 'review_requested' | 'review_started' }>;
type Props = Readonly<{ tenantId: string; companyId: string; branchId: string; permissions: string[] }>;

const financeRefPattern = /^fixture:finance\/[a-z][a-z0-9_-]{0,47}$/;
const periodRefPattern = /^fixture:period\/[a-z][a-z0-9_-]{0,47}$/;
const conceptRefPattern = /^fixture:concept\/[a-z][a-z0-9_-]{0,47}$/;
const states: FinanceState[] = ['BORRADOR', 'REQUIERE_REVISION', 'EN_REVISION'];
const kinds: FinanceKind[] = ['INGRESO', 'EGRESO', 'CUENTA_POR_COBRAR', 'CUENTA_POR_PAGAR', 'BANCO', 'PRESUPUESTO'];
const fictionalAmounts: FinanceRecord['amountFixture'][] = ['0.00', '100.00', '250.00'];

/** Nunca inferir datos reales de un identificador aparentemente ficticio. */
function isDemoReference(value: string, format: RegExp): boolean {
  return format.test(value) && !/[0-9]{7,}/.test(value);
}

function Workspace({ tenantId, companyId, branchId, permissions }: Props) {
  const scope = tenantId + '|' + companyId + '|' + branchId;
  const canView = companyId !== '__unassigned__' && permissions.includes('settings.view');
  const canCreate = canView && permissions.includes('settings.create');
  const canReview = canView && permissions.includes('settings.update');
  const [records, setRecords] = useState<FinanceRecord[]>([]);
  const [events, setEvents] = useState<AuditEvent[]>([]);
  const [reference, setReference] = useState('');
  const [periodRef, setPeriodRef] = useState('');
  const [conceptRef, setConceptRef] = useState('');
  const [kind, setKind] = useState<FinanceKind>('INGRESO');
  const [amountFixture, setAmountFixture] = useState<FinanceRecord['amountFixture']>('100.00');
  const [selected, setSelected] = useState('');
  const [search, setSearch] = useState('');
  const [kindFilter, setKindFilter] = useState<'TODOS' | FinanceKind>('TODOS');
  const [stateFilter, setStateFilter] = useState<'TODOS' | FinanceState>('TODOS');
  const [notice, setNotice] = useState('');

  function audit(item: string, action: AuditEvent['action']) {
    setEvents(previous => [...previous, {
      sequence: (previous.at(-1)?.sequence ?? 0) + 1, scope, reference: item, action,
    }].slice(-100));
  }

  function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || !isDemoReference(reference, financeRefPattern)
      || !isDemoReference(periodRef, periodRefPattern) || !isDemoReference(conceptRef, conceptRefPattern)
      || !kinds.includes(kind) || !fictionalAmounts.includes(amountFixture)
      || records.length >= 100
      || records.some(row => row.reference === reference && row.tenantId === tenantId
        && row.companyId === companyId && row.branchId === branchId)) {
      setNotice('No se guardó: permiso insuficiente, duplicado o referencias no ficticias. Usa fixture:.../demo.');
      return;
    }
    setRecords(previous => [...previous, {
      reference, tenantId, companyId, branchId, periodRef, conceptRef, kind,
      amountFixture, state: 'BORRADOR',
    }]);
    audit(reference, 'created');
    setSelected(reference);
    setReference('');
    setPeriodRef('');
    setConceptRef('');
    setNotice('Borrador ficticio creado solo en memoria. Sin efecto contable ni tributario.');
  }

  function view(ref: string) {
    if (!canView || !isDemoReference(ref, financeRefPattern) || !records.some(row =>
      row.reference === ref && row.tenantId === tenantId
      && row.companyId === companyId && row.branchId === branchId)) return;
    setSelected(ref);
    audit(ref, 'viewed');
  }

  function changeState(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const current = records.find(row => row.reference === selected
      && row.tenantId === tenantId && row.companyId === companyId && row.branchId === branchId);
    if (!canReview || !current || !isDemoReference(selected, financeRefPattern) || current.state === 'EN_REVISION') {
      setNotice('No se cambió el estado: permiso insuficiente o transición inválida.');
      return;
    }
    const next: FinanceState = current.state === 'BORRADOR' ? 'REQUIERE_REVISION' : 'EN_REVISION';
    setRecords(previous => previous.map(row => row.reference === selected
      && row.tenantId === tenantId && row.companyId === companyId && row.branchId === branchId
      ? { ...row, state: next } : row));
    audit(selected, current.state === 'BORRADOR' ? 'review_requested' : 'review_started');
    setNotice('Transición ficticia explícita. Ningún registro fue aprobado, pagado ni cerrado.');
  }

  if (!canView) return (
    <section id="finance-management" aria-label="Finanzas sintéticas">
      <p role="alert">Acceso denegado: no hay permiso financiero o razón social activa.</p>
    </section>
  );

  const scoped = records.filter(row => row.tenantId === tenantId
    && row.companyId === companyId && row.branchId === branchId);
  const visible = scoped.filter(row =>
    (kindFilter === 'TODOS' || row.kind === kindFilter)
    && (stateFilter === 'TODOS' || row.state === stateFilter)
    && (search.trim() === '' || row.reference.includes(search.toLowerCase().trim())));
  const current = scoped.find(row => row.reference === selected);

  return (
    <section id="finance-management" className="catalog-panel" aria-labelledby="finance-title">
      <header>
        <h2 id="finance-title">Finanzas y contabilidad · laboratorio M07</h2>
        <p role="alert">SOLO DATOS FICTICIOS. Sin valor legal, contable, bancario ni tributario.</p>
        <p className="muted">Registros locales descartables. Sin transferencias, facturas ni operaciones bancarias.</p>
      </header>
      {notice && <output role="status" aria-live="polite">{notice}</output>}

      <div className="catalog-grid">
        <section className="catalog-card" aria-labelledby="finance-create-title">
          <h3 id="finance-create-title">Nuevo asiento ilustrativo</h3>
          <form className="catalog-editor" onSubmit={create}>
            <fieldset disabled={!canCreate} aria-label="Datos sintéticos del asiento">
              <label htmlFor="finance-demo-ref">Identificador de laboratorio</label>
              <input id="finance-demo-ref" required maxLength={64}
                pattern="fixture:finance/[a-z][a-z0-9_-]{0,47}"
                placeholder="fixture:finance/demo" value={reference}
                onChange={event => setReference(event.target.value)} />
              <label htmlFor="finance-demo-period">Período simulado</label>
              <input id="finance-demo-period" required maxLength={64}
                pattern="fixture:period/[a-z][a-z0-9_-]{0,47}"
                placeholder="fixture:period/demo" value={periodRef}
                onChange={event => setPeriodRef(event.target.value)} />
              <label htmlFor="finance-demo-concept">Concepto simulado</label>
              <input id="finance-demo-concept" required maxLength={64}
                pattern="fixture:concept/[a-z][a-z0-9_-]{0,47}"
                placeholder="fixture:concept/demo" value={conceptRef}
                onChange={event => setConceptRef(event.target.value)} />
              <label htmlFor="finance-demo-kind">Clasificación ilustrativa</label>
              <select id="finance-demo-kind" value={kind}
                onChange={event => setKind(event.target.value as FinanceKind)}>
                {kinds.map(value => <option value={value} key={value}>{value}</option>)}
              </select>
              <label htmlFor="finance-demo-amount">Cantidad ficticia en COP (sin valor monetario)</label>
              <select id="finance-demo-amount" value={amountFixture}
                onChange={event => setAmountFixture(event.target.value as FinanceRecord['amountFixture'])}>
                {fictionalAmounts.map(value => <option value={value} key={value}>{value}</option>)}
              </select>
              <button type="submit">Crear borrador ficticio</button>
            </fieldset>
          </form>
          {!canCreate && <p role="status">Solo lectura: falta permiso para crear.</p>}
        </section>

        <section className="catalog-card" aria-labelledby="finance-register-title">
          <h3 id="finance-register-title">Libro de muestra, aislado por empresa</h3>
          <fieldset aria-label="Filtros de registros ficticios">
            <label htmlFor="finance-find">Buscar identificador</label>
            <input id="finance-find" maxLength={80} value={search}
              onChange={event => setSearch(event.target.value)} />
            <label htmlFor="finance-kind-filter">Clasificación</label>
            <select id="finance-kind-filter" value={kindFilter}
              onChange={event => setKindFilter(event.target.value as typeof kindFilter)}>
              <option value="TODOS">Todas</option>
              {kinds.map(value => <option value={value} key={value}>{value}</option>)}
            </select>
            <label htmlFor="finance-state-filter">Fase de revisión</label>
            <select id="finance-state-filter" value={stateFilter}
              onChange={event => setStateFilter(event.target.value as typeof stateFilter)}>
              <option value="TODOS">Todas</option>
              {states.map(value => <option value={value} key={value}>{value}</option>)}
            </select>
          </fieldset>
          <p role="status">{visible.length} registro(s) ficticio(s) en el ámbito actual.</p>
          <ul aria-label="Asientos simulados">
            {visible.map(row => <li key={row.reference}>
              <button type="button" onClick={() => view(row.reference)}>
                {row.reference} / {row.kind} / {row.state}
              </button>
            </li>)}
          </ul>
        </section>

        <section className="catalog-card" aria-labelledby="finance-preview-title">
          <h3 id="finance-preview-title">Inspección y control manual</h3>
          {current ? (
            <>
              <dl>
                <dt>Identificador</dt><dd>{current.reference}</dd>
                <dt>Período</dt><dd>{current.periodRef}</dd>
                <dt>Concepto</dt><dd>{current.conceptRef}</dd>
                <dt>Clase</dt><dd>{current.kind}</dd>
                <dt>COP de demostración</dt><dd>{current.amountFixture}</dd>
                <dt>Fase</dt><dd>{current.state}</dd>
              </dl>
              {canReview && current.state !== 'EN_REVISION' && (
                <form onSubmit={changeState}>
                  <button type="submit">
                    {current.state === 'BORRADOR' ? 'Solicitar revisión simulada' : 'Iniciar revisión simulada'}
                  </button>
                </form>
              )}
              <p>Ningún registro se aprueba, contabiliza, paga ni cierra automáticamente.</p>
            </>
          ) : <p role="status">Todavía no se ha seleccionado un asiento de prueba.</p>}
        </section>

        <aside className="catalog-card" aria-labelledby="finance-trace-title">
          <h3 id="finance-trace-title">Bitácora de demostración (no oficial)</h3>
          <p className="muted">La auditoría de servidor es obligatoria para operaciones reales.</p>
          <ol>
            {events.filter(row => row.scope === scope).map(row => (
              <li key={row.sequence}>
                {row.reference} / evento {row.action}
              </li>
            ))}
          </ol>
          {events.length === 0 && <p role="status">No hay movimientos de laboratorio.</p>}
        </aside>
      </div>
    </section>
  );
}

/** Construcción: no registra funcionalidades financieras para clientes reales. */
export function FinanceManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <Workspace key={props.tenantId + '|' + props.companyId + '|' + props.branchId}
    {...props} />;
}
