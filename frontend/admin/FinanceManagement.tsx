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
  const [panelError, setPanelError] = useState('');

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
      setPanelError('No se guardó: permiso insuficiente, duplicado o referencias no ficticias. Usa fixture:.../demo.');
      return;
    }
    setRecords(previous => [...previous, {
      reference, tenantId, companyId, branchId, periodRef, conceptRef, kind,
      amountFixture, state: 'BORRADOR',
    }]);
    audit(reference, 'created');
    setSelected(reference);
    setCreating(false);
    setPanelError('');
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
    setPanelError('');
    audit(ref, 'viewed');
  }

  function changeState(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const current = records.find(row => row.reference === selected
      && row.tenantId === tenantId && row.companyId === companyId && row.branchId === branchId);
    if (!canReview || !current || !isDemoReference(selected, financeRefPattern) || current.state === 'EN_REVISION') {
      setNotice('No se cambió el estado: permiso insuficiente o transición inválida.');
      setPanelError('No se cambió el estado: permiso insuficiente o transición inválida.');
      return;
    }
    const next: FinanceState = current.state === 'BORRADOR' ? 'REQUIERE_REVISION' : 'EN_REVISION';
    setRecords(previous => previous.map(row => row.reference === selected
      && row.tenantId === tenantId && row.companyId === companyId && row.branchId === branchId
      ? { ...row, state: next } : row));
    audit(selected, current.state === 'BORRADOR' ? 'review_requested' : 'review_started');
    setPanelError('');
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
      <PageHeader title="Finanzas y contabilidad · laboratorio M07" description="Registros ficticios y cierres de prueba sin efecto financiero." actions={canCreate ? <button type="button" className="button" onClick={() => { setSelected(''); setPanelError(''); setCreating(true); }}>Nuevo asiento ilustrativo</button> : undefined} />
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
        <SidePanel open={creating && canCreate} title="Nuevo asiento ilustrativo" onClose={() => { setCreating(false); setPanelError(''); }}>
          {panelError && <output role="alert" aria-live="assertive" className="alert alert-error">{panelError}</output>}
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

        <SidePanel open={Boolean(current) && !creating} title="Inspección y control manual" onClose={() => { setSelected(''); setPanelError(''); }}>
          {panelError && <output role="alert" aria-live="assertive" className="alert alert-error">{panelError}</output>}
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


/** #1659: persistencia financiera por empresa, independiente del laboratorio en memoria. */
export type FinanceRealRecord = Readonly<{
  ref: string; tenantId: string; companyId: string;
  kind: 'ingreso' | 'egreso' | 'ajuste' | 'presupuesto';
  state: 'PENDIENTE' | 'REQUIERE_REVISION'; version: number;
}>;
export type FinanceRealOptions = Readonly<{
  enabled: boolean; allowed: boolean; tenantId: string; companyId: string; branchId: string;
  method?: 'GET' | 'POST' | 'PATCH';
  ref?: string; kind?: FinanceRealRecord['kind'];
  state?: FinanceRealRecord['state']; expectedVersion?: number; csrf?: string;
}>;
export type FinanceRealResult =
  | { status: 'disabled' | 'denied' | 'error' }
  | { status: 'ready'; records: FinanceRealRecord[] }
  | { status: 'ready'; record: FinanceRealRecord };

const realFinanceRef = /^[a-z][a-z0-9_-]{0,63}$/;
const realFinanceKinds: FinanceRealRecord['kind'][] = ['ingreso', 'egreso', 'ajuste', 'presupuesto'];
const realFinanceNames: Record<FinanceRealRecord['kind'], string> = {
  ingreso: 'Ingreso', egreso: 'Egreso', ajuste: 'Ajuste', presupuesto: 'Presupuesto',
};
function financeOpaque(ref: string): boolean {
  return realFinanceRef.test(ref) && !/[0-9]{7,}/.test(ref);
}
function financeRecord(value: unknown, tenant: string, company: string): FinanceRealRecord | null {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
  const row = value as Record<string, unknown>;
  if (row.tenant_id !== tenant || row.company_id !== company
    || typeof row.entry_ref !== 'string' || !financeOpaque(row.entry_ref)
    || typeof row.kind !== 'string'
    || !realFinanceKinds.includes(row.kind as FinanceRealRecord['kind'])
    || (row.state !== 'PENDIENTE' && row.state !== 'REQUIERE_REVISION')
    || !Number.isInteger(row.version) || (row.version as number) < 1
    || (row.version as number) > 1_000_000) return null;
  return { ref: row.entry_ref, tenantId: tenant, companyId: company,
    kind: row.kind as FinanceRealRecord['kind'], state: row.state, version: row.version as number };
}

/** OFF y permisos insuficientes impiden transporte, incluso con fetch sustituido. */
export async function financeRealRequest(
  options: FinanceRealOptions, transport: typeof fetch = fetch,
): Promise<FinanceRealResult> {
  if (!options.enabled) return { status: 'disabled' };
  if (!options.allowed || !options.tenantId || !options.branchId
    || !options.companyId || options.companyId === '__unassigned__') return { status: 'denied' };
  const method = options.method ?? 'GET';
  if (!['GET', 'POST', 'PATCH'].includes(method)
    || (options.ref !== undefined && !financeOpaque(options.ref))) return { status: 'error' };
  if (method === 'POST' && (!options.csrf || !options.ref || !options.kind
    || !realFinanceKinds.includes(options.kind))) return { status: 'error' };
  if (method === 'PATCH' && (!options.csrf || !options.ref
    || (options.state !== 'PENDIENTE' && options.state !== 'REQUIERE_REVISION')
    || !Number.isInteger(options.expectedVersion) || (options.expectedVersion ?? 0) < 1)) {
    return { status: 'error' };
  }
  const root = '/api/v1/branches/' + encodeURIComponent(options.branchId) + '/finance/real-records';
  const url = options.ref && method !== 'POST' ? root + '/' + encodeURIComponent(options.ref)
    + (method === 'PATCH' ? '/state' : '') : root;
  const payload = method === 'POST'
    ? { entry_ref: options.ref, kind: options.kind }
    : { state: options.state, expected_version: options.expectedVersion };
  try {
    const reply = await transport(url, {
      method, credentials: 'same-origin', cache: 'no-store',
      headers: { Accept: 'application/json', ...(method === 'GET' ? {}
        : { 'Content-Type': 'application/json', 'X-CSRF-Token': options.csrf ?? '' }) },
      ...(method === 'GET' ? {} : { body: JSON.stringify(payload) }),
    });
    if (reply.status === 401 || reply.status === 403) return { status: 'denied' };
    if (reply.status === 404 && method === 'GET' && options.ref === undefined) return { status: 'disabled' };
    if (!reply.ok) return { status: 'error' };
    const body: unknown = await reply.json();
    if (!body || typeof body !== 'object' || Array.isArray(body)) return { status: 'error' };
    const data = body as Record<string, unknown>;
    if (method === 'GET' && options.ref === undefined) {
      if (!Array.isArray(data.records) || data.records.length > 100) return { status: 'error' };
      const mapped = data.records.map(row => financeRecord(row, options.tenantId, options.companyId));
      if (mapped.some(row => row === null)) return { status: 'error' };
      const records = mapped as FinanceRealRecord[];
      if (new Set(records.map(row => row.ref)).size !== records.length) return { status: 'error' };
      return { status: 'ready', records };
    }
    const record = financeRecord(data.record, options.tenantId, options.companyId);
    if (!record || (options.ref && record.ref !== options.ref)) return { status: 'error' };
    return { status: 'ready', record };
  } catch { return { status: 'error' }; }
}

type FinanceRealProps = Props & Readonly<{ enabled: boolean; csrfToken: string }>;
export function FinanceRealManagement({
  enabled, tenantId, companyId, branchId, permissions, csrfToken,
}: FinanceRealProps) {
  const canRead = permissions.includes('settings.view') && companyId !== '__unassigned__';
  const canCreate = canRead && permissions.includes('settings.create');
  const canUpdate = canRead && permissions.includes('settings.update');
  const [status, setStatus] = useState<'loading' | 'ready' | 'denied' | 'disabled' | 'error'>('loading');
  const [rows, setRows] = useState<FinanceRealRecord[]>([]);
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [panel, setPanel] = useState<'create' | 'detail' | null>(null);
  const [selected, setSelected] = useState('');
  const [searchReal, setSearchReal] = useState('');
  const [stateFilterReal, setStateFilterReal] = useState<'TODOS' | FinanceRealRecord['state']>('TODOS');
  const [newRef, setNewRef] = useState('');
  const [newKind, setNewKind] = useState<FinanceRealRecord['kind']>('ingreso');

  useEffect(() => {
    if (!enabled || !canRead) return;
    let active = true;
    setStatus('loading');
    setRows([]);
    setPanel(null);
    void financeRealRequest({ enabled, allowed: canRead, tenantId, companyId, branchId })
      .then(result => {
        if (!active) return;
        if (result.status === 'ready' && 'records' in result) {
          setRows(result.records);
          setStatus('ready');
        } else setStatus(result.status === 'ready' ? 'error' : result.status);
      });
    return () => { active = false; };
  }, [enabled, canRead, tenantId, companyId, branchId]);

  async function addRecord(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy || status !== 'ready') return;
    setBusy(true);
    const result = await financeRealRequest({
      enabled, allowed: canCreate, tenantId, companyId, branchId,
      method: 'POST', ref: newRef, kind: newKind, csrf: csrfToken,
    });
    setBusy(false);
    if (result.status === 'ready' && 'record' in result) {
      setRows(previous => [...previous, result.record]);
      setSelected(result.record.ref);
      setPanel('detail');
      setNewRef('');
      setNotice('Registro guardado para revisión. Sin contabilización, cierre ni pago.');
    } else {
      if (result.status === 'denied') setStatus('denied');
      if (result.status === 'disabled') setStatus('disabled');
      setNotice('No se pudo crear. Verifica ámbito, permisos y referencia.');
    }
  }

  async function changeRealState(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const current = rows.find(row => row.ref === selected);
    if (!current || busy || status !== 'ready') return;
    const next = current.state === 'PENDIENTE' ? 'REQUIERE_REVISION' : 'PENDIENTE';
    setBusy(true);
    const result = await financeRealRequest({
      enabled, allowed: canUpdate, tenantId, companyId, branchId,
      method: 'PATCH', ref: current.ref, state: next,
      expectedVersion: current.version, csrf: csrfToken,
    });
    setBusy(false);
    if (result.status === 'ready' && 'record' in result) {
      setRows(previous => previous.map(row => row.ref === result.record.ref ? result.record : row));
      setNotice('Cambio versionado y auditado por el servidor.');
    } else {
      if (result.status === 'denied') setStatus('denied');
      if (result.status === 'disabled') setStatus('disabled');
      setNotice('No se pudo actualizar. Recarga los registros antes de repetir.');
    }
  }

  const shell = (message: string, role: 'status' | 'alert') => (
    <section id="finance-management" className="catalog-panel ui-page" aria-label="Finanzas">
      <PageHeader title="Finanzas" />
      <p role={role}>{message}</p>
    </section>
  );
  if (!enabled) return shell('Finanzas aún no está habilitado. No se consultaron datos.', 'status');
  if (!canRead || status === 'denied') return shell('Acceso denegado: falta empresa, razón social o permiso.', 'alert');
  if (status === 'disabled') return shell('El servidor no tiene habilitada Finanzas.', 'alert');

  const visible = rows.filter(row => (stateFilterReal === 'TODOS' || row.state === stateFilterReal)
    && (row.ref.toLowerCase().includes(searchReal.trim().toLowerCase())
      || realFinanceNames[row.kind].toLowerCase().includes(searchReal.trim().toLowerCase())));
  const picked = rows.find(row => row.ref === selected);
  return <section id="finance-management" className="catalog-panel ui-page" aria-label="Finanzas">
    <PageHeader title="Finanzas" description="Registros internos de revisión por empresa y razón social."
      actions={canCreate ? <button type="button" onClick={() => setPanel('create')}>Nuevo registro</button>
        : undefined} />
    <p className="muted">Solo referencias opacas. Sin movimientos bancarios, pagos ni cierre contable automático.</p>
    {notice && <output role="status" aria-live="polite">{notice}</output>}
    {status === 'loading' && <p role="status">Cargando registros financieros…</p>}
    {status === 'error' && <p role="alert">No se pudieron cargar los registros. Comprueba la conexión.</p>}
    {status === 'ready' && <>
      <MetricStrip label="Resumen financiero" items={[
        { label: 'Registros', value: rows.length },
        { label: 'Pendientes', value: rows.filter(row => row.state === 'PENDIENTE').length },
        { label: 'En revisión', value: rows.filter(row => row.state === 'REQUIERE_REVISION').length },
      ]} />
      <section className="ui-card catalog-card" aria-label="Listado de registros financieros">
        <Toolbar trailing={<output aria-live="polite">{visible.length} resultados</output>}>
          <label>Buscar referencia <input type="search" value={searchReal}
            onChange={event => setSearchReal(event.target.value)} /></label>
          <label>Estado <select value={stateFilterReal}
            onChange={event => setStateFilterReal(event.target.value as typeof stateFilterReal)}>
            <option value="TODOS">Todos</option>
            <option value="PENDIENTE">Pendiente</option>
            <option value="REQUIERE_REVISION">Requiere revisión</option>
          </select></label>
        </Toolbar>
        <DataTable<FinanceRealRecord> caption="Registros financieros por empresa"
          rows={visible} rowKey={row => row.ref}
          onOpen={row => { setSelected(row.ref); setPanel('detail'); }}
          columns={[
            { key: 'ref', header: 'Referencia', render: row => row.ref },
            { key: 'kind', header: 'Clase', render: row => realFinanceNames[row.kind] },
            { key: 'state', header: 'Estado', render: row =>
              <StatusPill tone={row.state === 'PENDIENTE' ? 'neutral' : 'warn'}>
                {row.state === 'PENDIENTE' ? 'Pendiente' : 'Requiere revisión'}
              </StatusPill> },
          ]} empty={<p role="status">No hay registros para este ámbito y filtro.</p>} />
      </section>
    </>}
    <SidePanel open={panel === 'create' && canCreate && status === 'ready'}
      title="Crear registro financiero" onClose={() => setPanel(null)}>
      <form onSubmit={event => void addRecord(event)}>
        <p>Referencia opaca, sin nombres, montos, cuentas ni datos personales.</p>
        <label>Referencia <input required maxLength={64} pattern="[a-z][a-z0-9_\-]{0,63}"
          value={newRef} onChange={event => setNewRef(event.target.value)} /></label>
        <label>Clase <select value={newKind}
          onChange={event => setNewKind(event.target.value as FinanceRealRecord['kind'])}>
          {realFinanceKinds.map(kind => <option key={kind} value={kind}>{realFinanceNames[kind]}</option>)}
        </select></label>
        <button type="submit" disabled={busy}>{busy ? 'Guardando…' : 'Guardar para revisión'}</button>
      </form>
    </SidePanel>
    <SidePanel open={panel === 'detail' && !!picked && status === 'ready'}
      title="Detalle y revisión" onClose={() => setPanel(null)}>
      {picked && <><dl>
        <dt>Referencia</dt><dd>{picked.ref}</dd>
        <dt>Clase</dt><dd>{realFinanceNames[picked.kind]}</dd>
        <dt>Estado</dt><dd>{picked.state === 'PENDIENTE' ? 'Pendiente' : 'Requiere revisión'}</dd>
        <dt>Versión auditada</dt><dd>{picked.version}</dd>
      </dl>
      {canUpdate && <form onSubmit={event => void changeRealState(event)}>
        <button type="submit" disabled={busy}>{busy ? 'Guardando…'
          : picked.state === 'PENDIENTE' ? 'Enviar a revisión' : 'Devolver a pendiente'}</button>
      </form>}
      <p>Los estados no autorizan pagos ni asientos contables efectivos.</p></>}
    </SidePanel>
  </section>;
}
