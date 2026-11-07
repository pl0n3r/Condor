import { useEffect, useState } from 'react';
import { Card, DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';

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
  const [search, setSearch] = useState('');
  const [selectedVariant, setSelectedVariant] = useState<string | null>(null);

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

  const rows = state.status === 'ready'
    ? state.suggestions.filter((item) =>
      item.sku.toLocaleLowerCase('es-CO').includes(search.toLocaleLowerCase('es-CO')))
    : [];
  const selected = rows.find((item) => item.variant_id === selectedVariant);
  return (
    <section className="catalog-panel ui-page" aria-label="Sugerencias de resurtido">
      <PageHeader title="Sugerencias de resurtido"
        description="Estimación de solo lectura basada en consumo de los últimos 30 días." />
      {state.status === 'ready' && (
        <MetricStrip label="Resumen de resurtido" items={[
          { label: 'Variantes para reponer', value: state.suggestions.length },
          { label: 'Cantidad total sugerida', value: QUANTITY.format(state.suggestions.reduce((sum, item) => sum + item.suggested_quantity, 0)) },
          { label: 'Menor cobertura', value: state.suggestions.length === 0 ? 'Sin datos' : QUANTITY.format(state.suggestions[0].coverage_days) + ' días' },
        ]} />
      )}
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
      {state.status === 'ready' && (
        <Card title="Prioridad por menor cobertura disponible">
          <Toolbar trailing={<span className="ui-count">{rows.length} de {state.suggestions.length}</span>}>
            <label className="ui-search"><span className="ui-sr-only">Buscar por SKU</span>
              <input type="search" placeholder="Buscar por SKU" value={search}
                onChange={(event) => setSearch(event.target.value)} />
            </label>
          </Toolbar>
          <DataTable caption="Sugerencias de resurtido por variante" rows={rows}
            rowKey={(item) => item.variant_id} onOpen={(item) => setSelectedVariant(item.variant_id)}
            empty={<><strong>Sin sugerencias</strong><span>{search
              ? 'No hay coincidencias; prueba otro SKU.'
              : 'No hay sugerencias de resurtido para esta sede con el consumo registrado.'}</span></>}
            columns={[
              { key: 'sku', header: 'SKU', render: (item) => item.sku },
              { key: 'consumo', header: 'Consumo en 30 días', render: (item) => QUANTITY.format(item.consumed_30d), align: 'right' },
              { key: 'promedio', header: 'Promedio diario', render: (item) => QUANTITY.format(item.average_daily), align: 'right' },
              { key: 'cobertura', header: 'Cobertura', render: (item) => <StatusPill tone={item.coverage_days < 7 ? 'warn' : 'neutral'}>{QUANTITY.format(item.coverage_days)} días</StatusPill> },
              { key: 'disponible', header: 'Disponible', render: (item) => QUANTITY.format(item.available_quantity), align: 'right' },
              { key: 'sugerido', header: 'Cantidad sugerida', render: (item) => <strong>{QUANTITY.format(item.suggested_quantity)}</strong>, align: 'right' },
            ]} />
        </Card>
      )}
      <SidePanel open={Boolean(selected)} title={selected ? 'Resurtido · ' + selected.sku : 'Detalle de resurtido'}
        onClose={() => setSelectedVariant(null)}
        footer={<button className="button button-secondary" type="button" onClick={() => setSelectedVariant(null)}>Cerrar</button>}>
        {selected && <dl className="ui-details">
          <div><dt>SKU</dt><dd>{selected.sku}</dd></div>
          <div><dt>Consumo en 30 días</dt><dd>{QUANTITY.format(selected.consumed_30d)}</dd></div>
          <div><dt>Promedio diario</dt><dd>{QUANTITY.format(selected.average_daily)}</dd></div>
          <div><dt>Cobertura</dt><dd>{QUANTITY.format(selected.coverage_days)} días</dd></div>
          <div><dt>Disponible</dt><dd>{QUANTITY.format(selected.available_quantity)}</dd></div>
          <div><dt>Cantidad sugerida</dt><dd>{QUANTITY.format(selected.suggested_quantity)}</dd></div>
          <p className="muted">Estimación de solo lectura: no crea órdenes ni mueve existencias.</p>
        </dl>}
      </SidePanel>
    </section>
  );
}
