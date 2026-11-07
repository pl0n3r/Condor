import { useEffect, useState } from 'react';
import { Card, DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';
import {
  platformPlanAuditPath, platformPlanCatalogPath, platformPlanLimitsPath, platformPlanPricesPath,
} from './api';

/** Proyección owner-only de CommercialCatalogReader, nunca fuente para cobros. */
export type PlatformPlan = {
  key: string;
  name: string;
  version: number;
  currency: string;
  monthly_amount: number | null;
  annual_amount: number | null;
  quote_required: boolean;
  limits: Record<string, unknown>;
  capabilities: string[];
};

type PriceDraft = {
  monthly: string;
  annual: string;
  currency: string;
  quote: boolean;
  effective: string;
};

type View = 'prices' | 'limits' | 'history';
type State = 'idle' | 'loading' | 'ready' | 'saving' | 'saved' | 'conflict' | 'error';
type AuditItem = {
  id: string;
  actor: string;
  created_at: string;
  action: string;
  before: { plan_key?: string; version?: number };
  after: {
    plan_key?: string;
    version?: number;
    monthly_amount?: number | null;
    annual_amount?: number | null;
    quote_required?: boolean;
  };
};

/** Cantidades expresadas en unidades menores; '' = null, pero '0' es precio cero. */
export function minorUnits(raw: string): number | null {
  const value = raw.trim();
  if (value === '') return null;
  if (!/^(0|[1-9][0-9]{0,9})$/.test(value) || Number(value) > 2147483647) {
    throw new Error('Usa un entero entre 0 y 2147483647, o deja vacío para Sin tarifa.');
  }
  return Number(value);
}

export function tariffStatus(plan: Pick<PlatformPlan, 'quote_required' | 'monthly_amount' | 'annual_amount'>): string {
  if (plan.quote_required) return 'Cotizar';
  return plan.monthly_amount === null && plan.annual_amount === null ? 'Sin tarifa' : 'Con tarifa';
}

function amountLabel(amount: number | null, currency: string): string {
  if (amount === null) return 'Sin tarifa';
  try {
    return new Intl.NumberFormat('es-CO', {
      style: 'currency', currency, maximumFractionDigits: 2,
    }).format(amount / 100);
  } catch {
    return String(amount) + ' ' + currency + ' (unidades menores)';
  }
}

function initialDraft(plan: PlatformPlan): PriceDraft {
  return {
    monthly: plan.monthly_amount === null ? '' : String(plan.monthly_amount),
    annual: plan.annual_amount === null ? '' : String(plan.annual_amount),
    currency: plan.currency,
    quote: plan.quote_required,
    effective: '',
  };
}

function validatePrice(draft: PriceDraft): {
  monthly_amount: number | null;
  annual_amount: number | null;
} {
  if (!/^[A-Z]{3}$/.test(draft.currency)) {
    throw new Error('Indica una moneda de tres letras, por ejemplo COP.');
  }
  if (draft.effective !== '' && !/^\d{4}-\d{2}-\d{2}$/.test(draft.effective)) {
    throw new Error('La vigencia debe ser una fecha UTC.');
  }
  const monthly_amount = minorUnits(draft.monthly);
  const annual_amount = minorUnits(draft.annual);
  if (draft.quote && (monthly_amount !== null || annual_amount !== null)) {
    throw new Error('Para Cotizar deja los dos importes vacíos.');
  }
  return { monthly_amount, annual_amount };
}

/** UI del catálogo sobre APIs existentes. Solo el backend autoriza y versiona. */
export function PlatformPlansPanel({ plans, onChanged }: Readonly<{
  plans: readonly PlatformPlan[];
  onChanged: () => void;
}>) {
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState<'all' | 'priced' | 'missing' | 'quote'>('all');
  const [selectedKey, setSelectedKey] = useState('');
  const [tab, setTab] = useState<View>('prices');
  const [token, setToken] = useState('');
  const [state, setState] = useState<State>('idle');
  const [message, setMessage] = useState('');
  const [draft, setDraft] = useState<PriceDraft | null>(null);
  const [name, setName] = useState('');
  const [limits, setLimits] = useState('');
  const [capabilities, setCapabilities] = useState('');
  const [preview, setPreview] = useState(false);
  const [auditPage, setAuditPage] = useState(1);
  const [audit, setAudit] = useState<AuditItem[]>([]);
  const [auditNext, setAuditNext] = useState(false);
  const [auditState, setAuditState] = useState<'idle' | 'loading' | 'ready' | 'error'>('idle');

  const selected = plans.find((plan) => plan.key === selectedKey);
  const filtered = plans.filter((plan) =>
    (plan.key + ' ' + plan.name).toLocaleLowerCase('es-CO').includes(search.trim().toLocaleLowerCase('es-CO'))
    && (filter === 'all'
      || (filter === 'priced' && tariffStatus(plan) === 'Con tarifa')
      || (filter === 'missing' && tariffStatus(plan) === 'Sin tarifa')
      || (filter === 'quote' && plan.quote_required)));

  useEffect(() => {
    if (!selected) return;
    const abort = new AbortController();
    setDraft(initialDraft(selected));
    setName(selected.name);
    // No asumir límites/capacidades en el resumen de /api/context:
    // solo el formulario SSR canónico contiene el valor vigente de edición.
    setLimits('');
    setCapabilities('');
    setPreview(false);
    setMessage('');
    setState('loading');
    setToken('');

    // El GET SSR existente es la única fuente del token CSRF específico
    // de planes. Nunca lo imprimimos ni interpretamos su HTML en la página.
    void fetch(platformPlanCatalogPath(), { credentials: 'same-origin', signal: abort.signal })
      .then(async (response) => {
        if (!response.ok) throw new Error('No fue posible leer el editor de planes.');
        const document = new DOMParser().parseFromString(await response.text(), 'text/html');
        const canonicalPath = platformPlanCatalogPath() + '/' + encodeURIComponent(selected.key);
        const currentForm = [...document.querySelectorAll<HTMLFormElement>('form[action]')]
          .find((form) => form.getAttribute('action') === canonicalPath);
        const value = currentForm?.querySelector<HTMLInputElement>('input[name="_csrf_token"]')?.value;
        const version = currentForm?.querySelector<HTMLInputElement>('input[name="expected_version"]')?.value;
        const canonicalLimits = currentForm?.querySelector<HTMLTextAreaElement>('textarea[name="limits"]')?.value;
        const canonicalCapabilities = currentForm?.querySelector<HTMLInputElement>('input[name="capabilities"]')?.value;
        const canonicalName = currentForm?.querySelector<HTMLInputElement>('input[name="name"]')?.value;
        if (!value || canonicalLimits === undefined || canonicalCapabilities === undefined
          || !canonicalName) throw new Error('No se pudo verificar el plan en el servidor.');
        const parsed: unknown = JSON.parse(canonicalLimits);
        if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
          throw new Error('Los límites canónicos no tienen el formato previsto.');
        }
        if (!abort.signal.aborted) {
          if (version !== String(selected.version)) {
            setToken('');
            setState('conflict');
            setMessage('El catálogo cambió mientras se abría el panel. Actualiza antes de guardar.');
            return;
          }
          setName(canonicalName);
          setLimits(JSON.stringify(parsed, null, 2));
          setCapabilities(canonicalCapabilities);
          setToken(value);
          setState('ready');
        }
      })
      .catch(() => {
        if (!abort.signal.aborted) setState('error');
      });
    return () => abort.abort();
  }, [selected?.key, selected?.version]);

  useEffect(() => {
    if (!selected || tab !== 'history') return;
    const abort = new AbortController();
    setAuditState('loading');
    void fetch(platformPlanAuditPath(auditPage), {
      credentials: 'same-origin', signal: abort.signal,
    }).then(async (response) => {
      if (!response.ok) throw new Error('No se pudo leer el historial.');
      const result = await response.json() as { items: AuditItem[]; has_next: boolean };
      if (!Array.isArray(result.items)) throw new Error('Historial inválido.');
      if (!abort.signal.aborted) {
        setAudit(result.items.filter((entry) =>
          entry.after?.plan_key === selected.key || entry.before?.plan_key === selected.key));
        setAuditNext(result.has_next === true);
        setAuditState('ready');
      }
    }).catch(() => {
      if (!abort.signal.aborted) setAuditState('error');
    });
    return () => abort.abort();
  }, [selected?.key, tab, auditPage]);

  function open(plan: PlatformPlan, view: View = 'prices') {
    setSelectedKey(plan.key);
    setTab(view);
    setAuditPage(1);
    setPreview(false);
  }

  function close() {
    setSelectedKey('');
    setToken('');
    setDraft(null);
    setPreview(false);
    setMessage('');
    setState('idle');
  }

  function edit(patch: Partial<PriceDraft>) {
    setDraft((current) => current ? { ...current, ...patch } : null);
    setPreview(false);
    setMessage('');
    if (state !== 'loading' && state !== 'saving') setState('ready');
  }

  async function savePrice() {
    if (!selected || !draft || !token || state === 'saving') return;
    let values: ReturnType<typeof validatePrice>;
    try {
      values = validatePrice(draft);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'Revisa los importes.');
      return;
    }
    if (!preview) {
      setPreview(true);
      setMessage('');
      return;
    }
    setState('saving');
    try {
      const response = await fetch(platformPlanPricesPath(selected.key), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({
          expected_version: selected.version,
          currency: draft.currency,
          monthly_amount: values.monthly_amount,
          annual_amount: values.annual_amount,
          quote_required: draft.quote,
          effective_from: draft.effective || null,
          addons: [], verticals: [],
        }),
      });
      if (response.status === 422) {
        setState('conflict');
        setMessage('La versión puede haber cambiado o la vigencia no es válida. Recarga los planes antes de repetir.');
        return;
      }
      if (!response.ok) throw new Error('No se pudo registrar la versión de precios.');
      setState('saved');
      setPreview(false);
      setMessage('Versión de tarifas registrada; el cobro real permanece desactivado.');
      onChanged();
    } catch {
      setState('error');
      setMessage('No se pudo guardar. No se presupone ningún cambio.');
    }
  }

  async function saveLimits() {
    if (!selected || !token || state === 'saving') return;
    let parsed: unknown;
    try {
      parsed = JSON.parse(limits);
      if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
        throw new Error('Límites inválidos.');
      }
    } catch {
      setMessage('Los límites deben ser un objeto JSON válido.');
      return;
    }
    setState('saving');
    try {
      const response = await fetch(platformPlanLimitsPath(selected.key), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({
          name: name.trim(),
          limits: parsed,
          capabilities: capabilities.split(',').map((x) => x.trim()).filter(Boolean),
          expected_version: selected.version,
        }),
      });
      if (response.status === 422) {
        setState('conflict');
        setMessage('Conflicto de versión o límites inválidos. Recarga antes de guardar.');
        return;
      }
      if (!response.ok) throw new Error('No se pudo actualizar el plan.');
      setState('saved');
      setMessage('Nueva versión de límites y capacidades registrada.');
      onChanged();
    } catch {
      setState('error');
      setMessage('La edición no pudo confirmarse.');
    }
  }

  const metrics = [
    { label: 'Planes', value: plans.length },
    { label: 'Con tarifa', value: plans.filter((plan) => tariffStatus(plan) === 'Con tarifa').length },
    { label: 'Sin tarifa', value: plans.filter((plan) => tariffStatus(plan) === 'Sin tarifa').length },
    { label: 'A cotizar', value: plans.filter((plan) => plan.quote_required).length },
  ];

  return (
    <section className="platform-section ui-page" aria-label="Planes y precios">
      <PageHeader title="Planes y precios"
        description="Versiones comerciales del catálogo. Configurar una tarifa no activa cobros."
        actions={<button className="button" type="button" disabled={plans.length === 0}
          onClick={() => { if (plans[0]) open(plans[0]); }}>Nueva versión de precios</button>} />
      <MetricStrip label="Resumen del catálogo de planes" items={metrics} />
      <Card title="Catálogo vigente">
        <Toolbar trailing={<span className="ui-count">{filtered.length} de {plans.length} planes</span>}>
          <label className="ui-search">
            <span className="ui-sr-only">Buscar planes</span>
            <input type="search" value={search} placeholder="Buscar por nombre o clave"
              onChange={(event) => setSearch(event.target.value)} />
          </label>
          <label>Tarifa
            <select value={filter} onChange={(event) => setFilter(event.target.value as typeof filter)}>
              <option value="all">Todas</option>
              <option value="priced">Con tarifa</option>
              <option value="missing">Sin tarifa</option>
              <option value="quote">Cotizar</option>
            </select>
          </label>
        </Toolbar>
        <DataTable caption="Planes y precios vigentes" rows={filtered} rowKey={(plan) => plan.key}
          onOpen={(plan) => open(plan)}
          empty={<><strong>No hay planes que coincidan.</strong><span>Revisa los filtros o el catálogo.</span></>}
          columns={[
            { key: 'name', header: 'Plan', render: (plan) => <strong>{plan.name}</strong> },
            { key: 'version', header: 'Versión', render: (plan) => 'v' + plan.version },
            { key: 'monthly', header: 'Mensual', render: (plan) => amountLabel(plan.monthly_amount, plan.currency) },
            { key: 'annual', header: 'Anual', render: (plan) => amountLabel(plan.annual_amount, plan.currency) },
            { key: 'status', header: 'Tarifa', render: (plan) =>
              <StatusPill tone={plan.quote_required ? 'info' : tariffStatus(plan) === 'Con tarifa' ? 'ok' : 'warn'}>
                {tariffStatus(plan)}
              </StatusPill> },
            { key: 'limits', header: 'Límites', render: (plan) =>
              Object.keys(plan.limits).length + ' configurados' },
          ]} />
      </Card>
      <SidePanel open={!!selected} title={selected ? selected.name + ' · v' + selected.version : 'Plan'}
        onClose={close}>
        {selected && <>
          <div role="tablist" aria-label="Secciones del plan" className="ui-toolbar">
            {([
              ['prices', 'Precios'], ['limits', 'Límites y capacidades'], ['history', 'Historial'],
            ] as const).map(([view, label]) => (
              <button key={view} type="button" role="tab" aria-selected={tab === view}
                className={tab === view ? 'button' : 'button button-secondary'}
                onClick={() => { setTab(view); setMessage(''); setPreview(false); }}>
                {label}
              </button>
            ))}
          </div>
          {state === 'loading' && <p role="status">Cargando autorización de edición…</p>}
          {state === 'error' && <p role="alert">No se pudo completar la operación. No se presupone ningún cambio.</p>}
          {message && <p role={state === 'conflict' ? 'alert' : 'status'}>{message}</p>}
          {state === 'conflict' && <button type="button" className="button button-secondary"
            onClick={() => { onChanged(); close(); }}>Cerrar y recargar catálogo</button>}
          {tab === 'prices' && draft && <>
            <p>Tarifa vigente: {amountLabel(selected.monthly_amount, selected.currency)} mensual ·{' '}
              {amountLabel(selected.annual_amount, selected.currency)} anual.</p>
            <form className="platform-form" onSubmit={(event) => { event.preventDefault(); void savePrice(); }}>
              <label className="field">Moneda
                <input value={draft.currency} maxLength={3} required
                  onChange={(event) => edit({ currency: event.target.value.toUpperCase() })} />
              </label>
              <label className="field">Precio mensual (unidades menores; vacío = Sin tarifa)
                <input inputMode="numeric" value={draft.monthly} disabled={draft.quote}
                  onChange={(event) => edit({ monthly: event.target.value })} />
              </label>
              <label className="field">Precio anual (unidades menores; 0 = precio cero)
                <input inputMode="numeric" value={draft.annual} disabled={draft.quote}
                  onChange={(event) => edit({ annual: event.target.value })} />
              </label>
              <label className="field">
                <input type="checkbox" checked={draft.quote} onChange={(event) => edit({
                  quote: event.target.checked,
                  ...(event.target.checked ? { monthly: '', annual: '' } : {}),
                })} /> Requiere cotización
              </label>
              <label className="field">Vigencia UTC (vacío = hoy)
                <input type="date" value={draft.effective}
                  onChange={(event) => edit({ effective: event.target.value })} />
              </label>
              {preview && <div className="ui-card" role="status">
                <strong>Vista previa de v{selected.version + 1}</strong>
                <p>Mensual: {draft.quote ? 'Cotizar' : amountLabel(minorUnits(draft.monthly), draft.currency)}</p>
                <p>Anual: {draft.quote ? 'Cotizar' : amountLabel(minorUnits(draft.annual), draft.currency)}</p>
                <p>Vigencia UTC: {draft.effective || 'ahora'}. No se generarán cobros.</p>
              </div>}
              <button className="button" type="submit"
                disabled={!token || state === 'saving' || state === 'saved' || state === 'conflict'}>
                {state === 'saving' ? 'Guardando…' : preview ? 'Confirmar versión' : 'Revisar versión'}
              </button>
            </form>
          </>}
          {tab === 'limits' && <>
            <p>Editar límites y capacidades conserva las tarifas existentes y crea una versión auditable.</p>
            <form className="platform-form" onSubmit={(event) => { event.preventDefault(); void saveLimits(); }}>
              <label className="field">Nombre del plan
                <input value={name} maxLength={160} required
                  onChange={(event) => setName(event.target.value)} />
              </label>
              <label className="field">Límites (JSON)
                <textarea value={limits} required rows={5}
                  onChange={(event) => setLimits(event.target.value)} />
              </label>
              <label className="field">Capacidades (claves separadas por comas)
                <input value={capabilities} onChange={(event) => setCapabilities(event.target.value)} />
              </label>
              <button type="submit" className="button"
                disabled={!token || state === 'saving' || state === 'conflict' || state === 'saved'}>
                {state === 'saving' ? 'Guardando…' : 'Guardar nueva versión'}
              </button>
            </form>
          </>}
          {tab === 'history' && <>
            <p>Eventos auditables del catálogo. Los datos históricos anteriores al ledger no se infieren.</p>
            {auditState === 'loading' && <p role="status">Cargando historial…</p>}
            {auditState === 'error' && <p role="alert">No se pudo leer el historial autorizado.</p>}
            {auditState === 'ready' && (audit.length === 0
              ? <p>No hay eventos de este plan en esta página del historial.</p>
              : <ol>{audit.map((entry) => <li key={entry.id}>
                <strong>{entry.action.replaceAll('.', ' · ')} · v{entry.after.version ?? '?'}</strong>
                <p>Fecha UTC: {entry.created_at} · Actor: {entry.actor}</p>
                <p>Mensual: {entry.after.monthly_amount === null ? 'Sin tarifa' : (entry.after.monthly_amount ?? 'No registrado')}
                  {' · '} Anual: {entry.after.annual_amount === null ? 'Sin tarifa' : (entry.after.annual_amount ?? 'No registrado')}</p>
              </li>)}</ol>)}
            <div className="ui-toolbar">
              <button className="button button-secondary" type="button" disabled={auditPage === 1}
                onClick={() => setAuditPage((page) => page - 1)}>Anterior</button>
              <span>Página {auditPage}</span>
              <button className="button button-secondary" type="button"
                disabled={!auditNext} onClick={() => setAuditPage((page) => page + 1)}>Siguiente</button>
            </div>
          </>}
        </>}
      </SidePanel>
    </section>
  );
}
