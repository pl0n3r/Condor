/// <reference types="vite/client" />
import { useState, type FormEvent } from 'react';

/** SG-SST M03: prototipo exclusivamente sintético en memoria, sin red ni efectos legales. */
type Estado = 'PENDIENTE' | 'EN_REVISION' | 'CIERRE_REQUIERE_REVISION';
type Registro = Readonly<{
  tenantId: string; companyId: string; branchId: string;
  ref: string; evidenceRef: string; actorRef: string; state: Estado;
}>;
type Audit = Readonly<{ scope: string; ref: string; action: 'created' | 'viewed' | 'state_changed' }>;
type Props = Readonly<{
  tenantId: string; companyId: string; branchId: string; permissions: string[];
}>;
const recordRefPattern = /^fixture:occupational\/[a-z][a-z0-9_-]{0,47}$/;
const evidenceRefPattern = /^fixture:evidence\/[a-z][a-z0-9_-]{0,47}$/;
const actorRefPattern = /^fixture:actor\/[a-z][a-z0-9_-]{0,47}$/;
const estados: Estado[] = ['PENDIENTE', 'EN_REVISION', 'CIERRE_REQUIERE_REVISION'];

function Workspace({ tenantId, companyId, branchId, permissions }: Props) {
  const canView = companyId !== '__unassigned__' && permissions.includes('inventory.view');
  const canCreate = canView && permissions.includes('inventory.create');
  const canUpdate = canView && permissions.includes('inventory.update');
  const scope = tenantId + '|' + companyId + '|' + branchId;
  const [records, setRecords] = useState<Registro[]>([]);
  const [history, setHistory] = useState<Audit[]>([]);
  const [ref, setRef] = useState('');
  const [evidenceRef, setEvidenceRef] = useState('');
  const [actorRef, setActorRef] = useState('');
  const [selected, setSelected] = useState('');
  const [search, setSearch] = useState('');
  const [stateFilter, setStateFilter] = useState<'TODOS' | Estado>('TODOS');
  const [nextState, setNextState] = useState<Estado>('EN_REVISION');
  const [notice, setNotice] = useState('');

  function audit(item: string, action: Audit['action']) {
    setHistory(previous => [...previous, { scope, ref: item, action }].slice(-100));
  }
  function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || !recordRefPattern.test(ref)
      || !evidenceRefPattern.test(evidenceRef) || !actorRefPattern.test(actorRef)
      || records.length >= 100 || records.some(row => row.ref === ref
        && row.tenantId === tenantId && row.companyId === companyId && row.branchId === branchId)) {
      setNotice('No se guardó: permiso insuficiente, duplicado o referencia no ficticia.');
      return;
    }
    setRecords(previous => [...previous, {
      tenantId, companyId, branchId, ref, evidenceRef, actorRef, state: 'PENDIENTE',
    }]);
    audit(ref, 'created');
    setSelected(ref);
    setRef(''); setEvidenceRef(''); setActorRef('');
    setNotice('Registro ficticio creado en memoria. No tiene valor legal.');
  }
  function view(item: string) {
    if (!canView || !recordRefPattern.test(item)
      || !records.some(row => row.ref === item && row.tenantId === tenantId
        && row.companyId === companyId && row.branchId === branchId)) return;
    setSelected(item);
    audit(item, 'viewed');
  }
  function changeState(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const selectedRow = records.find(row => row.ref === selected && row.tenantId === tenantId
      && row.companyId === companyId && row.branchId === branchId);
    const valid = selectedRow !== undefined && (
      (selectedRow.state === 'PENDIENTE' && nextState === 'EN_REVISION')
      || (selectedRow.state === 'EN_REVISION' && nextState === 'CIERRE_REQUIERE_REVISION')
    );
    if (!canUpdate || !actorRefPattern.test(actorRef) || !valid) {
      setNotice('Transición rechazada: permiso, actor ficticio o estado inválido.');
      return;
    }
    setRecords(previous => previous.map(row => row.ref === selected
      && row.tenantId === tenantId && row.companyId === companyId && row.branchId === branchId
      ? { ...row, state: nextState, actorRef } : row));
    audit(selected, 'state_changed');
    setNotice('Transición ficticia registrada. No existe cierre automático.');
  }

  if (!canView) return (
    <section id="compliance-management" aria-label="SG-SST sintético">
      <p role="alert">No tienes permiso de SG-SST o una razón social activa.</p>
    </section>
  );
  const scoped = records.filter(row => row.tenantId === tenantId
    && row.companyId === companyId && row.branchId === branchId);
  const filtered = scoped.filter(row => (stateFilter === 'TODOS' || row.state === stateFilter)
    && row.ref.includes(search.trim().toLowerCase()));
  const detail = scoped.find(row => row.ref === selected);

  return (
    <section id="compliance-management" className="catalog-panel" aria-labelledby="compliance-title">
      <div className="section-heading"><div>
        <h2 id="compliance-title">Seguridad y salud en el trabajo · pruebas</h2>
        <p role="alert">SOLO DATOS FICTICIOS. Sin cumplimiento legal certificado, personas reales ni auditoría de servidor.</p>
        <p className="muted">Simulación efímera: se borra al cambiar de empresa o recargar.</p>
      </div></div>
      {notice && <output role="status" aria-live="polite">{notice}</output>}
      <div className="catalog-grid">
        <section className="catalog-card" aria-labelledby="compliance-list-title">
          <h3 id="compliance-list-title">Registros de demostración</h3>
          <label>Buscar referencia ficticia
            <input aria-label="Buscar registros SG-SST" value={search}
              onChange={event => setSearch(event.target.value)} />
          </label>
          <label>Filtrar estado
            <select aria-label="Estado SG-SST" value={stateFilter}
              onChange={event => setStateFilter(event.target.value as typeof stateFilter)}>
              <option value="TODOS">Todos</option>
              {estados.map(item => <option key={item} value={item}>{item}</option>)}
            </select>
          </label>
          {filtered.length === 0 ? <p role="status">Sin registros ficticios para estos filtros.</p> : (
            <ul>{filtered.map(row => <li key={row.ref}>
              <button type="button" onClick={() => view(row.ref)}>{row.ref} · {row.state}</button>
            </li>)}</ul>
          )}
        </section>
        {canCreate && (
          <section className="catalog-card" aria-labelledby="compliance-create-title">
            <h3 id="compliance-create-title">Nuevo registro ficticio</h3>
            <form className="catalog-editor" onSubmit={create}>
              <label>Referencia ficticia
                <input required maxLength={70} pattern="fixture:occupational/[a-z][a-z0-9_-]{0,47}"
                  value={ref} placeholder="fixture:occupational/demo"
                  onChange={event => setRef(event.target.value)} />
              </label>
              <label>Evidencia ficticia
                <input required maxLength={70} pattern="fixture:evidence/[a-z][a-z0-9_-]{0,47}"
                  value={evidenceRef} placeholder="fixture:evidence/demo"
                  onChange={event => setEvidenceRef(event.target.value)} />
              </label>
              <label>Actor ficticio, sin identificación
                <input required maxLength={70} pattern="fixture:actor/[a-z][a-z0-9_-]{0,47}"
                  value={actorRef} placeholder="fixture:actor/demo"
                  onChange={event => setActorRef(event.target.value)} />
              </label>
              <button type="submit">Crear registro de prueba</button>
            </form>
          </section>
        )}
        <section className="catalog-card" aria-labelledby="compliance-detail-title">
          <h3 id="compliance-detail-title">Detalle y revisión</h3>
          {detail ? <>
            <dl>
              <dt>Referencia</dt><dd>{detail.ref}</dd>
              <dt>Evidencia</dt><dd>{detail.evidenceRef}</dd>
              <dt>Actor ficticio</dt><dd>{detail.actorRef}</dd>
              <dt>Estado</dt><dd>{detail.state}</dd>
            </dl>
            {canUpdate && detail.state !== 'CIERRE_REQUIERE_REVISION' && (
              <form className="catalog-editor" onSubmit={changeState}>
                <label>Actor de revisión ficticio
                  <input required maxLength={70} pattern="fixture:actor/[a-z][a-z0-9_-]{0,47}"
                    value={actorRef} placeholder="fixture:actor/revisor"
                    onChange={event => setActorRef(event.target.value)} />
                </label>
                <label>Acción explícita de prueba
                  <select aria-label="Transición de SG-SST" value={nextState}
                    onChange={event => setNextState(event.target.value as Estado)}>
                    <option value="EN_REVISION">Iniciar revisión</option>
                    <option value="CIERRE_REQUIERE_REVISION">Solicitar revisión de cierre</option>
                  </select>
                </label>
                <button type="submit">Registrar transición ficticia explícita</button>
              </form>
            )}
            <p className="muted">Ningún estado se aprueba ni se cierra automáticamente.</p>
          </> : <p role="status">Selecciona un registro para ver su detalle.</p>}
        </section>
        <section className="catalog-card" aria-labelledby="compliance-audit-title">
          <h3 id="compliance-audit-title">Bitácora de demostración (no oficial)</h3>
          <p className="muted">La bitácora local no sustituye la auditoría de servidor.</p>
          {history.length === 0 ? <p role="status">Sin operaciones de prueba.</p> : (
            <ol>{history.map((item, index) => <li key={index}>{item.ref} · {item.action}</li>)}</ol>
          )}
        </section>
      </div>
    </section>
  );
}

export function ComplianceManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <Workspace key={props.tenantId + '|' + props.companyId + '|' + props.branchId} {...props} />;
}
