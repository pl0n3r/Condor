/// <reference types="vite/client" />
import { useEffect, useState, type FormEvent } from 'react';
import { DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';

const caseTypes = ['PQRS', 'GARANTIA', 'SOLICITUD'] as const;
const caseStages = ['PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION'] as const;
type CaseKind = typeof caseTypes[number];
type CaseStage = typeof caseStages[number];
const stageNames: Record<CaseStage, string> = {
  PENDIENTE: 'Pendiente',
  EN_REVISION: 'En revisión',
  REQUIERE_REVISION: 'Requiere revisión',
};
const allowedChanges: Record<CaseStage, CaseStage[]> = {
  PENDIENTE: ['EN_REVISION', 'REQUIERE_REVISION'],
  EN_REVISION: ['REQUIERE_REVISION'],
  REQUIERE_REVISION: ['EN_REVISION'],
};

export type CaseEntry = {
  ref: string; kind: CaseKind; state: CaseStage; version: number;
  tenant: string; company: string; occurredAt: string;
};
type CaseReply =
  | { status: 'disabled' | 'denied' | 'error' }
  | { status: 'ready'; rows: CaseEntry[]; cursor: string | null }
  | { status: 'ready'; entry: CaseEntry };
export type CaseRequest = {
  enabled: boolean; allowed: boolean; tenant: string; company: string; branch: string;
  verb?: 'GET' | 'POST' | 'PATCH'; ref?: string; after?: string;
  kind?: CaseKind; next?: CaseStage; version?: number; csrf?: string; signal?: AbortSignal;
};
type Props = {
  enabled: boolean; tenantId: string; companyId: string; branchId: string;
  permissions: string[]; csrfToken: string;
};

const opaque = (value: string) =>
  /^[a-z][a-z0-9_-]{0,63}$/.test(value) && !/[0-9]{7,}/.test(value);

function asCase(value: unknown, tenant: string, company: string): CaseEntry | undefined {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return;
  const fields = value as Record<string, unknown>;
  const ref = fields.record_ref;
  const version = Number(fields.version);
  if (fields.tenant_id !== tenant || fields.company_id !== company ||
    typeof ref !== 'string' || !opaque(ref) ||
    !caseTypes.some(type => type === fields.kind) ||
    !caseStages.some(stage => stage === fields.state) ||
    !Number.isSafeInteger(version) || version < 1 || version > 1_000_000 ||
    typeof fields.occurred_at !== 'string' ||
    !/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/.test(fields.occurred_at)) return;
  return { ref, version, tenant, company, occurredAt: fields.occurred_at,
    kind: fields.kind as CaseKind, state: fields.state as CaseStage };
}

/** El borde de red falla cerrado: ámbito, autorización y bandera son obligatorios. */
export async function requestCustomerServiceCase(
  opt: CaseRequest, transport: typeof fetch = fetch,
): Promise<CaseReply> {
  if (!opt.enabled) return { status: 'disabled' };
  if (!opt.allowed || !opt.tenant || !opt.company || !opt.branch ||
    opt.company === '__unassigned__') return { status: 'denied' };

  const method = opt.verb ?? 'GET';
  if ((opt.ref !== undefined && !opaque(opt.ref)) ||
    (opt.after !== undefined && !opaque(opt.after))) return { status: 'error' };
  if (method === 'POST' && (!opt.csrf || !opt.ref ||
    !caseTypes.includes(opt.kind as CaseKind))) return { status: 'error' };
  if (method === 'PATCH' && (!opt.csrf || !opt.ref ||
    !caseStages.includes(opt.next as CaseStage) ||
    !Number.isSafeInteger(opt.version) || (opt.version ?? 0) < 1 ||
    (opt.version ?? 0) >= 1_000_000)) return { status: 'error' };

  const baseUrl = '/api/v1/branches/' + encodeURIComponent(opt.branch)
    + '/customer-service-real/records';
  const url = method === 'POST' || (!opt.ref && !opt.after) ? baseUrl
    : method === 'GET' && opt.after ? baseUrl + '?after=' + encodeURIComponent(opt.after)
      : baseUrl + '/' + encodeURIComponent(opt.ref ?? '') + (method === 'PATCH' ? '/state' : '');
  const payload = method === 'POST'
    ? { record_ref: opt.ref, kind: opt.kind }
    : { state: opt.next, expected_version: opt.version };

  try {
    const response = await transport(url, {
      method, credentials: 'same-origin', cache: 'no-store', signal: opt.signal,
      headers: method === 'GET'
        ? { Accept: 'application/json' }
        : { Accept: 'application/json', 'Content-Type': 'application/json',
          'X-CSRF-Token': opt.csrf ?? '' },
      ...(method === 'GET' ? {} : { body: JSON.stringify(payload) }),
    });
    if (response.status === 401 || response.status === 403) return { status: 'denied' };
    const data: unknown = await response.json().catch(() => null);
    if (response.status === 404 && typeof data === 'object' && data !== null &&
      'error' in data && data.error === 'feature_unavailable') return { status: 'disabled' };
    if (!response.ok || typeof data !== 'object' || data === null || Array.isArray(data)) {
      return { status: 'error' };
    }
    const body = data as Record<string, unknown>;
    if (method === 'GET' && !opt.ref) {
      const list = body.records;
      const next = body.next_cursor;
      if (!Array.isArray(list) || list.length > 100 ||
        !(next === null || (typeof next === 'string' && opaque(next)))) {
        return { status: 'error' };
      }
      const rows = list.map(item => asCase(item, opt.tenant, opt.company));
      if (rows.some(row => row === undefined)) return { status: 'error' };
      const cases = rows as CaseEntry[];
      const refs = cases.map(row => row.ref);
      if (new Set(refs).size !== refs.length ||
        refs.some((ref, i) => (i > 0 && ref <= refs[i - 1]) ||
          (opt.after !== undefined && ref <= opt.after)) ||
        (next !== null && (cases.length !== 100 || next !== refs.at(-1)))) {
        return { status: 'error' };
      }
      return { status: 'ready', rows: cases, cursor: next as string | null };
    }
    const record = asCase(body.record, opt.tenant, opt.company);
    if (!record || (opt.ref && opt.ref !== record.ref)) return { status: 'error' };
    return { status: 'ready', entry: record };
  } catch {
    return { status: 'error' };
  }
}

type Display =
  | { state: 'loading' | 'error' | 'denied' | 'disabled' }
  | { state: 'ready'; rows: CaseEntry[]; cursor: string | null };
const dateOf = (timestamp: string): string =>
  timestamp.slice(8, 10) + '/' + timestamp.slice(5, 7) + '/' + timestamp.slice(0, 4);

export function CustomerServiceManagement({
  enabled, tenantId, companyId, branchId, permissions, csrfToken,
}: Props) {
  const canRead = permissions.includes('inventory.view');
  const createAllowed = permissions.includes('inventory.create');
  const modifyAllowed = permissions.includes('inventory.update');
  const scopeAllowed = canRead && Boolean(tenantId && branchId && companyId) &&
    companyId !== '__unassigned__';
  const requestBase = { enabled, tenant: tenantId, company: companyId, branch: branchId };
  const [screen, setScreen] = useState<Display>({ state: 'loading' });
  const [reloadKey, setReloadKey] = useState(0);
  const [search, setSearch] = useState('');
  const [panel, setPanel] = useState<'create' | 'details' | null>(null);
  const [caseRef, setCaseRef] = useState('');
  const [caseType, setCaseType] = useState<CaseKind>('PQRS');
  const [selected, setSelected] = useState<CaseEntry | null>(null);
  const [nextState, setNextState] = useState<CaseStage>('EN_REVISION');
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');

  useEffect(() => {
    if (!enabled || !scopeAllowed) return;
    const abort = new AbortController();
    setScreen({ state: 'loading' });
    void requestCustomerServiceCase({
      ...requestBase, allowed: canRead, signal: abort.signal,
    }).then(reply => {
      if (abort.signal.aborted) return;
      setScreen(reply.status === 'ready' && 'rows' in reply
        ? { state: 'ready', rows: reply.rows, cursor: reply.cursor }
        : { state: reply.status === 'ready' ? 'error' : reply.status });
    });
    return () => abort.abort();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [enabled, tenantId, companyId, branchId, scopeAllowed, reloadKey]);

  async function nextPage(cursor: string) {
    if (saving || screen.state !== 'ready') return;
    setSaving(true);
    const response = await requestCustomerServiceCase({ ...requestBase,
      allowed: scopeAllowed, after: cursor });
    if (response.status === 'ready' && 'rows' in response) {
      const known = new Set(screen.rows.map(item => item.ref));
      if (response.rows.some(item => known.has(item.ref))) {
        setMessage('El servidor repitió un caso. Actualiza la vista antes de continuar.');
      } else setScreen({ state: 'ready', rows: [...screen.rows, ...response.rows],
        cursor: response.cursor });
    } else setMessage('No se pudo consultar la siguiente página.');
    setSaving(false);
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (saving || !enabled || !scopeAllowed || !csrfToken) return;
    const creating = panel === 'create';
    if (!(creating ? createAllowed : modifyAllowed) || (!creating && !selected)) return;
    setSaving(true);
    setMessage('');
    const reply = await requestCustomerServiceCase({ ...requestBase,
      allowed: creating ? createAllowed : modifyAllowed,
      csrf: csrfToken, verb: creating ? 'POST' : 'PATCH',
      ref: creating ? caseRef : selected?.ref,
      ...(creating ? { kind: caseType } : { next: nextState, version: selected?.version }),
    });
    if (reply.status === 'ready' && 'entry' in reply) {
      setMessage('Caso actualizado. El cambio no constituye una aprobación automática.');
      setPanel(null);
      setSelected(null);
      setReloadKey(current => current + 1);
    } else setMessage(reply.status === 'denied'
      ? 'No tienes autorización para cambiar este caso.'
      : 'No se pudo registrar el cambio. Comprueba los datos y la versión vigente.');
    setSaving(false);
  }

  if (!enabled) return <section id="customer-service" className="ui-page">
    <PageHeader title="Servicio al cliente" description="Funcionalidad pendiente de habilitación." />
    <p role="status">El módulo aún no está habilitado. No se consultan datos de clientes.</p>
  </section>;
  if (!scopeAllowed) return <section id="customer-service" className="ui-page">
    <PageHeader title="Servicio al cliente" description="Acceso denegado al contexto empresarial." />
    <p role="alert">Selecciona una empresa y una sede autorizadas para consultar casos.</p>
  </section>;

  const all = screen.state === 'ready' ? screen.rows : [];
  const matching = all.filter(item =>
    (item.ref + ' ' + item.kind + ' ' + stageNames[item.state])
      .toLocaleLowerCase('es-CO').includes(search.toLocaleLowerCase('es-CO')));

  return <section id="customer-service" className="ui-page" aria-label="Gestión de servicio al cliente">
    <PageHeader title="Servicio al cliente"
      description="Atiende solicitudes y garantías desde la razón social seleccionada."
      actions={createAllowed && <button type="button" className="button" disabled={saving}
        onClick={() => { setCaseRef(''); setCaseType('PQRS'); setPanel('create'); }}>Nuevo caso</button>} />
    {screen.state === 'loading' && <output role="status" aria-live="polite">Cargando casos…</output>}
    {screen.state === 'error' && <p role="alert">No se pudieron recuperar los casos.
      <button type="button" className="button button-secondary"
        onClick={() => setReloadKey(value => value + 1)}>Reintentar</button></p>}
    {screen.state === 'denied' && <p role="alert">El servidor denegó el acceso a los casos.</p>}
    {screen.state === 'disabled' && <p role="status">Servicio al cliente no está disponible.</p>}
    {screen.state === 'ready' && <>
      <MetricStrip label="Estado de las solicitudes" items={[
        { label: 'Casos cargados', value: all.length },
        { label: 'Pendientes', value: all.filter(item => item.state === 'PENDIENTE').length },
        { label: 'Revisión requerida', value: all.filter(item => item.state === 'REQUIERE_REVISION').length },
      ]} />
      <Toolbar trailing={<span className="ui-count">{matching.length} casos</span>}>
        <label className="ui-search"><span className="ui-sr-only">Buscar casos por referencia y estado</span>
          <input type="search" value={search} placeholder="Buscar casos"
            onChange={event => setSearch(event.target.value)} /></label>
      </Toolbar>
      <DataTable caption="Casos de la empresa activa" rows={matching} rowKey={item => item.ref}
        onOpen={item => { setSelected(item);
          setNextState(allowedChanges[item.state][0] ?? 'EN_REVISION'); setPanel('details'); }}
        empty={<p role="status">No hay casos con los filtros seleccionados.</p>}
        columns={[
          { key: 'ref', header: 'Referencia', render: item => item.ref },
          { key: 'type', header: 'Tipo', render: item => item.kind },
          { key: 'state', header: 'Estado', render: item =>
            <StatusPill tone={item.state === 'PENDIENTE' ? 'neutral' : 'info'}>
              {stageNames[item.state]}</StatusPill> },
          { key: 'date', header: 'Fecha', render: item => dateOf(item.occurredAt) },
        ]} />
      {screen.cursor && <button type="button" className="button button-secondary" disabled={saving}
        onClick={() => void nextPage(screen.cursor!)}>Ver más casos</button>}
    </>}
    {message && <output role="status" aria-live="polite" className="alert">{message}</output>}
    <SidePanel open={panel !== null} title={panel === 'create' ? 'Crear caso' : 'Detalle del caso'}
      onClose={() => setPanel(null)}>
      <form onSubmit={event => void submit(event)}>
        {panel === 'create' ? <>
          <label className="field">Referencia interna
            <input required maxLength={64} value={caseRef} pattern="[a-z][a-z0-9_-]*"
              placeholder="solicitud_interna" onChange={event => setCaseRef(event.target.value)} />
          </label>
          <label className="field">Tipo de solicitud
            <select value={caseType} onChange={event => setCaseType(event.target.value as CaseKind)}>
              {caseTypes.map(value => <option key={value} value={value}>{value}</option>)}
            </select>
          </label>
        </> : selected && <>
          <dl className="ui-details">
            <div><dt>Referencia</dt><dd>{selected.ref}</dd></div>
            <div><dt>Tipo</dt><dd>{selected.kind}</dd></div>
            <div><dt>Estado actual</dt><dd>{stageNames[selected.state]}</dd></div>
            <div><dt>Versión</dt><dd>{selected.version}</dd></div>
            <div><dt>Fecha</dt><dd>{dateOf(selected.occurredAt)}</dd></div>
          </dl>
          {modifyAllowed && <label className="field">Solicitar nuevo estado
            <select value={nextState} onChange={event => setNextState(event.target.value as CaseStage)}>
              {allowedChanges[selected.state].map(value =>
                <option key={value} value={value}>{stageNames[value]}</option>)}
            </select>
          </label>}
        </>}
        <p className="muted">Los cambios se registran para revisión humana; no generan una aprobación automática.</p>
        {((panel === 'create' && createAllowed) || (panel === 'details' && modifyAllowed)) &&
          <button type="submit" className="button" disabled={saving}>
            {saving ? 'Guardando…' : panel === 'create' ? 'Crear caso' : 'Solicitar cambio'}
          </button>}
      </form>
    </SidePanel>
  </section>;
}
