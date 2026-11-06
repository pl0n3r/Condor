/// <reference types="vite/client" />
import { useState, type FormEvent } from 'react';
import { Card, DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';

/**
 * M04 Nómina: demostración exclusivamente sintética en memoria local.
 * No calcula obligaciones legales, no ejecuta pagos ni tiene auditoría oficial.
 */
type PayrollState = 'BORRADOR' | 'PENDIENTE_REVISION' | 'REVISION_EN_CURSO';
type PayrollRecord = Readonly<{
  reference: string;
  tenantId: string;
  companyId: string;
  branchId: string;
  periodRef: string;
  employeeRef: string;
  conceptRef: string;
  state: PayrollState;
}>;
type AuditEvent = Readonly<{
  scope: string;
  reference: string;
  operation: 'created' | 'viewed' | 'review_requested' | 'review_started';
}>;
type Props = Readonly<{
  tenantId: string;
  companyId: string;
  branchId: string;
  permissions: string[];
}>;

const payrollRefPattern = /^fixture:payroll\/[a-z][a-z0-9_-]{0,47}$/;
const periodRefPattern = /^fixture:period\/[a-z][a-z0-9_-]{0,47}$/;
const employeeRefPattern = /^fixture:employee\/[a-z][a-z0-9_-]{0,47}$/;
const conceptRefPattern = /^fixture:concept\/[a-z][a-z0-9_-]{0,47}$/;
const states: PayrollState[] = ['BORRADOR', 'PENDIENTE_REVISION', 'REVISION_EN_CURSO'];

function Workspace({ tenantId, companyId, branchId, permissions }: Props) {
  const hasCompany = companyId !== '__unassigned__';
  const canView = hasCompany && permissions.includes('payroll.view');
  const canCreate = canView && permissions.includes('payroll.create');
  const canReview = canView && permissions.includes('payroll.review');
  const scope = tenantId + '|' + companyId + '|' + branchId;
  const [records, setRecords] = useState<PayrollRecord[]>([]);
  const [events, setEvents] = useState<AuditEvent[]>([]);
  const [reference, setReference] = useState('');
  const [periodRef, setPeriodRef] = useState('');
  const [employeeRef, setEmployeeRef] = useState('');
  const [conceptRef, setConceptRef] = useState('');
  const [selected, setSelected] = useState('');
  const [panel, setPanel] = useState<'create' | 'detail' | null>(null);
  const [search, setSearch] = useState('');
  const [stateFilter, setStateFilter] = useState<'TODOS' | PayrollState>('TODOS');
  const [nextAction, setNextAction] = useState<'SOLICITAR_REVISION' | 'INICIAR_REVISION'>('SOLICITAR_REVISION');
  const [notice, setNotice] = useState('');

  function audit(item: string, operation: AuditEvent['operation']) {
    setEvents(previous => [...previous, { scope, reference: item, operation }].slice(-100));
  }

  function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate
      || !payrollRefPattern.test(reference)
      || !periodRefPattern.test(periodRef)
      || !employeeRefPattern.test(employeeRef)
      || !conceptRefPattern.test(conceptRef)
      || records.length >= 100
      || records.some(row => row.reference === reference
        && row.tenantId === tenantId && row.companyId === companyId && row.branchId === branchId)) {
      setNotice('No se guardó: permiso insuficiente, duplicado o referencia no ficticia. Usa fixture:.../demo.');
      return;
    }

    const record: PayrollRecord = {
      reference, tenantId, companyId, branchId, periodRef, employeeRef, conceptRef,
      state: 'BORRADOR',
    };
    setRecords(previous => [...previous, record]);
    audit(reference, 'created');
    setSelected(reference); setPanel('detail');
    setReference('');
    setPeriodRef('');
    setEmployeeRef('');
    setConceptRef('');
    setNotice('Borrador de nómina ficticio creado solo en memoria. Sin efecto legal.');
  }

  function view(item: string) {
    if (!canView || !payrollRefPattern.test(item)
      || !records.some(row => row.reference === item
        && row.tenantId === tenantId && row.companyId === companyId && row.branchId === branchId)) {
      return;
    }
    setSelected(item); setPanel('detail');
    audit(item, 'viewed');
  }

  function changeState(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const record = records.find(row => row.reference === selected
      && row.tenantId === tenantId && row.companyId === companyId && row.branchId === branchId);
    const expected = nextAction === 'SOLICITAR_REVISION' ? 'BORRADOR' : 'PENDIENTE_REVISION';
    if (!canReview || !payrollRefPattern.test(selected) || !record || record.state !== expected) {
      setNotice('No se cambió el estado: falta permiso, selección o transición válida.');
      return;
    }
    const next: PayrollState = nextAction === 'SOLICITAR_REVISION'
      ? 'PENDIENTE_REVISION' : 'REVISION_EN_CURSO';
    setRecords(previous => previous.map(row => row.reference === selected
      && row.tenantId === tenantId && row.companyId === companyId && row.branchId === branchId
      ? { ...row, state: next } : row));
    audit(selected, nextAction === 'SOLICITAR_REVISION' ? 'review_requested' : 'review_started');
    setNotice('Transición ficticia explícita registrada. No se aprueba, paga ni cierra nada.');
  }

  if (!canView) return <section id="payroll-management" className="catalog-panel" aria-label="Nómina sintética">
    <PageHeader title="Nómina · simulador de empresa" />
    <p role="alert">Acceso denegado: no hay permiso de nómina o razón social activa.</p>
  </section>;

  const scoped = records.filter(row => row.tenantId === tenantId
    && row.companyId === companyId && row.branchId === branchId);
  const visible = scoped.filter(row =>
    (stateFilter === 'TODOS' || row.state === stateFilter)
    && (search === '' || row.reference.includes(search.toLowerCase().trim())));
  const current = scoped.find(row => row.reference === selected);

  return <section id="payroll-management" className="catalog-panel" aria-label="Nómina sintética">
    <PageHeader title="Nómina · simulador de empresa"
      description="Operación exclusivamente en memoria, disponible solo en construcción."
      actions={canCreate ? <button type="button" onClick={() => setPanel('create')}>
        Crear borrador sintético
      </button> : undefined} />
    <p role="alert">
      SOLO DATOS FICTICIOS. Sin valor legal, tributario ni laboral.
      No representa liquidación, pago, desprendible oficial ni auditoría de servidor.
    </p>
    {notice && <output role="status" aria-live="polite">{notice}</output>}
    <MetricStrip label="Indicadores de nómina sintética" items={[
      { label: 'Periodos y novedades', value: scoped.length },
      { label: 'Borradores', value: scoped.filter(row => row.state === 'BORRADOR').length },
      { label: 'En revisión', value: scoped.filter(row => row.state !== 'BORRADOR').length },
    ]} />
    <Card title="Periodos y novedades de demostración">
      <Toolbar trailing={<output aria-live="polite">{visible.length} resultados</output>}>
        <label>Filtrar por referencia
          <input type="search" aria-label="Filtrar nóminas ficticias" value={search}
            onChange={event => setSearch(event.target.value)} />
        </label>
        <label>Estado
          <select aria-label="Filtrar por estado" value={stateFilter}
            onChange={event => setStateFilter(event.target.value as typeof stateFilter)}>
            <option value="TODOS">Todos</option>
            {states.map(state => <option key={state} value={state}>{state}</option>)}
          </select>
        </label>
      </Toolbar>
      <DataTable<PayrollRecord> caption="Nóminas de demostración por empresa y sede"
        rows={visible} rowKey={row => row.reference}
        onOpen={row => view(row.reference)}
        columns={[
          { key: 'reference', header: 'Referencia', render: row => row.reference },
          { key: 'period', header: 'Periodo', render: row => row.periodRef },
          { key: 'employee', header: 'Colaborador', render: row => row.employeeRef },
          { key: 'state', header: 'Estado', render: row =>
            <StatusPill tone={row.state === 'BORRADOR' ? 'neutral'
              : row.state === 'PENDIENTE_REVISION' ? 'warn' : 'info'}>{row.state}</StatusPill> },
        ]}
        empty={<p role="status">Sin nóminas ficticias para este filtro.</p>} />
    </Card>
    <Card title="Bitácora de demostración (no oficial)">
      <p className="muted">Los permisos y la auditoría de servidor son obligatorios para una implementación real.</p>
      {events.filter(row => row.scope === scope).length === 0
        ? <p role="status">Sin operaciones de prueba.</p>
        : <ul>{events.filter(row => row.scope === scope).map((row, index) => (
          <li key={index}>{row.operation} · {row.reference}</li>
        ))}</ul>}
    </Card>
    <SidePanel open={panel === 'create' && canCreate} title="Crear borrador sintético"
      onClose={() => setPanel(null)}>
      <form className="catalog-editor" onSubmit={create}>
        <label>Referencia ficticia
          <input required maxLength={64} pattern="fixture:payroll/[a-z][a-z0-9_-]{0,47}"
            placeholder="fixture:payroll/demo" value={reference}
            onChange={event => setReference(event.target.value)} />
        </label>
        <label>Periodo ficticio
          <input required maxLength={64} pattern="fixture:period/[a-z][a-z0-9_-]{0,47}"
            placeholder="fixture:period/demo" value={periodRef}
            onChange={event => setPeriodRef(event.target.value)} />
        </label>
        <label>Colaborador ficticio (sin nombres ni identificaciones)
          <input required maxLength={64} pattern="fixture:employee/[a-z][a-z0-9_-]{0,47}"
            placeholder="fixture:employee/demo" value={employeeRef}
            onChange={event => setEmployeeRef(event.target.value)} />
        </label>
        <label>Concepto ficticio (sin importes reales)
          <input required maxLength={64} pattern="fixture:concept/[a-z][a-z0-9_-]{0,47}"
            placeholder="fixture:concept/demo" value={conceptRef}
            onChange={event => setConceptRef(event.target.value)} />
        </label>
        <button type="submit">Crear borrador ficticio</button>
      </form>
    </SidePanel>
    <SidePanel open={panel === 'detail' && !!current} title="Detalle y revisión de prueba"
      onClose={() => setPanel(null)}>
      {current ? <>
        <dl>
          <dt>Referencia</dt><dd>{current.reference}</dd>
          <dt>Periodo</dt><dd>{current.periodRef}</dd>
          <dt>Colaborador</dt><dd>{current.employeeRef}</dd>
          <dt>Concepto</dt><dd>{current.conceptRef}</dd>
          <dt>Estado</dt><dd>
            <StatusPill tone={current.state === 'BORRADOR' ? 'neutral' : 'info'}>{current.state}</StatusPill>
          </dd>
        </dl>
        {canReview && current.state !== 'REVISION_EN_CURSO' && (
          <form className="catalog-editor" onSubmit={changeState}>
            <label>Acción explícita de prueba
              <select aria-label="Acción de revisión" value={nextAction}
                onChange={event => setNextAction(event.target.value as typeof nextAction)}>
                <option value="SOLICITAR_REVISION">Solicitar revisión</option>
                <option value="INICIAR_REVISION">Iniciar revisión pendiente</option>
              </select>
            </label>
            <button type="submit">Registrar transición ficticia explícita</button>
          </form>
        )}
        <p className="muted">Ningún estado se aprueba, liquida, paga o cierra automáticamente.</p>
      </> : <p role="status">Selecciona una nómina ficticia para consultar su detalle.</p>}
    </SidePanel>
  </section>;
}

/** El import no activa la pantalla en un bundle de producción. */
export function PayrollManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <Workspace key={props.tenantId + '|' + props.companyId + '|' + props.branchId}
    {...props} />;
}
