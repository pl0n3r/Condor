/// <reference types="vite/client" />
import { useState, type ChangeEvent, type FormEvent } from 'react';

/**
 * M10: simulador exclusivamente en memoria para construcción.
 * No consulta API, no persiste datos y NO representa auditoría oficial.
 */
type AssetState = 'INVENTARIADO' | 'ASIGNADO' | 'MANTENIMIENTO_PENDIENTE' | 'BAJA_REQUIERE_REVISION';
type AssetAction = 'ASIGNAR' | 'MANTENIMIENTO' | 'SOLICITAR_BAJA';
type Asset = Readonly<{
  ref: string;
  tenantId: string;
  companyId: string;
  branchId: string;
  responsibleRef: string | null;
  evidenceRef: string | null;
  state: AssetState;
}>;
type AuditEvent = Readonly<{
  scope: string;
  assetRef: string;
  operation: 'created' | 'viewed' | 'state_changed';
}>;
type Props = Readonly<{
  tenantId: string;
  companyId: string;
  branchId: string;
  permissions: string[];
}>;
const assetRefPattern = /^fixture:asset\/[a-z][a-z0-9_-]{0,47}$/;
const responsibleRefPattern = /^fixture:responsible\/[a-z][a-z0-9_-]{0,47}$/;
const evidenceRefPattern = /^fixture:evidence\/[a-z][a-z0-9_-]{0,47}$/;
const states: AssetState[] = [
  'INVENTARIADO', 'ASIGNADO', 'MANTENIMIENTO_PENDIENTE', 'BAJA_REQUIERE_REVISION',
];

function Workspace({ tenantId, companyId, branchId, permissions }: Props) {
  const hasCompany = companyId !== '__unassigned__';
  const canView = hasCompany && permissions.includes('inventory.view');
  const canCreate = canView && permissions.includes('inventory.create');
  const canUpdate = canView && permissions.includes('inventory.update');
  const scope = tenantId + '|' + companyId + '|' + branchId;
  const [records, setRecords] = useState<Asset[]>([]);
  const [events, setEvents] = useState<AuditEvent[]>([]);
  const [reference, setReference] = useState('');
  const [selected, setSelected] = useState('');
  const [stateFilter, setStateFilter] = useState<'TODOS' | AssetState>('TODOS');
  const [action, setAction] = useState<AssetAction>('ASIGNAR');
  const [responsible, setResponsible] = useState('');
  const [evidence, setEvidence] = useState('');
  const [notice, setNotice] = useState('');

  function audit(assetRef: string, operation: AuditEvent['operation']) {
    setEvents(previous => [...previous, { scope, assetRef, operation }].slice(-100));
  }

  function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || !assetRefPattern.test(reference)
        || records.length >= 100 || records.some(row => row.ref === reference)) {
      setNotice('Referencia no válida o repetida. Usa solo fixture:asset/demo.');
      return;
    }
    const record: Asset = {
      ref: reference, tenantId, companyId, branchId,
      responsibleRef: null, evidenceRef: null, state: 'INVENTARIADO',
    };
    setRecords(previous => [...previous, record]);
    audit(reference, 'created');
    setReference('');
    setNotice('Activo ficticio inventariado. Ningún estado se aprobó.');
  }

  function view(assetRef: string) {
    if (!canView || !assetRefPattern.test(assetRef)
        || !records.some(row => row.ref === assetRef && row.tenantId === tenantId
          && row.companyId === companyId && row.branchId === branchId)) return;
    setSelected(assetRef);
    audit(assetRef, 'viewed');
  }

  function changeState(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canUpdate || !assetRefPattern.test(selected)
        || !records.some(row => row.ref === selected && row.tenantId === tenantId
          && row.companyId === companyId && row.branchId === branchId)
        || (action === 'ASIGNAR' && !responsibleRefPattern.test(responsible))
        || (action === 'SOLICITAR_BAJA' && !evidenceRefPattern.test(evidence))) {
      setNotice('Operación denegada: exige permiso y referencias ficticias válidas.');
      return;
    }
    const next: AssetState = action === 'ASIGNAR' ? 'ASIGNADO'
      : action === 'MANTENIMIENTO' ? 'MANTENIMIENTO_PENDIENTE'
      : 'BAJA_REQUIERE_REVISION';
    setRecords(previous => previous.map(row => row.ref === selected
      && row.tenantId === tenantId && row.companyId === companyId && row.branchId === branchId
      ? { ...row, state: next,
          responsibleRef: action === 'ASIGNAR' ? responsible : row.responsibleRef,
          evidenceRef: action === 'SOLICITAR_BAJA' ? evidence : row.evidenceRef }
      : row));
    audit(selected, 'state_changed');
    setResponsible('');
    setEvidence('');
    setNotice('Acción explícita registrada solo en memoria; pendiente de autorización real.');
  }

  if (!canView) return (
    <section id="assets-management" aria-label="Activos empresariales (pruebas)">
      <p role="alert">No tienes permiso o razón social activa para consultar activos ficticios.</p>
    </section>
  );

  const scoped = records.filter(row => row.tenantId === tenantId
    && row.companyId === companyId && row.branchId === branchId);
  const visible = scoped.filter(row => stateFilter === 'TODOS' || row.state === stateFilter);
  const detail = scoped.find(row => row.ref === selected);
  return (
    <section id="assets-management" className="catalog-panel" aria-labelledby="assets-title">
      <div className="section-heading"><div>
        <span className="eyebrow">M10 · Exclusivo construcción</span>
        <h2 id="assets-title">Activos empresariales</h2>
        <p className="muted">Simulador ficticio sin API, persistencia ni auditoría de servidor.</p>
      </div></div>
      {notice && <output aria-live="polite" role="status">{notice}</output>}
      <div className="production-layout">
        <section className="catalog-card" aria-labelledby="assets-list-title">
          <h3 id="assets-list-title">Inventario de prueba</h3>
          <label className="field"><span>Filtrar por estado</span>
            <select value={stateFilter} onChange={(event: ChangeEvent<HTMLSelectElement>) =>
              setStateFilter(event.target.value as typeof stateFilter)}>
              <option value="TODOS">Todos</option>
              {states.map(state => <option key={state} value={state}>{state}</option>)}
            </select>
          </label>
          {visible.length === 0 ? <p role="status">Sin activos ficticios.</p> :
            <ul>{visible.map(row => <li key={row.ref}>
              <button type="button" onClick={() => view(row.ref)}>{row.ref}</button>
              {' · '}{row.state}
            </li>)}</ul>}
        </section>
        {canCreate && <section className="catalog-card" aria-labelledby="assets-create-title">
          <h3 id="assets-create-title">Alta ficticia</h3>
          <form className="catalog-editor" onSubmit={create}>
            <label className="field"><span>Referencia de activo de prueba</span>
              <input required maxLength={64} pattern="fixture:asset/[a-z][a-z0-9_-]{0,47}"
                placeholder="fixture:asset/demo" value={reference}
                onChange={(event: ChangeEvent<HTMLInputElement>) => setReference(event.target.value)} />
            </label>
            <button className="button" type="submit">Inventariar activo ficticio</button>
          </form>
        </section>}
        <section className="catalog-card" aria-labelledby="assets-detail-title">
          <h3 id="assets-detail-title">Detalle y acciones explícitas</h3>
          {detail ? <>
            <p><strong>Referencia:</strong> {detail.ref}</p>
            <p><strong>Estado:</strong> {detail.state}</p>
            <p><strong>Responsable:</strong> {detail.responsibleRef ?? 'No asignado'}</p>
            <p><strong>Evidencia:</strong> {detail.evidenceRef ?? 'No adjunta'}</p>
            {canUpdate && <form className="catalog-editor" onSubmit={changeState}>
              <label className="field"><span>Acción ficticia</span>
                <select value={action} onChange={(event: ChangeEvent<HTMLSelectElement>) =>
                  setAction(event.target.value as AssetAction)}>
                  <option value="ASIGNAR">Asignar responsable</option>
                  <option value="MANTENIMIENTO">Solicitar mantenimiento</option>
                  <option value="SOLICITAR_BAJA">Solicitar baja (no aprobar)</option>
                </select>
              </label>
              {action === 'ASIGNAR' && <label className="field"><span>Responsable sintético</span>
                <input required maxLength={70} placeholder="fixture:responsible/demo"
                  pattern="fixture:responsible/[a-z][a-z0-9_-]{0,47}"
                  value={responsible} onChange={event => setResponsible(event.target.value)} />
              </label>}
              {action === 'SOLICITAR_BAJA' && <label className="field"><span>Evidencia sintética</span>
                <input required maxLength={70} placeholder="fixture:evidence/demo"
                  pattern="fixture:evidence/[a-z][a-z0-9_-]{0,47}"
                  value={evidence} onChange={event => setEvidence(event.target.value)} />
              </label>}
              <button type="submit">Registrar transición ficticia explícita</button>
            </form>}
            <p className="muted">Ningún activo se aprueba, da de baja o cierra automáticamente.</p>
          </> : <p role="status">Selecciona un activo para consultar su detalle.</p>}
        </section>
        <section className="catalog-card" aria-labelledby="assets-audit-title">
          <h3 id="assets-audit-title">Bitácora de demostración (no oficial)</h3>
          {events.length === 0 ? <p role="status">Sin operaciones de prueba.</p> :
            <ul>{events.filter(row => row.scope === scope).map((row, index) =>
              <li key={index}>{row.operation}{' · '}{row.assetRef}</li>)}</ul>}
        </section>
      </div>
    </section>
  );
}

/** Direct import cannot activate this component in a production bundle. */
export function AssetsManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <Workspace key={props.tenantId + '|' + props.companyId + '|' + props.branchId}
    {...props} />;
}
