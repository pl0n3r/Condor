/// <reference types="vite/client" />
import { useState, type FormEvent } from 'react';

/** M02: simulación local de contratación laboral; sin efecto contractual real. */
type ContractState = 'BORRADOR' | 'REQUIERE_REVISION' | 'EN_REVISION';
type ContractKind = 'PLANTILLA' | 'CONTRATO' | 'OTROSI';
type ContractRow = Readonly<{
  reference: string;
  personRef: string;
  templateRef: string;
  kind: ContractKind;
  tenantId: string;
  companyId: string;
  branchId: string;
  state: ContractState;
}>;
type AuditEvent = Readonly<{ scope: string; reference: string; action: 'created' | 'viewed' | 'review_requested' | 'review_started' }>;
type Props = Readonly<{ tenantId: string; companyId: string; branchId: string; permissions: string[] }>;

const contractsRefPattern = /^fixture:contracts\/(demo|test|sample)-[0-9]{2,3}$/;
const personRefPattern = /^fixture:person\/(demo|test|sample)-[0-9]{2,3}$/;
const templateRefPattern = /^fixture:template\/(demo|test|sample)-[0-9]{2,3}$/;
const kinds: ContractKind[] = ['PLANTILLA', 'CONTRATO', 'OTROSI'];
const states: ContractState[] = ['BORRADOR', 'REQUIERE_REVISION', 'EN_REVISION'];

function isFixture(value: string, pattern: RegExp): boolean {
  return pattern.test(value) && !/[0-9]{7,}/.test(value);
}

function Workspace({ tenantId, companyId, branchId, permissions }: Props) {
  const scope = tenantId + '|' + companyId + '|' + branchId;
  const canView = companyId !== '__unassigned__' && permissions.includes('users.view');
  const canCreate = canView && permissions.includes('users.create');
  const canReview = canView && permissions.includes('users.update');
  const [records, setRecords] = useState<ContractRow[]>([]);
  const [events, setEvents] = useState<AuditEvent[]>([]);
  const [reference, setReference] = useState('');
  const [personRef, setBodyRef] = useState('');
  const [templateRef, setRepresentativeRef] = useState('');
  const [kind, setKind] = useState<ContractKind>('PLANTILLA');
  const [selected, setSelected] = useState('');
  const [search, setSearch] = useState('');
  const [kindFilter, setKindFilter] = useState<'TODOS' | ContractKind>('TODOS');
  const [stateFilter, setStateFilter] = useState<'TODOS' | ContractState>('TODOS');
  const [notice, setNotice] = useState('');

  function audit(item: string, action: AuditEvent['action']) {
    setEvents(previous => [...previous, { scope, reference: item, action }].slice(-100));
  }

  function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || !isFixture(reference, contractsRefPattern)
      || !isFixture(personRef, personRefPattern)
      || !isFixture(templateRef, templateRefPattern)
      || !kinds.includes(kind) || records.length >= 100
      || records.some(row => row.reference === reference && row.tenantId === tenantId
        && row.companyId === companyId && row.branchId === branchId)) {
      setNotice('Rechazado: revisa permisos, referencias ficticias y duplicados.');
      return;
    }
    setRecords(previous => [...previous, {
      reference, personRef, templateRef, kind, tenantId, companyId, branchId,
      state: 'BORRADOR',
    }]);
    audit(reference, 'created');
    setSelected(reference);
    setReference('');
    setBodyRef('');
    setRepresentativeRef('');
    setNotice('Borrador ficticio creado solo en memoria, sin valor legal.');
  }

  function view(ref: string) {
    if (!canView || !isFixture(ref, contractsRefPattern) || !records.some(row =>
      row.reference === ref && row.tenantId === tenantId
      && row.companyId === companyId && row.branchId === branchId)) return;
    setSelected(ref);
    audit(ref, 'viewed');
  }

  function changeState(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const current = records.find(row => row.reference === selected
      && row.tenantId === tenantId && row.companyId === companyId && row.branchId === branchId);
    if (!canReview || !current || !isFixture(selected, contractsRefPattern)
      || current.state === 'EN_REVISION') {
      setNotice('No se cambió el estado: falta permiso o la transición no aplica.');
      return;
    }
    const next: ContractState = current.state === 'BORRADOR' ? 'REQUIERE_REVISION' : 'EN_REVISION';
    setRecords(previous => previous.map(row =>
      row.reference === selected && row.tenantId === tenantId
      && row.companyId === companyId && row.branchId === branchId
        ? { ...row, state: next } : row));
    audit(selected, current.state === 'BORRADOR' ? 'review_requested' : 'review_started');
    setNotice('Transición ficticia explícita; no se aprobó ni cerró ninguna contrato.');
  }

  if (!canView) return (
    <section id="contracts-management" aria-label="Contratación laboral sintético">
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
    <section id="contracts-management" className="catalog-panel" aria-labelledby="contracts-title">
      <header>
        <h2 id="contracts-title">Contratación laboral · simulación M02</h2>
        <p role="alert">SOLO DATOS FICTICIOS. Sin valor legal ni facultades reales.</p>
        <p className="muted">Personas, representantes y decisiones de prueba, solo en memoria.</p>
      </header>
      {notice && <output role="status" aria-live="polite">{notice}</output>}
      <div className="catalog-grid">
        <section className="catalog-card" aria-labelledby="contracts-list-title">
          <h3 id="contracts-list-title">Contratos ficticias</h3>
          <label>Buscar referencia
            <input aria-label="Buscar contrato ficticia" value={search} maxLength={80}
              onChange={event => setSearch(event.target.value)} />
          </label>
          <label>Tipo de tipo de contrato
            <select aria-label="Filtrar por tipo de contrato" value={kindFilter}
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
          {visible.length === 0 ? <p role="status">Sin contratos ficticias para el filtro.</p> : (
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
          <section className="catalog-card" aria-labelledby="contracts-create-title">
            <h3 id="contracts-create-title">Crear contrato de prueba</h3>
            <form className="catalog-editor" onSubmit={create}>
              <label>Referencia ficticia
                <input required maxLength={64} pattern="fixture:contracts/(demo|test|sample)-[0-9]{2,3}"
                  placeholder="fixture:contracts/demo" value={reference}
                  onChange={event => setReference(event.target.value)} />
              </label>
              <label>Persona ficticio
                <input required maxLength={64} pattern="fixture:person/(demo|test|sample)-[0-9]{2,3}"
                  placeholder="fixture:person/demo" value={personRef}
                  onChange={event => setBodyRef(event.target.value)} />
              </label>
              <label>Plantilla ficticio
                <input required maxLength={64} pattern="fixture:template/(demo|test|sample)-[0-9]{2,3}"
                  placeholder="fixture:template/demo" value={templateRef}
                  onChange={event => setRepresentativeRef(event.target.value)} />
              </label>
              <label>Clase
                <select value={kind} onChange={event => setKind(event.target.value as ContractKind)}>
                  {kinds.map(item => <option key={item} value={item}>{item}</option>)}
                </select>
              </label>
              <button type="submit">Crear borrador ficticio</button>
            </form>
          </section>
        )}
        <section className="catalog-card" aria-labelledby="contracts-detail-title">
          <h3 id="contracts-detail-title">Detalle y revisión manual</h3>
          {current ? <>
            <dl>
              <dt>Referencia</dt><dd>{current.reference}</dd>
              <dt>Persona</dt><dd>{current.personRef}</dd>
              <dt>Plantilla</dt><dd>{current.templateRef}</dd>
              <dt>Clase</dt><dd>{current.kind}</dd>
              <dt>Estado</dt><dd>{current.state}</dd>
            </dl>
            {canReview && current.state !== 'EN_REVISION' && (
              <form className="catalog-editor" onSubmit={changeState}>
                <button type="submit">{current.state === 'BORRADOR'
                  ? 'Solicitar revisión ficticia' : 'Iniciar revisión ficticia'}</button>
              </form>
            )}
            <p className="muted">Ningún contrato, firma o liquidación se aprueba ni cierra automáticamente.</p>
          </> : <p role="status">Selecciona una contrato ficticia para el detalle.</p>}
        </section>
        <section className="catalog-card" aria-labelledby="contracts-audit-title">
          <h3 id="contracts-audit-title">Bitácora de demostración (no oficial)</h3>
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
export function ContractsManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <Workspace key={props.tenantId + '|' + props.companyId + '|' + props.branchId}
    {...props} />;
}
