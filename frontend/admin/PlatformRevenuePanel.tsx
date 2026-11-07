import { useEffect, useState } from 'react';
import { DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar, type Column } from './ui/Kit';

type Totals = Readonly<{ new: number; expansion: number; contraction: number; churn: number; mrr_delta: number }>;
type Movement = Readonly<{
  tenant_id: string; company: string; plan_key: string; plan_name: string;
  type: 'new' | 'expansion' | 'contraction' | 'churn';
  recognition: 'effective' | 'scheduled'; occurred_at: string;
  amount: number; mrr_delta: number;
}>;
type Revenue = Readonly<{
  status: 'ready'; currency: string | null;
  period: { from: string; to: string };
  totals: Totals;
  plans: ReadonlyArray<{ key: string; name: string } & Totals>;
  companies: ReadonlyArray<{ tenant_id: string; company: string } & Totals>;
  movements: ReadonlyArray<Movement>;
}>;
type State = { status: 'loading' | 'unavailable' | 'forbidden' } | Revenue;
const emptyTotals: Totals = { new: 0, expansion: 0, contraction: 0, churn: 0, mrr_delta: 0 };
const eventName: Record<Movement['type'], string> = {
  new: 'Alta', expansion: 'Ampliación', contraction: 'Reducción', churn: 'Baja',
};

function validTotals(v: unknown): v is Totals {
  if (!v || typeof v !== 'object' || Array.isArray(v)) return false;
  const row = v as Record<string, unknown>;
  return Object.keys(emptyTotals).every((key) =>
    typeof row[key] === 'number' && Number.isSafeInteger(row[key]));
}
function validRevenue(v: unknown): v is Revenue {
  if (!v || typeof v !== 'object' || Array.isArray(v)) return false;
  const p = v as Record<string, unknown>;
  if (p.status !== 'ready' || !validTotals(p.totals)
    || (p.currency !== null && (typeof p.currency !== 'string' || !/^[A-Z]{3}$/.test(p.currency)))
    || !Array.isArray(p.movements) || p.movements.length > 10000
    || !Array.isArray(p.plans) || !Array.isArray(p.companies)
    || p.plans.length > 2000 || p.companies.length > 2000
    || !p.plans.every((value: unknown) => {
      if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
      const row = value as Record<string, unknown>;
      return typeof row.key === 'string' && typeof row.name === 'string' && validTotals(row);
    })
    || !p.companies.every((value: unknown) => {
      if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
      const row = value as Record<string, unknown>;
      return typeof row.tenant_id === 'string' && typeof row.company === 'string' && validTotals(row);
    })) return false;
  return p.movements.every((raw: unknown) => {
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return false;
    const m = raw as Record<string, unknown>;
    return typeof m.tenant_id === 'string' && typeof m.company === 'string'
      && typeof m.plan_key === 'string' && typeof m.plan_name === 'string'
      && ['new', 'expansion', 'contraction', 'churn'].includes(String(m.type))
      && ['effective', 'scheduled'].includes(String(m.recognition))
      && typeof m.occurred_at === 'string'
      && /^\d{4}-\d{2}-\d{2}T/.test(m.occurred_at)
      && typeof m.amount === 'number' && Number.isSafeInteger(m.amount) && m.amount >= 0
      && typeof m.mrr_delta === 'number' && Number.isSafeInteger(m.mrr_delta);
  });
}
function money(amount: number, currency: string | null): string {
  if (currency === null) return 'Sin tarifa';
  try {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency, maximumFractionDigits: 0 }).format(amount);
  } catch { return 'Tarifa no verificable'; }
}

/** The platform owner sees MRR movements, not payments, invoices or customer PII. */
export function PlatformRevenuePanel() {
  const today = new Date().toISOString().slice(0, 10);
  const [from, setFrom] = useState(today.slice(0, 8) + '01');
  const [to, setTo] = useState(today);
  const [plan, setPlan] = useState('');
  const [planOptions, setPlanOptions] = useState<ReadonlyArray<{ key: string; name: string }>>([]);
  const [state, setState] = useState<State>({ status: 'loading' });
  const [selected, setSelected] = useState<Movement | null>(null);
  useEffect(() => {
    if (to < from) { setState({ status: 'unavailable' }); return; }
    const abort = new AbortController();
    setState({ status: 'loading' });
    setSelected(null);
    const params = new URLSearchParams({ from, to });
    if (plan) params.set('plan', plan);
    void fetch('/adminpl0n3r/api/ingresos?' + params.toString(), {
      credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: abort.signal,
    }).then(async (response) => {
      if (abort.signal.aborted) return;
      if (response.status === 401 || response.status === 403) {
        setState({ status: 'forbidden' }); return;
      }
      if (!response.ok) { setState({ status: 'unavailable' }); return; }
      const raw: unknown = await response.json();
      if (validRevenue(raw)) {
        if (plan === '') setPlanOptions(raw.plans.map(({ key, name }) => ({ key, name })));
        setState(raw);
      } else {
        setState({ status: 'unavailable' });
      }
    }).catch(() => {
      if (!abort.signal.aborted) setState({ status: 'unavailable' });
    });
    return () => abort.abort();
  }, [from, to, plan]);
  const data = state.status === 'ready' ? state : null;
  const currency = data?.currency ?? null;
  const columns: Column<Movement>[] = [
    { key: 'date', header: 'Fecha', render: (m) => m.occurred_at.slice(0, 10) },
    { key: 'company', header: 'Empresa', render: (m) => m.company },
    { key: 'plan', header: 'Plan', render: (m) => m.plan_name },
    { key: 'type', header: 'Movimiento', render: (m) => <StatusPill tone={m.type === 'churn' ? 'warn' : 'neutral'}>{eventName[m.type]}</StatusPill> },
    { key: 'amount', header: 'MRR', align: 'right', render: (m) => money(m.mrr_delta, currency) },
  ];
  return (
    <section className="platform-section" aria-label="Ingresos SaaS del propietario">
      <PageHeader title="Ingresos SaaS" description="Movimientos de ingreso recurrente (MRR), no pagos cobrados ni facturas." />
      <Toolbar>
        <label className="field">Desde<input type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} /></label>
        <label className="field">Hasta<input type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} /></label>
        <label className="field">Plan
          <select value={plan} onChange={(e) => setPlan(e.target.value)}>
            <option value="">Todos los planes</option>
            {planOptions.map((p) => <option value={p.key} key={p.key}>{p.name}</option>)}
          </select>
        </label>
      </Toolbar>
      {state.status === 'loading' && <p role="status">Consultando movimientos…</p>}
      {state.status === 'forbidden' && <p role="alert">No tienes permiso para ver ingresos de la plataforma.</p>}
      {state.status === 'unavailable' && <p role="alert">Sin tarifa o evidencia comercial incompleta. No se mostrarán cifras como cero.</p>}
      {data && <>
        <MetricStrip label="Resumen de movimientos MRR" items={[
          { label: 'Altas', value: money(data.totals.new, currency) },
          { label: 'Ampliaciones', value: money(data.totals.expansion, currency) },
          { label: 'Reducciones', value: money(data.totals.contraction, currency) },
          { label: 'Bajas', value: money(data.totals.churn, currency) },
          { label: 'Cambio neto de MRR', value: money(data.totals.mrr_delta, currency) },
        ]} />
        <DataTable caption="Movimientos de ingresos SaaS" columns={columns} rows={data.movements}
          rowKey={(m) => m.tenant_id + m.occurred_at + m.type} onOpen={setSelected}
          empty="Sin movimientos para este periodo. Un cero verificado no significa tarifa desconocida." />
        <h3>Totales por plan</h3>
        <DataTable caption="Totales de MRR por plan" rows={data.plans}
          rowKey={(p) => p.key} empty="Sin movimientos efectivos por plan."
          columns={[
            { key: 'plan', header: 'Plan', render: (p) => p.name },
            { key: 'new', header: 'Altas', render: (p) => money(p.new, currency) },
            { key: 'net', header: 'Cambio neto MRR', render: (p) => money(p.mrr_delta, currency) },
          ]} />
        <h3>Totales por empresa</h3>
        <DataTable caption="Totales de MRR por empresa" rows={data.companies}
          rowKey={(c) => c.tenant_id} empty="Sin movimientos efectivos por empresa."
          columns={[
            { key: 'company', header: 'Empresa', render: (c) => c.company },
            { key: 'new', header: 'Altas', render: (c) => money(c.new, currency) },
            { key: 'net', header: 'Cambio neto MRR', render: (c) => money(c.mrr_delta, currency) },
          ]} />
        <p className="muted">Los cambios programados se muestran como previsiones y no se incluyen en los totales efectivos.</p>
        <SidePanel open={selected !== null} title="Detalle del movimiento" onClose={() => setSelected(null)}>
          {selected && <dl>
            <dt>Empresa</dt><dd>{selected.company}</dd>
            <dt>Plan</dt><dd>{selected.plan_name}</dd>
            <dt>Movimiento</dt><dd>{eventName[selected.type]}</dd>
            <dt>Fecha</dt><dd>{selected.occurred_at.slice(0, 10)}</dd>
            <dt>Importe</dt><dd>{money(selected.amount, currency)}</dd>
            <dt>Reconocimiento</dt><dd>{selected.recognition === 'effective' ? 'Efectivo' : 'Programado'}</dd>
          </dl>}
        </SidePanel>
      </>}
    </section>
  );
}
