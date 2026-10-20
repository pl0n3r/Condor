import { FormEvent, useEffect, useRef, useState } from 'react';
import { productionMaterialsPath } from './api';

type Supplier = {
  id: string;
  code: string;
  name: string;
  payment_terms_days: number;
  active: boolean;
};
type Material = { id: string; code: string; name: string; unit: string; active: boolean };
type Line = { material_id: string; quantity: string; unit_cost: string };
type MaterialCost = {
  material_id: string;
  state: 'KNOWN' | 'UNKNOWN';
  average_unit_cost: string | null;
  total_quantity: string;
  receipt_count: number;
};
type PurchaseOrder = {
  id: string;
  status: string;
  supplier_id: string;
  lines: Array<Line & { id: string }>;
};
type Snapshot = {
  suppliers: Supplier[];
  orders: PurchaseOrder[];
  materials: Material[];
  materialCosts: MaterialCost[];
};
type LoadState =
  | { status: 'loading' }
  | { status: 'ready'; data: Snapshot }
  | { status: 'denied' }
  | { status: 'error'; message: string };
type Props = Readonly<{ branchId: string; permissions: string[]; csrfToken: string }>;

const emptyLine = (): Line => ({ material_id: '', quantity: '1', unit_cost: '0' });
const quantityPattern = /^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/;
const costPattern = /^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,4})?$/;

async function messageFrom(response: Response): Promise<string> {
  const fallback = 'No fue posible completar la solicitud de compras.';
  try {
    const body = await response.json() as { message?: unknown; error?: unknown };
    const value = body.message ?? body.error;
    return typeof value === 'string' && value.length <= 250 ? value : fallback;
  } catch {
    return fallback;
  }
}

async function readJson<T>(url: string): Promise<T> {
  const response = await fetch(url, {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });
  if (response.status === 401 || response.status === 403) {
    throw new Error('PERMISSION_DENIED');
  }
  if (!response.ok) {
    throw new Error(await messageFrom(response));
  }
  return await response.json() as T;
}

export function PurchasingManagement({ branchId, permissions, csrfToken }: Props) {
  const canView = permissions.includes('inventory.view');
  const canCreate = permissions.includes('inventory.create');
  const canReceive = permissions.includes('inventory.update');
  const [state, setState] = useState<LoadState>({ status: 'loading' });
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ kind: 'success' | 'error'; text: string } | null>(null);
  const [supplierCode, setSupplierCode] = useState('');
  const [supplierName, setSupplierName] = useState('');
  const [supplierTerms, setSupplierTerms] = useState('0');
  const [supplierId, setSupplierId] = useState('');
  const [lines, setLines] = useState<Line[]>([emptyLine()]);
  const receiveKeys = useRef(new Map<string, string>());
  const requestId = useRef(0);

  // The branch is resolved from the authenticated admin context, not from form input.
  // The server revalidates tenant, legal entity, branch and permissions on each call.
  const basePath = '/api/v1/branches/' + encodeURIComponent(branchId) + '/purchasing';
  const suppliersPath = basePath + '/suppliers';
  const ordersPath = basePath + '/orders';
  const materialCostsPath = basePath + '/material-costs';

  async function load() {
    const current = ++requestId.current;
    setState({ status: 'loading' });
    try {
      const [suppliersResult, ordersResult, materialsResult, materialCostsResult] = await Promise.all([
        readJson<{ suppliers: Supplier[] }>(suppliersPath),
        readJson<{ orders: PurchaseOrder[] }>(ordersPath),
        readJson<{ materials: Material[] }>(productionMaterialsPath(branchId)),
        readJson<{ material_costs: MaterialCost[] }>(materialCostsPath),
      ]);
      if (current !== requestId.current) return;
      if (!Array.isArray(suppliersResult.suppliers)
        || !Array.isArray(ordersResult.orders)
        || !Array.isArray(materialsResult.materials)
        || !Array.isArray(materialCostsResult.material_costs)
        || ordersResult.orders.some((order) => !Array.isArray(order.lines))
        || materialCostsResult.material_costs.some((cost) => (
          typeof cost.material_id !== 'string'
          || (cost.state !== 'KNOWN' && cost.state !== 'UNKNOWN')
          || (cost.state === 'UNKNOWN' && cost.average_unit_cost !== null)
          || (cost.state === 'KNOWN' && (
            typeof cost.average_unit_cost !== 'string'
            || !/^(0|[1-9][0-9]*)\.[0-9]{4}$/.test(cost.average_unit_cost)
          ))
          || typeof cost.total_quantity !== 'string'
          || !/^(0|[1-9][0-9]*)\.[0-9]{6}$/.test(cost.total_quantity)
          || !Number.isSafeInteger(cost.receipt_count)
          || cost.receipt_count < 0
        ))) {
        throw new Error('El servidor devolvió una respuesta de compras inválida.');
      }
      const data = {
        suppliers: suppliersResult.suppliers,
        orders: ordersResult.orders,
        materials: materialsResult.materials,
        materialCosts: materialCostsResult.material_costs,
      };
      setState({ status: 'ready', data });
      setSupplierId((old) => (
        data.suppliers.some((s) => s.id === old && s.active)
          ? old : data.suppliers.find((s) => s.active)?.id ?? ''
      ));
      setLines((currentLines) => currentLines.map((line) => ({
        ...line,
        material_id: data.materials.some((m) => m.id === line.material_id && m.active)
          ? line.material_id : data.materials.find((m) => m.active)?.id ?? '',
      })));
    } catch (error) {
      if (current !== requestId.current) return;
      if (error instanceof Error && error.message === 'PERMISSION_DENIED') {
        setState({ status: 'denied' });
      } else {
        setState({
          status: 'error',
          message: error instanceof Error ? error.message : 'No fue posible cargar compras.',
        });
      }
    }
  }

  useEffect(() => {
    receiveKeys.current.clear();
    setNotice(null);
    if (canView) {
      void load();
    }
    return () => { requestId.current++; };
  }, [branchId, canView]);

  async function mutate(url: string, payload: Record<string, unknown>, success: string): Promise<boolean> {
    if (busy) return false;
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
        throw new Error(await messageFrom(response));
      }
      setNotice({ kind: 'success', text: success });
      await load();
      return true;
    } catch (error) {
      setNotice({
        kind: 'error',
        text: error instanceof Error ? error.message : 'La operación no se completó.',
      });
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function createSupplier(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || busy) return;
    const terms = Number(supplierTerms);
    if (!Number.isInteger(terms) || terms < 0 || terms > 365) {
      setNotice({ kind: 'error', text: 'El plazo de pago debe estar entre 0 y 365 días.' });
      return;
    }
    if (await mutate(suppliersPath, {
      code: supplierCode.trim(),
      name: supplierName.trim(),
      payment_terms_days: terms,
    }, 'Proveedor creado.')) {
      setSupplierCode('');
      setSupplierName('');
      setSupplierTerms('0');
    }
  }

  function updateLine(index: number, patch: Partial<Line>) {
    setLines((current) => current.map((line, position) => (
      position === index ? { ...line, ...patch } : line
    )));
  }

  async function createOrder(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || busy || supplierId === '' || lines.length === 0) return;
    if (lines.some((line) => (
      line.material_id === '' ||
      !quantityPattern.test(line.quantity) ||
      !costPattern.test(line.unit_cost) ||
      /^0(?:\.0+)?$/.test(line.quantity)
    ))) {
      setNotice({ kind: 'error', text: 'Revisa los materiales, cantidades y costos.' });
      return;
    }
    if (new Set(lines.map((line) => line.material_id)).size !== lines.length) {
      setNotice({ kind: 'error', text: 'No repitas un material en la misma orden.' });
      return;
    }
    if (await mutate(ordersPath, { supplier_id: supplierId, lines }, 'Orden creada.')) {
      setLines([emptyLine()]);
    }
  }

  async function receiveOrder(order: PurchaseOrder) {
    if (!canReceive || busy || order.status === 'received') return;
    const previous = receiveKeys.current.get(order.id);
    const key = previous ?? crypto.randomUUID();
    receiveKeys.current.set(order.id, key);
    if (await mutate(
      ordersPath + '/' + encodeURIComponent(order.id) + '/receive',
      { idempotency_key: key },
      'Recepción registrada sin duplicar stock.',
    )) {
      receiveKeys.current.delete(order.id);
    }
  }

  if (!canView) {
    return (
      <section id="purchasing" aria-label="Compras">
        <p role="status">No tienes permiso para consultar compras.</p>
      </section>
    );
  }

  const data = state.status === 'ready' ? state.data : null;
  const activeSuppliers = data?.suppliers.filter((supplier) => supplier.active) ?? [];
  const activeMaterials = data?.materials.filter((material) => material.active) ?? [];

  return (
    <section id="purchasing" className="catalog-panel" aria-labelledby="purchasing-title">
      <div className="section-heading">
        <div>
          <span className="eyebrow">Operación</span>
          <h2 id="purchasing-title">Compras</h2>
          <p className="muted">Proveedores, órdenes y recepción de materiales de tu empresa.</p>
        </div>
      </div>
      {notice?.kind === 'error' && <div className="alert alert-error" role="alert">{notice.text}</div>}
      {notice?.kind === 'success' && <output aria-live="polite" className="alert alert-success">{notice.text}</output>}
      {state.status === 'loading' && <output aria-live="polite">Cargando compras…</output>}
      {state.status === 'denied' && <p role="alert">No tienes permiso para consultar compras en esta sede.</p>}
      {state.status === 'error' && (
        <div role="alert" className="catalog-state catalog-error">
          <p>{state.message}</p>
          <button type="button" className="button button-secondary" onClick={() => void load()}>Reintentar</button>
        </div>
      )}
      {data && (
        <div className="production-layout">
          <section className="catalog-card" aria-labelledby="supplier-title">
            <h3 id="supplier-title">Proveedores</h3>
            {data.suppliers.length === 0 ? (
              <p role="status">Aún no hay proveedores registrados.</p>
            ) : (
              <ul>
                {data.suppliers.map((supplier) => (
                  <li key={supplier.id}>{supplier.code} · {supplier.name}{supplier.active ? '' : ' · Inactivo'}</li>
                ))}
              </ul>
            )}
            {canCreate && (
              <form className="catalog-editor" onSubmit={(event) => void createSupplier(event)}>
                <h3>Nuevo proveedor</h3>
                <label className="field"><span>Código</span>
                  <input required maxLength={64} value={supplierCode} onChange={(event) => setSupplierCode(event.target.value)} />
                </label>
                <label className="field"><span>Nombre</span>
                  <input required maxLength={160} value={supplierName} onChange={(event) => setSupplierName(event.target.value)} />
                </label>
                <label className="field"><span>Plazo de pago (días)</span>
                  <input type="number" min={0} max={365} value={supplierTerms} onChange={(event) => setSupplierTerms(event.target.value)} />
                </label>
                <button className="button" disabled={busy} type="submit">Guardar proveedor</button>
              </form>
            )}
          </section>
          <section className="catalog-card" aria-labelledby="purchase-order-title">
            <h3 id="purchase-order-title">Órdenes de compra</h3>
            {data.orders.length === 0 ? (
              <p role="status">Aún no hay órdenes de compra.</p>
            ) : (
              <ul>
                {data.orders.map((order) => (
                  <li key={order.id}>
                    <strong>Orden {order.id}</strong> · {order.status}
                    <ul>
                      {order.lines.map((line) => (
                        <li key={line.id}>
                          {activeMaterials.find((m) => m.id === line.material_id)?.name ?? line.material_id}
                          {' · '}{line.quantity} · costo unitario {line.unit_cost}
                        </li>
                      ))}
                    </ul>
                    {order.status !== 'received' && canReceive && (
                      <button className="button button-secondary" disabled={busy} type="button" onClick={() => void receiveOrder(order)}>
                        Registrar recepción
                      </button>
                    )}
                  </li>
                ))}
              </ul>
            )}
            {canCreate && (
              <form className="catalog-editor" onSubmit={(event) => void createOrder(event)}>
                <h3>Nueva orden</h3>
                <label className="field"><span>Proveedor</span>
                  <select required value={supplierId} onChange={(event) => setSupplierId(event.target.value)}>
                    <option value="">Selecciona un proveedor</option>
                    {activeSuppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}
                  </select>
                </label>
                {lines.map((line, index) => (
                  <fieldset key={index} className="catalog-editor">
                    <legend>Material {index + 1}</legend>
                    <label className="field"><span>Material</span>
                      <select required value={line.material_id} onChange={(event) => updateLine(index, { material_id: event.target.value })}>
                        <option value="">Selecciona un material</option>
                        {activeMaterials.map((material) => (
                          <option key={material.id} value={material.id}>{material.code} · {material.name} ({material.unit})</option>
                        ))}
                      </select>
                    </label>
                    <label className="field"><span>Cantidad</span>
                      <input required inputMode="decimal" value={line.quantity} onChange={(event) => updateLine(index, { quantity: event.target.value })} />
                    </label>
                    <label className="field"><span>Costo unitario</span>
                      <input required inputMode="decimal" value={line.unit_cost} onChange={(event) => updateLine(index, { unit_cost: event.target.value })} />
                    </label>
                    {lines.length > 1 && (
                      <button type="button" className="button button-secondary" onClick={() => setLines((current) => current.filter((_, i) => i !== index))}>Quitar línea</button>
                    )}
                  </fieldset>
                ))}
                <button type="button" className="button button-secondary" disabled={lines.length >= 100 || busy} onClick={() => setLines((current) => [...current, emptyLine()])}>Agregar material</button>
                <button className="button" disabled={busy || !activeSuppliers.length || !activeMaterials.length} type="submit">Crear orden</button>
              </form>
            )}
            <section aria-labelledby="weighted-cost-title">
              <h3 id="weighted-cost-title">Costo promedio ponderado de materiales</h3>
              {data.materials.length === 0 ? (
                <p role="status">No hay materiales para consultar costos históricos.</p>
              ) : (
                <ul>
                  {data.materials.map((material) => {
                    const historical = data.materialCosts.find((cost) => cost.material_id === material.id);
                    return (
                      <li key={material.id}>
                        {material.code} · {material.name}: {' '}
                        {historical?.state === 'KNOWN' && historical.average_unit_cost !== null
                          ? `${historical.average_unit_cost} COP por ${material.unit}`
                          : 'Sin costo promedio histórico verificado'}
                      </li>
                    );
                  })}
                </ul>
              )}
              <p className="muted">
                El promedio procede de recepciones confirmadas del servidor, no del costo unitario de una orden.
                Los materiales sin historial se muestran como desconocidos, nunca como costo cero.
              </p>
            </section>
          </section>
        </div>
      )}
    </section>
  );
}
