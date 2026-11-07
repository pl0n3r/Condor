import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { inventoryPath } from './api';
import { Card, DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';

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
type Proposal = {
  source_from_id: string;
  source_from_branch_id: string;
  source_from_name: string;
  source_to_id: string;
  source_to_branch_id: string;
  variant_id: string;
  sku: string;
  quantity: number;
  destination_available: number;
  origin_excess: number;
};
type ProposalState =
  | { status: 'loading' }
  | { status: 'ready'; proposals: Proposal[] }
  | { status: 'error'; message: string };
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

function readProposals(value: unknown, branchId: string): Proposal[] {
  if (!record(value) || value.branch_id !== branchId
    || !Array.isArray(value.proposals)
    || !value.proposals.every((candidate: unknown) => record(candidate)
      && typeof candidate.source_from_id === 'string'
      && typeof candidate.source_to_id === 'string'
      && typeof candidate.source_from_branch_id === 'string'
      && candidate.source_from_branch_id !== branchId
      && candidate.source_to_branch_id === branchId
      && typeof candidate.source_from_name === 'string'
      && typeof candidate.variant_id === 'string'
      && typeof candidate.sku === 'string'
      && Number.isSafeInteger(candidate.quantity) && (candidate.quantity as number) > 0
      && Number.isSafeInteger(candidate.origin_excess)
      && (candidate.origin_excess as number) >= (candidate.quantity as number)
      && Number.isSafeInteger(candidate.destination_available)
      && (candidate.destination_available as number) >= 0
      && candidate.source_from_id !== candidate.source_to_id)) {
    throw new Error('Las propuestas recibidas no son válidas para esta sede.');
  }
  return value.proposals as Proposal[];
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
  const [proposalState, setProposalState] = useState<ProposalState>({ status: 'loading' });
  const [pendingProposal, setPendingProposal] = useState<Proposal | null>(null);
  const [search, setSearch] = useState('');
  const [creating, setCreating] = useState(false);
  const latest = useRef(0);
  const idempotency = useRef<Idempotency>({ signature: '', key: '' });

  async function loadProposals(request: number) {
    if (!canUpdate) {
      setProposalState({ status: 'ready', proposals: [] });
      return;
    }
    try {
      const response = await fetch(inventoryPath(branchId) + '/branch-transfers/proposals', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const proposals = readProposals(await jsonResponse(response), branchId);
      if (request === latest.current) {
        setProposalState({ status: 'ready', proposals });
      }
    } catch (error) {
      if (request === latest.current) {
        setProposalState({
          status: 'error',
          message: error instanceof Error ? error.message : 'No fue posible consultar propuestas.',
        });
      }
    }
  }

  async function load() {
    if (!canView) return;
    const request = ++latest.current;
    setState({ status: 'loading' });
    setProposalState({ status: 'loading' });
    try {
      const response = await fetch(inventoryPath(branchId), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const data = readSnapshot(await jsonResponse(response), branchId);
      if (request !== latest.current) return;
      setState({ status: 'ready', data });
      await loadProposals(request);
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
    setPendingProposal(null);
    setProposalState({ status: 'loading' });
    idempotency.current = { signature: '', key: '' };
    if (canView) void load();
    return () => { latest.current += 1; };
  }, [branchId, canView, canUpdate]);

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

  async function executeTransfer(
    routeBranchId: string,
    from: string,
    to: string,
    variant: string,
    amount: number,
  ) {
    if (!canView || !canUpdate || busy || !ready || !Number.isSafeInteger(amount)
      || amount <= 0 || from === to) {
      return;
    }
    const signature = JSON.stringify([routeBranchId, from, to, variant, amount]);
    if (idempotency.current.signature !== signature) {
      idempotency.current = { signature, key: crypto.randomUUID() };
    }
    const request = latest.current;
    setBusy(true);
    setNotice(null);
    setResult(null);
    try {
      // Only this explicit confirmation triggers a write. The existing
      // branch-transfer endpoint rechecks both branch permissions and stock
      // inside its idempotent, pessimistically locked transaction.
      const response = await fetch(
        inventoryPath(routeBranchId) + '/branch-transfers',
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
      setPendingProposal(null);
      setCreating(false);
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
      if (request === latest.current) {
        setProposalState({ status: 'loading' });
        await loadProposals(request);
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

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canTransfer || !selectedOrigin || !selectedDestination || !selectedVariant
      || parsedQuantity === null) {
      setNotice('Selecciona sedes distintas, una variante y una cantidad disponible.');
      return;
    }
    await executeTransfer(
      branchId,
      selectedOrigin.id,
      selectedDestination.id,
      selectedVariant.id,
      parsedQuantity,
    );
  }

  async function confirmProposal() {
    if (!pendingProposal || busy || !canUpdate || !canView) return;
    await executeTransfer(
      pendingProposal.source_from_branch_id,
      pendingProposal.source_from_id,
      pendingProposal.source_to_id,
      pendingProposal.variant_id,
      pendingProposal.quantity,
    );
  }

  return (
    <section id="stock-transfers" className="catalog-panel ui-page" aria-label="Transferencias entre sedes">
      <PageHeader title="Transferencias entre sedes"
        description="La transferencia solo se confirma con el servidor de inventario."
        actions={canUpdate && ready && origins.length > 0 && destinations.length > 0 && variants.length > 0
          && <button type="button" className="button" onClick={() => setCreating(true)}>Nueva transferencia</button>} />
      {ready && <MetricStrip label="Resumen de transferencias" items={[
        { label: 'Sedes de destino', value: destinations.length },
        { label: 'Variantes disponibles', value: variants.length },
        { label: 'Propuestas pendientes', value: proposalState.status === 'ready' ? proposalState.proposals.length : 'Consultando…' },
      ]} />}
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
          {canUpdate && (
            <Card title="Propuestas de reposición entre sedes">
              <p className="muted">
                Basadas en el consumo de 30 días y el excedente disponible de otra sede autorizada.
                No se mueve inventario hasta que confirmes la transferencia.
              </p>
              {proposalState.status === 'loading' && (
                <output className="muted" aria-live="polite">Calculando propuestas…</output>
              )}
              {proposalState.status === 'error' && (
                <div className="alert alert-error" role="alert">
                  <p>{proposalState.message}</p>
                  <button type="button" className="button" onClick={() => {
                    setProposalState({ status: 'loading' });
                    void loadProposals(latest.current);
                  }}>Reintentar propuestas</button>
                </div>
              )}
              {proposalState.status === 'ready' && proposalState.proposals.length === 0 && (
                <p className="muted">
                  No hay propuestas con excedente suficiente en sedes autorizadas.
                </p>
              )}
              {proposalState.status === 'ready' && <>
                <Toolbar trailing={<span className="ui-count">{proposalState.proposals.length} propuestas</span>}>
                  <label className="ui-search"><span className="ui-sr-only">Buscar SKU</span>
                    <input type="search" placeholder="Buscar SKU" value={search} onChange={event => setSearch(event.target.value)} />
                  </label>
                </Toolbar>
                <DataTable caption="Propuestas de reposición entre sedes"
                  rows={proposalState.proposals.filter(proposal => proposal.sku.toLocaleLowerCase('es-CO')
                    .includes(search.toLocaleLowerCase('es-CO')))}
                  rowKey={proposal => proposal.variant_id}
                  onOpen={proposal => { setPendingProposal(proposal); setNotice(null); }}
                  empty={<><strong>Sin propuestas</strong><span>Sin excedente suficiente o sin coincidencias.</span></>}
                  columns={[
                    { key: 'sku', header: 'SKU', render: proposal => proposal.sku },
                    { key: 'source', header: 'Origen', render: proposal => proposal.source_from_name },
                    { key: 'quantity', header: 'Cantidad sugerida', render: proposal => proposal.quantity, align: 'right' },
                    { key: 'excess', header: 'Excedente de origen', render: proposal => proposal.origin_excess, align: 'right' },
                    { key: 'state', header: 'Estado', render: () => <StatusPill tone="info">Revisar propuesta</StatusPill> },
                  ]} />
              </>}
              <SidePanel open={Boolean(pendingProposal)} title="Confirmación de transferencia propuesta"
                onClose={() => setPendingProposal(null)}>
                {pendingProposal && <div className="catalog-editor" role="group"
                  aria-label="Confirmación de transferencia propuesta">
                  <p>
                    Confirmar {pendingProposal.quantity} unidades de {pendingProposal.sku}
                    {' '}desde {pendingProposal.source_from_name}. El servidor volverá a
                    validar permisos y existencias.
                  </p>
                  <button type="button" className="button" disabled={busy || result !== null}
                    onClick={() => void confirmProposal()}>
                    {busy ? 'Transfiriendo…' : 'Confirmar transferencia'}
                  </button>
                  <button type="button" className="button" disabled={busy}
                    onClick={() => setPendingProposal(null)}>Cancelar</button>
                </div>}
              </SidePanel>
            </Card>
          )}
          {canUpdate && origins.length > 0 && destinations.length > 0
            && variants.length > 0 && (
              <SidePanel open={creating} title="Nueva transferencia" onClose={() => setCreating(false)}>
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
              </SidePanel>
            )}
        </>
      )}
      {notice && (
        <output className={result ? 'alert alert-success' : 'alert alert-error'}
          aria-live="polite">{notice}</output>
      )}
      {result && (
        <Card title="Resultado de la transferencia">
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
        </Card>
      )}
    </section>
  );
}
