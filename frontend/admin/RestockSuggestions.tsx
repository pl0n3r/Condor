import { useEffect, useState } from 'react';

type Suggestion = {
  variant_id: string;
  sku: string;
  source_id: string;
  consumed_30d: number;
  average_daily: number;
  coverage_days: number;
  available_quantity: number;
  suggested_quantity: number;
};

type State =
  | { status: 'loading' }
  | { status: 'ready'; suggestions: Suggestion[] }
  | { status: 'error'; message: string }
  | { status: 'forbidden' };

type Props = Readonly<{
  branchId: string;
  permissions: readonly string[];
}>;

const BRANCH_ID = /^[0-9A-HJKMNP-TV-Z]{26}$/;
const QUANTITY = new Intl.NumberFormat('es-CO', { maximumFractionDigits: 2 });

function record(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function finiteNonNegative(value: unknown): value is number {
  return typeof value === 'number' && Number.isFinite(value) && value >= 0;
}

function nonNegativeInteger(value: unknown): value is number {
  return finiteNonNegative(value) && Number.isSafeInteger(value);
}

function suggestion(value: unknown): value is Suggestion {
  return record(value)
    && typeof value.variant_id === 'string' && BRANCH_ID.test(value.variant_id)
    && typeof value.source_id === 'string' && BRANCH_ID.test(value.source_id)
    && typeof value.sku === 'string' && value.sku.length >= 1 && value.sku.length <= 64
    && nonNegativeInteger(value.consumed_30d)
    && finiteNonNegative(value.average_daily)
    && finiteNonNegative(value.coverage_days)
    && nonNegativeInteger(value.available_quantity)
    && nonNegativeInteger(value.suggested_quantity)
    && value.consumed_30d > 0 && value.suggested_quantity > 0;
}

function parseSuggestions(value: unknown, branchId: string): Suggestion[] {
  if (!record(value) || value.branch_id !== branchId
    || typeof value.source_id !== 'string' || !BRANCH_ID.test(value.source_id)
    || value.window_days !== 30 || !Array.isArray(value.suggestions)
    || value.suggestions.length > 10000 || !value.suggestions.every(suggestion)
    || !value.suggestions.every((item: Suggestion) => item.source_id === value.source_id)) {
    throw new Error('Las sugerencias recibidas no corresponden a esta sede.');
  }

  // Less coverage means more urgency; never invent recommendations or demand.
  return [...value.suggestions].sort((a: Suggestion, b: Suggestion) =>
    a.coverage_days - b.coverage_days
    || b.suggested_quantity - a.suggested_quantity
    || a.sku.localeCompare(b.sku, 'es-CO'));
}

export function RestockSuggestions({ branchId, permissions }: Props) {
  const canView = permissions.includes('inventory.view');
  const [state, setState] = useState<State>({ status: 'loading' });
  const [reload, setReload] = useState(0);

  useEffect(() => {
    if (!canView) {
      setState({ status: 'forbidden' });
      return;
    }
    if (!BRANCH_ID.test(branchId)) {
      setState({ status: 'error', message: 'La sede seleccionada no es válida.' });
      return;
    }

    const controller = new AbortController();
    let current = true;
    setState({ status: 'loading' });

    async function load(): Promise<void> {
      try {
        const response = await fetch(
          '/api/inventory/restock-suggestions?branch_id=' + encodeURIComponent(branchId),
          {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
            cache: 'no-store',
          },
        );
        if (!response.ok) {
          throw new Error(response.status === 403
            ? 'No tienes permiso para consultar las sugerencias de esta sede.'
            : 'No fue posible cargar las sugerencias de resurtido.');
        }
        const payload: unknown = await response.json();
        const suggestions = parseSuggestions(payload, branchId);
        if (current) setState({ status: 'ready', suggestions });
      } catch {
        if (current) {
          setState({
            status: 'error',
            message: 'No fue posible consultar el resurtido de esta sede. Intenta de nuevo.',
          });
        }
      }
    }
    void load();

    return () => {
      current = false;
      controller.abort();
    };
  }, [branchId, canView, reload]);

  return (
    <section className="catalog-panel" aria-labelledby="restock-suggestions-title">
      <div className="catalog-heading">
        <div>
          <span className="eyebrow">Inventario</span>
          <h3 id="restock-suggestions-title">Sugerencias de resurtido</h3>
          <p className="muted">Estimación de solo lectura basada en consumo de los últimos 30 días.</p>
        </div>
      </div>
      {state.status === 'forbidden' && (
        <p className="alert alert-error" role="alert">
          No tienes permiso para consultar las sugerencias de resurtido.
        </p>
      )}
      {state.status === 'loading' && (
        <output className="muted" aria-live="polite">Cargando sugerencias de resurtido…</output>
      )}
      {state.status === 'error' && (
        <div className="alert alert-error" role="alert">
          <p>{state.message}</p>
          <button className="button" type="button" onClick={() => setReload((v) => v + 1)}>
            Reintentar
          </button>
        </div>
      )}
      {state.status === 'ready' && state.suggestions.length === 0 && (
        <p className="muted" role="status">
          No hay sugerencias de resurtido para esta sede con el consumo registrado.
        </p>
      )}
      {state.status === 'ready' && state.suggestions.length > 0 && (
        <div className="catalog-variant-list">
          <table>
            <caption>Prioridad por menor cobertura disponible</caption>
            <thead>
              <tr>
                <th scope="col">SKU</th>
                <th scope="col">Consumo en 30 días</th>
                <th scope="col">Promedio diario</th>
                <th scope="col">Cobertura (días)</th>
                <th scope="col">Disponible</th>
                <th scope="col">Cantidad sugerida</th>
              </tr>
            </thead>
            <tbody>
              {state.suggestions.map((item) => (
                <tr key={item.variant_id}>
                  <th scope="row">{item.sku}</th>
                  <td>{QUANTITY.format(item.consumed_30d)}</td>
                  <td>{QUANTITY.format(item.average_daily)}</td>
                  <td>{QUANTITY.format(item.coverage_days)}</td>
                  <td>{QUANTITY.format(item.available_quantity)}</td>
                  <td><strong>{QUANTITY.format(item.suggested_quantity)}</strong></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
