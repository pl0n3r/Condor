import { FormEvent, useEffect, useRef, useState } from 'react';
import { ordersPath } from './api';

type OrderSummary = Readonly<{
  id: string;
  order_status: string;
  payment_status: string;
  fulfillment_status: string;
  total_amount_minor: number;
  currency: string;
}>;

type PaymentBalance = {
  paid_minor: number;
  pending_minor: number;
  payment_status: string;
};

type Delivery = {
  method: 'shipping' | 'pickup' | 'none' | null;
  status: string | null;
  tracking_number: string | null;
};

type Remote =
  | { status: 'loading' }
  | { status: 'error'; message: string }
  | { status: 'ready'; payment: PaymentBalance; delivery: Delivery };

type Props = Readonly<{
  branchId: string;
  order: OrderSummary;
  csrfToken: string;
  canUpdate: boolean;
  cancelPath: string;
  consumePath: string;
  onChanged: () => Promise<void>;
}>;

const DELIVERY_NEXT: Record<string, Record<string, string[]>> = {
  shipping: { pending: ['shipped'], shipped: ['delivered'], delivered: [] },
  pickup: { pending: ['ready'], ready: ['collected'], collected: [] },
  none: { pending: ['completed'], completed: [] },
};

const STATE_NAMES: Record<string, string> = {
  open: 'Abierto', cancelled: 'Cancelado', pending: 'Pendiente',
  paid: 'Pagado', reserved: 'Reservado', released: 'Liberado',
  consumed: 'Consumido', shipped: 'Enviado', delivered: 'Entregado',
  ready: 'Listo para recoger', collected: 'Recogido', completed: 'Completado',
};
const METHOD_NAMES: Record<string, string> = {
  shipping: 'Envío', pickup: 'Recogida', none: 'Sin entrega',
};
const REASONS = [
  ['customer_return', 'Devolución del cliente'],
  ['payment_correction', 'Corrección de pago'],
  ['order_cancelled', 'Pedido cancelado'],
  ['other', 'Otro motivo'],
] as const;

function name(value: string | null): string {
  return value === null ? 'Sin configurar' : (STATE_NAMES[value] ?? value);
}

function money(minor: number, currency: string): string {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency', currency, minimumFractionDigits: 0,
  }).format(minor / 100);
}

function parseMinor(value: string): number | null {
  if (!/^\d+(?:[.,]\d{1,2})?$/.test(value.trim())) return null;
  const number = Number(value.replace(',', '.'));
  const minor = Math.round(number * 100);
  return Number.isSafeInteger(minor) && minor > 0 ? minor : null;
}

async function errorMessage(response: Response): Promise<string> {
  try {
    const body = await response.json() as { message?: string; error?: string };
    return body.message ?? body.error ?? 'No se pudo completar la operación.';
  } catch {
    return 'No se pudo completar la operación.';
  }
}

function newKey(): string {
  return 'admin-' + crypto.randomUUID();
}

export function OrderLifecyclePanel({
  branchId, order, csrfToken, canUpdate, cancelPath, consumePath, onChanged,
}: Props) {
  const [remote, setRemote] = useState<Remote>({ status: 'loading' });
  const [notice, setNotice] = useState<{ error: boolean; message: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState('cash');
  const [paymentKey, setPaymentKey] = useState('');
  const [refundAmount, setRefundAmount] = useState('');
  const [reason, setReason] = useState('customer_return');
  const [deliveryMethod, setDeliveryMethod] = useState<'shipping' | 'pickup' | 'none'>('shipping');
  const [tracking, setTracking] = useState('');
  const paymentAttempt = useRef<{ fingerprint: string; key: string } | null>(null);
  const refundAttempt = useRef<{ fingerprint: string; key: string } | null>(null);
  const latest = useRef(0);

  // All paths derive from the existing branch-scoped API client, not tenant input.
  const root = ordersPath(branchId) + '/' + encodeURIComponent(order.id);
  const paymentPath = root + '/payments';
  const refundsPath = root + '/refunds';
  const deliveryPath = root + '/delivery';

  async function load() {
    const sequence = ++latest.current;
    setRemote({ status: 'loading' });
    try {
      const [paymentResponse, deliveryResponse] = await Promise.all([
        fetch(paymentPath, { credentials: 'same-origin', headers: { Accept: 'application/json' } }),
        fetch(deliveryPath, { credentials: 'same-origin', headers: { Accept: 'application/json' } }),
      ]);
      if (!paymentResponse.ok) throw new Error(await errorMessage(paymentResponse));
      if (!deliveryResponse.ok) throw new Error(await errorMessage(deliveryResponse));
      const paymentBody = await paymentResponse.json() as { payment: PaymentBalance };
      const deliveryBody = await deliveryResponse.json() as { delivery: Delivery };
      const balance = paymentBody.payment;
      if (!balance || !Number.isSafeInteger(balance.pending_minor)
        || !Number.isSafeInteger(balance.paid_minor) || !deliveryBody.delivery) {
        throw new Error('El servidor devolvió un estado de pedido incompleto.');
      }
      if (latest.current === sequence) {
        setRemote({ status: 'ready', payment: balance, delivery: deliveryBody.delivery });
      }
    } catch (error) {
      if (latest.current === sequence) {
        setRemote({
          status: 'error',
          message: error instanceof Error ? error.message : 'No se pudo cargar el ciclo.',
        });
      }
    }
  }

  useEffect(() => {
    setNotice(null);
    setPaymentKey('');
    paymentAttempt.current = null;
    refundAttempt.current = null;
    void load();
    return () => { latest.current += 1; };
  }, [branchId, order.id]);

  async function submit(
    url: string, success: string, body?: Record<string, unknown>, refreshOrder = false,
  ): Promise<boolean> {
    if (!canUpdate || busy || remote.status !== 'ready') return false;
    setBusy(true);
    setNotice(null);
    try {
      const response = await fetch(url, {
        method: 'POST', credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        ...(body ? { body: JSON.stringify(body) } : {}),
      });
      if (!response.ok) throw new Error(await errorMessage(response));
      setNotice({ error: false, message: success });
      if (refreshOrder) await onChanged();
      await load();
      return true;
    } catch (error) {
      setNotice({
        error: true,
        message: error instanceof Error ? error.message : 'No se pudo guardar el cambio.',
      });
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function registerPayment(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (remote.status !== 'ready') return;
    const amountMinor = parseMinor(amount);
    if (amountMinor === null || amountMinor > remote.payment.pending_minor) {
      setNotice({ error: true, message: 'Escribe un monto válido que no supere el saldo pendiente.' });
      return;
    }
    const fingerprint = order.id + ':' + amountMinor + ':' + method;
    const previous = paymentAttempt.current;
    const key = previous?.fingerprint === fingerprint ? previous.key : newKey();
    paymentAttempt.current = { fingerprint, key };
    if (await submit(paymentPath, 'Pago registrado; consulta el saldo actualizado.', {
      amount_minor: amountMinor, method, idempotency_key: key,
    })) {
      setPaymentKey(key);
      paymentAttempt.current = null;
      setAmount('');
    }
  }

  async function registerRefund(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const amountMinor = parseMinor(refundAmount);
    const reference = paymentKey.trim();
    if (amountMinor === null || !/^[A-Za-z0-9_-]{1,120}$/.test(reference)) {
      setNotice({ error: true, message: 'Indica una referencia de pago y un monto válidos.' });
      return;
    }
    const fingerprint = order.id + ':' + reference + ':' + amountMinor + ':' + reason;
    const previous = refundAttempt.current;
    const key = previous?.fingerprint === fingerprint ? previous.key : newKey();
    refundAttempt.current = { fingerprint, key };
    if (await submit(refundsPath, 'Reembolso registrado.', {
      payment_key: reference, amount_minor: amountMinor, reason,
      idempotency_key: key,
    })) {
      refundAttempt.current = null;
      setRefundAmount('');
    }
  }

  async function configureDelivery(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    await submit(deliveryPath, 'Cumplimiento configurado.', {
      method: deliveryMethod,
      ...(deliveryMethod === 'shipping' && tracking.trim()
        ? { tracking_number: tracking.trim() } : {}),
    });
  }

  const orderCanCancel = order.order_status === 'open' && order.fulfillment_status === 'reserved';
  const orderCanConsume = orderCanCancel;
  const ready = remote.status === 'ready' ? remote : null;
  const delivery = ready?.delivery;
  const deliverySteps = delivery?.method && delivery.status
    ? (DELIVERY_NEXT[delivery.method]?.[delivery.status] ?? []) : [];
  const deliveryEditable = order.order_status === 'open'
    && order.fulfillment_status !== 'released';
  const canPay = canUpdate && order.order_status === 'open'
    && ready !== null && ready.payment.pending_minor > 0;

  return (
    <section id={'order-lifecycle-' + order.id} className="catalog-card" aria-label={'Ciclo del pedido ' + order.id}>
      <div>
        <span className="eyebrow">Detalle del pedido</span>
        <h4>Ciclo del pedido</h4>
        <p className="muted">Estados independientes de pedido, pago e inventario.</p>
      </div>
      <dl className="catalog-form-grid">
        <div><dt>Estado del pedido</dt><dd>{name(order.order_status)}</dd></div>
        <div><dt>Estado del pago</dt>
          <dd>{name(ready?.payment.payment_status ?? order.payment_status)}</dd></div>
        <div><dt>Estado del inventario</dt><dd>{name(order.fulfillment_status)}</dd></div>
      </dl>

      {canUpdate && orderCanCancel && (
        <div className="catalog-actions" aria-label="Transiciones permitidas del pedido">
          <button type="button" className="button button-secondary" disabled={busy || !ready}
            onClick={() => void submit(cancelPath, 'Pedido cancelado; reserva liberada.', undefined, true)}>
            Cancelar pedido
          </button>
          <button type="button" className="button button-secondary" disabled={busy || !ready}
            onClick={() => void submit(consumePath, 'Inventario consumido.', undefined, true)}>
            Consumir inventario
          </button>
        </div>
      )}

      {remote.status === 'loading' && (
        <output role="status" aria-live="polite">Cargando pagos y entrega…</output>
      )}
      {remote.status === 'error' && (
        <div className="catalog-state catalog-error" role="alert">
          <p>{remote.message}</p>
          <button type="button" className="button button-secondary"
            onClick={() => void load()}>Reintentar carga</button>
        </div>
      )}
      {notice && (
        <output className={notice.error ? 'catalog-notice catalog-notice-error' : 'catalog-notice'}
          role={notice.error ? 'alert' : 'status'} aria-live="polite">
          {notice.message}
        </output>
      )}

      {ready && (
        <>
          <div>
            <h5>Pagos manuales</h5>
            <p>Registrado: {money(ready.payment.paid_minor, order.currency)}
              {' · '}Saldo pendiente: {money(ready.payment.pending_minor, order.currency)}</p>
            <p className="muted">Saldo calculado por el servidor. Los pagos parciales dependen de la configuración de la empresa.</p>
          </div>
          {canPay && (
            <form onSubmit={(event) => void registerPayment(event)} className="catalog-form-grid">
              <label className="field"><span>Monto del pago ({order.currency})</span>
                <input type="text" inputMode="decimal" required value={amount}
                  onChange={(event) => setAmount(event.target.value)}
                  placeholder="Ej. 10000" />
              </label>
              <label className="field"><span>Método de pago</span>
                <select value={method} onChange={(event) => setMethod(event.target.value)}>
                  <option value="cash">Efectivo</option>
                  <option value="transfer">Transferencia</option>
                </select>
              </label>
              <button type="submit" className="button" disabled={busy}>Registrar pago</button>
            </form>
          )}
          {paymentKey && <p className="muted">Referencia del último pago: <code>{paymentKey}</code></p>}
          {ready.payment.paid_minor === 0 && (
            <p role="status" className="muted">Este pedido todavía no tiene pagos registrados.</p>
          )}
          {ready.payment.paid_minor > 0 && canUpdate && (
            <form onSubmit={(event) => void registerRefund(event)} className="catalog-form-grid">
              <div><h5>Registrar reembolso</h5>
                <p className="muted">Total o parcial, sujeto al monto reembolsable validado por el servidor.</p></div>
              <label className="field"><span>Referencia del pago</span>
                <input type="text" required maxLength={120} value={paymentKey}
                  onChange={(event) => setPaymentKey(event.target.value)} />
              </label>
              <p className="muted">Para pagos anteriores usa su clave de registro. La API actual no publica el historial de referencias.</p>
              <label className="field"><span>Monto del reembolso ({order.currency})</span>
                <input type="text" inputMode="decimal" required value={refundAmount}
                  onChange={(event) => setRefundAmount(event.target.value)} />
              </label>
              <label className="field"><span>Motivo</span>
                <select value={reason} onChange={(event) => setReason(event.target.value)}>
                  {REASONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </select>
              </label>
              <button type="submit" className="button" disabled={busy}>Registrar reembolso</button>
            </form>
          )}

          <div>
            <h5>Cumplimiento de entrega</h5>
            <p>Modalidad: {delivery?.method ? METHOD_NAMES[delivery.method] : 'Sin configurar'}
              {' · '}Estado: {name(delivery?.status ?? null)}</p>
            {delivery?.tracking_number && <p>Guía: {delivery.tracking_number}</p>}
          </div>
          {canUpdate && deliveryEditable && !delivery?.method && (
            <form onSubmit={(event) => void configureDelivery(event)} className="catalog-form-grid">
              <label className="field"><span>Modalidad</span>
                <select value={deliveryMethod}
                  onChange={(event) => setDeliveryMethod(event.target.value as 'shipping' | 'pickup' | 'none')}>
                  <option value="shipping">Envío</option>
                  <option value="pickup">Recogida</option>
                  <option value="none">Sin entrega</option>
                </select>
              </label>
              {deliveryMethod === 'shipping' && (
                <label className="field"><span>Número de guía (opcional)</span>
                  <input type="text" maxLength={80} value={tracking}
                    onChange={(event) => setTracking(event.target.value)} />
                </label>
              )}
              <button type="submit" className="button" disabled={busy}>Definir cumplimiento</button>
            </form>
          )}
          {canUpdate && deliveryEditable && delivery?.method && deliverySteps.length > 0 && (
            <div className="catalog-actions" aria-label="Transiciones permitidas de entrega">
              {deliverySteps.map((next) => (
                <button type="button" className="button button-secondary" key={next}
                  disabled={busy}
                  onClick={() => void submit(deliveryPath + '/transition',
                    'Entrega actualizada.', { status: next })}>
                  Marcar: {name(next)}
                </button>
              ))}
            </div>
          )}
        </>
      )}
    </section>
  );
}
