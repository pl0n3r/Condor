/// <reference types="vite/client" />
import { useEffect, useState, type FormEvent } from 'react';
import { DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';

type ProcessKind = 'PROCESO' | 'PROCEDIMIENTO' | 'FLUJO' | 'CONTROL';
type ProcessState = 'PENDIENTE' | 'EN_REVISION' | 'REQUIERE_REVISION';
export type ProcessEntry = Readonly<{
  ref: string; kind: ProcessKind; state: ProcessState; version: number;
  tenant: string; company: string; occurredAt: string;
}>;
type ProcessResult =
  | { status: 'disabled' | 'denied' | 'error' }
  | { status: 'ready'; rows: ProcessEntry[]; cursor: string | null }
  | { status: 'ready'; entry: ProcessEntry };
type Verb = 'GET' | 'POST' | 'PATCH';
export type ProcessRequest = Readonly<{
  enabled: boolean; allowed: boolean; tenant: string; company: string; branch: string;
  verb?: Verb; ref?: string; after?: string; kind?: ProcessKind;
  next?: ProcessState; version?: number; csrf?: string; signal?: AbortSignal;
}>;
type Props = Readonly<{
  enabled: boolean; tenantId: string; companyId: string; branchId: string;
  permissions: string[]; csrfToken: string;
}>;
const categories: readonly ProcessKind[] = ['PROCESO', 'PROCEDIMIENTO', 'FLUJO', 'CONTROL'];
const stages: readonly ProcessState[] = ['PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION'];
const routes: Readonly<Record<ProcessState, readonly ProcessState[]>> = {
  PENDIENTE: ['EN_REVISION', 'REQUIERE_REVISION'],
  EN_REVISION: ['REQUIERE_REVISION'],
  REQUIERE_REVISION: ['EN_REVISION'],
};
const names: Record<ProcessState, string> = {
  PENDIENTE: 'Pendiente', EN_REVISION: 'En revisión', REQUIERE_REVISION: 'Requiere revisión',
};
const alias = (v: string): boolean => /^[a-z][a-z0-9_-]{0,63}$/.test(v) && !/[0-9]{7,}/.test(v);

function parseEntry(item: unknown, tenant: string, company: string): ProcessEntry | null {
  if (item === null || typeof item !== 'object' || Array.isArray(item)) return null;
  const source = item as Record<string, unknown>;
  if (source.tenant_id !== tenant || source.company_id !== company
    || typeof source.record_ref !== 'string' || !alias(source.record_ref)
    || !categories.includes(source.kind as ProcessKind) || !stages.includes(source.state as ProcessState)
    || typeof source.occurred_at !== 'string'
    || !/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/.test(source.occurred_at)) return null;
  const version = Number(source.version);
  if (!Number.isSafeInteger(version) || version < 1 || version > 1_000_000) return null;
  return { ref: source.record_ref, kind: source.kind as ProcessKind,
    state: source.state as ProcessState, version, tenant, company,
    occurredAt: source.occurred_at };
}

/** Un único borde HTTP: si falta ámbito, permiso o flag, no se consulta la red. */
export async function requestOperationalProcess(
  opt: ProcessRequest, transport: typeof fetch = fetch,
): Promise<ProcessResult> {
  if (!opt.enabled) return { status: 'disabled' };
  if (!opt.allowed || !opt.tenant || !opt.company || !opt.branch
    || opt.company === '__unassigned__') return { status: 'denied' };
  const verb = opt.verb ?? 'GET';
  if (opt.ref !== undefined && !alias(opt.ref)) return { status: 'error' };
  if (opt.after !== undefined && !alias(opt.after)) return { status: 'error' };
  if (verb === 'POST' && (!opt.csrf || !opt.ref
    || !categories.includes(opt.kind as ProcessKind))) return { status: 'error' };
  if (verb === 'PATCH' && (!opt.csrf || !opt.ref || !stages.includes(opt.next as ProcessState)
    || !Number.isInteger(opt.version) || (opt.version ?? 0) < 1
    || (opt.version ?? 0) >= 1_000_000)) return { status: 'error' };

  const endpoint = '/api/v1/branches/' + encodeURIComponent(opt.branch)
    + '/operations-processes-real/records';
  const target = opt.ref && verb !== 'POST'
    ? endpoint + '/' + encodeURIComponent(opt.ref) + (verb === 'PATCH' ? '/state' : '')
    : endpoint + (verb === 'GET' && opt.after ? '?after=' + encodeURIComponent(opt.after) : '');
  const payload = verb === 'POST' ? { record_ref: opt.ref, kind: opt.kind }
    : { state: opt.next, expected_version: opt.version };
  try {
    const response = await transport(target, {
      method: verb, credentials: 'same-origin', cache: 'no-store',
      headers: { Accept: 'application/json',
        ...(verb === 'GET' ? {} : { 'Content-Type': 'application/json',
          'X-CSRF-Token': opt.csrf ?? '' }) },
      ...(verb === 'GET' ? {} : { body: JSON.stringify(payload) }),
      ...(opt.signal ? { signal: opt.signal } : {}),
    });
    if (response.status === 401 || response.status === 403) return { status: 'denied' };
    if (response.status === 404) {
      const problem: unknown = await response.json().catch(() => null);
      if (problem && typeof problem === 'object' && !Array.isArray(problem)
        && (problem as Record<string, unknown>).error === 'feature_unavailable') {
        return { status: 'disabled' };
      }
      return { status: 'error' };
    }
    if (!response.ok) return { status: 'error' };
    const data: unknown = await response.json();
    if (!data || typeof data !== 'object' || Array.isArray(data)) return { status: 'error' };
    const responseBody = data as Record<string, unknown>;
    if (verb === 'GET' && !opt.ref) {
      if (!Array.isArray(responseBody.records) || responseBody.records.length > 100) return { status: 'error' };
      const cursor = responseBody.next_cursor;
      if (!(cursor === null || (typeof cursor === 'string' && alias(cursor)))) return { status: 'error' };
      const rows = responseBody.records.map(x => parseEntry(x, opt.tenant, opt.company));
      if (rows.some(x => x === null)) return { status: 'error' };
      const found = rows as ProcessEntry[];
      const refs = found.map(x => x.ref);
      if (new Set(refs).size !== refs.length || refs.some((x, i) => i > 0 && x <= refs[i - 1])
        || (opt.after && refs.some(x => x <= opt.after!))
        || (cursor !== null && (found.length !== 100 || cursor !== found[found.length - 1].ref))) {
        return { status: 'error' };
      }
      return { status: 'ready', rows: found, cursor: cursor as string | null };
    }
    const entry = parseEntry(responseBody.record, opt.tenant, opt.company);
    return entry && (!opt.ref || entry.ref === opt.ref)
      ? { status: 'ready', entry } : { status: 'error' };
  } catch {
    return { status: 'error' };
  }
}

type View = { kind: 'loading' } | { kind: 'disabled' | 'denied' | 'error' }
  | { kind: 'ready'; rows: ProcessEntry[]; cursor: string | null };
export function OperationsProcessesPanel({ enabled, tenantId, companyId, branchId,
  permissions, csrfToken }: Props) {
  const canRead = permissions.includes('inventory.view');
  const canCreate = permissions.includes('inventory.create');
  const canModify = permissions.includes('inventory.update');
  const permitted = canRead && companyId !== '__unassigned__' && !!tenantId && !!branchId;
  const [view, setView] = useState<View>({ kind: 'loading' });
  const [reload, setReload] = useState(0);
  const [filter, setFilter] = useState('');
  const [selected, setSelected] = useState<ProcessEntry | null>(null);
  const [mode, setMode] = useState<'create' | 'inspect' | null>(null);
  const [ref, setRef] = useState('');
  const [kind, setKind] = useState<ProcessKind>('PROCESO');
  const [transition, setTransition] = useState<ProcessState>('EN_REVISION');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  const opts = { enabled, allowed: permitted, tenant: tenantId, company: companyId, branch: branchId };

  useEffect(() => {
    if (!enabled || !permitted) return;
    const controller = new AbortController();
    setView({ kind: 'loading' });
    void requestOperationalProcess({ ...opts, signal: controller.signal }).then(result => {
      if (controller.signal.aborted) return;
      if (result.status === 'ready' && 'rows' in result) {
        setView({ kind: 'ready', rows: result.rows, cursor: result.cursor });
      } else setView({ kind: result.status === 'ready' ? 'error' : result.status });
    });
    return () => controller.abort();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [enabled, tenantId, companyId, branchId, permitted, reload]);

  async function loadMore(cursor: string) {
    if (busy || view.kind !== 'ready') return;
    setBusy(true);
    const result = await requestOperationalProcess({ ...opts, after: cursor });
    if (result.status === 'ready' && 'rows' in result) {
      const seen = new Set(view.rows.map(x => x.ref));
      if (result.rows.some(x => seen.has(x.ref))) setNotice('La página recibida contiene referencias repetidas.');
      else setView({ kind: 'ready', rows: [...view.rows, ...result.rows], cursor: result.cursor });
    } else setNotice('No fue posible cargar más procesos.');
    setBusy(false);
  }
  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy || !enabled) return;
    const creating = mode === 'create';
    const allowed = creating ? canCreate : canModify;
    if (!allowed || (!creating && !selected)) return;
    setBusy(true);
    setNotice('');
    const result = await requestOperationalProcess({ ...opts, allowed,
      verb: creating ? 'POST' : 'PATCH', ref: creating ? ref : selected?.ref,
      ...(creating ? { kind } : { next: transition, version: selected?.version }),
      csrf: csrfToken });
    if (result.status === 'ready' && 'entry' in result) {
      setNotice('Proceso guardado para revisión. No constituye aprobación automática.');
      setMode(null);
      setSelected(null);
      setReload(x => x + 1);
    } else setNotice(result.status === 'denied' ? 'No tienes permiso para esta operación.'
      : 'No se pudo guardar el proceso. Revisa la información o la versión actual.');
    setBusy(false);
  }
  function show(row: ProcessEntry) {
    setSelected(row);
    setTransition(routes[row.state][0] ?? 'EN_REVISION');
    setMode('inspect');
  }
  if (!enabled) return <section id="operations-processes" className="ui-page">
    <PageHeader title="Procesos" description="El módulo aún no está habilitado." />
    <p role="status">No se han consultado ni mostrado registros empresariales.</p>
  </section>;
  if (!permitted) return <section id="operations-processes" className="ui-page">
    <PageHeader title="Procesos" description="Acceso denegado al ámbito empresarial." />
    <p role="alert">Selecciona una razón social autorizada y comprueba tus permisos.</p>
  </section>;
  const rows = view.kind === 'ready' ? view.rows : [];
  const filtered = rows.filter(row => (row.ref + ' ' + row.kind + ' ' + names[row.state])
    .toLocaleLowerCase('es-CO').includes(filter.toLocaleLowerCase('es-CO')));
  return <section id="operations-processes" className="ui-page" aria-label="Gestión de procesos">
    <PageHeader title="Procesos" description="Controla los procesos internos por razón social, con historial y revisión manual."
      actions={canCreate ? <button type="button" className="button" disabled={busy}
        onClick={() => { setRef(''); setKind('PROCESO'); setMode('create'); }}>
        Nuevo proceso
      </button> : undefined} />
    {view.kind === 'loading' && <output role="status" aria-live="polite">Cargando procesos…</output>}
    {view.kind === 'error' && <p role="alert">No se pudieron consultar los procesos.
      <button type="button" className="button button-secondary" onClick={() => setReload(x => x + 1)}>Reintentar</button>
    </p>}
    {view.kind === 'disabled' && <p role="status">El módulo aún no está habilitado.</p>}
    {view.kind === 'denied' && <p role="alert">El servidor denegó el acceso a Procesos.</p>}
    {view.kind === 'ready' && <>
      <MetricStrip label="Resumen de procesos" items={[
        { label: 'Procesos cargados', value: rows.length },
        { label: 'Pendientes', value: rows.filter(x => x.state === 'PENDIENTE').length },
        { label: 'Requieren revisión', value: rows.filter(x => x.state === 'REQUIERE_REVISION').length },
      ]} />
      <Toolbar trailing={<span className="ui-count">{filtered.length} procesos visibles</span>}>
        <label className="ui-search"><span className="ui-sr-only">Buscar procesos</span>
          <input type="search" value={filter} placeholder="Buscar procesos o estado"
            onChange={event => setFilter(event.target.value)} /></label>
      </Toolbar>
      <DataTable caption="Procesos de la razón social" rows={filtered} rowKey={x => x.ref}
        onOpen={show} empty={<><strong>No hay procesos para mostrar.</strong>
          <span>Crea un proceso o prueba otra búsqueda.</span></>}
        columns={[
          { key: 'ref', header: 'Proceso', render: x => x.ref },
          { key: 'kind', header: 'Categoría', render: x => x.kind },
          { key: 'state', header: 'Estado', render: x =>
            <StatusPill tone={x.state === 'PENDIENTE' ? 'neutral' : 'info'}>{names[x.state]}</StatusPill> },
          { key: 'rev', header: 'Versión', render: x => x.version },
        ]} />
      {view.cursor && <button type="button" className="button button-secondary" disabled={busy}
        onClick={() => { void loadMore(view.cursor!); }}>Cargar más procesos</button>}
    </>}
    {notice && <output role="status" aria-live="polite" className="alert">{notice}</output>}
    <SidePanel open={mode !== null} title={mode === 'create' ? 'Nuevo proceso' : 'Detalle del proceso'}
      onClose={() => setMode(null)}>
      <form onSubmit={event => { void save(event); }}>
        {mode === 'create' ? <>
          <label className="field">Referencia interna
            <input required maxLength={64} value={ref} onChange={event => setRef(event.target.value)}
              pattern="[a-z][a-z0-9_-]*" placeholder="proceso_compras" />
          </label>
          <label className="field">Categoría
            <select value={kind} onChange={event => setKind(event.target.value as ProcessKind)}>
              {categories.map(x => <option key={x} value={x}>{x}</option>)}
            </select>
          </label>
        </> : selected && <>
          <dl className="ui-details">
            <div><dt>Referencia</dt><dd>{selected.ref}</dd></div>
            <div><dt>Estado</dt><dd>{names[selected.state]}</dd></div>
            <div><dt>Versión</dt><dd>{selected.version}</dd></div>
            <div><dt>Registrado</dt><dd>{selected.occurredAt}</dd></div>
          </dl>
          {canModify && <label className="field">Solicitar estado
            <select value={transition} onChange={event => setTransition(event.target.value as ProcessState)}>
              {routes[selected.state].map(x => <option key={x} value={x}>{names[x]}</option>)}
            </select>
          </label>}
        </>}
        <p className="muted">Los cambios requieren revisión; no constituyen aprobación jurídica.</p>
        {((mode === 'create' && canCreate) || (mode === 'inspect' && canModify)) &&
          <button type="submit" className="button" disabled={busy}>
            {busy ? 'Guardando…' : mode === 'create' ? 'Guardar proceso' : 'Registrar cambio'}
          </button>}
      </form>
    </SidePanel>
  </section>;
}
