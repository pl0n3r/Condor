/// <reference types="vite/client" />
import { useEffect, useState, type FormEvent } from 'react';
import { DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';

const kinds = ['EQUIPO', 'MUEBLE', 'VEHICULO'] as const;
const states = ['PENDIENTE', 'ASIGNADO', 'EN_REVISION', 'BAJA_REQUIERE_REVISION'] as const;
type Kind = typeof kinds[number];
type Stage = typeof states[number];
const choices: Record<Stage, Stage[]> = {
  PENDIENTE: ['ASIGNADO', 'EN_REVISION'],
  ASIGNADO: ['EN_REVISION', 'BAJA_REQUIERE_REVISION'],
  EN_REVISION: ['ASIGNADO', 'BAJA_REQUIERE_REVISION'],
  BAJA_REQUIERE_REVISION: ['EN_REVISION'],
};
const names: Record<Stage, string> = {
  PENDIENTE: 'Pendiente', ASIGNADO: 'Asignado',
  EN_REVISION: 'En revisión', BAJA_REQUIERE_REVISION: 'Baja en revisión',
};
export type AssetEntry = {
  ref: string; category: Kind; state: Stage; version: number;
  tenant: string; company: string; occurredAt: string;
  responsible: string | null; evidence: string | null;
};
type Reply =
  | { status: 'disabled' | 'denied' | 'error' }
  | { status: 'ready'; rows: AssetEntry[]; cursor: string | null }
  | { status: 'ready'; entry: AssetEntry };
export type AssetRequest = {
  enabled: boolean; allowed: boolean; tenant: string; company: string; branch: string;
  verb?: 'GET' | 'POST' | 'PATCH'; ref?: string; after?: string;
  category?: Kind; next?: Stage; version?: number; responsible?: string;
  evidence?: string; csrf?: string; signal?: AbortSignal;
};
type Props = {
  enabled: boolean; tenantId: string; companyId: string; branchId: string;
  permissions: string[]; csrfToken: string;
};
const opaque = (value: string) =>
  /^[a-z][a-z0-9_-]{0,63}$/.test(value) && !/[0-9]{7,}/.test(value);

function asAsset(value: unknown, tenant: string, company: string): AssetEntry | undefined {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return;
  const row = value as Record<string, unknown>;
  const version = row.version;
  if (row.tenant_id !== tenant || row.company_id !== company ||
    typeof row.record_ref !== 'string' || !opaque(row.record_ref) ||
    !kinds.some(kind => kind === row.category) ||
    !states.some(state => state === row.state) ||
    typeof version !== 'number' || !Number.isSafeInteger(version) ||
    version < 1 || version > 1_000_000 ||
    typeof row.occurred_at !== 'string' ||
    !/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/.test(row.occurred_at) ||
    (row.responsible_ref !== null && row.responsible_ref !== undefined &&
      (typeof row.responsible_ref !== 'string' || !opaque(row.responsible_ref))) ||
    (row.evidence_ref !== null && row.evidence_ref !== undefined &&
      (typeof row.evidence_ref !== 'string' || !opaque(row.evidence_ref)))) return;
  return {
    ref: row.record_ref, category: row.category as Kind, state: row.state as Stage,
    version, tenant, company, occurredAt: row.occurred_at,
    responsible: typeof row.responsible_ref === 'string' ? row.responsible_ref : null,
    evidence: typeof row.evidence_ref === 'string' ? row.evidence_ref : null,
  };
}

/** Fail-closed HTTP boundary. OFF never contacts the server. */
export async function requestAssets(
  opt: AssetRequest, transport: typeof fetch = fetch,
): Promise<Reply> {
  if (!opt.enabled) return { status: 'disabled' };
  if (!opt.allowed || !opt.tenant || !opt.branch || !opt.company ||
    opt.company === '__unassigned__') return { status: 'denied' };
  const method = opt.verb ?? 'GET';
  if ((opt.ref !== undefined && !opaque(opt.ref)) ||
    (opt.after !== undefined && !opaque(opt.after))) return { status: 'error' };
  if (method === 'POST' && (!opt.csrf || !opt.ref ||
    !kinds.includes(opt.category as Kind))) return { status: 'error' };
  if (method === 'PATCH' && (!opt.csrf || !opt.ref ||
    !states.includes(opt.next as Stage) ||
    !Number.isSafeInteger(opt.version) || (opt.version ?? 0) < 1 ||
    (opt.version ?? 0) >= 1_000_000 ||
    (opt.next === 'ASIGNADO' && (!opt.responsible || !opaque(opt.responsible))) ||
    (opt.next === 'BAJA_REQUIERE_REVISION' && (!opt.evidence || !opaque(opt.evidence))) ||
    (opt.responsible !== undefined && !opaque(opt.responsible)) ||
    (opt.evidence !== undefined && !opaque(opt.evidence)))) return { status: 'error' };
  const base = '/api/v1/branches/' + encodeURIComponent(opt.branch) + '/assets-real/records';
  const url = method === 'POST' || (!opt.ref && !opt.after) ? base
    : method === 'GET' && opt.after ? base + '?after=' + encodeURIComponent(opt.after)
      : base + '/' + encodeURIComponent(opt.ref ?? '') + (method === 'PATCH' ? '/state' : '');
  const payload = method === 'POST'
    ? { record_ref: opt.ref, category: opt.category }
    : { state: opt.next, expected_version: opt.version,
        ...(opt.next === 'ASIGNADO' ? { responsible_ref: opt.responsible } : {}),
        ...(opt.next === 'BAJA_REQUIERE_REVISION' ? { evidence_ref: opt.evidence } : {}) };
  try {
    const response = await transport(url, {
      method, credentials: 'same-origin', cache: 'no-store', signal: opt.signal,
      headers: method === 'GET' ? { Accept: 'application/json' } : {
        Accept: 'application/json', 'Content-Type': 'application/json',
        'X-CSRF-Token': opt.csrf ?? '',
      },
      ...(method === 'GET' ? {} : { body: JSON.stringify(payload) }),
    });
    if (response.status === 401 || response.status === 403) return { status: 'denied' };
    const data: unknown = await response.json().catch(() => null);
    if (response.status === 404 && data && typeof data === 'object' &&
      'error' in data && data.error === 'feature_unavailable') return { status: 'disabled' };
    if (!response.ok || !data || typeof data !== 'object' || Array.isArray(data)) {
      return { status: 'error' };
    }
    const body = data as Record<string, unknown>;
    if (method === 'GET' && !opt.ref) {
      const values = body.records, next = body.next_cursor;
      if (!Array.isArray(values) || values.length > 100 ||
        !(next === null || (typeof next === 'string' && opaque(next)))) {
        return { status: 'error' };
      }
      const rows = values.map(item => asAsset(item, opt.tenant, opt.company));
      if (rows.some(row => row === undefined)) return { status: 'error' };
      const assets = rows as AssetEntry[];
      const refs = assets.map(row => row.ref);
      if (new Set(refs).size !== refs.length ||
        refs.some((ref, i) => (i > 0 && ref <= refs[i - 1]) ||
          (opt.after !== undefined && ref <= opt.after)) ||
        (next !== null && (assets.length !== 100 || next !== refs.at(-1)))) {
        return { status: 'error' };
      }
      return { status: 'ready', rows: assets, cursor: next as string | null };
    }
    const entry = asAsset(body.record, opt.tenant, opt.company);
    if (!entry || (opt.ref && opt.ref !== entry.ref)) return { status: 'error' };
    return { status: 'ready', entry };
  } catch {
    return { status: 'error' };
  }
}

type Screen =
  | { state: 'loading' | 'error' | 'denied' | 'disabled' }
  | { state: 'ready'; rows: AssetEntry[]; cursor: string | null };

export function AssetsManagement({
  enabled, tenantId, companyId, branchId, permissions, csrfToken,
}: Props) {
  const allowed = permissions.includes('inventory.view') && Boolean(tenantId && branchId
    && companyId && companyId !== '__unassigned__');
  const createAllowed = permissions.includes('inventory.create');
  const updateAllowed = permissions.includes('inventory.update');
  const base = { enabled, tenant: tenantId, company: companyId, branch: branchId };
  const [screen, setScreen] = useState<Screen>({ state: 'loading' });
  const [refresh, setRefresh] = useState(0);
  const [search, setSearch] = useState('');
  const [stateFilter, setStateFilter] = useState<'TODOS' | Stage>('TODOS');
  const [panel, setPanel] = useState<'new' | 'detail' | null>(null);
  const [ref, setRef] = useState('');
  const [category, setCategory] = useState<Kind>('EQUIPO');
  const [selected, setSelected] = useState<AssetEntry | null>(null);
  const [nextState, setNextState] = useState<Stage>('EN_REVISION');
  const [responsible, setResponsible] = useState('');
  const [evidence, setEvidence] = useState('');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');

  useEffect(() => {
    if (!enabled || !allowed) return;
    const abort = new AbortController();
    setScreen({ state: 'loading' });
    void requestAssets({ ...base, allowed, signal: abort.signal }).then(result => {
      if (abort.signal.aborted) return;
      setScreen(result.status === 'ready' && 'rows' in result
        ? { state: 'ready', rows: result.rows, cursor: result.cursor }
        : { state: result.status === 'ready' ? 'error' : result.status });
    });
    return () => abort.abort();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [enabled, tenantId, companyId, branchId, allowed, refresh]);

  async function more(cursor: string) {
    if (busy || screen.state !== 'ready') return;
    setBusy(true);
    const result = await requestAssets({ ...base, allowed, after: cursor });
    if (result.status === 'ready' && 'rows' in result &&
      !result.rows.some(row => screen.rows.some(existing => existing.ref === row.ref))) {
      setScreen({ state: 'ready', rows: [...screen.rows, ...result.rows], cursor: result.cursor });
    } else setNotice('No se pudo cargar otra página. Actualiza el listado.');
    setBusy(false);
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy || !enabled || !allowed || !csrfToken) return;
    const creating = panel === 'new';
    if (!(creating ? createAllowed : updateAllowed) || (!creating && !selected)) return;
    setBusy(true);
    setNotice('');
    const result = await requestAssets({
      ...base, allowed: true, csrf: csrfToken, verb: creating ? 'POST' : 'PATCH',
      ref: creating ? ref : selected?.ref,
      ...(creating ? { category } : {
        next: nextState, version: selected?.version,
        ...(nextState === 'ASIGNADO' ? { responsible } : {}),
        ...(nextState === 'BAJA_REQUIERE_REVISION' ? { evidence } : {}),
      }),
    });
    if (result.status === 'ready' && 'entry' in result) {
      setNotice('Cambio registrado. Las bajas y revisiones siguen sujetas a validación.');
      setPanel(null); setSelected(null); setRefresh(count => count + 1);
    } else setNotice(result.status === 'denied'
      ? 'No tienes permiso para modificar activos.'
      : 'No se confirmó el cambio. Comprueba la referencia y la versión vigente.');
    setBusy(false);
  }

  if (!enabled) return <section id="assets-management" className="ui-page">
    <PageHeader title="Activos empresariales" description="Gestión pendiente de habilitación." />
    <p role="status">El módulo aún no está habilitado. No se consultan ni muestran activos.</p>
  </section>;
  if (!allowed) return <section id="assets-management" className="ui-page">
    <PageHeader title="Activos empresariales" description="Acceso limitado al ámbito autorizado." />
    <p role="alert">Selecciona una razón social y una sede autorizadas para ver activos.</p>
  </section>;
  const all = screen.state === 'ready' ? screen.rows : [];
  const shown = all.filter(item =>
    (stateFilter === 'TODOS' || item.state === stateFilter) &&
    (item.ref + ' ' + item.category + ' ' + names[item.state])
      .toLocaleLowerCase('es-CO').includes(search.toLocaleLowerCase('es-CO')));

  return <section id="assets-management" className="ui-page" aria-label="Activos empresariales">
    <PageHeader title="Activos empresariales"
      description="Consulta y administra activos de la razón social seleccionada."
      actions={createAllowed && <button type="button" className="button" disabled={busy}
        onClick={() => { setRef(''); setCategory('EQUIPO'); setPanel('new'); }}>Nuevo activo</button>} />
    {screen.state === 'loading' && <output role="status" aria-live="polite">Cargando activos…</output>}
    {screen.state === 'error' && <p role="alert">No se pudo consultar el inventario.
      <button type="button" className="button button-secondary"
        onClick={() => setRefresh(n => n + 1)}>Reintentar</button></p>}
    {screen.state === 'denied' && <p role="alert">El servidor rechazó el acceso a activos.</p>}
    {screen.state === 'disabled' && <p role="status">El módulo aún no está habilitado.</p>}
    {screen.state === 'ready' && <>
      <MetricStrip label="Resumen de activos" items={[
        { label: 'Activos cargados', value: all.length },
        { label: 'Asignados', value: all.filter(x => x.state === 'ASIGNADO').length },
        { label: 'En revisión', value: all.filter(x =>
          x.state === 'EN_REVISION' || x.state === 'BAJA_REQUIERE_REVISION').length },
      ]} />
      <Toolbar trailing={<span className="ui-count">{shown.length} activos</span>}>
        <label className="ui-search"><span className="ui-sr-only">Buscar activos</span>
          <input type="search" value={search} placeholder="Buscar por referencia o categoría"
            onChange={event => setSearch(event.target.value)} /></label>
        <label>Estado
          <select aria-label="Filtrar por estado" value={stateFilter}
            onChange={event => setStateFilter(event.target.value as typeof stateFilter)}>
            <option value="TODOS">Todos</option>
            {states.map(x => <option value={x} key={x}>{names[x]}</option>)}
          </select>
        </label>
      </Toolbar>
      <DataTable caption="Activos de la razón social" rows={shown} rowKey={x => x.ref}
        onOpen={row => { setSelected(row);
          setNextState(choices[row.state][0] ?? 'EN_REVISION'); setPanel('detail'); }}
        empty={<p role="status">No hay activos para los filtros seleccionados.</p>}
        columns={[
          { key: 'ref', header: 'Referencia', render: row => row.ref },
          { key: 'category', header: 'Categoría', render: row => row.category },
          { key: 'state', header: 'Estado', render: row => <StatusPill
            tone={row.state === 'ASIGNADO' ? 'ok' : row.state === 'PENDIENTE' ? 'neutral' : 'warn'}>
            {names[row.state]}</StatusPill> },
          { key: 'version', header: 'Versión', render: row => row.version },
        ]} />
      {screen.cursor && <button className="button button-secondary" type="button" disabled={busy}
        onClick={() => void more(screen.cursor!)}>Ver más activos</button>}
    </>}
    {notice && <output role="status" aria-live="polite" className="alert">{notice}</output>}
    <SidePanel open={panel !== null} title={panel === 'new' ? 'Nuevo activo' : 'Detalle del activo'}
      onClose={() => setPanel(null)}>
      <form onSubmit={event => void submit(event)}>
        {panel === 'new' ? <>
          <label className="field">Referencia interna
            <input required maxLength={64} pattern="[a-z][a-z0-9_-]*"
              placeholder="equipo_oficina" value={ref} onChange={event => setRef(event.target.value)} />
          </label>
          <label className="field">Categoría
            <select value={category} onChange={event => setCategory(event.target.value as Kind)}>
              {kinds.map(x => <option key={x} value={x}>{x}</option>)}
            </select>
          </label>
        </> : selected && <>
          <dl className="ui-details">
            <div><dt>Referencia</dt><dd>{selected.ref}</dd></div>
            <div><dt>Categoría</dt><dd>{selected.category}</dd></div>
            <div><dt>Estado</dt><dd>{names[selected.state]}</dd></div>
            <div><dt>Versión</dt><dd>{selected.version}</dd></div>
            <div><dt>Responsable</dt><dd>{selected.responsible ?? 'Sin asignar'}</dd></div>
          </dl>
          {updateAllowed && <label className="field">Nuevo estado
            <select value={nextState} onChange={event => setNextState(event.target.value as Stage)}>
              {choices[selected.state].map(x => <option value={x} key={x}>{names[x]}</option>)}
            </select>
          </label>}
          {updateAllowed && nextState === 'ASIGNADO' && <label className="field">Referencia del responsable
            <input required maxLength={64} pattern="[a-z][a-z0-9_-]*"
              value={responsible} onChange={event => setResponsible(event.target.value)} />
          </label>}
          {updateAllowed && nextState === 'BAJA_REQUIERE_REVISION' && <label className="field">Referencia de evidencia
            <input required maxLength={64} pattern="[a-z][a-z0-9_-]*"
              value={evidence} onChange={event => setEvidence(event.target.value)} />
          </label>}
        </>}
        <p className="muted">Las transiciones no equivalen a aprobación automática ni a baja definitiva.</p>
        {((panel === 'new' && createAllowed) || (panel === 'detail' && updateAllowed)) &&
          <button className="button" type="submit" disabled={busy}>
            {busy ? 'Guardando…' : panel === 'new' ? 'Crear activo' : 'Registrar cambio'}
          </button>}
      </form>
    </SidePanel>
  </section>;
}
