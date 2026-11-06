import { FormEvent, useEffect, useRef, useState } from 'react';
import { Card, DataTable, MetricStrip, PageHeader, Toolbar } from './ui/Kit';

type CurrencyTotal = {
  currency: string;
  units: number;
  revenue_minor: number;
};

type ProductRow = {
  product_id: string;
  variant_id: string;
  product_name: string;
  variant_name: string;
  sku: string;
  currency: string;
  units: number;
  revenue_minor: number;
  participation_bps: number;
};

type MonthRow = {
  month: string;
  currency: string;
  units: number;
  revenue_minor: number;
};

type Report<T> = {
  items: T[];
  currency_totals: CurrencyTotal[];
  group_by: 'product' | 'month';
};

type Reports = { products: ProductRow[]; months: MonthRow[] };
type LoadState =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'error'; message: string }
  | { status: 'ready'; data: Reports };

type Props = Readonly<{ permissions: string[] }>;

function validDate(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const parsed = new Date(value + 'T00:00:00Z');
  return !Number.isNaN(parsed.getTime())
    && parsed.toISOString().slice(0, 10) === value;
}

function validRange(from: string, to: string): boolean {
  if (!validDate(from) || !validDate(to)) return false;
  const days = (Date.parse(to + 'T00:00:00Z')
    - Date.parse(from + 'T00:00:00Z')) / 86400000;
  return days >= 0 && days <= 365;
}

function validMoneyRow(value: unknown): value is CurrencyTotal {
  if (value === null || typeof value !== 'object') return false;
  const row = value as Record<string, unknown>;
  return typeof row.currency === 'string'
    && /^[A-Z]{3}$/.test(row.currency)
    && typeof row.units === 'number' && Number.isSafeInteger(row.units) && row.units >= 0
    && typeof row.revenue_minor === 'number'
    && Number.isSafeInteger(row.revenue_minor) && row.revenue_minor >= 0;
}

function productRow(value: unknown): value is ProductRow {
  if (!validMoneyRow(value)) return false;
  const row = value as unknown as Record<string, unknown>;
  return ['product_id', 'variant_id', 'product_name', 'variant_name', 'sku']
    .every((key) => typeof row[key] === 'string')
    && typeof row.participation_bps === 'number'
    && Number.isInteger(row.participation_bps)
    && row.participation_bps >= 0
    && row.participation_bps <= 10000;
}

function monthRow(value: unknown): value is MonthRow {
  if (!validMoneyRow(value)) return false;
  const row = value as unknown as Record<string, unknown>;
  return typeof row.month === 'string'
    && /^\d{4}-(0[1-9]|1[0-2])$/.test(row.month);
}

async function report<T>(
  endpoint: 'sales-by-product' | 'sales-by-month',
  params: URLSearchParams,
  dimension: 'product' | 'month',
  validateRow: (value: unknown) => value is T,
): Promise<Report<T>> {
  const response = await fetch('/api/reports/' + endpoint + '?' + params.toString(), {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });
  if (response.status === 401 || response.status === 403) {
    throw new Error('No tienes permiso para consultar estos reportes.');
  }
  if (!response.ok) {
    throw new Error('No fue posible consultar las ventas.');
  }
  const payload: unknown = await response.json();
  if (payload === null || typeof payload !== 'object') {
    throw new Error('Respuesta de reportes inválida.');
  }
  const data = payload as Record<string, unknown>;
  if (data.group_by !== dimension || !Array.isArray(data.items)
    || !Array.isArray(data.currency_totals)
    || !data.items.every(validateRow)
    || !data.currency_totals.every(validMoneyRow)) {
    throw new Error('Respuesta de reportes inválida.');
  }
  return data as unknown as Report<T>;
}

function money(amountMinor: number, currency: string): string {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency', currency, maximumFractionDigits: 2,
  }).format(amountMinor / 100);
}

export function SalesReportPanel({ permissions }: Props) {
  const allowed = permissions.includes('orders.view');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [state, setState] = useState<LoadState>({ status: 'idle' });
  const requestId = useRef(0);

  useEffect(() => () => { requestId.current += 1; }, []);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!allowed) return;
    const serial = ++requestId.current;
    if (!validRange(from, to)) {
      setState({ status: 'error', message: 'El rango debe ser válido y no superar 366 días.' });
      return;
    }
    setState({ status: 'loading' });
    const params = new URLSearchParams({ from, to });
    try {
      const [products, months] = await Promise.all([
        report('sales-by-product', params, 'product', productRow),
        report('sales-by-month', params, 'month', monthRow),
      ]);
      if (serial !== requestId.current) return;
      setState({
        status: 'ready',
        data: {
          products: [...products.items].sort((a, b) => (
            a.product_name.localeCompare(b.product_name, 'es')
            || a.variant_name.localeCompare(b.variant_name, 'es')
            || a.currency.localeCompare(b.currency, 'es')
          )),
          months: [...months.items].sort((a, b) => (
            a.month.localeCompare(b.month) || a.currency.localeCompare(b.currency)
          )),
        },
      });
    } catch (cause) {
      if (serial !== requestId.current) return;
      setState({
        status: 'error',
        message: cause instanceof Error ? cause.message : 'No fue posible consultar las ventas.',
      });
    }
  }

  if (!allowed) return null;

  const summary = state.status === 'ready' ? state.data : null;
  return (
    <section id="sales-reports" className="ui-page" aria-label="Reportes de ventas">
      <PageHeader title="Reportes de ventas"
        description="Ventas por producto y por mes, limitadas a tus sedes autorizadas." />
      {summary && (
        <MetricStrip label="Resumen de ventas" items={[
          { label: 'Productos y variantes', value: summary.products.length },
          { label: 'Meses con ventas', value: summary.months.length },
        ]} />
      )}
      <Card>
        <form onSubmit={(event) => { void submit(event); }}>
          <Toolbar trailing={<button type="submit" className="button" disabled={state.status === 'loading'}>
            Consultar ventas
          </button>}>
            <label htmlFor="sales-from">Desde
              <input id="sales-from" type="date" required value={from}
                onChange={(event) => setFrom(event.target.value)} />
            </label>
            <label htmlFor="sales-to">Hasta
              <input id="sales-to" type="date" required value={to}
                onChange={(event) => setTo(event.target.value)} />
            </label>
          </Toolbar>
        </form>
        <div aria-live="polite">
          {state.status === 'idle' && <p>Selecciona un rango para consultar las ventas.</p>}
          {state.status === 'loading' && <p role="status">Cargando reportes…</p>}
          {state.status === 'error' && <p role="alert">{state.message}</p>}
        </div>
      </Card>
      {summary && (
        <>
          <Card title="Ventas por producto">
            <DataTable caption="Ventas agrupadas por producto y variante"
              rows={summary.products}
              rowKey={(row) => row.currency + ':' + row.variant_id}
              empty={<p>No hay ventas por producto en el rango seleccionado.</p>}
              columns={[
                { key: 'product', header: 'Producto', render: (row) => row.product_name },
                { key: 'variant', header: 'Variante / SKU', render: (row) => row.variant_name + ' · ' + row.sku },
                { key: 'units', header: 'Unidades', render: (row) => row.units, align: 'right' },
                { key: 'revenue', header: 'Ingreso', render: (row) => money(row.revenue_minor, row.currency), align: 'right' },
                { key: 'share', header: 'Participación', render: (row) => (row.participation_bps / 100).toFixed(2) + ' %', align: 'right' },
              ]} />
          </Card>
          <Card title="Ventas por mes">
            <DataTable caption="Ventas agrupadas por mes y moneda"
              rows={summary.months}
              rowKey={(row) => row.month + ':' + row.currency}
              empty={<p>No hay ventas por mes en el rango seleccionado.</p>}
              columns={[
                { key: 'month', header: 'Mes', render: (row) => row.month },
                { key: 'units', header: 'Unidades', render: (row) => row.units, align: 'right' },
                { key: 'revenue', header: 'Ingreso', render: (row) => money(row.revenue_minor, row.currency), align: 'right' },
              ]} />
          </Card>
        </>
      )}
    </section>
  );
}
