import { useEffect, useRef, useState } from 'react';

type SourceRow = { id: string; source_url: string };
type Sale = SourceRow & { amount_minor: number; currency: string; occurred_at: string };
type Stock = SourceRow & {
  variant_id: string; quantity: number; reserved_quantity: number;
  valuation_minor: null; valuation_state: 'UNKNOWN';
};
type Cost = SourceRow & {
  cost_state: 'ESTIMATED_COST' | 'UNKNOWN';
  estimated_total_cost: string | null; currency: null;
};
type Dashboard = {
  branch_id: string;
  period: { from: string; through: string };
  sales: {
    state: 'EMPTY' | 'PAID_AND_FULFILLED';
    by_currency_minor: Record<string, number>;
    records: Sale[];
    notice: string;
  };
  inventory: { state: 'UNKNOWN'; valuation_minor: null; reason: string; records: Stock[] };
  production: { state: 'EMPTY' | 'ESTIMATED_ONLY'; records: Cost[]; notice: string };
};
type LoadState =
  | { status: 'loading' }
  | { status: 'ready'; data: Dashboard }
  | { status: 'denied' }
  | { status: 'error' };

type Props = Readonly<{ branchId: string; permissions: string[] }>;

const datePart = (date: Date) => date.toISOString().slice(0, 10);

function authorizedSourceHref(branchId: string, href: string): string | null {
  const scope = '/api/v1/branches/' + encodeURIComponent(branchId) + '/management/sources/';
  if (!href.startsWith(scope) || href.includes('?') || href.includes('#')
    || href.includes('..') || href.includes('//')) {
    return null;
  }
  return href;
}

function SourceLink({
  branchId, row, children,
}: {
  branchId: string;
  row: SourceRow;
  children: React.ReactNode;
}) {
  const href = authorizedSourceHref(branchId, row.source_url);
  return href ? <a href={href} rel="nofollow">{children}</a> : <span>{children}</span>;
}

/**
 * Reusable read-only management panel. A direct SSR screen is also exposed at
 * /admin/management/{branchId}; integrating this component into AdminApp/Vite
 * requires a separate exclusive claim over the shared bundle.
 */
export function ManagementDashboard({ branchId, permissions }: Props) {
  const canView = permissions.includes('orders.view') && permissions.includes('inventory.view');
  const [state, setState] = useState<LoadState>({ status: 'loading' });
  const [from, setFrom] = useState(() => datePart(new Date(Date.now() - 29 * 86400000)));
  const [through, setThrough] = useState(() => datePart(new Date()));
  const currentRequest = useRef(0);

  useEffect(() => {
    const number = ++currentRequest.current;
    if (!canView) {
      setState({ status: 'denied' });
      return;
    }
    const abort = new AbortController();
    setState({ status: 'loading' });
    const query = new URLSearchParams({ from, through });
    const path = '/api/v1/branches/' + encodeURIComponent(branchId)
      + '/management/dashboard?' + query.toString();

    void (async () => {
      try {
        const response = await fetch(path, {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          signal: abort.signal,
        });
        if (number !== currentRequest.current) return;
        if (response.status === 401 || response.status === 403) {
          setState({ status: 'denied' });
          return;
        }
        if (!response.ok) throw new Error('REQUEST_FAILED');
        const data = await response.json() as Dashboard;
        if (number !== currentRequest.current) return;
        if (data.branch_id !== branchId
          || !Array.isArray(data.sales?.records)
          || !Array.isArray(data.inventory?.records)
          || !Array.isArray(data.production?.records)
          || data.inventory?.valuation_minor !== null
          || data.inventory?.state !== 'UNKNOWN') {
          throw new Error('INVALID_DASHBOARD');
        }
        setState({ status: 'ready', data });
      } catch (error: unknown) {
        if (abort.signal.aborted || number !== currentRequest.current) return;
        setState({ status: 'error' });
      }
    })();

    return () => abort.abort();
  }, [branchId, canView, from, through]);

  return (
    <section id="management" aria-labelledby="management-title">
      <h2 id="management-title">Panel gerencial · solo lectura</h2>
      <p>Las estimaciones y valores desconocidos nunca se presentan como utilidad realizada.</p>
      {canView && (
        <div>
          <label>Desde
            <input type="date" value={from} onChange={(event) => setFrom(event.target.value)} />
          </label>
          <label>Hasta
            <input type="date" value={through} onChange={(event) => setThrough(event.target.value)} />
          </label>
        </div>
      )}
      {state.status === 'loading' && <p role="status">Cargando cifras verificables…</p>}
      {state.status === 'denied' && <p role="alert">No tienes permiso para consultar este panel.</p>}
      {state.status === 'error' && <p role="alert">No fue posible recuperar las fuentes del panel.</p>}
      {state.status === 'ready' && (
        <>
          <section aria-label="Ventas del período">
            <h3>Ventas cobradas y entregadas</h3>
            <p>{state.data.sales.notice}</p>
            {Object.entries(state.data.sales.by_currency_minor).map(([currency, amount]) => (
              <p key={currency}>{currency}: {amount} unidades menores · ver registros individuales</p>
            ))}
            {state.data.sales.records.length === 0 && <p>No hay ventas verificadas en este período.</p>}
            <ul>
              {state.data.sales.records.map((sale) => (
                <li key={sale.id}>
                  <SourceLink branchId={branchId} row={sale}>
                    Pedido {sale.id} · {sale.amount_minor} {sale.currency} (unidades menores)
                  </SourceLink>
                </li>
              ))}
            </ul>
          </section>
          <section aria-label="Inventario valorizado">
            <h3>Inventario valorizado: UNKNOWN</h3>
            <p role="status">{state.data.inventory.reason}</p>
            {state.data.inventory.records.length === 0 && <p>Sin saldos de inventario.</p>}
            <ul>
              {state.data.inventory.records.map((stock) => (
                <li key={stock.id}>
                  <SourceLink branchId={branchId} row={stock}>
                    Saldo {stock.id} · {stock.quantity} unidades (reservadas: {stock.reserved_quantity})
                  </SourceLink>
                  {' · Valoración: no disponible'}
                </li>
              ))}
            </ul>
          </section>
          <section aria-label="Costo de producción">
            <h3>Costo de producción · estimado</h3>
            <p>{state.data.production.notice}</p>
            {state.data.production.records.length === 0 && <p>Sin órdenes terminadas.</p>}
            <ul>
              {state.data.production.records.map((cost) => (
                <li key={cost.id}>
                  <SourceLink branchId={branchId} row={cost}>
                    Orden {cost.id} · {cost.cost_state === 'ESTIMATED_COST'
                      ? 'estimado ' + cost.estimated_total_cost
                      : 'UNKNOWN'}
                  </SourceLink>
                </li>
              ))}
            </ul>
          </section>
        </>
      )}
    </section>
  );
}
