/// <reference types="vite/client" />
import { useState, type FormEvent } from 'react';

/** M13 Calidad: prototipo efímero; solo referencias sintéticas, sin red ni datos reales. */
type Status = 'PENDIENTE' | 'EN_REVISION' | 'CIERRE_REQUIERE_REVISION';
type RecordItem = Readonly<{
  tenantId: string; companyId: string; branchId: string;
  ref: string; findingRef: string; actorRef: string; status: Status;
}>;
type Audit = Readonly<{
  scope: string; ref: string; action: 'created' | 'viewed' | 'state_changed';
  actorRef?: string;
}>;
type Props = Readonly<{
  tenantId: string; companyId: string; branchId: string; permissions: string[];
}>;

const recordPattern = /^fixture:quality\/[a-z][a-z0-9_-]{0,47}$/;
const findingPattern = /^fixture:finding\/[a-z][a-z0-9_-]{0,47}$/;
const actorPattern = /^fixture:actor\/[a-z][a-z0-9_-]{0,47}$/;
const states: Status[] = ['PENDIENTE', 'EN_REVISION', 'CIERRE_REQUIERE_REVISION'];

function Workspace({ tenantId, companyId, branchId, permissions }: Props) {
  const scope = tenantId + '|' + companyId + '|' + branchId;
  const canView = companyId !== '__unassigned__' && permissions.includes('inventory.view');
  const canCreate = canView && permissions.includes('inventory.create');
  const canUpdate = canView && permissions.includes('inventory.update');
  const [records, setRecords] = useState<RecordItem[]>([]);
  const [history, setHistory] = useState<Audit[]>([]);
  const [ref, setRef] = useState('');
  const [findingRef, setFindingRef] = useState('');
  const [actorRef, setActorRef] = useState('');
  const [reviewActorRef, setReviewActorRef] = useState('');
  const [selected, setSelected] = useState('');
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState<'TODOS' | Status>('TODOS');
  const [notice, setNotice] = useState('');

  const scoped = records.filter(row => row.tenantId === tenantId
    && row.companyId === companyId && row.branchId === branchId);
  const detail = scoped.find(row => row.ref === selected);
  const nextStatus: Status | null = detail?.status === 'PENDIENTE' ? 'EN_REVISION'
    : detail?.status === 'EN_REVISION' ? 'CIERRE_REQUIERE_REVISION' : null;
  const visible = scoped.filter(row => (filter === 'TODOS' || row.status === filter)
    && row.ref.includes(search.trim().toLowerCase()));

  function audit(item: string, action: Audit['action'], actor: string | null = null) {
    setHistory(previous => [...previous, {
      scope, ref: item, action, actorRef: actor ?? undefined,
    }].slice(-100));
  }

  function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || !recordPattern.test(ref)
      || !findingPattern.test(findingRef) || !actorPattern.test(actorRef)
      || scoped.length >= 100 || scoped.some(row => row.ref === ref)) {
      setNotice('No se guardó: permiso insuficiente, duplicado o referencia ficticia inválida.');
      return;
    }
    setRecords(previous => [...previous, {
      tenantId, companyId, branchId, ref, findingRef, actorRef, status: 'PENDIENTE',
    }]);
    audit(ref, 'created', actorRef);
    setSelected(ref);
    setRef(''); setFindingRef(''); setActorRef('');
    setNotice('Registro ficticio creado únicamente en memoria.');
  }

  function view(item: string) {
    if (!canView || !recordPattern.test(item) || !scoped.some(row => row.ref === item)) return;
    setSelected(item);
    audit(item, 'viewed');
  }

  function changeState(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canUpdate || detail === undefined || nextStatus === null
      || !actorPattern.test(reviewActorRef)
      || !scoped.some(row => row.ref === detail.ref)) {
      setNotice('Transición rechazada: permiso, actor o estado inválido.');
      return;
    }
    const item = detail.ref;
    setRecords(previous => previous.map(row => row.tenantId === tenantId
      && row.companyId === companyId && row.branchId === branchId
      && row.ref === item ? { ...row, status: nextStatus } : row));
    audit(item, 'state_changed', reviewActorRef);
    setReviewActorRef('');
    setNotice('Transición ficticia explícita registrada; sin aprobación o cierre automático.');
  }

  if (!canView) return (
    <section id="quality-management" aria-label="Calidad sintética">
      <p role="alert">No tienes permiso para Calidad o una empresa activa.</p>
    </section>
  );

  return (
    <section id="quality-management" className="catalog-panel" aria-labelledby="quality-title">
      <div className="section-heading"><div>
        <span className="eyebrow">M13 · Calidad</span>
        <h2 id="quality-title">Calidad de la empresa · pruebas</h2>
        <p role="alert">SOLO DATOS FICTICIOS. Sin valor legal ni auditoría de servidor.</p>
        <p className="muted">Sesión local, efímera: no se envía ni almacena nada en un servidor.</p>
      </div></div>
      {notice && <output role="status" aria-live="polite">{notice}</output>}
      <div className="catalog-grid">
        <section className="catalog-card" aria-labelledby="quality-list-title">
          <h3 id="quality-list-title">No conformidades de demostración</h3>
          <label>Buscar referencia ficticia
            <input value={search} onChange={event => setSearch(event.target.value)} />
          </label>
          <label>Filtrar por estado
            <select value={filter}
              onChange={event => setFilter(event.target.value as typeof filter)}>
              <option value="TODOS">Todos</option>
              {states.map(item => <option key={item} value={item}>{item}</option>)}
            </select>
          </label>
          {visible.length === 0 ? <p role="status">Sin registros ficticios para estos filtros.</p> : (
            <ul>{visible.map(row => <li key={row.ref}>
              <button type="button" onClick={() => view(row.ref)}>{row.ref} · {row.status}</button>
            </li>)}</ul>
          )}
        </section>
        {canCreate && (
          <section className="catalog-card" aria-labelledby="quality-create-title">
            <h3 id="quality-create-title">Nuevo registro ficticio</h3>
            <form className="catalog-editor" onSubmit={create}>
              <label>Referencia ficticia
                <input required maxLength={70} pattern="fixture:quality/[a-z][a-z0-9_-]{0,47}"
                  placeholder="fixture:quality/demo" value={ref}
                  onChange={event => setRef(event.target.value)} />
              </label>
              <label>Hallazgo ficticio
                <input required maxLength={70} pattern="fixture:finding/[a-z][a-z0-9_-]{0,47}"
                  placeholder="fixture:finding/demo" value={findingRef}
                  onChange={event => setFindingRef(event.target.value)} />
              </label>
              <label>Actor ficticio, sin identificación personal
                <input required maxLength={70} pattern="fixture:actor/[a-z][a-z0-9_-]{0,47}"
                  placeholder="fixture:actor/demo" value={actorRef}
                  onChange={event => setActorRef(event.target.value)} />
              </label>
              <button type="submit">Crear registro ficticio</button>
            </form>
          </section>
        )}
        <section className="catalog-card" aria-labelledby="quality-detail-title">
          <h3 id="quality-detail-title">Detalle y revisión explícita</h3>
          {detail ? <>
            <dl>
              <dt>Referencia</dt><dd>{detail.ref}</dd>
              <dt>Hallazgo</dt><dd>{detail.findingRef}</dd>
              <dt>Actor ficticio</dt><dd>{detail.actorRef}</dd>
              <dt>Estado</dt><dd>{detail.status}</dd>
            </dl>
            {canUpdate && nextStatus !== null && (
              <form className="catalog-editor" onSubmit={changeState}>
                <label>Actor de revisión ficticio
                  <input required maxLength={70} pattern="fixture:actor/[a-z][a-z0-9_-]{0,47}"
                    placeholder="fixture:actor/revisor" value={reviewActorRef}
                    onChange={event => setReviewActorRef(event.target.value)} />
                </label>
                <p>Próximo estado: <strong>{nextStatus}</strong></p>
                <button type="submit">Registrar transición explícita</button>
              </form>
            )}
            <p className="muted">No hay transición automática a APROBADO ni CERRADO.</p>
          </> : <p role="status">Selecciona un registro para ver su detalle.</p>}
        </section>
        <section className="catalog-card" aria-labelledby="quality-audit-title">
          <h3 id="quality-audit-title">Bitácora ficticia de esta sesión</h3>
          <p className="muted">La bitácora local no sustituye la auditoría del servidor.</p>
          {history.filter(item => item.scope === scope).length === 0
            ? <p role="status">Sin operaciones de prueba.</p>
            : <ol>{history.filter(item => item.scope === scope).map((item, index) => (
              <li key={index}>{item.ref} · {item.action}
                {item.actorRef ? ' · ' + item.actorRef : ''}</li>
            ))}</ol>}
        </section>
      </div>
    </section>
  );
}

export function QualityManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <Workspace key={[props.tenantId, props.companyId, props.branchId].join('|')} {...props} />;
}
