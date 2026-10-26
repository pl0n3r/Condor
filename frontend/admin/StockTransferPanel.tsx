import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { inventoryPath } from './api';

type Source = {
  id: string;
  name: string;
  type: 'branch' | 'logical';
  branch_id: string | null;
  legal_entity_id: string;
};
type Variant = { id: string; sku: string; name: string; product_name: string };
type Balance = { source_id: string; variant: { id: string }; quantity: number };
type Movement = {
  id: string;
  source_id: string;
  variant_id: string;
  type: string;
  delta: number;
  balance_after: number;
  transfer_id: string | null;
};
type Snapshot = {
  legal_entity: { id: string; name: string };
  branch: { id: string; name: string };
  sources: Source[];
  variants: Variant[];
  balances: Balance[];
  movements: Movement[];
};
type TransferReceipt = {
  id: string;
  source_from_id: string;
  source_to_id: string;
  variant_id: string;
  quantity: number;
  status: 'completed';
};
type Result = { receipt: TransferReceipt; movements: Movement[] };
type PanelState =
  | { status: 'loading' }
  | { status: 'ready'; data: Snapshot }
  | { status: 'error'; message: string }
  | { status: 'denied' };
type Props = Readonly<{ branchId: string; permissions: string[]; csrfToken: string }>;
type Idempotency = { signature: string; key: string };

function record(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function readSnapshot(value: unknown, branchId: string): Snapshot {
  if (!record(value) || !record(value.branch) || value.branch.id !== branchId
    || !record(value.legal_entity) || typeof value.legal_entity.id !== 'string'
    || !Array.isArray(value.sources) || !Array.isArray(value.variants)
    || !Array.isArray(value.balances) || !Array.isArray(value.movements)
    || !value.sources.every((source: unknown) => record(source)
      && typeof source.id === 'string' && typeof source.name === 'string'
      && typeof source.legal_entity_id === 'string'
      && (source.type === 'branch' || source.type === 'logical')
      && (source.branch_id === null || typeof source.branch_id === 'string'))
    || !value.variants.every((variant: unknown) => record(variant)
      && typeof variant.id === 'string' && typeof variant.sku === 'string'
      && typeof variant.name === 'string' && typeof variant.product_name === 'string')
    || !value.balances.every((balance: unknown) => record(balance)
      && typeof balance.source_id === 'string' && record(balance.variant)
      && typeof balance.variant.id === 'string'
      && typeof balance.quantity === 'number' && Number.isSafeInteger(balance.quantity))
    || !value.movements.every((movement: unknown) => record(movement)
      && typeof movement.id === 'string' && typeof movement.source_id === 'string'
      && typeof movement.variant_id === 'string'
      && typeof movement.type === 'string' && Number.isSafeInteger(movement.delta)
      && Number.isSafeInteger(movement.balance_after)
      && (movement.transfer_id === null || typeof movement.transfer_id === 'string'))) {
    throw new Error('El inventario recibido no corresponde a esta sede.');
  }
  return value as Snapshot;
}

function responseError(status: number): Error {
  switch (status) {
    case 401:
    case 403:
      return new Error('No tienes permiso para transferir existencias entre estas sedes.');
    case 404:
      return new Error('La sede o la variante ya no está disponible.');
    case 409:
      return new Error('Conflicto de transferencia. Consulta el inventario antes de reintentar.');
    case 422:
      return new Error('La cantidad no es válida o excede el stock disponible.');
    default:
      return new Error('No fue posible comunicar la transferencia con el servidor.');
  }
}

async function jsonResponse(response: Response): Promise<unknown> {
  if (!response.ok) throw responseError(response.status);
  try {
    return await response.json() as unknown;
  } catch {
    throw new Error('El servidor devolvió una respuesta inválida.');
  }
}

function readReceipt(value: unknown, from: string, to: string,
  variantId: string, quantity: number): TransferReceipt {
  if (!record(value) || !record(value.transfer)
    || typeof value.transfer.id !== 'string' || value.transfer.id.length === 0
    || value.transfer.source_from_id !== from
    || value.transfer.source_to_id !== to
    || value.transfer.variant_id !== variantId
    || value.transfer.quantity !== quantity
    || value.transfer.status !== 'completed') {
    throw new Error('No se pudo validar el comprobante de la transferencia.');
  }
  return value.transfer as TransferReceipt;
}

function parseQuantity(value: string): number | null {
  if (!/^[1-9][0-9]*$/.test(value)) return null;
  const number = Number(value);
  return Number.isSafeInteger(number) ? number : null;
}

export function StockTransferPanel({ branchId, permissions, csrfToken }: Props) {
  const canView = permissions.includes('inventory.view');
  const canUpdate = permissions.includes('inventory.update');
  const [state, setState] = useState<PanelState>({ status: 'loading' });
  const [originId, setOriginId] = useState('');
  const [destinationId, setDestinationId] = useState('');
  const [variantId, setVariantId] = useState('');
  const [quantity, setQuantity] = useState('1');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [result, setResult] = useState<Result | null>(null);
  const latest = useRef(0);
  const idempotency = useRef<Idempotency>({ signature: '', key: '' });

  async function load() {
    if (!canView) return;
    const request = ++latest.current;
    setState({ status: 'loading' });
    try {
      const response = await fetch(inventoryPath(branchId), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const data = readSnapshot(await jsonResponse(response), branchId);
      if (request !== latest.current) return;
      setState({ status: 'ready', data });
    } catch (error) {
      if (request !== latest.current) return;
      if (error instanceof Error && error.message.startsWith('No tienes permiso')) {
        setState({ status: 'denied' });
      } else {
        setState({
          status: 'error',
          message: error instanceof Error ? error.message
            : 'No fue posible cargar las existencias.',
        });
      }
    }
  }

  useEffect(() => {
    setOriginId('');
    setDestinationId('');
    setVariantId('');
    setResult(null);
    setNotice(null);
    idempotency.current = { signature: '', key: '' };
    if (canView) void load();
    return () => { latest.current += 1; };
  }, [branchId, canView]);

  const ready = state.status === 'ready' ? state.data : null;
  const sources = useMemo(() => (ready?.sources ?? []).filter((source) => (
    source.type === 'branch' && source.branch_id !== null
    && source.legal_entity_id === ready?.legal_entity.id
  )), [ready]);
  const origins = sources.filter((source) => source.branch_id === branchId);
  const selectedOrigin = origins.find((source) => source.id === originId) ?? origins[0];
  const destinations = sources.filter((source) => (
    source.branch_id !== branchId
  ));
  const selectedDestination = destinations.find((source) => (
    source.id === destinationId
  )) ?? destinations[0];
  const variants = ready?.variants ?? [];
  const selectedVariant = variants.find((variant) => variant.id === variantId)
    ?? variants[0];
  const available = selectedOrigin && selectedVariant
    ? ready?.balances.find((balance) => (
        balance.source_id === selectedOrigin.id
        && balance.variant.id === selectedVariant.id
      ))?.quantity ?? 0
    : 0;
  const parsedQuantity = parseQuantity(quantity);
  const validQuantity = parsedQuantity !== null && parsedQuantity <= available;
  const canTransfer = canView && canUpdate && !busy && ready !== null
    && Boolean(selectedOrigin && selectedDestination && selectedVariant)
    && validQuantity && result === null
    && selectedOrigin?.id !== selectedDestination?.id;

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canTransfer || !selectedOrigin || !selectedDestination || !selectedVariant
      || parsedQuantity === null) {
      setNotice('Selecciona sedes distintas, una variante y una cantidad disponible.');
      return;
    }
    const from = selectedOrigin.id;
    const to = selectedDestination.id;
    const variant = selectedVariant.id;
    const amount = parsedQuantity;
    const signature = JSON.stringify([branchId, from, to, variant, amount]);
    if (idempotency.current.signature !== signature) {
      idempotency.current = { signature, key: crypto.randomUUID() };
    }
    const request = latest.current;
    setBusy(true);
    setNotice(null);
    setResult(null);
    try {
      const response = await fetch(
        inventoryPath(branchId) + '/branch-transfers',
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
          },
          body: JSON.stringify({
            source_from_id: from,
            source_to_id: to,
            variant_id: variant,
            quantity: amount,
            idempotency_key: idempotency.current.key,
          }),
        },
      );
      const receipt = readReceipt(
        await jsonResponse(response), from, to, variant, amount,
      );
      if (request !== latest.current) return;
      // The POST only returns a transfer receipt. The movement ledger comes
      // from the existing authenticated snapshot, never from invented deltas.
      setResult({ receipt, movements: [] });
      idempotency.current = { signature: '', key: '' };
      try {
        const refresh = await fetch(inventoryPath(branchId), {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        const snapshot = readSnapshot(await jsonResponse(refresh), branchId);
        if (request !== latest.current) return;
        setState({ status: 'ready', data: snapshot });
        const movements = snapshot.movements.filter(
          (movement) => movement.transfer_id === receipt.id
            && movement.variant_id === variant,
        );
        const out = movements.find((movement) => (
          movement.source_id === from
          && movement.type === 'transfer_out' && movement.delta === -amount
        ));
        const into = movements.find((movement) => (
          movement.source_id === to
          && movement.type === 'transfer_in' && movement.delta === amount
        ));
        setResult({ receipt, movements: out && into ? [out, into] : [] });
        setNotice(out && into
          ? 'Transferencia aplicada y movimientos comprobados.'
          : 'Transferencia confirmada. El historial reciente no muestra los dos movimientos.');
      } catch {
        if (request === latest.current) {
          setNotice('Transferencia confirmada; no fue posible actualizar los movimientos.');
        }
      }
    } catch (error) {
      if (request === latest.current) {
        setNotice(error instanceof Error ? error.message
          : 'No fue posible confirmar la transferencia.');
      }
    } finally {
      if (request === latest.current) setBusy(false);
    }
  }

  return (
    <section id="stock-transfers" className="catalog-panel"
      aria-labelledby="stock-transfers-title">
      <div className="catalog-heading">
        <div>
          <span className="eyebrow">Inventario · movimiento entre sedes</span>
          <h2 id="stock-transfers-title">Transferencias entre sedes</h2>
          <p className="muted">
            La transferencia solo se confirma con el servidor de inventario.
          </p>
        </div>
      </div>
      {!canView && (
        <div className="alert alert-error" role="alert">
          No tienes permiso para consultar inventario.
        </div>
      )}
      {canView && state.status === 'loading' && (
        <output className="muted" aria-live="polite">
          Cargando sedes, variantes y existencias…
        </output>
      )}
      {canView && state.status === 'denied' && (
        <div className="alert alert-error" role="alert">
          No tienes acceso al inventario de esta sede.
        </div>
      )}
      {canView && state.status === 'error' && (
        <div className="alert alert-error" role="alert">
          <p>{state.message}</p>
          <button type="button" className="button" onClick={() => void load()}>
            Reintentar
          </button>
        </div>
      )}
      {ready && (
        <>
          {!canUpdate && (
            <p className="muted">No tienes permiso para hacer transferencias.</p>
          )}
          {(origins.length === 0 || destinations.length === 0 || variants.length === 0) && (
            <p className="muted">
              No hay sedes de origen y destino distintas o variantes disponibles
              para transferir con tu acceso actual.
            </p>
          )}
          {canUpdate && origins.length > 0 && destinations.length > 0
            && variants.length > 0 && (
              <form className="catalog-editor" onSubmit={(event) => void submit(event)}>
                <div className="catalog-form-grid">
                  <label className="field">
                    <span>Sede de origen</span>
                    <select required value={selectedOrigin?.id ?? ''}
                      onChange={(event) => { setOriginId(event.target.value); setResult(null); }}>
                      {origins.map((source) => (
                        <option key={source.id} value={source.id}>{source.name}</option>
                      ))}
                    </select>
                  </label>
                  <label className="field">
                    <span>Sede de destino</span>
                    <select required value={selectedDestination?.id ?? ''}
                      onChange={(event) => { setDestinationId(event.target.value); setResult(null); }}>
                      {destinations.map((source) => (
                        <option key={source.id} value={source.id}>{source.name}</option>
                      ))}
                    </select>
                  </label>
                  <label className="field">
                    <span>Variante</span>
                    <select required value={selectedVariant?.id ?? ''}
                      onChange={(event) => { setVariantId(event.target.value); setResult(null); }}>
                      {variants.map((variant) => (
                        <option key={variant.id} value={variant.id}>
                          {variant.product_name} · {variant.name} · {variant.sku}
                        </option>
                      ))}
                    </select>
                  </label>
                  <label className="field">
                    <span>Cantidad</span>
                    <input required type="number" min="1" step="1"
                      max={Math.max(0, available)}
                      value={quantity} onChange={(event) => {
                        setQuantity(event.target.value); setResult(null);
                      }} />
                  </label>
                </div>
                <p className="muted">
                  Stock registrado en origen: <strong>{available} und.</strong>
                  {' '}La disponibilidad final se valida en el servidor.
                </p>
                {!validQuantity && (
                  <p className="alert alert-error" role="alert">
                    La cantidad debe ser un entero positivo y no superar
                    las existencias disponibles.
                  </p>
                )}
                <button type="submit" className="button" disabled={!canTransfer}>
                  {busy ? 'Transfiriendo…' : 'Transferir entre sedes'}
                </button>
              </form>
            )}
        </>
      )}
      {notice && (
        <output className={result ? 'alert alert-success' : 'alert alert-error'}
          aria-live="polite">{notice}</output>
      )}
      {result && (
        <section aria-labelledby="transfer-result-title" className="catalog-list">
          <h3 id="transfer-result-title">Resultado de la transferencia</h3>
          <p>Comprobante: <strong>{result.receipt.id}</strong></p>
          <button type="button" className="button" onClick={() => {
            setResult(null);
            setNotice(null);
            setQuantity('1');
            idempotency.current = { signature: '', key: '' };
          }}>
            Preparar otra transferencia
          </button>
          <p>Estado: {result.receipt.status === 'completed' ? 'Completada' : 'Pendiente'}</p>
          {result.movements.length === 2 ? result.movements.map((movement) => (
            <article className="catalog-card" key={movement.id}>
              <div>
                <strong>{movement.type === 'transfer_out'
                  ? 'Salida de origen' : 'Entrada en destino'}</strong>
                <p>Movimiento: {movement.id}</p>
              </div>
              <p>{movement.delta > 0 ? '+' : ''}{movement.delta} und.
                {' '}Saldo posterior: {movement.balance_after} und.</p>
            </article>
          )) : (
            <p className="muted">
              Los movimientos todavía no aparecen en los últimos 50 registros.
              Consulta el inventario actualizado antes de operar de nuevo.
            </p>
          )}
        </section>
      )}
    </section>
  );
}
