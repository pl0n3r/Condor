/// <reference types="vite/client" />
import { useState, type ChangeEvent, type FormEvent } from 'react';

/** M16 synthetic-only simulator. Not an API, persistent store or official audit. */
type CaseState = 'REQUIERE_REVISION' | 'EN_REVISION';
type CaseKind = 'PQRS' | 'GARANTIA' | 'SOLICITUD';
type CaseRecord = Readonly<{ ref: string; kind: CaseKind; state: CaseState }>;
type Audit = Readonly<{ scope: string; ref: string; operation: 'created' | 'viewed' | 'state_changed' }>;
type Props = Readonly<{ tenantId: string; branchId: string; permissions: string[] }>;
const syntheticRef = /^fixture:case\/[a-z][a-z0-9_-]{0,31}$/;
const kinds: CaseKind[] = ['PQRS', 'GARANTIA', 'SOLICITUD'];

function Workspace({ tenantId, branchId, permissions }: Props) {
  const canView = permissions.includes('inventory.view');
  const canCreate = canView && permissions.includes('inventory.create');
  const canUpdate = canView && permissions.includes('inventory.update');
  const [records, setRecords] = useState<CaseRecord[]>([]);
  const [events, setEvents] = useState<Audit[]>([]);
  const [selected, setSelected] = useState('');
  const [filter, setFilter] = useState<'TODOS' | CaseState>('TODOS');
  const [ref, setRef] = useState('');
  const [kind, setKind] = useState<CaseKind>('PQRS');
  const [notice, setNotice] = useState('');
  const scope = tenantId + '/' + branchId;
  function log(refId: string, operation: Audit['operation']) {
    setEvents(current => [...current, { scope, ref: refId, operation }].slice(-100));
  }
  function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || !syntheticRef.test(ref) || !kinds.includes(kind)
      || records.length >= 100 || records.some(r => r.ref === ref)) {
      setNotice('Solo se admiten referencias de prueba nuevas, como fixture:case/demo.');
      return;
    }
    setRecords(current => [...current, { ref, kind, state: 'REQUIERE_REVISION' }]);
    log(ref, 'created');
    setRef('');
    setNotice('Caso ficticio creado. Pendiente de revisión.');
  }
  function view(reference: string) {
    if (!canView || !syntheticRef.test(reference) || !records.some(r => r.ref === reference)) return;
    setSelected(reference);
    log(reference, 'viewed');
  }
  function moveToReview(reference: string) {
    if (!canUpdate || !syntheticRef.test(reference)
      || !records.some(r => r.ref === reference && r.state === 'REQUIERE_REVISION')) return;
    setRecords(current => current.map(r => r.ref === reference ? { ...r, state: 'EN_REVISION' } : r));
    log(reference, 'state_changed');
    setNotice('Transición de prueba registrada para revisión humana.');
  }
  if (!canView) return <section id="customer-service" aria-label="Servicio al cliente (pruebas)">
    <p role="alert">No tienes permiso para consultar el servicio al cliente.</p>
  </section>;
  const shown = records.filter(r => filter === 'TODOS' || r.state === filter);
  const detail = records.find(r => r.ref === selected);
  return <section id="customer-service" className="catalog-panel" aria-labelledby="customer-service-title">
    <div className="section-heading"><div>
      <span className="eyebrow">M16 · Solo construcción</span>
      <h2 id="customer-service-title">Servicio al cliente</h2>
      <p className="muted">Simulador local sin datos reales, API, persistencia ni auditoría de servidor.</p>
    </div></div>
    {notice && <output aria-live="polite">{notice}</output>}
    <div className="production-layout">
      <section className="catalog-card" aria-labelledby="case-list-title">
        <h3 id="case-list-title">Casos ficticios</h3>
        <label className="field"><span>Filtrar por estado</span>
          <select value={filter} onChange={(event: ChangeEvent<HTMLSelectElement>) =>
            setFilter(event.target.value as typeof filter)}>
            <option value="TODOS">Todos</option>
            <option value="REQUIERE_REVISION">Requiere revisión</option>
            <option value="EN_REVISION">En revisión</option>
          </select>
        </label>
        {shown.length === 0 ? <p role="status">Sin casos ficticios.</p> :
          <ul>{shown.map(r => <li key={r.ref}>
            <button type="button" onClick={() => view(r.ref)}>{r.ref}</button>
            {' · '}{r.kind}{' · '}{r.state}</li>)}</ul>}
      </section>
      {canCreate && <section className="catalog-card" aria-labelledby="new-case-title">
        <h3 id="new-case-title">Nuevo caso ficticio</h3>
        <form className="catalog-editor" onSubmit={create}>
          <label className="field"><span>Referencia sintética</span>
            <input required maxLength={45} placeholder="fixture:case/demo"
              pattern="fixture:case/[a-z][a-z0-9_-]{0,31}"
              value={ref} onChange={(event: ChangeEvent<HTMLInputElement>) => setRef(event.target.value)} />
          </label>
          <label className="field"><span>Tipo</span>
            <select value={kind} onChange={(event: ChangeEvent<HTMLSelectElement>) =>
              setKind(event.target.value as CaseKind)}>
              <option value="PQRS">PQRS</option><option value="GARANTIA">Garantía</option>
              <option value="SOLICITUD">Solicitud</option>
            </select>
          </label>
          <button className="button" type="submit">Crear caso de prueba</button>
        </form>
      </section>}
      <section className="catalog-card" aria-labelledby="case-detail-title">
        <h3 id="case-detail-title">Detalle y estado</h3>
        {detail ? <>
          <p><strong>Referencia:</strong> {detail.ref}</p>
          <p><strong>Tipo:</strong> {detail.kind}</p>
          <p><strong>Estado:</strong> {detail.state}</p>
          {canUpdate && detail.state === 'REQUIERE_REVISION' &&
            <button type="button" onClick={() => moveToReview(detail.ref)}>
              Pasar explícitamente a revisión
            </button>}
          <p className="muted">No se permiten aprobaciones ni cierres automáticos.</p>
        </> : <p role="status">Selecciona un caso para ver su detalle.</p>}
      </section>
      <section className="catalog-card" aria-labelledby="audit-demo-title">
        <h3 id="audit-demo-title">Bitácora de demostración (no oficial)</h3>
        {events.length === 0 ? <p role="status">Sin operaciones de prueba.</p> :
          <ul>{events.filter(e => e.scope === scope).map((e, i) =>
            <li key={i}>{e.operation}{' · '}{e.ref}</li>)}</ul>}
      </section>
    </div>
  </section>;
}

/** In production even a direct import of this component is inert. */
export function CustomerServiceManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <Workspace key={props.tenantId + '|' + props.branchId} {...props} />;
}
