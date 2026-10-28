/// <reference types="vite/client" />
import { useState, type FormEvent } from 'react';

/** M05: simulación local de Seguridad social; no administra personas reales. */
type SocialState = 'BORRADOR' | 'REQUIERE_REVISION' | 'EN_REVISION';
type SocialKind = 'AFILIACION' | 'NOVEDAD' | 'CAMBIO_FICTICIO';
type SocialRecord = Readonly<{
  reference: string;
  employeeRef: string;
  planRef: string;
  kind: SocialKind;
  state: SocialState;
  tenantId: string;
  companyId: string;
  branchId: string;
}>;
type AuditEvent = Readonly<{ scope: string; reference: string; action: 'created' | 'viewed' | 'review_requested' | 'review_started' }>;
type Props = Readonly<{ tenantId: string; companyId: string; branchId: string; permissions: string[] }>;

const recordPattern = /^fixture:affiliation\/[a-z][a-z0-9_-]{0,47}$/;
const employeePattern = /^fixture:employee\/[a-z][a-z0-9_-]{0,47}$/;
const planPattern = /^fixture:plan\/[a-z][a-z0-9_-]{0,47}$/;
const kinds: SocialKind[] = ['AFILIACION', 'NOVEDAD', 'CAMBIO_FICTICIO'];
const states: SocialState[] = ['BORRADOR', 'REQUIERE_REVISION', 'EN_REVISION'];

function isFixture(value: string, pattern: RegExp): boolean {
  return pattern.test(value) && !/[0-9]{7,}/.test(value);
}

function Workspace({ tenantId, companyId, branchId, permissions }: Props) {
  const scope = tenantId + '|' + companyId + '|' + branchId;
  const canView = companyId !== '__unassigned__' && permissions.includes('users.view');
  const canCreate = canView && permissions.includes('users.create');
  const canReview = canView && permissions.includes('users.update');
  const [records, setRecords] = useState<SocialRecord[]>([]);
  const [events, setEvents] = useState<AuditEvent[]>([]);
  const [reference, setReference] = useState('');
  const [employeeRef, setEmployeeRef] = useState('');
  const [planRef, setPlanRef] = useState('');
  const [kind, setKind] = useState<SocialKind>('AFILIACION');
  const [selected, setSelected] = useState('');
  const [search, setSearch] = useState('');
  const [kindFilter, setKindFilter] = useState<'TODOS' | SocialKind>('TODOS');
  const [stateFilter, setStateFilter] = useState<'TODOS' | SocialState>('TODOS');
  const [notice, setNotice] = useState('');

  function audit(item: string, action: AuditEvent['action']) {
    setEvents(previous => [...previous, { scope, reference: item, action }].slice(-100));
  }

  function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || !isFixture(reference, recordPattern)
      || !isFixture(employeeRef, employeePattern)
      || !isFixture(planRef, planPattern)
      || !kinds.includes(kind) || records.length >= 100
      || records.some(row => row.reference === reference && row.tenantId === tenantId
        && row.companyId === companyId && row.branchId === branchId)) {
      setNotice('Rechazado: verifica permisos, duplicados y referencias de demostración.');
      return;
    }
    setRecords(previous => [...previous, {
      reference, employeeRef, planRef, kind, state: 'BORRADOR',
      tenantId, companyId, branchId,
    }]);
    audit(reference, 'created');
    setSelected(reference);
    setReference('');
    setEmployeeRef('');
    setPlanRef('');
    setNotice('Borrador de prueba creado solo en memoria. Sin valor legal.');
  }

  function view(ref: string) {
    if (!canView || !isFixture(ref, recordPattern) || !records.some(row =>
      row.reference === ref && row.tenantId === tenantId
      && row.companyId === companyId && row.branchId === branchId)) return;
    setSelected(ref);
    audit(ref, 'viewed');
  }

  function changeState(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const current = records.find(row => row.reference === selected
      && row.tenantId === tenantId && row.companyId === companyId && row.branchId === branchId);
    if (!canReview || !current || !isFixture(selected, recordPattern)
      || current.state === 'EN_REVISION') {
      setNotice('No se cambió el estado: falta permiso o transición manual válida.');
      return;
    }
    const next: SocialState = current.state === 'BORRADOR' ? 'REQUIERE_REVISION' : 'EN_REVISION';
    setRecords(previous => previous.map(row =>
      row.reference === selected && row.tenantId === tenantId
      && row.companyId === companyId && row.branchId === branchId
        ? { ...row, state: next } : row));
    audit(selected, current.state === 'BORRADOR' ? 'review_requested' : 'review_started');
    setNotice('Se registró una revisión ficticia. No hay aprobación ni cierre automáticos.');
  }

  if (!canView) return (
    <section id="social-security-management" aria-label="Talento humano sintético">
      <p role="alert">No tienes permiso para la demostración o no hay empresa activa.</p>
    </section>
  );

  const scopeRecords = records.filter(row => row.tenantId === tenantId
    && row.companyId === companyId && row.branchId === branchId);
  const visible = scopeRecords.filter(row =>
    (kindFilter === 'TODOS' || row.kind === kindFilter)
    && (stateFilter === 'TODOS' || row.state === stateFilter)
    && (search.trim() === '' || row.reference.includes(search.trim().toLowerCase())));
  const current = scopeRecords.find(row => row.reference === selected);

  return (
    <section id="social-security-management" className="catalog-panel" aria-labelledby="social-security-title">
      <header>
        <h2 id="social-security-title">Seguridad social · simulación M05</h2>
        <p role="alert">SOLO DATOS FICTICIOS. Sin valor legal. No ingresar nombres ni documentos reales.</p>
        <p className="muted">Operaciones locales de demostración. Sin conexión, almacenamiento ni trámites oficiales.</p>
      </header>
      {notice && <output role="status" aria-live="polite">{notice}</output>}
      <div className="catalog-grid">
        <section className="catalog-card" aria-labelledby="social-security-list-title">
          <h3 id="social-security-list-title">Registros ficticios</h3>
          <label>Buscar referencia
            <input aria-label="Buscar registro ficticio" maxLength={80} value={search}
              onChange={event => setSearch(event.target.value)} />
          </label>
          <label>Tipo de novedad
            <select aria-label="Filtrar tipo" value={kindFilter}
              onChange={event => setKindFilter(event.target.value as typeof kindFilter)}>
              <option value="TODOS">Todos</option>
              {kinds.map(value => <option value={value} key={value}>{value}</option>)}
            </select>
          </label>
          <label>Estado
            <select aria-label="Filtrar estado" value={stateFilter}
              onChange={event => setStateFilter(event.target.value as typeof stateFilter)}>
              <option value="TODOS">Todos</option>
              {states.map(value => <option value={value} key={value}>{value}</option>)}
            </select>
          </label>
          {visible.length === 0 ? <p role="status">No hay registros ficticios para el filtro.</p> : (
            <ul>{visible.map(row => <li key={row.reference}>
              <button type="button" onClick={() => view(row.reference)}>
                {row.reference} · {row.kind} · {row.state}
              </button>
            </li>)}</ul>
          )}
        </section>
        {canCreate && (
          <section className="catalog-card" aria-labelledby="social-security-create-title">
            <h3 id="social-security-create-title">Crear registro de prueba</h3>
            <form className="catalog-editor" onSubmit={create}>
              <label>Referencia ficticia
                <input required maxLength={64} pattern="fixture:affiliation/[a-z][a-z0-9_-]{0,47}"
                  placeholder="fixture:affiliation/demo" value={reference}
                  onChange={event => setReference(event.target.value)} />
              </label>
              <label>Colaborador ficticio
                <input required maxLength={64} pattern="fixture:employee/[a-z][a-z0-9_-]{0,47}"
                  placeholder="fixture:employee/demo" value={employeeRef}
                  onChange={event => setEmployeeRef(event.target.value)} />
              </label>
              <label>Plan ficticio
                <input required maxLength={64} pattern="fixture:plan/[a-z][a-z0-9_-]{0,47}"
                  placeholder="fixture:plan/demo" value={planRef}
                  onChange={event => setPlanRef(event.target.value)} />
              </label>
              <label>Tipo de registro
                <select value={kind} onChange={event => setKind(event.target.value as SocialKind)}>
                  {kinds.map(value => <option value={value} key={value}>{value}</option>)}
                </select>
              </label>
              <button type="submit">Crear borrador ficticio</button>
            </form>
          </section>
        )}
        <section className="catalog-card" aria-labelledby="social-security-detail-title">
          <h3 id="social-security-detail-title">Detalle y revisión manual</h3>
          {current ? <>
            <dl>
              <dt>Referencia</dt><dd>{current.reference}</dd>
              <dt>Colaborador</dt><dd>{current.employeeRef}</dd>
              <dt>Plan</dt><dd>{current.planRef}</dd>
              <dt>Novedad</dt><dd>{current.kind}</dd>
              <dt>Estado</dt><dd>{current.state}</dd>
            </dl>
            {canReview && current.state !== 'EN_REVISION' && (
              <form className="catalog-editor" onSubmit={changeState}>
                <button type="submit">{current.state === 'BORRADOR'
                  ? 'Solicitar revisión ficticia' : 'Iniciar revisión ficticia'}</button>
              </form>
            )}
            <p className="muted">Ninguna afiliación ni novedad se aprueba, paga o cierra automáticamente.</p>
          </> : <p role="status">Selecciona un registro ficticio para ver el detalle.</p>}
        </section>
        <section className="catalog-card" aria-labelledby="social-security-audit-title">
          <h3 id="social-security-audit-title">Bitácora de demostración (no oficial)</h3>
          <p className="muted">Las operaciones reales requieren permisos y auditoría de servidor.</p>
          {events.filter(row => row.scope === scope).length === 0
            ? <p role="status">Sin operaciones de prueba.</p>
            : <ul>{events.filter(row => row.scope === scope).map((row, index) =>
              <li key={index}>{row.action} · {row.reference}</li>)}</ul>}
        </section>
      </div>
    </section>
  );
}

/** Exclusivo de Vite DEV: no montar ni ejecutar contra clientes reales. */
export function SocialSecurityManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <Workspace key={props.tenantId + '|' + props.companyId + '|' + props.branchId} {...props} />;
}
