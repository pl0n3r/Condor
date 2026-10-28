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
type AuditEvent = Readonly<{ scope: string; reference: string; action: 'created' | 'viewed' | 'review_requested' | 'review_started' }>;
type Props = Readonly<{ tenantId: string; companyId: string; branchId: string; permissions: string[] }>;

const financeRefPattern = /^fixture:finance\/[a-z][a-z0-9_-]{0,47}$/;
const periodRefPattern = /^fixture:period\/[a-z][a-z0-9_-]{0,47}$/;
const conceptRefPattern = /^fixture:concept\/[a-z][a-z0-9_-]{0,47}$/;
const states: FinanceState[] = ['BORRADOR', 'REQUIERE_REVISION', 'EN_REVISION'];
const kinds: FinanceKind[] = ['INGRESO', 'EGRESO', 'CUENTA_POR_COBRAR', 'CUENTA_POR_PAGAR', 'BANCO', 'PRESUPUESTO'];
const fictionalAmounts: FinanceRecord['amountFixture'][] = ['0.00', '100.00', '250.00'];

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
    setEvents(previous => [...previous, { scope, reference: item, action }].slice(-100));
  }

  function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || !financeRefPattern.test(reference)
      || !periodRefPattern.test(periodRef) || !conceptRefPattern.test(conceptRef)
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
    if (!canView || !financeRefPattern.test(ref) || !records.some(row =>
      row.reference === ref && row.tenantId === tenantId
      && row.companyId === companyId && row.branchId === branchId)) return;
    setSelected(ref);
    audit(ref, 'viewed');
  }

  function changeState(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const current = records.find(row => row.reference === selected
      && row.tenantId === tenantId && row.companyId === companyId && row.branchId === branchId);
    if (!canReview || !current || !financeRefPattern.test(selected) || current.state === 'EN_REVISION') {
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
        <h2 id="finance-title">Finanzas y contabilidad · simulador</h2>
        <p role="alert">SOLO DATOS FICTICIOS. Sin valor legal, contable, bancario ni tributario.</p>
        <p className="muted">Demostración en memoria, sin conexión a bancos, pagos o clientes reales.</p>
      </header>
      {notice && <output role="status" aria-live="polite">{notice}</output>}
      <div className="catalog-grid">
        <section className="catalog-card" aria-labelledby="finance-list-title">
          <h3 id="finance-list-title">Registros ficticios</h3>
          <label>Buscar referencia
            <input aria-label="Buscar registro ficticio" value={search} maxLength={80}
              onChange={event => setSearch(event.target.value)} />
          </label>
          <label>Tipo de registro
            <select aria-label="Filtrar por tipo" value={kindFilter}
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
          {visible.length === 0 ? <p role="status">Sin registros ficticios para este filtro.</p> : (
            <ul>{visible.map(row => (
              <li key={row.reference}>
                <button type="button" onClick={() => view(row.reference)}>
                  {row.reference} · {row.kind} · {row.state}
                </button>
              </li>
            ))}</ul>
          )}
        </section>
        {canCreate && (
          <section className="catalog-card" aria-labelledby="finance-create-title">
            <h3 id="finance-create-title">Crear demostración</h3>
            <form className="catalog-editor" onSubmit={create}>
              <label>Referencia ficticia
                <input required maxLength={64} pattern="fixture:finance/[a-z][a-z0-9_-]{0,47}"
                  placeholder="fixture:finance/demo" value={reference}
                  onChange={event => setReference(event.target.value)} />
              </label>
              <label>Periodo ficticio
                <input required maxLength={64} pattern="fixture:period/[a-z][a-z0-9_-]{0,47}"
                  placeholder="fixture:period/demo" value={periodRef}
                  onChange={event => setPeriodRef(event.target.value)} />
              </label>
              <label>Concepto ficticio
                <input required maxLength={64} pattern="fixture:concept/[a-z][a-z0-9_-]{0,47}"
                  placeholder="fixture:concept/demo" value={conceptRef}
                  onChange={event => setConceptRef(event.target.value)} />
              </label>
              <label>Tipo
                <select value={kind} onChange={event => setKind(event.target.value as FinanceKind)}>
                  {kinds.map(item => <option key={item} value={item}>{item}</option>)}
                </select>
              </label>
              <label>Importe ilustrativo (COP ficticios)
                <select value={amountFixture}
                  onChange={event => setAmountFixture(event.target.value as FinanceRecord['amountFixture'])}>
                  {fictionalAmounts.map(item => <option key={item} value={item}>{item}</option>)}
                </select>
              </label>
              <button type="submit">Crear borrador ficticio</button>
            </form>
          </section>
        )}
        <section className="catalog-card" aria-labelledby="finance-detail-title">
          <h3 id="finance-detail-title">Detalle y revisión manual</h3>
          {current ? <>
            <dl>
              <dt>Referencia</dt><dd>{current.reference}</dd>
              <dt>Tipo</dt><dd>{current.kind}</dd>
              <dt>Periodo</dt><dd>{current.periodRef}</dd>
              <dt>Concepto</dt><dd>{current.conceptRef}</dd>
              <dt>Importe de prueba</dt><dd>{current.amountFixture} COP ficticios</dd>
              <dt>Estado</dt><dd>{current.state}</dd>
            </dl>
            {canReview && current.state !== 'EN_REVISION' && (
              <form className="catalog-editor" onSubmit={changeState}>
                <button type="submit">{current.state === 'BORRADOR'
                  ? 'Solicitar revisión ficticia' : 'Iniciar revisión ficticia'}</button>
              </form>
            )}
            <p className="muted">Ningún registro se aprueba, contabiliza, paga ni cierra automáticamente.</p>
          </> : <p role="status">Selecciona un registro ficticio para consultar el detalle.</p>}
        </section>
        <section className="catalog-card" aria-labelledby="finance-audit-title">
          <h3 id="finance-audit-title">Bitácora de demostración (no oficial)</h3>
          <p className="muted">Una operación real exige permisos y auditoría de servidor.</p>
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

/** Construcción: no registra funcionalidades financieras para clientes reales. */
export function FinanceManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <Workspace key={props.tenantId + '|' + props.companyId + '|' + props.branchId}
    {...props} />;
}
