/// <reference types="vite/client" />
import { useEffect, useState, type ChangeEvent, type FormEvent } from 'react';

/**
 * M10: interfaz de construcción contra API sintética protegida.
 * No admite datos reales, archivos jurídicos, aprobación ni operación live.
 */
type AssetState = 'PENDIENTE' | 'ASIGNADO' | 'EN_REVISION' | 'BAJA_REQUIERE_REVISION';
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
  'PENDIENTE', 'ASIGNADO', 'EN_REVISION', 'BAJA_REQUIERE_REVISION',
];
type ApiAsset = Readonly<{
  asset_ref: string;
  responsible_ref: string | null;
  evidence_ref: string | null;
  state: AssetState;
}>;
type LoadState = 'loading' | 'ready' | 'error' | 'permission-denied';

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
  const [loadState, setLoadState] = useState<LoadState>('loading');
  const [csrfToken, setCsrfToken] = useState('');
  const [busy, setBusy] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const apiPath = '/api/v1/branches/' + encodeURIComponent(branchId) + '/assets';

  function fromApi(row: ApiAsset): Asset {
    if (!assetRefPattern.test(row.asset_ref) ||
        !['PENDIENTE', 'ASIGNADO', 'EN_REVISION', 'BAJA_REQUIERE_REVISION'].includes(row.state) ||
        (row.responsible_ref !== null && !responsibleRefPattern.test(row.responsible_ref)) ||
        (row.evidence_ref !== null && !evidenceRefPattern.test(row.evidence_ref))) {
      throw new Error('El servidor entregó datos no sintéticos.');
    }
    return {
      ref: row.asset_ref, tenantId, companyId, branchId,
      responsibleRef: row.responsible_ref,
      evidenceRef: row.evidence_ref,
      state: row.state,
    };
  }

  useEffect(() => {
    if (!canView) {
      setLoadState('permission-denied');
      return;
    }
    const abort = new AbortController();
    setLoadState('loading');
    setCsrfToken('');
    setRecords([]);
    setSelected('');
    void fetch(apiPath, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      signal: abort.signal,
    }).then(async response => {
      if (response.status === 401 || response.status === 403) {
        if (!abort.signal.aborted) setLoadState('permission-denied');
        return;
      }
      if (!response.ok) throw new Error('No fue posible consultar los activos de prueba.');
      const data = await response.json() as { assets: ApiAsset[]; csrf_token: string };
      if (!Array.isArray(data.assets) || typeof data.csrf_token !== 'string' ||
          data.csrf_token.length === 0) throw new Error('Respuesta sintética inválida.');
      if (!abort.signal.aborted) {
        setRecords(data.assets.map(fromApi));
        setCsrfToken(data.csrf_token);
        setLoadState('ready');
      }
    }).catch(() => {
      if (!abort.signal.aborted) setLoadState('error');
    });
    return () => abort.abort();
  }, [apiPath, canView, tenantId, companyId, branchId, attempt]);

  async function persist(method: 'POST' | 'PATCH', path: string, payload: object): Promise<Asset | null> {
    if (!csrfToken || busy) {
      setNotice('Operación no autorizada o ya en curso.');
      return null;
    }
    setBusy(true);
    try {
      const response = await fetch(path, {
        method,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken,
        },
        body: JSON.stringify(payload),
      });
      if (response.status === 401 || response.status === 403) {
        setRecords([]);
        setSelected('');
        setLoadState('permission-denied');
        return null;
      }
      if (!response.ok) throw new Error('El servidor rechazó la operación sintética.');
      const result = await response.json() as { asset: ApiAsset };
      const record = fromApi(result.asset);
      setRecords(previous => {
        const existing = previous.some(row => row.ref === record.ref);
        return existing ? previous.map(row => row.ref === record.ref ? record : row)
          : [...previous, record];
      });
      return record;
    } catch {
      setNotice('Error de red o validación: no se confirmó ninguna escritura.');
      return null;
    } finally {
      setBusy(false);
    }
  }

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
    void persist('POST', apiPath, { asset_ref: reference }).then(record => {
      if (!record) return;
      audit(record.ref, 'created');
      setReference('');
      setNotice('Activo ficticio guardado por API sintética, sin aprobación.');
    });
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
    const path = apiPath + '/' + encodeURIComponent(selected.slice('fixture:asset/'.length));
    const assignment = action === 'ASIGNAR';
    const payload = assignment ? { responsible_ref: responsible } : action === 'SOLICITAR_BAJA'
      ? { state: 'BAJA_REQUIERE_REVISION', evidence_ref: evidence }
      : { state: 'EN_REVISION' };
    void persist('PATCH', path + (assignment ? '/responsible' : '/state'), payload).then(record => {
      if (!record) return;
      audit(selected, 'state_changed');
      setResponsible('');
      setEvidence('');
      setNotice('Transición sintética auditada en servidor; pendiente de revisión humana.');
    });
  }

  if (!canView) return (
    <section id="assets-management" aria-label="Activos empresariales (pruebas)">
      <p role="alert">No tienes permiso o razón social activa para consultar activos ficticios.</p>
    </section>
  );

  if (loadState === 'loading') return (
    <section id="assets-management"><p role="status">Cargando activos sintéticos…</p></section>
  );
  if (loadState === 'permission-denied') return (
    <section id="assets-management"><p role="alert">Permiso denegado (401/403).</p></section>
  );
  if (loadState === 'error') return (
    <section id="assets-management"><p role="alert">Error de red o API sintética no habilitada.</p>
      <button type="button" onClick={() => setAttempt(value => value + 1)}>Reintentar</button>
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
        <p className="muted">API sintética DEV. Auditoría de servidor, sin validez jurídica ni datos reales.</p>
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
            <button className="button" type="submit" disabled={busy}>Inventariar activo ficticio</button>
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
              <button type="submit" disabled={busy}>Registrar transición ficticia explícita</button>
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
