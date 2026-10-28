import { useEffect, useState } from 'react';

type Fraction = Readonly<{ numerator: number; denominator: number }>;
type CanonicalSources = Readonly<{
  snapshot: 'App\\Application\\Commercial\\PlatformCommercialMetrics';
  movements: 'App\\Application\\Commercial\\SaasRevenueMovements';
  retention: 'App\\Application\\Commercial\\SaasRetentionMetrics';
}>;
export type SaasDashboardMetrics = Readonly<{
  currency: string;
  mrr_minor: number;
  arr_minor: number;
  active_customers: number;
  trials: number;
  arpa_minor_exact: Fraction | null;
  new_mrr_minor: number;
  expansion_mrr_minor: number;
  contraction_mrr_minor: number;
  churned_mrr_minor: number;
  nrr_percent_exact: Fraction | null;
  audit_count: number;
  source: CanonicalSources;
}>;
type Ready = Readonly<{ status: 'ready'; data: SaasDashboardMetrics }>;
type Empty = Readonly<{ status: 'empty' }>;
type Unknown = Readonly<{ status: 'unknown' }>;
export type ParsedDashboard = Ready | Empty | Unknown;
type ViewState = ParsedDashboard | Readonly<{ status: 'loading' }>;
export type PlatformSaasDashboardPanelProps = Readonly<{
  tenantId: string;
  owner: boolean;
  syntheticOnly: boolean;
  /** Authenticated, server-scoped adapter supplied by the platform owner. */
  loadMetrics: (tenantId: string, signal: AbortSignal) => Promise<unknown>;
}>;

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
function onlyKeys(row: Record<string, unknown>, keys: readonly string[]): boolean {
  return Object.keys(row).every((key) => keys.includes(key));
}
function integer(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
}
export function syntheticTenant(value: unknown): value is string {
  return typeof value === 'string' && value.length <= 96
    && /^synthetic-tenant-[a-z0-9]+(?:-[a-z0-9]+)*$/.test(value);
}
function fraction(value: unknown): value is Fraction {
  return isRecord(value) && onlyKeys(value, ['numerator', 'denominator'])
    && integer(value.numerator) && integer(value.denominator) && value.denominator > 0;
}
function validSource(value: unknown): value is CanonicalSources {
  return isRecord(value) && onlyKeys(value, ['snapshot', 'movements', 'retention'])
    && Object.keys(value).length === 3
    && value.snapshot === 'App\\Application\\Commercial\\PlatformCommercialMetrics'
    && value.movements === 'App\\Application\\Commercial\\SaasRevenueMovements'
    && value.retention === 'App\\Application\\Commercial\\SaasRetentionMetrics';
}

/** Allowlisted commercial projection, never real customer or payment fields. */
export function parseSaasDashboardPayload(
  payload: unknown, tenantId: string, owner: boolean, syntheticOnly: boolean,
): ParsedDashboard {
  const unknown: Unknown = { status: 'unknown' };
  if (owner !== true || syntheticOnly !== true || !syntheticTenant(tenantId)
    || !isRecord(payload)
    || !onlyKeys(payload, ['tenant_id', 'synthetic', 'owner_scope', 'status', 'projection'])
    || payload.tenant_id !== tenantId || payload.synthetic !== true
    || payload.owner_scope !== 'platform_owner') return unknown;
  if (payload.status === 'empty' && payload.projection === null) return { status: 'empty' };
  if (payload.status !== 'ready' || !isRecord(payload.projection)) return unknown;
  const p = payload.projection;
  const keys = [
    'status', 'reason', 'currency', 'mrr_minor', 'arr_minor',
    'active_customers', 'trials', 'arpa_minor_exact',
    'new_mrr_minor', 'expansion_mrr_minor', 'contraction_mrr_minor',
    'churned_mrr_minor', 'nrr_percent_exact', 'audit_count', 'source',
  ];
  if (!onlyKeys(p, keys) || Object.keys(p).length !== keys.length
    || p.status !== 'valid' || p.reason !== null
    || typeof p.currency !== 'string' || !/^[A-Z]{3}$/.test(p.currency)
    || !integer(p.mrr_minor) || !integer(p.arr_minor)
    || !integer(p.active_customers) || !integer(p.trials)
    || !integer(p.new_mrr_minor) || !integer(p.expansion_mrr_minor)
    || !integer(p.contraction_mrr_minor) || !integer(p.churned_mrr_minor)
    || !integer(p.audit_count) || !validSource(p.source)
    || (p.arpa_minor_exact !== null && !fraction(p.arpa_minor_exact))
    || (p.nrr_percent_exact !== null && !fraction(p.nrr_percent_exact))
    || (p.active_customers === 0 && p.arpa_minor_exact !== null)
    || (p.active_customers > 0 && p.arpa_minor_exact === null)) return unknown;
  const result: SaasDashboardMetrics = {
    currency: p.currency, mrr_minor: p.mrr_minor, arr_minor: p.arr_minor,
    active_customers: p.active_customers, trials: p.trials,
    arpa_minor_exact: p.arpa_minor_exact, new_mrr_minor: p.new_mrr_minor,
    expansion_mrr_minor: p.expansion_mrr_minor,
    contraction_mrr_minor: p.contraction_mrr_minor,
    churned_mrr_minor: p.churned_mrr_minor,
    nrr_percent_exact: p.nrr_percent_exact, audit_count: p.audit_count,
    source: p.source,
  };
  return { status: 'ready', data: result };
}
function money(minor: number, currency: string): string {
  try {
    return new Intl.NumberFormat('es-CO', {
      style: 'currency', currency, minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(minor / 100);
  } catch { return 'UNKNOWN'; }
}
function ratio(value: Fraction | null): string {
  if (!value) return 'UNKNOWN';
  const result = value.numerator / value.denominator;
  return Number.isFinite(result) ? result.toFixed(2) + '%' : 'UNKNOWN';
}

/** Read only: no HTTP client, mutation, billing operation or persistence. */
export function PlatformSaasDashboardPanel({
  tenantId, owner, syntheticOnly, loadMetrics,
}: PlatformSaasDashboardPanelProps) {
  const [state, setState] = useState<ViewState>({ status: 'loading' });
  const [revision, setRevision] = useState(0);
  const allowed = owner === true && syntheticOnly === true && syntheticTenant(tenantId);
  useEffect(() => {
    const controller = new AbortController();
    setState({ status: allowed ? 'loading' : 'unknown' });
    if (allowed) {
      Promise.resolve().then(() => loadMetrics(tenantId, controller.signal))
        .then((payload) => {
          if (!controller.signal.aborted) {
            setState(parseSaasDashboardPayload(payload, tenantId, owner, syntheticOnly));
          }
        })
        .catch(() => {
          if (!controller.signal.aborted) setState({ status: 'unknown' });
        });
    }
    return () => controller.abort();
  }, [tenantId, owner, syntheticOnly, loadMetrics, allowed, revision]);

  const visible: ViewState = allowed ? state : { status: 'unknown' };
  return (
    <section className="platform-section" aria-label="Métricas SaaS por empresa" aria-live="polite">
      <header><h2>Control Center · Métricas SaaS</h2><p>Consulta sintética, solo lectura.</p></header>
      {visible.status === 'loading' && <p role="status">Cargando proyección…</p>}
      {visible.status === 'empty' && <p role="status">Sin métricas verificables para esta empresa.</p>}
      {visible.status === 'unknown' && (
        <div role="alert">
          <p>UNKNOWN: métricas no disponibles o acceso sin verificar. No se muestran cifras.</p>
          {allowed && <button type="button" onClick={() => setRevision((n) => n + 1)}>Reintentar lectura</button>}
        </div>
      )}
      {visible.status === 'ready' && (
        <div className="tenant-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(11rem, 1fr))', gap: '1rem' }}>
          {([
            ['MRR', money(visible.data.mrr_minor, visible.data.currency)],
            ['ARR', money(visible.data.arr_minor, visible.data.currency)],
            ['Clientes activos', String(visible.data.active_customers)],
            ['Pruebas', String(visible.data.trials)],
            ['ARPA', visible.data.arpa_minor_exact ? money(visible.data.arpa_minor_exact.numerator / visible.data.arpa_minor_exact.denominator, visible.data.currency) : 'UNKNOWN'],
            ['MRR nuevo', money(visible.data.new_mrr_minor, visible.data.currency)],
            ['Expansión', money(visible.data.expansion_mrr_minor, visible.data.currency)],
            ['Contracción', money(visible.data.contraction_mrr_minor, visible.data.currency)],
            ['Churn', money(visible.data.churned_mrr_minor, visible.data.currency)],
            ['NRR', ratio(visible.data.nrr_percent_exact)],
            ['Eventos auditados', String(visible.data.audit_count)],
          ] as const).map(([label, value]) => (
            <article className="platform-card" key={label} aria-label={label}>
              <h3>{label}</h3><output>{value}</output>
            </article>
          ))}
        </div>
      )}
    </section>
  );
}
