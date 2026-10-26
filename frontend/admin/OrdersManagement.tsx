import { OrderLifecyclePanel } from './OrderLifecyclePanel';
import { FormEvent, useEffect, useRef, useState } from 'react';
import {
  orderCancelPath,
  orderConsumePath,
  ordersPath,
} from './api';

type OrderLine = {
  id: string;
  variant_id: string;
  quantity: number;
  effective_amount_minor: number;
  line_total_minor: number;
  currency: string;
  reservation_status: string | null;
};

type Order = {
  id: string;
  inventory_source_id: string;
  sales_channel_id: string | null;
  customer_id: string | null;
  order_status: string;
  payment_status: string;
  fulfillment_status: string;
  currency: string;
  total_amount_minor: number;
  created_at: string;
  lines: OrderLine[];
};

type Source = { id: string; name: string; legal_entity_id: string };
type PriceList = { id: string; name: string; currency: string };
type Customer = { id: string; name: string };
type Channel = {
  id: string;
  name: string;
  inventory_source_id: string;
  price_list_id: string;
};
type Variant = {
  id: string;
  sku: string;
  name: string;
  product_name: string;
};

type OrdersPagination = {
  page: number;
  per_page: number;
  total: number;
  has_more: boolean;
};

type OrdersFilters = {
  status: string;
  from: string;
  to: string;
  customerId: string;
  q: string;
};

const EMPTY_FILTERS: OrdersFilters = {
  status: '',
  from: '',
  to: '',
  customerId: '',
  q: '',
};

type Snapshot = {
  orders: Order[];
  pagination: OrdersPagination;
  sources: Source[];
  price_lists: PriceList[];
  customers: Customer[];
  channels: Channel[];
  variants: Variant[];
};

type Props = Readonly<{
  branchId: string;
  permissions: string[];
  csrfToken: string;
}>;

type State =
  | { status: 'loading' }
  | { status: 'ready'; data: Snapshot }
  | { status: 'error'; message: string };

type DraftLine = {
  id: string;
  variantId: string;
  quantity: string;
};
type Notice = { kind: 'success' | 'error'; text: string };
type PendingCreate = {
  fingerprint: string;
  idempotencyKey: string;
};

function secureUuid(): string {
  return crypto.randomUUID();
}

function idempotencyKey(): string {
  return 'admin-' + secureUuid();
}

function draftLine(variantId = ''): DraftLine {
  return {
    id: secureUuid(),
    variantId,
    quantity: '1',
  };
}

async function responseMessage(
  response: Response,
  fallback: string,
): Promise<string> {
  try {
    const payload = await response.json() as {
      message?: string;
      error?: string;
    };

    return payload.message ?? payload.error ?? fallback;
  } catch {
    return fallback;
  }
}

function money(amount: number, currency: string): string {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency,
    maximumFractionDigits: 0,
  }).format(amount / 100);
}

export function OrdersManagement({
  branchId,
  permissions,
  csrfToken,
}: Props) {
  const can = (permission: string) => permissions.includes(permission);
  const canView = can('orders.view');
  const canCreate = can('orders.create');
  const canUpdate = can('orders.update');

  const [state, setState] = useState<State>({ status: 'loading' });
  const [draftFilters, setDraftFilters] = useState<OrdersFilters>({ ...EMPTY_FILTERS });
  const [filters, setFilters] = useState<OrdersFilters>({ ...EMPTY_FILTERS });
  const [page, setPage] = useState(1);
  const latestRequest = useRef(0);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [expandedOrderId, setExpandedOrderId] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [sourceId, setSourceId] = useState('');
  const [priceListId, setPriceListId] = useState('');
  const [channelId, setChannelId] = useState('');
  const [customerId, setCustomerId] = useState('');
  const [lines, setLines] = useState<DraftLine[]>([
    draftLine(),
  ]);
  const pendingCreate = useRef<PendingCreate | null>(null);

  async function loadOrders() {
    if (!canView) {
      return;
    }
    const requestId = ++latestRequest.current;
    setState({ status: 'loading' });

    const params = new URLSearchParams({
      page: String(page),
      per_page: '20',
    });
    if (filters.status) params.set('status', filters.status);
    if (filters.from) params.set('from', filters.from);
    if (filters.to) params.set('to', filters.to);
    if (filters.customerId) params.set('customer_id', filters.customerId);
    if (filters.q.trim()) params.set('q', filters.q.trim());

    try {
      const response = await fetch(ordersPath(branchId) + '?' + params.toString(), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (!response.ok) {
        throw new Error(await responseMessage(
          response,
          'No fue posible cargar pedidos.',
        ));
      }

      const data = await response.json() as Snapshot;
      if (requestId !== latestRequest.current) return;
      setState({ status: 'ready', data });
      setSourceId((current) => current || data.sources[0]?.id || '');
      setPriceListId(
        (current) => current || data.price_lists[0]?.id || '',
      );
      setLines((current) => current.map((line, index) => (
        line.variantId || index > 0
          ? line
          : { ...line, variantId: data.variants[0]?.id || '' }
      )));
    } catch (error) {
      if (requestId !== latestRequest.current) return;
      setState({
        status: 'error',
        message: error instanceof Error
          ? error.message
          : 'No fue posible cargar pedidos.',
      });
    }
  }

  useEffect(() => {
    pendingCreate.current = null;
    setExpandedOrderId(null);
    setDraftFilters({ ...EMPTY_FILTERS });
    setFilters({ ...EMPTY_FILTERS });
    setPage(1);
  }, [branchId]);

  useEffect(() => {
    void loadOrders();
    return () => { latestRequest.current += 1; };
  }, [branchId, canView, filters, page]);

  function updateFilter(field: keyof OrdersFilters, value: string) {
    setDraftFilters((current) => ({ ...current, [field]: value }));
  }

  function applyFilters(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPage(1);
    setFilters({ ...draftFilters });
  }

  function clearFilters() {
    setDraftFilters({ ...EMPTY_FILTERS });
    setPage(1);
    setFilters({ ...EMPTY_FILTERS });
  }

  async function submitOrder(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (state.status !== 'ready') {
      return;
    }

    const items = lines.map((line) => ({
      variant_id: line.variantId,
      quantity: Number.parseInt(line.quantity, 10),
    }));
    if (
      !sourceId
      || !priceListId
      || items.some((item) => (
        !item.variant_id
        || !Number.isInteger(item.quantity)
        || item.quantity <= 0
      ))
    ) {
      setNotice({
        kind: 'error',
        text: 'Completa fuente, lista, variante y cantidades válidas.',
      });
      return;
    }

    const draft = {
      inventory_source_id: sourceId,
      price_list_id: priceListId,
      sales_channel_id: channelId || null,
      customer_id: customerId || null,
      items,
    };
    const fingerprint = JSON.stringify(draft);
    const previous = pendingCreate.current;
    const requestKey = previous?.fingerprint === fingerprint
      ? previous.idempotencyKey
      : idempotencyKey();
    pendingCreate.current = {
      fingerprint,
      idempotencyKey: requestKey,
    };

    setBusy(true);
    setNotice(null);
    try {
      const response = await fetch(ordersPath(branchId), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        body: JSON.stringify({
          ...draft,
          idempotency_key: requestKey,
        }),
      });
      if (!response.ok) {
        throw new Error(await responseMessage(
          response,
          'No fue posible crear el pedido.',
        ));
      }

      pendingCreate.current = null;
      setNotice({
        kind: 'success',
        text: 'Pedido creado y stock reservado.',
      });
      setLines([draftLine(state.data.variants[0]?.id || '')]);
      if (page === 1) {
        await loadOrders();
      } else {
        setPage(1);
      }
    } catch (error) {
      setNotice({
        kind: 'error',
        text: error instanceof Error
          ? error.message
          : 'No fue posible crear el pedido.',
      });
    } finally {
      setBusy(false);
    }
  }


  async function transition(path: string, success: string) {
    if (busy) return;
    setBusy(true);
    setNotice(null);
    try {
      const response = await fetch(path, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken },
      });
      if (!response.ok) throw new Error(await responseMessage(response, 'No fue posible actualizar el pedido.'));
      setNotice({ kind: 'success', text: success });
      await loadOrders();
    } catch (error) {
      setNotice({
        kind: 'error',
        text: error instanceof Error ? error.message : 'No fue posible actualizar el pedido.',
      });
    } finally {
      setBusy(false);
    }
  }
  if (!canView) {
    return (
      <section id="orders" className="catalog-panel">
        <div className="section-heading">
          <div>
            <span className="eyebrow">Ventas</span>
            <h2>Pedidos</h2>
          </div>
        </div>
        <p className="muted">No tienes permiso para consultar pedidos.</p>
      </section>
    );
  }

  const ready = state.status === 'ready' ? state.data : null;

  return (
    <section
      id="orders"
      className="catalog-panel"
      aria-labelledby="orders-title"
    >
      <div className="section-heading">
        <div>
          <span className="eyebrow">Ventas</span>
          <h2 id="orders-title">Pedidos</h2>
          <p className="muted">
            Admin y e-commerce comparten el mismo dominio, reservas y
            snapshots históricos de precio.
          </p>
        </div>
      </div>

      {notice && (
        <output
          className={
            notice.kind === 'error'
              ? 'catalog-notice catalog-notice-error'
              : 'catalog-notice'
          }
          aria-live="polite"
        >
          {notice.text}
        </output>
      )}

      {state.status === 'loading' && (
        <output className="catalog-state muted" aria-live="polite">
          Cargando pedidos…
        </output>
      )}

      {state.status === 'error' && (
        <div className="catalog-state catalog-error" role="alert">
          <strong>No pudimos cargar los pedidos.</strong>
          <span>{state.message}</span>
          <button
            type="button"
            className="button button-secondary"
            onClick={() => void loadOrders()}
          >
            Reintentar
          </button>
          <button type="button" className="button button-secondary"
            onClick={clearFilters}>
            Limpiar filtros
          </button>
        </div>
      )}

      {ready && canCreate && (
        <form className="catalog-card" onSubmit={submitOrder}>
          <div>
            <span className="eyebrow">Pedido manual</span>
            <h3>Crear pedido</h3>
            <p className="muted">
              Condor resuelve el precio y reserva inventario al guardar.
            </p>
          </div>

          <div className="catalog-form-grid">
            <label className="field">
              <span>Canal opcional</span>
              <select
                value={channelId}
                onChange={(event) => {
                  const value = event.target.value;
                  setChannelId(value);
                  const channel = ready.channels.find(
                    (candidate) => candidate.id === value,
                  );
                  if (channel) {
                    setSourceId(channel.inventory_source_id);
                    setPriceListId(channel.price_list_id);
                  }
                }}
              >
                <option value="">Sin canal</option>
                {ready.channels.map((channel) => (
                  <option value={channel.id} key={channel.id}>
                    {channel.name}
                  </option>
                ))}
              </select>
            </label>

            <label className="field">
              <span>Fuente de inventario</span>
              <select
                required
                value={sourceId}
                onChange={(event) => setSourceId(event.target.value)}
              >
                {ready.sources.map((source) => (
                  <option value={source.id} key={source.id}>
                    {source.name}
                  </option>
                ))}
              </select>
            </label>

            <label className="field">
              <span>Lista de precios</span>
              <select
                required
                value={priceListId}
                onChange={(event) => setPriceListId(event.target.value)}
              >
                {ready.price_lists.map((list) => (
                  <option value={list.id} key={list.id}>
                    {list.name}
                  </option>
                ))}
              </select>
            </label>

            <label className="field">
              <span>Cliente opcional</span>
              <select
                value={customerId}
                onChange={(event) => setCustomerId(event.target.value)}
              >
                <option value="">Sin cliente</option>
                {ready.customers.map((customer) => (
                  <option value={customer.id} key={customer.id}>
                    {customer.name}
                  </option>
                ))}
              </select>
            </label>
          </div>

          <div className="catalog-variant-list">
            {lines.map((line, index) => (
              <div className="catalog-variant-row" key={line.id}>
                <label className="field">
                  <span>Variante</span>
                  <select
                    required
                    value={line.variantId}
                    onChange={(event) => setLines((current) => (
                      current.map((candidate, candidateIndex) => (
                        candidateIndex === index
                          ? { ...candidate, variantId: event.target.value }
                          : candidate
                      ))
                    ))}
                  >
                    {ready.variants.map((variant) => (
                      <option value={variant.id} key={variant.id}>
                        {variant.product_name} · {variant.sku}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="field">
                  <span>Cantidad</span>
                  <input
                    required
                    min="1"
                    inputMode="numeric"
                    type="number"
                    value={line.quantity}
                    onChange={(event) => setLines((current) => (
                      current.map((candidate, candidateIndex) => (
                        candidateIndex === index
                          ? { ...candidate, quantity: event.target.value }
                          : candidate
                      ))
                    ))}
                  />
                </label>
                {lines.length > 1 && (
                  <button
                    type="button"
                    className="button button-secondary"
                    onClick={() => setLines((current) => (
                      current.filter((_, candidateIndex) => (
                        candidateIndex !== index
                      ))
                    ))}
                  >
                    Quitar
                  </button>
                )}
              </div>
            ))}
          </div>

          <div className="catalog-actions">
            <button
              type="button"
              className="button button-secondary"
              onClick={() => setLines((current) => [
                ...current,
                draftLine(ready.variants[0]?.id || ''),
              ])}
            >
              Añadir línea
            </button>
            <button type="submit" className="button" disabled={busy}>
              Crear pedido
            </button>
          </div>
        </form>
      )}

      {ready && (
        <form className="catalog-card" onSubmit={applyFilters} aria-label="Filtros de pedidos">
          <div>
            <h3>Filtrar pedidos</h3>
            <p className="muted">Los resultados pertenecen a la sede seleccionada.</p>
          </div>
          <div className="catalog-form-grid">
            <label className="field">
              <span>Estado del pedido</span>
              <select value={draftFilters.status}
                onChange={(event) => updateFilter('status', event.target.value)}>
                <option value="">Todos los estados</option>
                <option value="open">Abierto</option>
                <option value="cancelled">Cancelado</option>
              </select>
            </label>
            <label className="field">
              <span>Desde</span>
              <input type="date" value={draftFilters.from} max={draftFilters.to || undefined}
                onChange={(event) => updateFilter('from', event.target.value)} />
            </label>
            <label className="field">
              <span>Hasta</span>
              <input type="date" value={draftFilters.to} min={draftFilters.from || undefined}
                onChange={(event) => updateFilter('to', event.target.value)} />
            </label>
            <label className="field">
              <span>Cliente</span>
              <select value={draftFilters.customerId}
                onChange={(event) => updateFilter('customerId', event.target.value)}>
                <option value="">Todos los clientes</option>
                {ready.customers.map((customer) => (
                  <option value={customer.id} key={customer.id}>{customer.name}</option>
                ))}
              </select>
            </label>
            <label className="field">
              <span>Buscar por ID o cliente</span>
              <input type="search" maxLength={100} value={draftFilters.q}
                onChange={(event) => updateFilter('q', event.target.value)} />
            </label>
          </div>
          <div className="catalog-actions">
            <button className="button" type="submit">Aplicar filtros</button>
            <button className="button button-secondary" type="button"
              onClick={clearFilters}>Limpiar filtros</button>
          </div>
        </form>
      )}

      {ready && (
        <div className="catalog-list">
          {ready.orders.length === 0 ? (
            <p className="muted" role="status">
              No hay pedidos que coincidan con los filtros en esta sede.
            </p>
          ) : ready.orders.map((order) => (
            <article className="catalog-card" key={order.id}>
              <div className="section-heading">
                <div>
                  <span className="eyebrow">
                    {order.order_status} · {order.fulfillment_status}
                  </span>
                  <h3>Pedido {order.id.slice(-8)}</h3>
                  <p className="muted">
                    {new Date(order.created_at).toLocaleString('es-CO')}
                    {' · '}
                    {money(order.total_amount_minor, order.currency)}
                  </p>
                </div>
                {canView && (
                  <a
                    className="button button-secondary"
                    href={`/api/orders/${encodeURIComponent(order.id)}/receipt`}
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label={`Abrir comprobante del pedido ${order.id} en una pestaña nueva`}
                  >
                    Comprobante
                  </a>
                )}
                {canUpdate && order.order_status === 'open'
                  && order.fulfillment_status === 'reserved' && (
                  <div className="catalog-actions">
                    <button type="button" className="button button-secondary" disabled={busy}
                      onClick={() => void transition(orderCancelPath(branchId, order.id),
                        'Pedido cancelado y reserva liberada.')}>Cancelar</button>
                    <button type="button" className="button" disabled={busy}
                      onClick={() => void transition(orderConsumePath(branchId, order.id),
                        'Inventario consumido.')}>Consumir inventario</button>
                  </div>
                )}
              </div>

              <div className="catalog-variant-list">
                {order.lines.map((line) => {
                  const variant = ready.variants.find(
                    (candidate) => candidate.id === line.variant_id,
                  );
                  return (
                    <div className="catalog-variant-row" key={line.id}>
                      <div>
                        <strong>
                          {variant?.product_name ?? 'Producto'}
                          {' · '}
                          {variant?.sku ?? line.variant_id}
                        </strong>
                        <span className="muted">
                          {line.quantity} ×{' '}
                          {money(
                            line.effective_amount_minor,
                            line.currency,
                          )}
                          {' · reserva '}
                          {line.reservation_status ?? 'sin reserva'}
                        </span>
                      </div>
                      <strong>
                        {money(line.line_total_minor, line.currency)}
                      </strong>
                    </div>
                  );
                })}
              </div>
              <button type="button" className="button button-secondary"
                aria-expanded={expandedOrderId === order.id}
                aria-controls={'order-lifecycle-' + order.id}
                onClick={() => setExpandedOrderId((current) => current === order.id ? null : order.id)}>
                {expandedOrderId === order.id ? 'Ocultar ciclo del pedido' : 'Ver ciclo del pedido'}
              </button>
              {expandedOrderId === order.id && (
              <OrderLifecyclePanel
                branchId={branchId}
                order={order}
                csrfToken={csrfToken}
                canUpdate={canUpdate}
                cancelPath={orderCancelPath(branchId, order.id)}
                consumePath={orderConsumePath(branchId, order.id)}
                onChanged={loadOrders}
              />
              )}
            </article>
          ))}
        </div>
      )}

      {ready && (
        <nav className="catalog-actions" aria-label="Paginación de pedidos">
          <button type="button" className="button button-secondary"
            disabled={ready.pagination.page <= 1}
            onClick={() => setPage((current) => Math.max(1, current - 1))}>
            Anterior
          </button>
          <output aria-live="polite">
            Página {ready.pagination.page} · {ready.pagination.total} pedidos
          </output>
          <button type="button" className="button button-secondary"
            disabled={!ready.pagination.has_more}
            onClick={() => setPage((current) => current + 1)}>
            Siguiente
          </button>
        </nav>
      )}
    </section>
  );
}
