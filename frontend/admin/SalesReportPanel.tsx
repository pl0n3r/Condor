import { FormEvent, useEffect, useRef, useState } from 'react';

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

  return (
    <section id="sales-reports" aria-labelledby="sales-reports-title">
      <h2 id="sales-reports-title">Reportes de ventas</h2>
      <p className="muted">Ventas por producto y por mes, limitadas a tus sedes autorizadas.</p>
      <form onSubmit={(event) => { void submit(event); }}>
        <label htmlFor="sales-from">Desde</label>
        <input id="sales-from" type="date" required value={from}
          onChange={(event) => setFrom(event.target.value)} />
        <label htmlFor="sales-to">Hasta</label>
        <input id="sales-to" type="date" required value={to}
          onChange={(event) => setTo(event.target.value)} />
        <button type="submit" disabled={state.status === 'loading'}>
          Consultar ventas
        </button>
      </form>

      <div aria-live="polite">
        {state.status === 'idle' && <p>Selecciona un rango para consultar las ventas.</p>}
        {state.status === 'loading' && <p role="status">Cargando reportes…</p>}
        {state.status === 'error' && <p role="alert">{state.message}</p>}
      </div>

      {state.status === 'ready' && (
        <>
          <h3>Ventas por producto</h3>
          {state.data.products.length === 0 ? (
            <p>No hay ventas por producto en el rango seleccionado.</p>
          ) : (
            <div className="table-responsive">
              <table>
                <caption>Ventas agrupadas por producto y variante</caption>
                <thead><tr>
                  <th scope="col">Producto</th>
                  <th scope="col">Variante / SKU</th>
                  <th scope="col">Unidades</th>
                  <th scope="col">Ingreso</th>
                  <th scope="col">Participación</th>
                </tr></thead>
                <tbody>{state.data.products.map((row) => (
                  <tr key={row.currency + ':' + row.variant_id}>
                    <th scope="row">{row.product_name}</th>
                    <td>{row.variant_name} · {row.sku}</td>
                    <td>{row.units}</td>
                    <td>{money(row.revenue_minor, row.currency)}</td>
                    <td>{(row.participation_bps / 100).toFixed(2)} %</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          )}
          <h3>Ventas por mes</h3>
          {state.data.months.length === 0 ? (
            <p>No hay ventas por mes en el rango seleccionado.</p>
          ) : (
            <div className="table-responsive">
              <table>
                <caption>Ventas agrupadas por mes y moneda</caption>
                <thead><tr>
                  <th scope="col">Mes</th>
                  <th scope="col">Unidades</th>
                  <th scope="col">Ingreso</th>
                </tr></thead>
                <tbody>{state.data.months.map((row) => (
                  <tr key={row.month + ':' + row.currency}>
                    <th scope="row">{row.month}</th>
                    <td>{row.units}</td>
                    <td>{money(row.revenue_minor, row.currency)}</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          )}
        </>
      )}
    </section>
  );
}
