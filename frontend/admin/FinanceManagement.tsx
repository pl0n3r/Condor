/// <reference types="vite/client" />
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { PageHeader, MetricStrip, Toolbar, DataTable, SidePanel, StatusPill } from './ui/Kit';
import { FinanceClosureAccessDenied, previewSyntheticFinanceClosure, type FinanceClosureMode, type FinanceClosureResult } from './api';

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

type ClosurePreviewStatus =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'empty' }
  | { status: 'denied' }
  | { status: 'error' }
  | { status: 'ready'; result: FinanceClosureResult };

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
  const [creating, setCreating] = useState(false);
  const [search, setSearch] = useState('');
  const [kindFilter, setKindFilter] = useState<'TODOS' | FinanceKind>('TODOS');
  const [stateFilter, setStateFilter] = useState<'TODOS' | FinanceState>('TODOS');
  const [notice, setNotice] = useState('');

  const [closurePreview, setClosurePreview] = useState<ClosurePreviewStatus>({ status: 'idle' });
  const pendingClosurePreview = useRef<AbortController | null>(null);

  useEffect(() => () => pendingClosurePreview.current?.abort(), []);

  async function inspectClosure(mode: FinanceClosureMode) {
    if (!canReview) {
      setClosurePreview({ status: 'denied' });
      return;
    }
    pendingClosurePreview.current?.abort();
    const controller = new AbortController();
    pendingClosurePreview.current = controller;
    setClosurePreview({ status: 'loading' });
    try {
      const result = await previewSyntheticFinanceClosure(branchId, mode, controller.signal);
      if (!controller.signal.aborted) {
        setClosurePreview(result.periods.length === 0
          ? { status: 'empty' } : { status: 'ready', result });
      }
    } catch (error) {
      if (!controller.signal.aborted) {
        setClosurePreview({
          status: error instanceof FinanceClosureAccessDenied ? 'denied' : 'error',
        });
      }
    }
  }


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
    setCreating(false);
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
    <section id="finance-management" className="ui-page catalog-panel" aria-label="Finanzas y contabilidad · laboratorio M07">
      <PageHeader title="Finanzas y contabilidad · laboratorio M07" description="Registros ficticios y cierres de prueba sin efecto financiero." actions={canCreate ? <button type="button" className="button" onClick={() => { setSelected(''); setCreating(true); }}>Nuevo asiento ilustrativo</button> : undefined} />
      <MetricStrip label="Indicadores de Finanzas y contabilidad · laboratorio M07" items={[{ label: 'Registros', value: scoped.length }, { label: 'Resultados', value: visible.length }, { label: 'Eventos de esta sesión', value: events.filter(item => item.scope === scope).length }]} />
      <p role="alert">SOLO DATOS FICTICIOS. Sin valor legal, contable, bancario ni tributario.</p>
      <p className="muted">Registros locales descartables. Sin transferencias, facturas ni operaciones bancarias.</p>
      {notice && <output role="status" aria-live="polite">{notice}</output>}


      <section className="ui-card catalog-card" aria-labelledby="finance-closure-title"
        aria-busy={closurePreview.status === 'loading'}>
        <h3 id="finance-closure-title">Vista previa HTTP de cierre (solo laboratorio)</h3>
        <p>Consulta autenticada por sede. No aprueba, registra ni cierra períodos contables.</p>
        {canReview ? (
          <div>
            <button type="button" disabled={closurePreview.status === 'loading'}
              onClick={() => void inspectClosure('reconciled')}>
              Consultar conciliación ficticia
            </button>
            <button type="button" disabled={closurePreview.status === 'loading'}
              onClick={() => void inspectClosure('empty')}>
              Consultar período vacío ficticio
            </button>
          </div>
        ) : <p role="alert">Permiso denegado para consultar cierres sintéticos.</p>}
        {closurePreview.status === 'loading' && <p role="status">Consultando el servidor…</p>}
        {closurePreview.status === 'empty' && <p role="status">Sin períodos de prueba en la respuesta HTTP.</p>}
        {closurePreview.status === 'denied' && <p role="alert">Acceso denegado por el servidor o token inválido.</p>}
        {closurePreview.status === 'error' && <p role="alert">No fue posible consultar el preview HTTP. Intenta de nuevo.</p>}
        {closurePreview.status === 'ready' && (
          <div role="status" aria-live="polite">
            <p>{closurePreview.result.matched_candidate_count} coincidencia(s) ficticia(s).
              Ningún cierre habilitado.</p>
            <ul aria-label="Períodos ficticios consultados">
              {closurePreview.result.periods.map(period => (
                <li key={period.period_ref}>{period.period_ref} ·
                  {period.review_candidate ? ' candidato a revisión' : ' sin conciliación suficiente'}.
                  Sin cierre permitido.</li>
              ))}
            </ul>
          </div>
        )}
      </section>

      <div className="ui-grid-2 catalog-grid">
        <SidePanel open={creating && canCreate} title="Nuevo asiento ilustrativo" onClose={() => setCreating(false)}>
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
        
        </SidePanel>

        <section className="ui-card catalog-card" aria-labelledby="finance-register-title">
          <h3 id="finance-register-title">Libro de muestra, aislado por empresa</h3>
          <Toolbar trailing={<span className="ui-count">{visible.length} de {scoped.length}</span>}>
          
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
          
          </Toolbar>
          <p role="status">{visible.length} registro(s) ficticio(s) en el ámbito actual.</p>
          <DataTable caption="Listado de Finanzas y contabilidad · laboratorio M07" rows={visible} rowKey={row => row.reference} onOpen={row => view(row.reference)} empty="Sin registros ficticios para estos filtros." columns={[{ key: 'ref', header: 'Referencia', render: row => <span className="ui-cell-main"><strong>{row.reference}</strong></span> }, { key: 'kind', header: 'Clase', render: row => row.kind }, { key: 'amount', header: 'COP ilustrativo', render: row => row.amountFixture, align: 'right' as const }, { key: 'state', header: 'Estado', render: row => <StatusPill tone={row.state === 'EN_REVISION' ? 'info' : row.state === 'REQUIERE_REVISION' ? 'warn' : 'neutral'}>{row.state}</StatusPill> }]} />
        </section>

        <SidePanel open={Boolean(current) && !creating} title="Inspección y control manual" onClose={() => setSelected('')}>
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
        
        </SidePanel>

        <aside className="ui-card catalog-card" aria-labelledby="finance-trace-title">
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
