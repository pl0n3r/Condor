import { useEffect, useState } from 'react';
import type { AdminNavEntry } from './AdminShell';

/**
 * Inicio operativo del tenant. AdminApp ya monta esta pieza solo cuando el
 * contexto de empresa está listo; sus APIs siguen siendo la fuente de verdad.
 * Nunca deriva pendientes de cifras simuladas ni consulta módulos ocultos.
 */
type Pending = Readonly<{
  id: 'orders' | 'stock' | 'payroll';
  title: string;
  count: number;
  href: string;
  partial?: boolean;
}>;
type State =
  | { status: 'loading' }
  | { status: 'ready'; rows: Pending[]; warning: boolean }
  | { status: 'denied' }
  | { status: 'error' };
type SafeObject = Record<string, unknown>;

function record(value: unknown): value is SafeObject {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function isUlid(value: unknown): value is string {
  return typeof value === 'string' && /^[0-9A-HJKMNP-TV-Z]{26}$/.test(value);
}

function permittedLinks(entries: readonly AdminNavEntry[]): Set<string> {
  const hrefs = new Set<string>();
  for (const entry of entries) {
    if ('group' in entry) {
      for (const item of entry.items) hrefs.add(item.href);
    } else {
      hrefs.add(entry.href);
    }
  }
  return hrefs;
}

class Denied extends Error {}
class Unavailable extends Error {}

async function getJson(path: string, signal: AbortSignal): Promise<unknown> {
  const response = await fetch(path, {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
    signal,
  });
  if (response.status === 401 || response.status === 403) throw new Denied();
  if (response.status === 404) throw new Unavailable();
  if (!response.ok) throw new Error('REQUEST_FAILED');
  return response.json() as Promise<unknown>;
}

/** La API existente aplica ambos filtros y calcula pagination.total con scope seguro. */
async function orderPending(branchId: string, signal: AbortSignal): Promise<Pending> {
  const query = new URLSearchParams({
    status: 'open', fulfillment_status: 'reserved', page: '1', per_page: '1',
  });
  const payload = await getJson(
    '/api/v1/branches/' + encodeURIComponent(branchId) + '/orders?' + query.toString(),
    signal,
  );
  if (!record(payload) || !record(payload.pagination)
    || !Number.isSafeInteger(payload.pagination.total)
    || (payload.pagination.total as number) < 0
    || !Array.isArray(payload.orders)) throw new Error('INVALID_ORDERS_TOTAL');
  return {
    id: 'orders', title: 'Pedidos por despachar', href: '/admin#orders',
    count: payload.pagination.total as number,
  };
}

async function stockPending(branchId: string, signal: AbortSignal): Promise<Pending> {
  const response = await getJson(
    '/api/v1/branches/' + encodeURIComponent(branchId) + '/inventory/stock-alerts', signal,
  );
  if (!record(response) || !Array.isArray(response.alerts)) throw new Error('INVALID_STOCK');
  let count = 0;
  for (const alert of response.alerts) {
    if (!record(alert) || !['pending', 'delivered'].includes(String(alert.status))) {
      throw new Error('INVALID_STOCK_ALERT');
    }
    if (alert.status === 'pending') count += 1;
  }
  return { id: 'stock', title: 'Alertas de stock', href: '/admin#stock-alerts', count };
}

async function payrollPending(branchId: string, tenantId: string, companyId: string,
  signal: AbortSignal): Promise<Pending> {
  const response = await getJson(
    '/api/v1/branches/' + encodeURIComponent(branchId) + '/payroll/real-records', signal,
  );
  if (!record(response) || !Array.isArray(response.records)
    || response.records.length > 100) throw new Error('INVALID_PAYROLL');
  let count = 0;
  for (const row of response.records) {
    if (!record(row) || row.tenant_id !== tenantId || row.company_id !== companyId
      || !['BORRADOR', 'EN_REVISION'].includes(String(row.state))) {
      throw new Error('INVALID_PAYROLL_SCOPE');
    }
    if (row.state === 'EN_REVISION') count += 1;
  }
  return { id: 'payroll', title: 'Nómina para revisión', href: '/admin#payroll-management', count };
}

export function ModuleDirectory({ entries }: Readonly<{ entries: readonly AdminNavEntry[] }>) {
  const [state, setState] = useState<State>({ status: 'loading' });
  const [retry, setRetry] = useState(0);

  useEffect(() => {
    const abort = new AbortController();
    void (async () => {
      setState({ status: 'loading' });
      try {
        const context = await getJson('/api/v1/context', abort.signal);
        if (!record(context) || !record(context.tenant) || !record(context.active_branch)
          || !isUlid(context.tenant.id) || !isUlid(context.active_branch.id)
          || !Array.isArray(context.permissions)
          || !context.permissions.every((permission) => typeof permission === 'string')) {
          throw new Error('INVALID_CONTEXT');
        }
        const tenantId = context.tenant.id;
        const branchId = context.active_branch.id;
        const companyId = record(context.active_legal_entity)
          ? context.active_legal_entity.id : null;
        const permissions = context.permissions as string[];
        const links = permittedLinks(entries);
        const requests: Array<Promise<Pending | null>> = [];
        if (permissions.includes('orders.view') && links.has('/admin#orders')) {
          requests.push(orderPending(branchId, abort.signal).catch(error => {
            if (error instanceof Denied || error instanceof Unavailable) return null;
            throw error;
          }));
        }
        if (permissions.includes('inventory.view') && links.has('/admin#stock-alerts')) {
          requests.push(stockPending(branchId, abort.signal).catch(error => {
            if (error instanceof Denied || error instanceof Unavailable) return null;
            throw error;
          }));
        }
        // Flag real SSR + autorización: jamás abrir nómina desde el preview.
        const payrollEnabled = document.getElementById('condor-admin-root')
          ?.dataset.payrollRealDataEnabled === 'true';
        if (payrollEnabled && permissions.includes('users.view')
          && links.has('/admin#payroll-management') && isUlid(companyId)) {
          requests.push(payrollPending(branchId, tenantId, companyId, abort.signal)
            .catch(error => {
              if (error instanceof Denied || error instanceof Unavailable) return null;
              throw error;
            }));
        }
        if (requests.length === 0) {
          if (!abort.signal.aborted) setState({ status: 'denied' });
          return;
        }
        const results = await Promise.allSettled(requests);
        if (abort.signal.aborted) return;
        const successful = results
          .filter((result): result is PromiseFulfilledResult<Pending | null> =>
            result.status === 'fulfilled')
          .map(result => result.value)
          .filter((value): value is Pending => value !== null);
        const warning = results.some(result => result.status === 'rejected')
          || successful.length !== requests.length
          || successful.some(item => item.partial);
        if (successful.length === 0) {
          setState({ status: warning ? 'error' : 'denied' });
          return;
        }
        setState({ status: 'ready', rows: successful.filter(item => item.count > 0), warning });
      } catch (error) {
        if (!abort.signal.aborted) {
          setState({ status: error instanceof Denied ? 'denied' : 'error' });
        }
      }
    })();
    return () => abort.abort();
    // AdminApp desmonta esta pieza al cambiar empresa/sede; este mount es único.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [retry]);

  return (
    <section className="module-directory" aria-labelledby="module-directory-title">
      <h2 id="module-directory-title">Para hoy</h2>
      {state.status === 'loading' && <p role="status">Consultando pendientes de esta sede…</p>}
      {state.status === 'denied' && (
        <p role="status">No tienes permiso para consultar los pendientes de esta empresa.</p>
      )}
      {state.status === 'error' && (
        <div role="alert">
          <p>No pudimos comprobar los pendientes. No asumimos que todo esté al día.</p>
          <button type="button" className="button" onClick={() => setRetry(value => value + 1)}>
            Reintentar
          </button>
        </div>
      )}
      {state.status === 'ready' && (
        <>
          {state.warning && (
            <p role="status">Algunas fuentes no están disponibles. Los totales visibles pueden ser parciales.</p>
          )}
          {state.rows.length === 0 && !state.warning && (
            <p role="status">Todo al día. No encontramos pendientes en los módulos disponibles.</p>
          )}
          {state.rows.length > 0 && (
            <div className="module-directory-groups">
              <div className="module-directory-group">
                <h3>Prioridades de la sede</h3>
                <ul>
                  {state.rows.map(item => (
                    <li key={item.id}>
                      <a href={item.href} aria-label={item.title + ': ' + (item.partial ? 'al menos ' : '') + item.count}>
                        <strong>{item.title}</strong>
                        <span className="overview-value">
                          {item.partial ? 'Al menos ' : ''}{item.count}
                          {' '}pendiente{item.count === 1 ? '' : 's'}
                        </span>
                        <span>Ver módulo</span>
                      </a>
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          )}
        </>
      )}
    </section>
  );
}
