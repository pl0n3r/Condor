/// <reference types="vite/client" />
import { useState, type FormEvent } from 'react';

/** M15: simulación local de gobierno corporativo; nunca es un libro de actas real. */
type GovernanceState = 'BORRADOR' | 'REQUIERE_REVISION' | 'EN_REVISION';
type GovernanceKind = 'ASAMBLEA' | 'JUNTA' | 'COMITE';
type GovernanceRow = Readonly<{
  reference: string;
  bodyRef: string;
  representativeRef: string;
  kind: GovernanceKind;
  tenantId: string;
  companyId: string;
  branchId: string;
  state: GovernanceState;
}>;
type AuditEvent = Readonly<{ scope: string; reference: string; action: 'created' | 'viewed' | 'review_requested' | 'review_started' }>;
type Props = Readonly<{ tenantId: string; companyId: string; branchId: string; permissions: string[] }>;

const governanceRefPattern = /^fixture:governance\/[a-z][a-z0-9_-]{0,47}$/;
const bodyRefPattern = /^fixture:body\/[a-z][a-z0-9_-]{0,47}$/;
const representativeRefPattern = /^fixture:representative\/[a-z][a-z0-9_-]{0,47}$/;
const kinds: GovernanceKind[] = ['ASAMBLEA', 'JUNTA', 'COMITE'];
const states: GovernanceState[] = ['BORRADOR', 'REQUIERE_REVISION', 'EN_REVISION'];

function isFixture(value: string, pattern: RegExp): boolean {
  return pattern.test(value) && !/[0-9]{7,}/.test(value);
}

function Workspace({ tenantId, companyId, branchId, permissions }: Props) {
  const scope = tenantId + '|' + companyId + '|' + branchId;
  const canView = companyId !== '__unassigned__' && permissions.includes('settings.view');
  const canCreate = canView && permissions.includes('settings.create');
  const canReview = canView && permissions.includes('settings.update');
  const [records, setRecords] = useState<GovernanceRow[]>([]);
  const [events, setEvents] = useState<AuditEvent[]>([]);
  const [reference, setReference] = useState('');
  const [bodyRef, setBodyRef] = useState('');
  const [representativeRef, setRepresentativeRef] = useState('');
  const [kind, setKind] = useState<GovernanceKind>('ASAMBLEA');
  const [selected, setSelected] = useState('');
  const [search, setSearch] = useState('');
  const [kindFilter, setKindFilter] = useState<'TODOS' | GovernanceKind>('TODOS');
  const [stateFilter, setStateFilter] = useState<'TODOS' | GovernanceState>('TODOS');
  const [notice, setNotice] = useState('');

  function audit(item: string, action: AuditEvent['action']) {
    setEvents(previous => [...previous, { scope, reference: item, action }].slice(-100));
  }

  function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || !isFixture(reference, governanceRefPattern)
      || !isFixture(bodyRef, bodyRefPattern)
      || !isFixture(representativeRef, representativeRefPattern)
      || !kinds.includes(kind) || records.length >= 100
      || records.some(row => row.reference === reference && row.tenantId === tenantId
        && row.companyId === companyId && row.branchId === branchId)) {
      setNotice('Rechazado: revisa permisos, referencias ficticias y duplicados.');
      return;
    }
    setRecords(previous => [...previous, {
      reference, bodyRef, representativeRef, kind, tenantId, companyId, branchId,
      state: 'BORRADOR',
    }]);
    audit(reference, 'created');
    setSelected(reference);
    setReference('');
    setBodyRef('');
    setRepresentativeRef('');
    setNotice('Borrador ficticio creado solo en memoria, sin valor jurídico.');
  }

  function view(ref: string) {
    if (!canView || !isFixture(ref, governanceRefPattern) || !records.some(row =>
      row.reference === ref && row.tenantId === tenantId
      && row.companyId === companyId && row.branchId === branchId)) return;
    setSelected(ref);
    audit(ref, 'viewed');
  }

  function changeState(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const current = records.find(row => row.reference === selected
      && row.tenantId === tenantId && row.companyId === companyId && row.branchId === branchId);
    if (!canReview || !current || !isFixture(selected, governanceRefPattern)
      || current.state === 'EN_REVISION') {
      setNotice('No se cambió el estado: falta permiso o la transición no aplica.');
      return;
    }
    const next: GovernanceState = current.state === 'BORRADOR' ? 'REQUIERE_REVISION' : 'EN_REVISION';
    setRecords(previous => previous.map(row =>
      row.reference === selected && row.tenantId === tenantId
      && row.companyId === companyId && row.branchId === branchId
        ? { ...row, state: next } : row));
    audit(selected, current.state === 'BORRADOR' ? 'review_requested' : 'review_started');
    setNotice('Transición ficticia explícita; no se aprobó ni cerró ninguna actuación.');
  }

  if (!canView) return (
    <section id="governance-management" aria-label="Gobierno corporativo sintético">
      <p role="alert">No tienes acceso a esta simulación o no hay razón social activa.</p>
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
    <section id="governance-management" className="catalog-panel" aria-labelledby="governance-title">
      <header>
        <h2 id="governance-title">Gobierno corporativo · simulación M15</h2>
        <p role="alert">SOLO DATOS FICTICIOS. Sin valor jurídico ni facultades reales.</p>
        <p className="muted">Órganos, representantes y decisiones de prueba, solo en memoria.</p>
      </header>
      {notice && <output role="status" aria-live="polite">{notice}</output>}
      <div className="catalog-grid">
        <section className="catalog-card" aria-labelledby="governance-list-title">
          <h3 id="governance-list-title">Actuaciones ficticias</h3>
          <label>Buscar referencia
            <input aria-label="Buscar actuación ficticia" value={search} maxLength={80}
              onChange={event => setSearch(event.target.value)} />
          </label>
          <label>Tipo de órgano
            <select aria-label="Filtrar por órgano" value={kindFilter}
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
          {visible.length === 0 ? <p role="status">Sin actuaciones ficticias para el filtro.</p> : (
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
          <section className="catalog-card" aria-labelledby="governance-create-title">
            <h3 id="governance-create-title">Crear actuación de prueba</h3>
            <form className="catalog-editor" onSubmit={create}>
              <label>Referencia ficticia
                <input required maxLength={64} pattern="fixture:governance/[a-z][a-z0-9_-]{0,47}"
                  placeholder="fixture:governance/demo" value={reference}
                  onChange={event => setReference(event.target.value)} />
              </label>
              <label>Órgano ficticio
                <input required maxLength={64} pattern="fixture:body/[a-z][a-z0-9_-]{0,47}"
                  placeholder="fixture:body/demo" value={bodyRef}
                  onChange={event => setBodyRef(event.target.value)} />
              </label>
              <label>Representante ficticio
                <input required maxLength={64} pattern="fixture:representative/[a-z][a-z0-9_-]{0,47}"
                  placeholder="fixture:representative/demo" value={representativeRef}
                  onChange={event => setRepresentativeRef(event.target.value)} />
              </label>
              <label>Clase
                <select value={kind} onChange={event => setKind(event.target.value as GovernanceKind)}>
                  {kinds.map(item => <option key={item} value={item}>{item}</option>)}
                </select>
              </label>
              <button type="submit">Crear borrador ficticio</button>
            </form>
          </section>
        )}
        <section className="catalog-card" aria-labelledby="governance-detail-title">
          <h3 id="governance-detail-title">Detalle y revisión manual</h3>
          {current ? <>
            <dl>
              <dt>Referencia</dt><dd>{current.reference}</dd>
              <dt>Órgano</dt><dd>{current.bodyRef}</dd>
              <dt>Representante</dt><dd>{current.representativeRef}</dd>
              <dt>Clase</dt><dd>{current.kind}</dd>
              <dt>Estado</dt><dd>{current.state}</dd>
            </dl>
            {canReview && current.state !== 'EN_REVISION' && (
              <form className="catalog-editor" onSubmit={changeState}>
                <button type="submit">{current.state === 'BORRADOR'
                  ? 'Solicitar revisión ficticia' : 'Iniciar revisión ficticia'}</button>
              </form>
            )}
            <p className="muted">Ningún acta, poder o decisión se aprueba ni cierra automáticamente.</p>
          </> : <p role="status">Selecciona una actuación ficticia para el detalle.</p>}
        </section>
        <section className="catalog-card" aria-labelledby="governance-audit-title">
          <h3 id="governance-audit-title">Bitácora de demostración (no oficial)</h3>
          <p className="muted">Las operaciones reales exigen permisos y auditoría de servidor.</p>
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

/** Nunca habilitar este simulador para clientes reales. */
export function GovernanceManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <Workspace key={props.tenantId + '|' + props.companyId + '|' + props.branchId}
    {...props} />;
}
