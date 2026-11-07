/// <reference types="vite/client" />
import { useEffect, useState, type FormEvent } from 'react';
import { Card, DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';

// Contratos M02: únicamente referencias sintéticas, sesión aislada y ninguna firma jurídica.
type Phase = 'REQUIERE_REVISION' | 'EN_REVISION';
type ContractType = 'template' | 'contract' | 'addendum';
type Draft = Readonly<{ ref: string; category: ContractType; phase: Phase; scope: string }>;
type Note = Readonly<{ id: number; scope: string; ref: string; operation: string }>;
type Props = Readonly<{ tenantId: string; companyId: string; branchId: string; permissions: string[] }>;
type Result = { status: 'ready' | 'disabled' | 'denied' | 'error'; records?: Draft[]; record?: Draft };
const allowedType: readonly ContractType[] = ['template', 'contract', 'addendum'];
const labels: Record<ContractType, string> = { template: 'Plantilla', contract: 'Contrato', addendum: 'Otrosí' };
const refPattern = /^fixture:(template|contract|addendum)\/[a-z][a-z0-9_-]{0,47}$/;

export function contractsSyntheticEnabled(root: { dataset?: { contractsSyntheticApiEnabled?: string } } | null): boolean {
  return root?.dataset?.contractsSyntheticApiEnabled === 'true';
}

function verifiedRecord(value: unknown, scope: string): Draft {
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw Error('invalid record');
  const row = value as Record<string, unknown>;
  if (Object.keys(row).sort((a, b) => a.localeCompare(b)).join(',') !== 'kind,ref,state,synthetic_only'
    || typeof row.ref !== 'string' || typeof row.kind !== 'string'
    || !refPattern.test(row.ref) || row.ref.startsWith('fixture:' + row.kind + '/') === false
    || !allowedType.includes(row.kind as ContractType)
    || !['REQUIERE_REVISION', 'EN_REVISION'].includes(String(row.state))
    || row.synthetic_only !== true || /[0-9]{7,}/.test(row.ref)) throw Error('invalid synthetic record');
  return { ref: row.ref, category: row.kind as ContractType, phase: row.state as Phase, scope };
}

export async function syntheticContractRequest(
  options: { enabled: boolean; allowed: boolean; branchId: string; scope: string;
    method?: 'GET' | 'POST' | 'PATCH'; ref?: string; kind?: ContractType;
    state?: Phase; csrf?: string },
  transport: typeof fetch = fetch,
): Promise<Result> {
  if (!options.enabled) return { status: 'disabled' };
  if (!options.allowed) return { status: 'denied' };
  const method = options.method ?? 'GET';
  if (options.ref && (!refPattern.test(options.ref) || /[0-9]{7,}/.test(options.ref))) return { status: 'error' };
  if (method !== 'GET' && (!options.csrf || (method === 'POST'
    ? !options.ref || !options.kind || !options.ref.startsWith('fixture:' + options.kind + '/')
    : !options.ref || options.state !== 'EN_REVISION'))) return { status: 'error' };
  const base = '/api/v1/branches/' + encodeURIComponent(options.branchId) + '/contracts';
  const url = options.ref && method !== 'POST'
    ? base + '/' + options.ref.slice('fixture:'.length) + (method === 'PATCH' ? '/state' : '') : base;
  try {
    const response = await transport(url, {
      method, credentials: 'same-origin', cache: 'no-store',
      headers: { Accept: 'application/json', ...(method === 'GET' ? {} :
        { 'Content-Type': 'application/json', 'X-CSRF-Token': options.csrf ?? '' }) },
      ...(method === 'GET' ? {} : { body: JSON.stringify(method === 'POST'
        ? { kind: options.kind, ref: options.ref } : { state: options.state }) }),
    });
    if (response.status === 401 || response.status === 403) return { status: 'denied' };
    if (response.status === 404 && !options.ref) return { status: 'disabled' };
    if (!response.ok) return { status: 'error' };
    const body = await response.json() as Record<string, unknown>;
    if (body.synthetic_only !== true) return { status: 'error' };
    if (method === 'GET' && !options.ref) {
      if (!Array.isArray(body.records) || body.records.length > 100) return { status: 'error' };
      const records = body.records.map(row => verifiedRecord(row, options.scope));
      if (new Set(records.map(row => row.ref)).size !== records.length) return { status: 'error' };
      return { status: 'ready', records };
    }
    const record = verifiedRecord(body.record, options.scope);
    return record.ref === options.ref ? { status: 'ready', record } : { status: 'error' };
  } catch { return { status: 'error' }; }
}

function ContractPreview({ tenantId, companyId, branchId, permissions }: Props) {
  const scope = [tenantId, companyId, branchId].join('|');
  const canRead = companyId !== '__unassigned__' && permissions.includes('inventory.view');
  const canAdd = canRead && permissions.includes('inventory.create');
  const canAdvance = canRead && permissions.includes('inventory.update');
  const [drafts, setDrafts] = useState<Draft[]>([]);
  const [trail, setTrail] = useState<Note[]>([]);
  const [ref, setRef] = useState('');
  const [category, setCategory] = useState<ContractType>('contract');
  const [picked, setPicked] = useState<string | null>(null);
  const [panel, setPanel] = useState<'create' | 'detail' | null>(null);
  const [filter, setFilter] = useState('');
  const [notice, setNotice] = useState('');
  const [remoteState, setRemoteState] = useState<'loading' | 'ready' | 'denied' | 'error'>('loading');
  const [busy, setBusy] = useState(false);
  const endpoint = { enabled: true, branchId, scope };

  useEffect(() => {
    if (!canRead) return;
    let active = true;
    setRemoteState('loading');
    void syntheticContractRequest({ ...endpoint, allowed: canRead })
      .then(result => {
        if (!active) return;
        setDrafts(result.records ?? []);
        setRemoteState(result.status === 'ready' ? 'ready'
          : result.status === 'denied' ? 'denied' : 'error');
      });
    return () => { active = false; };
  }, [scope, canRead]);

  const scopedDrafts = drafts.filter(entry => entry.scope === scope);
  const listing = scopedDrafts.filter(entry => filter === '' || entry.ref === filter);
  const selected = scopedDrafts.find(entry => entry.ref === picked);

  function record(refToRecord: string, operation: string) {
    setTrail(items => items.concat({ id: (items[items.length - 1]?.id ?? 0) + 1,
      scope, ref: refToRecord, operation }).slice(-80));
  }

  async function createDemo(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy || !canAdd) return;
    setBusy(true);
    const response = await syntheticContractRequest({ ...endpoint, allowed: canAdd,
      method: 'POST', ref, kind: category,
      csrf: document.getElementById('condor-admin-root')?.dataset.accessToken });
    setBusy(false);
    if (!response.record) { setNotice('No se creó el borrador: ' + response.status); return; }
    setDrafts(rows => rows.concat(response.record!).slice(-100));
    record(ref, 'borrador_confirmado_por_servidor');
    setPicked(ref); setPanel('detail'); setRef('');
    setNotice('Solicitud sintética confirmada por Symfony, sin valor jurídico.');
  }

  async function inspect(item: Draft) {
    if (!canRead || busy || item.scope !== scope) return;
    setBusy(true);
    const response = await syntheticContractRequest({ ...endpoint, allowed: canRead, ref: item.ref });
    setBusy(false);
    if (!response.record) { setNotice('No se pudo consultar el contrato de prueba.'); return; }
    setDrafts(rows => rows.map(row => row.ref === item.ref ? response.record! : row));
    setPicked(item.ref); setPanel('detail'); record(item.ref, 'consulta_de_servidor');
  }

  async function advance(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canAdvance || busy || !selected || selected.phase === 'EN_REVISION') return;
    setBusy(true);
    const response = await syntheticContractRequest({ ...endpoint, allowed: canAdvance,
      method: 'PATCH', ref: selected.ref, state: 'EN_REVISION',
      csrf: document.getElementById('condor-admin-root')?.dataset.accessToken });
    setBusy(false);
    if (!response.record) { setNotice('El servidor rechazó la transición: ' + response.status); return; }
    setDrafts(rows => rows.map(row => row.ref === selected.ref ? response.record! : row));
    record(selected.ref, 'revision_manual_confirmada');
    setNotice('Revisión manual confirmada por el servidor; no es una firma.');
  }

  if (!canRead) return <section id="contracts-management" className="catalog-panel ui-page" aria-label="Contratación ficticia">
    <PageHeader title="Contratación laboral · prueba local M02" />
    <p role="alert">No tienes permiso de lectura de la demostración o no hay empresa seleccionada.</p>
  </section>;

  return <section id="contracts-management" className="catalog-panel ui-page" aria-label="Contratación ficticia">
    <PageHeader title="Contratación laboral · prueba local M02"
      description="Borradores y revisiones manuales de demostración, separados por empresa y sede."
      actions={canAdd ? <button type="button" className="button" onClick={() => setPanel('create')}>
        Crear borrador ficticio
      </button> : undefined} />
    <p role="alert">SOLO DATOS FICTICIOS: API de sesión, sin valor legal ni datos de trabajadores.</p>
    {remoteState === "loading" && <p role="status">Cargando contratos sintéticos…</p>}
    {remoteState === "denied" && <p role="alert">Acceso denegado por el servidor.</p>}
    {remoteState === "error" && <p role="alert">No se pudieron cargar los contratos sintéticos.</p>}
    {notice && <output role="status" aria-live="polite">{notice}</output>}
    <MetricStrip label="Indicadores de contratación de prueba" items={[
      { label: 'Borradores de prueba', value: scopedDrafts.length },
      { label: 'Requieren revisión', value: scopedDrafts.filter(item => item.phase === 'REQUIERE_REVISION').length },
      { label: 'En revisión', value: scopedDrafts.filter(item => item.phase === 'EN_REVISION').length },
    ]} />
    <Card title="Borradores de prueba">
      <Toolbar trailing={<output aria-live="polite">{listing.length} registros</output>}>
        <label>Filtrar por referencia ficticia
          <select value={filter} onChange={event => setFilter(event.target.value)}>
            <option value="">Todas las referencias</option>
            {scopedDrafts.map(item => <option key={item.ref} value={item.ref}>{item.ref}</option>)}
          </select>
        </label>
      </Toolbar>
      <DataTable<Draft> caption="Contratos sintéticos"
        rows={remoteState === "ready" ? listing : []} rowKey={item => item.ref}
        onOpen={item => inspect(item)}
        columns={[
          { key: 'ref', header: 'Referencia', render: item => item.ref },
          { key: 'type', header: 'Clase', render: item => labels[item.category] },
          { key: 'state', header: 'Estado', render: item =>
            <StatusPill tone={item.phase === 'EN_REVISION' ? 'info' : 'warn'}>{item.phase}</StatusPill> },
          { key: 'action', header: 'Acción', render: item =>
            <button type="button" onClick={() => inspect(item)}>Consultar prueba</button> },
        ]}
        empty={<output>Sin registros en esta empresa y sede.</output>} />
    </Card>
    <Card title="Bitácora local de demostración">
      <p className="muted">No es evidencia ni auditoría de servidor.</p>
      {trail.filter(note => note.scope === scope).length === 0
        ? <p role="status">Sin operaciones de prueba.</p>
        : <ol>{trail.filter(note => note.scope === scope).map(note =>
          <li key={note.id}>{note.ref}: {note.operation}</li>)}</ol>}
    </Card>
    <SidePanel open={panel === 'create' && canAdd} title="Nuevo borrador de prueba"
      onClose={() => setPanel(null)}>
      <form className="catalog-editor" onSubmit={createDemo}>
        <label>Contrato sintético
          <input value={ref} required maxLength={64}
            placeholder={"fixture:" + category + "/demo"}
            pattern="fixture:(template|contract|addendum)/[a-z][a-z0-9_-]{0,47}"
            onChange={event => setRef(event.target.value)} />
        </label>
        <label>Tipo de borrador
          <select value={category} onChange={event => { setCategory(event.target.value as ContractType); setRef(""); }}>
            {allowedType.map(item => <option key={item} value={item}>{labels[item]}</option>)}
          </select>
        </label>
        <button type="submit">Crear borrador ficticio</button>
      </form>
    </SidePanel>
    <SidePanel open={panel === 'detail' && !!selected} title="Revisión manual de prueba"
      onClose={() => setPanel(null)}>
      {selected ? <>
        <dl>
          <dt>Referencia</dt><dd>{selected.ref}</dd>
          <dt>Tipo</dt><dd>{labels[selected.category]}</dd>
          <dt>Estado</dt><dd>
            <StatusPill tone={selected.phase === 'EN_REVISION' ? 'info' : 'warn'}>{selected.phase}</StatusPill>
          </dd>
        </dl>
        {canAdvance && selected.phase !== 'EN_REVISION' && (
          <form onSubmit={advance}><button type="submit">
            {'Iniciar revisión de prueba'}
          </button></form>
        )}
        <p className="muted">Ningún borrador se aprueba, firma o cierra automáticamente.</p>
      </> : <output>Selecciona una referencia sintética.</output>}
    </SidePanel>
  </section>;
}

// Un montaje por ámbito y solo en DEV. No habilita rutas reales ni persistencia.
export function ContractsManagement(props: Props) {
  if (!import.meta.env.DEV || !contractsSyntheticEnabled(
    typeof document === 'undefined' ? null : document.getElementById('condor-admin-root')
  )) return <section id="contracts-management"><p role="status">Contratación de prueba deshabilitada por el servidor.</p></section>;
  return <ContractPreview key={[props.tenantId, props.companyId, props.branchId].join('|')}
    {...props} />;
}


/** Contratación persistida M02. Solo alias opacos, sin firmas ni datos personales. */
export type RealContractRecord = Readonly<{
  ref: string; tenantId: string; companyId: string;
  workerRef: string; templateRef: string; kind: ContractType;
  state: Phase; version: number;
}>;
export type RealContractOptions = Readonly<{
  enabled: boolean; allowed: boolean; tenantId: string; companyId: string; branchId: string;
  method?: 'GET' | 'POST' | 'PATCH'; ref?: string; workerRef?: string;
  templateRef?: string; kind?: ContractType; state?: Phase;
  expectedVersion?: number; csrf?: string;
}>;
type RealResult = { status: 'disabled' | 'denied' | 'error' }
  | { status: 'ready'; records: RealContractRecord[] }
  | { status: 'ready'; record: RealContractRecord };
type RealProps = Props & { enabled: boolean; csrfToken: string };
const opaqueRef = /^[a-z][a-z0-9_-]{0,63}$/;

function verifiedRealRecord(input: unknown, tenant: string, company: string): RealContractRecord | null {
  if (!input || typeof input !== 'object' || Array.isArray(input)) return null;
  const row = input as Record<string, unknown>;
  if (row.tenant_id !== tenant || row.company_id !== company
    || typeof row.record_ref !== 'string' || !opaqueRef.test(row.record_ref)
    || typeof row.worker_ref !== 'string' || !opaqueRef.test(row.worker_ref)
    || typeof row.template_ref !== 'string' || !opaqueRef.test(row.template_ref)
    || typeof row.kind !== 'string' || !allowedType.includes(row.kind as ContractType)
    || (row.state !== 'REQUIERE_REVISION' && row.state !== 'EN_REVISION')
    || typeof row.version !== 'number' && typeof row.version !== 'string') return null;
  const version = Number(row.version);
  if (!Number.isInteger(version) || version < 1 || version > 1_000_000) return null;
  return { ref: row.record_ref, tenantId: tenant, companyId: company,
    workerRef: row.worker_ref, templateRef: row.template_ref,
    kind: row.kind as ContractType, state: row.state, version };
}

/** OFF, missing permission or invalid company: no HTTP request can leave this function. */
export async function contractRealRequest(options: RealContractOptions,
  transport: typeof fetch = fetch): Promise<RealResult> {
  if (!options.enabled) return { status: 'disabled' };
  if (!options.allowed) return { status: 'denied' };
  if (!options.tenantId || !options.companyId || options.companyId === '__unassigned__'
    || !options.branchId) return { status: 'denied' };
  const method = options.method ?? 'GET';
  if (!['GET', 'POST', 'PATCH'].includes(method)
    || (options.ref !== undefined && !opaqueRef.test(options.ref))) return { status: 'error' };
  if (method === 'POST' && (!options.csrf || !options.ref
    || !options.workerRef || !opaqueRef.test(options.workerRef)
    || !options.templateRef || !opaqueRef.test(options.templateRef)
    || !options.kind || !allowedType.includes(options.kind))) return { status: 'error' };
  if (method === 'PATCH' && (!options.csrf || !options.ref
    || options.state !== 'EN_REVISION' || !Number.isInteger(options.expectedVersion)
    || (options.expectedVersion ?? 0) < 1)) return { status: 'error' };
  const root = '/api/v1/branches/' + encodeURIComponent(options.branchId) + '/contracts/real-records';
  const url = options.ref && method !== 'POST' ? root + '/' + encodeURIComponent(options.ref)
    + (method === 'PATCH' ? '/state' : '') : root;
  const data = method === 'POST'
    ? { record_ref: options.ref, worker_ref: options.workerRef,
      template_ref: options.templateRef, kind: options.kind }
    : { state: options.state, expected_version: options.expectedVersion };
  try {
    const response = await transport(url, {
      method, credentials: 'same-origin', cache: 'no-store',
      headers: { Accept: 'application/json', ...(method === 'GET' ? {}
        : { 'Content-Type': 'application/json', 'X-CSRF-Token': options.csrf ?? '' }) },
      ...(method === 'GET' ? {} : { body: JSON.stringify(data) }),
    });
    if (response.status === 401 || response.status === 403) return { status: 'denied' };
    if (response.status === 404) return { status: 'disabled' };
    if (!response.ok) return { status: 'error' };
    const body: unknown = await response.json();
    if (!body || typeof body !== 'object' || Array.isArray(body)) return { status: 'error' };
    const payload = body as Record<string, unknown>;
    if (method === 'GET' && !options.ref) {
      if (!Array.isArray(payload.records) || payload.records.length > 100) return { status: 'error' };
      const rows = payload.records.map(row =>
        verifiedRealRecord(row, options.tenantId, options.companyId));
      if (rows.some(row => row === null)) return { status: 'error' };
      const safe = rows as RealContractRecord[];
      if (new Set(safe.map(row => row.ref)).size !== safe.length) return { status: 'error' };
      return { status: 'ready', records: safe };
    }
    const record = verifiedRealRecord(payload.record, options.tenantId, options.companyId);
    if (!record || options.ref && record.ref !== options.ref) return { status: 'error' };
    return { status: 'ready', record };
  } catch { return { status: 'error' }; }
}

/** Vistas de laboratorio únicamente con referencias opacas y funcionalidad OFF por defecto. */
export function ContractsRealManagement({
  enabled, tenantId, companyId, branchId, permissions, csrfToken,
}: RealProps) {
  const canRead = permissions.includes('inventory.view') && companyId !== '__unassigned__';
  const canCreate = canRead && permissions.includes('inventory.create');
  const canUpdate = canRead && permissions.includes('inventory.update');
  const [status, setStatus] = useState<'loading' | 'ready' | 'denied' | 'error' | 'disabled'>('loading');
  const [rows, setRows] = useState<RealContractRecord[]>([]);
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [panel, setPanel] = useState<'create' | 'detail' | null>(null);
  const [selected, setSelected] = useState('');
  const [search, setSearch] = useState('');
  const [ref, setRef] = useState('');
  const [worker, setWorker] = useState('');
  const [template, setTemplate] = useState('');
  const [kind, setKind] = useState<ContractType>('contract');
  const [filter, setFilter] = useState<'TODOS' | Phase>('TODOS');

  useEffect(() => {
    if (!enabled || !canRead) return;
    let mounted = true;
    setStatus('loading');
    setRows([]);
    setPanel(null);
    void contractRealRequest({ enabled, allowed: canRead, tenantId, companyId, branchId })
      .then(result => {
        if (!mounted) return;
        if (result.status === 'ready' && 'records' in result) {
          setRows(result.records);
          setStatus('ready');
        } else setStatus(result.status === 'ready' ? 'error' : result.status);
      });
    return () => { mounted = false; };
  }, [enabled, canRead, tenantId, companyId, branchId]);

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy || status !== 'ready') return;
    setBusy(true);
    const result = await contractRealRequest({ enabled, allowed: canCreate, tenantId,
      companyId, branchId, method: 'POST', ref, workerRef: worker, templateRef: template,
      kind, csrf: csrfToken });
    setBusy(false);
    if (result.status === 'ready' && 'record' in result) {
      setRows(old => [...old, result.record]);
      setSelected(result.record.ref); setPanel('detail');
      setRef(''); setWorker(''); setTemplate('');
      setNotice('Registro creado. No hay firma, contratación real ni aprobación.');
    } else {
      if (result.status === 'denied') setStatus('denied');
      if (result.status === 'disabled') setStatus('disabled');
      setNotice('No se creó el registro. Comprueba los permisos y las referencias.');
    }
  }

  async function advance(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const current = rows.find(row => row.ref === selected);
    if (!current || current.state !== 'REQUIERE_REVISION' || busy || status !== 'ready') return;
    setBusy(true);
    const result = await contractRealRequest({ enabled, allowed: canUpdate,
      tenantId, companyId, branchId, method: 'PATCH', ref: current.ref,
      state: 'EN_REVISION', expectedVersion: current.version, csrf: csrfToken });
    setBusy(false);
    if (result.status === 'ready' && 'record' in result) {
      setRows(old => old.map(row => row.ref === result.record.ref ? result.record : row));
      setNotice('Estado actualizado y versionado en el servidor.');
    } else {
      if (result.status === 'denied') setStatus('denied');
      if (result.status === 'disabled') setStatus('disabled');
      setNotice('Transición rechazada. Actualiza la vista antes de reintentar.');
    }
  }

  const shell = (message: string, role: 'status' | 'alert') =>
    <section id="contracts-management" className="catalog-panel ui-page" aria-label="Contratación">
      <PageHeader title="Contratación" />
      <p role={role}>{message}</p>
    </section>;
  if (!enabled) return shell('La persistencia de Contratación permanece desactivada por el servidor.', 'status');
  if (!canRead || status === 'denied') return shell('Acceso denegado: empresa, razón social o permiso insuficientes.', 'alert');
  if (status === 'disabled') return shell('El servidor no tiene habilitada esta función.', 'alert');

  const visible = rows.filter(row =>
    (filter === 'TODOS' || row.state === filter)
    && [row.ref, row.workerRef, row.templateRef].some(value =>
      value.includes(search.trim().toLowerCase())));
  const current = rows.find(row => row.ref === selected);
  return <section id="contracts-management" className="catalog-panel ui-page" aria-label="Contratación">
    <PageHeader title="Contratación" description="Registros versionados por empresa y razón social."
      actions={canCreate ? <button type="button" onClick={() => setPanel('create')}>Nuevo registro</button> : undefined} />
    <p className="muted">Solo referencias opacas; sin datos personales, firmas ni efectos jurídicos.</p>
    {notice && <output aria-live="polite" role="status">{notice}</output>}
    {status === 'loading' && <p role="status">Cargando registros de contratación…</p>}
    {status === 'error' && <p role="alert">No fue posible cargar los registros.</p>}
    {status === 'ready' && <>
      <MetricStrip label="Resumen de contratación" items={[
        { label: 'Registros', value: rows.length },
        { label: 'Requieren revisión', value: rows.filter(row => row.state === 'REQUIERE_REVISION').length },
        { label: 'En revisión', value: rows.filter(row => row.state === 'EN_REVISION').length },
      ]} />
      <Card title="Registros por razón social">
        <Toolbar>
          <label>Buscar referencias <input type="search" value={search}
            onChange={event => setSearch(event.target.value)} /></label>
          <label>Estado <select value={filter}
            onChange={event => setFilter(event.target.value as typeof filter)}>
            <option value="TODOS">Todos</option>
            <option value="REQUIERE_REVISION">Requiere revisión</option>
            <option value="EN_REVISION">En revisión</option>
          </select></label>
        </Toolbar>
        <DataTable<RealContractRecord> caption="Contratación por empresa"
          rows={visible} rowKey={row => row.ref}
          onOpen={row => { setSelected(row.ref); setPanel('detail'); }}
          columns={[
            { key: 'reference', header: 'Referencia', render: row => row.ref },
            { key: 'worker', header: 'Colaborador (alias)', render: row => row.workerRef },
            { key: 'template', header: 'Plantilla (alias)', render: row => row.templateRef },
            { key: 'kind', header: 'Tipo', render: row => labels[row.kind] },
            { key: 'state', header: 'Estado', render: row =>
              <StatusPill tone={row.state === 'EN_REVISION' ? 'warn' : 'neutral'}>
                {row.state === 'EN_REVISION' ? 'En revisión' : 'Requiere revisión'}
              </StatusPill> },
          ]} empty={<p role="status">No hay registros para este filtro.</p>} />
      </Card>
    </>}
    <SidePanel open={panel === 'create' && canCreate && status === 'ready'}
      title="Nuevo registro de contratación" onClose={() => setPanel(null)}>
      <form onSubmit={event => void create(event)}>
        <p>Introduce alias ficticios, nunca nombres ni documentos personales.</p>
        <label>Referencia <input required pattern="[a-z][a-z0-9_\-]{0,63}" maxLength={64}
          value={ref} onChange={event => setRef(event.target.value)} /></label>
        <label>Colaborador (alias) <input required pattern="[a-z][a-z0-9_\-]{0,63}" maxLength={64}
          value={worker} onChange={event => setWorker(event.target.value)} /></label>
        <label>Plantilla (alias) <input required pattern="[a-z][a-z0-9_\-]{0,63}" maxLength={64}
          value={template} onChange={event => setTemplate(event.target.value)} /></label>
        <label>Tipo <select value={kind} onChange={event => setKind(event.target.value as ContractType)}>
          {allowedType.map(value => <option key={value} value={value}>{labels[value]}</option>)}
        </select></label>
        <button type="submit" disabled={busy}>{busy ? 'Guardando…' : 'Guardar registro'}</button>
      </form>
    </SidePanel>
    <SidePanel open={panel === 'detail' && !!current && status === 'ready'}
      title="Detalle de contratación" onClose={() => setPanel(null)}>
      {current && <><dl>
        <dt>Referencia</dt><dd>{current.ref}</dd>
        <dt>Colaborador</dt><dd>{current.workerRef}</dd>
        <dt>Plantilla</dt><dd>{current.templateRef}</dd>
        <dt>Tipo</dt><dd>{labels[current.kind]}</dd>
        <dt>Versión auditada</dt><dd>{current.version}</dd>
        <dt>Estado</dt><dd>{current.state === 'EN_REVISION' ? 'En revisión' : 'Requiere revisión'}</dd>
      </dl>
        {canUpdate && current.state === 'REQUIERE_REVISION' && <form onSubmit={event => void advance(event)}>
          <button type="submit" disabled={busy}>{busy ? 'Guardando…' : 'Enviar a revisión'}</button>
        </form>}
      </>}
    </SidePanel>
  </section>;
}
