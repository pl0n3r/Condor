import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import {
  inventoryAdjustmentPath,
  inventoryPath,
  inventorySourcePath,
  inventoryTransferPath,
} from './api';

type Source = {
  id: string;
  name: string;
  slug: string;
  type: 'branch' | 'logical';
  branch_id: string | null;
  legal_entity_id: string;
};

type Variant = {
  id: string;
  sku: string;
  name: string;
  product_name: string;
};

type Balance = {
  id: string;
  source_id: string;
  legal_entity_id: string;
  variant: Variant;
  quantity: number;
  version: number;
};

type Movement = {
  id: string;
  source_id: string;
  legal_entity_id: string;
  variant_id: string;
  type: string;
  delta: number;
  balance_after: number;
  transfer_id: string | null;
  context: Record<string, string | number | boolean | null>;
  created_at: string;
};

type Snapshot = {
  legal_entity: { id: string; name: string };
  branch: { id: string; name: string };
  sources: Source[];
  variants: Variant[];
  balances: Balance[];
  movements: Movement[];
  can_manage_logical_sources: boolean;
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

type Notice = { kind: 'success' | 'error'; text: string };
type IdempotencyState = { signature: string; key: string };

const MOVEMENT_TYPE_LABELS: Readonly<Record<string, string>> = {
  adjustment_in: 'Ajuste de entrada',
  adjustment_out: 'Ajuste de salida',
  transfer_in: 'Transferencia de entrada',
  transfer_out: 'Transferencia de salida',
};

export function InventoryManagement({
  branchId,
  permissions,
  csrfToken,
}: Props) {
  const can = (permission: string) => permissions.includes(permission);
  const canView = can('inventory.view');
  const canCreate = can('inventory.create');
  const canUpdate = can('inventory.update');

  const [state, setState] = useState<State>({ status: 'loading' });
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const adjustmentIdempotency = useRef<IdempotencyState>({
    signature: '',
    key: '',
  });
  const transferIdempotency = useRef<IdempotencyState>({
    signature: '',
    key: '',
  });

  const [sourceName, setSourceName] = useState('');
  const [sourceSlug, setSourceSlug] = useState('');
  const [sourceType, setSourceType] = useState<'branch' | 'logical'>('branch');

  const [adjustSource, setAdjustSource] = useState('');
  const [adjustVariant, setAdjustVariant] = useState('');
  const [adjustDelta, setAdjustDelta] = useState('1');
  const [adjustReason, setAdjustReason] = useState('');

  const [transferFrom, setTransferFrom] = useState('');
  const [transferTo, setTransferTo] = useState('');
  const [transferVariant, setTransferVariant] = useState('');
  const [transferQuantity, setTransferQuantity] = useState('1');

  async function loadInventory() {
    if (!canView) {
      return;
    }

    setState({ status: 'loading' });
    try {
      const response = await fetch(inventoryPath(branchId), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (!response.ok) {
        throw new Error(await responseMessage(
          response,
          'No fue posible cargar el inventario.',
        ));
      }

      const data = await response.json() as Snapshot;
      setState({ status: 'ready', data });

      const firstSource = data.sources[0]?.id ?? '';
      const secondSource = data.sources[1]?.id ?? '';
      const firstVariant = data.variants[0]?.id ?? '';
      setAdjustSource((current) => current || firstSource);
      setAdjustVariant((current) => current || firstVariant);
      setTransferFrom((current) => current || firstSource);
      setTransferTo((current) => current || secondSource);
      setTransferVariant((current) => current || firstVariant);
    } catch (error) {
      setState({
        status: 'error',
        message: error instanceof Error
          ? error.message
          : 'No fue posible cargar el inventario.',
      });
    }
  }

  useEffect(() => {
    setNotice(null);
    setState({ status: 'loading' });
    setSourceName('');
    setSourceSlug('');
    setSourceType('branch');
    setAdjustSource('');
    setAdjustVariant('');
    setTransferFrom('');
    setTransferTo('');
    setTransferVariant('');
    adjustmentIdempotency.current = { signature: '', key: '' };
    transferIdempotency.current = { signature: '', key: '' };

    if (canView) {
      void loadInventory();
    }
  }, [branchId, canView]);

  const ready = state.status === 'ready' ? state.data : null;

  const sourceById = useMemo(() => {
    const map = new Map<string, Source>();
    for (const source of ready?.sources ?? []) {
      map.set(source.id, source);
    }
    return map;
  }, [ready]);

  const variantById = useMemo(() => {
    const map = new Map<string, Variant>();
    for (const variant of ready?.variants ?? []) {
      map.set(variant.id, variant);
    }
    return map;
  }, [ready]);

  async function createSource(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || busy) {
      return;
    }

    const succeeded = await runMutation(
      inventorySourcePath(branchId),
      {
        name: sourceName,
        slug: sourceSlug,
        type: sourceType,
      },
      'Fuente creada.',
    );
    if (succeeded) {
      setSourceName('');
      setSourceSlug('');
    }
  }

  async function adjust(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canUpdate || busy) {
      return;
    }

    const payload = {
      source_id: adjustSource,
      variant_id: adjustVariant,
      delta: Number(adjustDelta),
      reason: adjustReason,
    };
    const signature = JSON.stringify(payload);
    adjustmentIdempotency.current = stableIdempotency(
      adjustmentIdempotency.current,
      'adjust',
      signature,
    );

    const succeeded = await runMutation(
      inventoryAdjustmentPath(branchId),
      {
        ...payload,
        idempotency_key: adjustmentIdempotency.current.key,
      },
      'Ajuste aplicado.',
    );
    if (succeeded) {
      adjustmentIdempotency.current = { signature: '', key: '' };
      setAdjustReason('');
    }
  }

  async function transfer(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canUpdate || busy) {
      return;
    }

    const payload = {
      source_from_id: transferFrom,
      source_to_id: transferTo,
      variant_id: transferVariant,
      quantity: Number(transferQuantity),
    };
    const signature = JSON.stringify(payload);
    transferIdempotency.current = stableIdempotency(
      transferIdempotency.current,
      'transfer',
      signature,
    );

    const succeeded = await runMutation(
      inventoryTransferPath(branchId),
      {
        ...payload,
        idempotency_key: transferIdempotency.current.key,
      },
      'Transferencia aplicada.',
    );
    if (succeeded) {
      transferIdempotency.current = { signature: '', key: '' };
    }
  }

  async function runMutation(
    url: string,
    payload: Record<string, unknown>,
    success: string,
  ): Promise<boolean> {
    setBusy(true);
    setNotice(null);
    try {
      const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        body: JSON.stringify(payload),
      });
      if (!response.ok) {
        throw new Error(await responseMessage(
          response,
          'No fue posible completar la operación.',
        ));
      }

      setNotice({ kind: 'success', text: success });
      await loadInventory();

      return true;
    } catch (error) {
      setNotice({
        kind: 'error',
        text: error instanceof Error
          ? error.message
          : 'No fue posible completar la operación.',
      });

      return false;
    } finally {
      setBusy(false);
    }
  }

  if (!canView) {
    return (
      <section id="inventory" className="catalog-panel" aria-labelledby="inventory-title">
        <h2 id="inventory-title">Inventario</h2>
        <div className="alert alert-error" role="alert">
          No tienes permiso para consultar inventario en esta sede.
        </div>
      </section>
    );
  }

  return (
    <section id="inventory" className="catalog-panel" aria-labelledby="inventory-title">
      <div className="catalog-heading">
        <div>
          <span className="eyebrow">Operación</span>
          <h2 id="inventory-title">Inventario</h2>
          {ready && (
            <p className="muted">
              {ready.legal_entity.name} · {ready.branch.name}
            </p>
          )}
        </div>
      </div>

      {notice?.kind === 'error' && (
        <div className="alert alert-error" role="alert">
          {notice.text}
        </div>
      )}
      {notice?.kind === 'success' && (
        <output className="alert alert-success" aria-live="polite">
          {notice.text}
        </output>
      )}

      {state.status === 'loading' && (
        <output className="muted" aria-live="polite">
          Cargando existencias y movimientos…
        </output>
      )}

      {state.status === 'error' && (
        <div className="alert alert-error" role="alert">
          {state.message}
        </div>
      )}

      {ready && (
        <>
          <div className="catalog-list">
            {ready.sources.length === 0 ? (
              <p className="muted">Aún no hay fuentes de inventario configuradas.</p>
            ) : ready.sources.map((source) => (
              <article className="catalog-card" key={source.id}>
                <div>
                  <span className="eyebrow">
                    {source.type === 'branch' ? 'Sede' : 'Fuente lógica'}
                  </span>
                  <h3>{source.name}</h3>
                  <p className="muted">{source.slug}</p>
                </div>
              </article>
            ))}
          </div>

          {canCreate && (
            <form className="catalog-editor" onSubmit={(event) => void createSource(event)}>
              <h3>Nueva fuente</h3>
              <div className="catalog-form-grid">
                <label className="field">
                  <span>Nombre</span>
                  <input
                    required
                    maxLength={160}
                    value={sourceName}
                    onChange={(event) => setSourceName(event.target.value)}
                  />
                </label>
                <label className="field">
                  <span>Slug</span>
                  <input
                    required
                    maxLength={120}
                    pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                    value={sourceSlug}
                    onChange={(event) => setSourceSlug(event.target.value)}
                  />
                </label>
                <label className="field">
                  <span>Tipo</span>
                  <select
                    value={sourceType}
                    onChange={(event) => setSourceType(
                      event.target.value as 'branch' | 'logical',
                    )}
                  >
                    <option value="branch">Sede activa</option>
                    {ready.can_manage_logical_sources && (
                      <option value="logical">Lógica / canal</option>
                    )}
                  </select>
                </label>
              </div>
              <button className="button" type="submit" disabled={busy}>
                {busy ? 'Guardando…' : 'Crear fuente'}
              </button>
            </form>
          )}

          <div className="catalog-list">
            {ready.balances.length === 0 ? (
              <p className="muted">No hay saldos registrados todavía.</p>
            ) : ready.balances.map((balance) => (
              <article className="catalog-card" key={balance.id}>
                <div>
                  <span className="eyebrow">
                    {sourceById.get(balance.source_id)?.name ?? 'Fuente'}
                  </span>
                  <h3>{balance.variant.product_name}</h3>
                  <p>{balance.variant.sku} · {balance.variant.name}</p>
                </div>
                <strong>{balance.quantity} und.</strong>
              </article>
            ))}
          </div>

          {canUpdate && ready.sources.length > 0 && ready.variants.length > 0 && (
            <div className="catalog-list">
              <form className="catalog-card" onSubmit={(event) => void adjust(event)}>
                <div>
                  <span className="eyebrow">Movimiento</span>
                  <h3>Ajuste manual</h3>
                </div>
                <label className="field">
                  <span>Fuente</span>
                  <select
                    required
                    value={adjustSource}
                    onChange={(event) => setAdjustSource(event.target.value)}
                  >
                    {ready.sources.map((source) => (
                      <option key={source.id} value={source.id}>{source.name}</option>
                    ))}
                  </select>
                </label>
                <label className="field">
                  <span>Variante</span>
                  <select
                    required
                    value={adjustVariant}
                    onChange={(event) => setAdjustVariant(event.target.value)}
                  >
                    {ready.variants.map((variant) => (
                      <option key={variant.id} value={variant.id}>
                        {variant.product_name} · {variant.sku}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="field">
                  <span>Cantidad (+ entrada / - salida)</span>
                  <input
                    required
                    type="number"
                    step="1"
                    value={adjustDelta}
                    onChange={(event) => setAdjustDelta(event.target.value)}
                  />
                </label>
                <label className="field">
                  <span>Motivo</span>
                  <input
                    required
                    maxLength={240}
                    value={adjustReason}
                    onChange={(event) => setAdjustReason(event.target.value)}
                  />
                </label>
                <button className="button" type="submit" disabled={busy}>
                  {busy ? 'Aplicando…' : 'Aplicar ajuste'}
                </button>
              </form>

              {ready.sources.length > 1 && (
                <form className="catalog-card" onSubmit={(event) => void transfer(event)}>
                  <div>
                    <span className="eyebrow">Movimiento</span>
                    <h3>Transferencia</h3>
                  </div>
                  <label className="field">
                    <span>Origen</span>
                    <select
                      required
                      value={transferFrom}
                      onChange={(event) => setTransferFrom(event.target.value)}
                    >
                      {ready.sources.map((source) => (
                        <option key={source.id} value={source.id}>{source.name}</option>
                      ))}
                    </select>
                  </label>
                  <label className="field">
                    <span>Destino</span>
                    <select
                      required
                      value={transferTo}
                      onChange={(event) => setTransferTo(event.target.value)}
                    >
                      {ready.sources.map((source) => (
                        <option key={source.id} value={source.id}>{source.name}</option>
                      ))}
                    </select>
                  </label>
                  <label className="field">
                    <span>Variante</span>
                    <select
                      required
                      value={transferVariant}
                      onChange={(event) => setTransferVariant(event.target.value)}
                    >
                      {ready.variants.map((variant) => (
                        <option key={variant.id} value={variant.id}>
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
                      type="number"
                      step="1"
                      value={transferQuantity}
                      onChange={(event) => setTransferQuantity(event.target.value)}
                    />
                  </label>
                  <button
                    className="button"
                    type="submit"
                    disabled={busy || transferFrom === transferTo}
                  >
                    {busy ? 'Transfiriendo…' : 'Transferir'}
                  </button>
                </form>
              )}
            </div>
          )}

          <div className="catalog-list">
            <article className="catalog-card">
              <div>
                <span className="eyebrow">Trazabilidad</span>
                <h3>Movimientos recientes</h3>
              </div>
              {ready.movements.length === 0 ? (
                <p className="muted">No hay movimientos registrados.</p>
              ) : (
                <div className="catalog-variant-list">
                  {ready.movements.map((movement) => {
                    const variant = variantById.get(movement.variant_id);
                    const source = sourceById.get(movement.source_id);
                    const reason = movement.context.reason;

                    return (
                      <div className="catalog-variant-row" key={movement.id}>
                        <div>
                          <strong>
                            {variant?.sku ?? movement.variant_id} · {movement.delta > 0 ? '+' : ''}
                            {movement.delta}
                          </strong>
                          <p className="muted">
                            {source?.name ?? 'Fuente'} · {movementTypeLabel(movement.type)}
                            {typeof reason === 'string' ? ' · ' + reason : ''}
                          </p>
                        </div>
                        <span>{new Date(movement.created_at).toLocaleString('es-CO')}</span>
                      </div>
                    );
                  })}
                </div>
              )}
            </article>
          </div>
        </>
      )}
    </section>
  );
}

function stableIdempotency(
  current: IdempotencyState,
  prefix: string,
  signature: string,
): IdempotencyState {
  if (current.signature === signature && current.key !== '') {
    return current;
  }

  return {
    signature,
    key: idempotencyKey(prefix),
  };
}

function movementTypeLabel(type: string): string {
  return MOVEMENT_TYPE_LABELS[type] ?? 'Movimiento de inventario';
}

function idempotencyKey(prefix: string): string {
  const random = typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : String(Date.now());

  return prefix + '-' + random;
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
