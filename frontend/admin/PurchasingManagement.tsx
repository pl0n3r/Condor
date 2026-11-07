import { FormEvent, useEffect, useRef, useState } from 'react';
import { productionMaterialsPath } from './api';
import { Card, DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';

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
  historyStatus: 'verified' | 'unavailable';
};
type LoadState =
  | { status: 'loading' }
  | { status: 'ready'; data: Snapshot }
  | { status: 'denied' }
  | { status: 'error'; message: string };
type Props = Readonly<{ branchId: string; permissions: string[]; csrfToken: string }>;
type PreviewKind = 'quotation' | 'request';
type PreviewState =
  | { status: 'empty' | 'loading' | 'denied' }
  | { status: 'error'; message: string }
  | { status: 'ready'; lineCount: number; totalCop?: number };

// The fixtures are deliberately fixed: never derive a tenant, entity, site,
// supplier or customer reference from a live purchasing record.
const syntheticScope = {
  tenant_ref: 'fixture:tenant/current',
  legal_entity_ref: 'fixture:legal-entity/current',
  site_ref: 'fixture:site/current',
} as const;


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
  const [activePanel, setActivePanel] = useState<'supplier' | 'order' | null>(null);
  const [supplierSearch, setSupplierSearch] = useState('');
  const [orderSearch, setOrderSearch] = useState('');
  const [selectedSupplierId, setSelectedSupplierId] = useState<string | null>(null);
  const [selectedOrderId, setSelectedOrderId] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ kind: 'success' | 'error'; text: string } | null>(null);
  const [supplierCode, setSupplierCode] = useState('');
  const [supplierName, setSupplierName] = useState('');
  const [supplierTerms, setSupplierTerms] = useState('0');
  const [supplierId, setSupplierId] = useState('');
  const [lines, setLines] = useState<Line[]>([emptyLine()]);
  const receiveKeys = useRef(new Map<string, string>());
  const requestId = useRef(0);
  const previewRequests = useRef<Record<PreviewKind, number>>({ quotation: 0, request: 0 });
  const [previewStates, setPreviewStates] = useState<Record<PreviewKind, PreviewState>>({
    quotation: { status: 'empty' },
    request: { status: 'empty' },
  });

  // The branch is resolved from the authenticated admin context, not from form input.
  // The server revalidates tenant, legal entity, branch and permissions on each call.
  const basePath = '/api/v1/branches/' + encodeURIComponent(branchId) + '/purchasing';
  const suppliersPath = basePath + '/suppliers';
  const ordersPath = basePath + '/orders';
  const materialCostsPath = basePath + '/material-costs';

  async function load(options: { keepData?: boolean } = {}) {
    const current = ++requestId.current;
    if (!options.keepData) setState({ status: 'loading' });
    try {
      // The historical cost endpoint may be deployed after the purchasing UI.
      // Degrade only historical costs; never downgrade an authorization failure.
      const historicalRequest = readJson<{ material_costs: MaterialCost[] }>(materialCostsPath)
        .then((result) => ({ result, available: true as const }))
        .catch((error: unknown) => {
          if (error instanceof Error && error.message === 'PERMISSION_DENIED') {
            throw error;
          }
          return { result: { material_costs: [] as MaterialCost[] }, available: false as const };
        });
      const [suppliersResult, ordersResult, materialsResult, historical] = await Promise.all([
        readJson<{ suppliers: Supplier[] }>(suppliersPath),
        readJson<{ orders: PurchaseOrder[] }>(ordersPath),
        readJson<{ materials: Material[] }>(productionMaterialsPath(branchId)),
        historicalRequest,
      ]);
      if (current !== requestId.current) return;
      // Historical data is optional until its API is integrated. Validate its
      // shape independently, including null elements in a successful 200.
      // No invalid/partial cost is ever displayed as a verified price.
      const historyValid = historical.available
        && historical.result !== null
        && typeof historical.result === 'object'
        && Array.isArray(historical.result.material_costs)
        && !historical.result.material_costs.some((cost) => (
          cost === null
          || typeof cost !== 'object'
          || typeof cost.material_id !== 'string'
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
        ));
      const materialCosts = historyValid ? historical.result.material_costs : [];
      if (!Array.isArray(suppliersResult.suppliers)
        || !Array.isArray(ordersResult.orders)
        || !Array.isArray(materialsResult.materials)
        || ordersResult.orders.some((order) => !Array.isArray(order.lines))) {
        throw new Error('El servidor devolvió una respuesta de compras inválida.');
      }
      const data = {
        suppliers: suppliersResult.suppliers,
        orders: ordersResult.orders,
        materials: materialsResult.materials,
        materialCosts,
        historyStatus: historyValid ? 'verified' as const : 'unavailable' as const,
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

  useEffect(() => {
    // Invalidate in-flight preview replies when the authorized branch changes.
    previewRequests.current.quotation++;
    previewRequests.current.request++;
    setPreviewStates({ quotation: { status: 'empty' }, request: { status: 'empty' } });
  }, [branchId, canView, canCreate]);

  async function loadSyntheticPreview(kind: PreviewKind) {
    if (!canView || !canCreate || previewStates[kind].status === 'loading') return;
    const sequence = ++previewRequests.current[kind];
    setPreviewStates((current) => ({ ...current, [kind]: { status: 'loading' } }));
    const line = { ...syntheticScope, item_ref: 'fixture:item/paper', quantity: '2' };
    const payload = {
      ...syntheticScope,
      currency: 'COP',
      mode: 'preview_only',
      lines: kind === 'quotation'
        ? [{ ...line, unit_price_cop: '125.50' }]
        : [line],
    };
    const path = kind === 'quotation' ? '/quotations/preview' : '/requests/preview';

    try {
      // The server enforces the development/test flag, CSRF and authenticated
      // branch scope; this UI never creates an order or stores a request.
      const response = await fetch(basePath + path, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        body: JSON.stringify(payload),
      });
      if (response.status === 401 || response.status === 403) {
        throw new Error('PERMISSION_DENIED');
      }
      if (response.status === 404) {
        throw new Error('Previews sintéticos no disponibles en este entorno.');
      }
      if (!response.ok) throw new Error(await messageFrom(response));
      const envelope = await response.json() as Record<string, unknown>;
      const result = envelope?.[kind === 'quotation' ? 'quotation_preview' : 'request_preview'];
      if (envelope?.synthetic_only !== true || result === null
        || typeof result !== 'object' || Array.isArray(result)) {
        throw new Error('Respuesta sintética no verificable.');
      }
      const value = result as Record<string, unknown>;
      if (value.mode !== 'preview_only' || value.currency !== 'COP'
        || typeof value.line_count !== 'number' || !Number.isSafeInteger(value.line_count)
        || value.line_count < 1 || value.can_order !== false
        || value.can_approve !== false || value.can_pay !== false
        || value.can_dispatch !== false || value.persisted !== false
        || (kind === 'quotation'
          ? value.status !== 'SYNTHETIC_PREVIEW'
            || typeof value.total_cop !== 'number'
            || !Number.isSafeInteger(value.total_cop) || value.total_cop < 0
          : value.status !== 'pending' || value.notified !== false)) {
        throw new Error('Respuesta sintética no verificable.');
      }
      if (sequence !== previewRequests.current[kind]) return;
      setPreviewStates((current) => ({
        ...current,
        [kind]: {
          status: 'ready',
          lineCount: value.line_count as number,
          ...(kind === 'quotation' ? { totalCop: value.total_cop as number } : {}),
        },
      }));
    } catch (error) {
      if (sequence !== previewRequests.current[kind]) return;
      setPreviewStates((current) => ({
        ...current,
        [kind]: error instanceof Error && error.message === 'PERMISSION_DENIED'
          ? { status: 'denied' }
          : { status: 'error', message: error instanceof Error ? error.message : 'Vista previa no disponible.' },
      }));
    }
  }

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
      await load({ keepData: true });
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
      setActivePanel(null);
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
      setActivePanel(null);
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
  const suppliersShown = data?.suppliers.filter((supplier) =>
    (supplier.code + ' ' + supplier.name).toLocaleLowerCase('es-CO').includes(supplierSearch.toLocaleLowerCase('es-CO'))) ?? [];
  const ordersShown = data?.orders.filter((order) => order.id.toLocaleLowerCase('es-CO').includes(orderSearch.toLocaleLowerCase('es-CO'))) ?? [];
  const selectedSupplier = data?.suppliers.find((supplier) => supplier.id === selectedSupplierId);
  const selectedOrder = data?.orders.find((order) => order.id === selectedOrderId);

  return (
    <section id="purchasing" className="catalog-panel ui-page" aria-label="Compras">
      <PageHeader title="Compras" description="Proveedores, órdenes y recepción de materiales de tu empresa."
        actions={canCreate && <>
          <button className="button button-secondary" type="button" onClick={() => setActivePanel('supplier')}>Nuevo proveedor</button>
          <button className="button" type="button" onClick={() => setActivePanel('order')}>Nueva orden</button>
        </>} />
      {data && <MetricStrip label="Resumen de compras" items={[
        { label: 'Proveedores', value: data.suppliers.length },
        { label: 'Órdenes', value: data.orders.length },
        { label: 'Pendientes de recepción', value: data.orders.filter(order => order.status !== 'received').length, tone: 'warn' },
      ]} />}
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
          <Card title="Proveedores">
            <Toolbar trailing={<span className="ui-count">{suppliersShown.length} de {data.suppliers.length}</span>}>
              <label className="ui-search"><span className="ui-sr-only">Buscar proveedores</span>
                <input type="search" placeholder="Buscar proveedor" value={supplierSearch} onChange={event => setSupplierSearch(event.target.value)} />
              </label>
            </Toolbar>
            <DataTable caption="Proveedores" rows={suppliersShown} rowKey={row => row.id}
              onOpen={row => setSelectedSupplierId(row.id)}
              empty={<><strong>Sin proveedores</strong><span>{supplierSearch ? 'Sin coincidencias.' : 'Aún no hay proveedores registrados.'}</span></>}
              columns={[
                { key: 'code', header: 'Código', render: row => row.code },
                { key: 'name', header: 'Proveedor', render: row => row.name },
                { key: 'state', header: 'Estado', render: row => <StatusPill tone={row.active ? 'ok' : 'neutral'}>{row.active ? 'Activo' : 'Inactivo'}</StatusPill> },
              ]} />
            {canCreate && <SidePanel open={activePanel === 'supplier'} title="Nuevo proveedor" onClose={() => setActivePanel(null)}>
              <form className="catalog-editor" onSubmit={(event) => void createSupplier(event)}>
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
            </SidePanel>}
          </Card>
          <Card title="Órdenes de compra">
            <Toolbar trailing={<span className="ui-count">{ordersShown.length} de {data.orders.length}</span>}>
              <label className="ui-search"><span className="ui-sr-only">Buscar órdenes</span>
                <input type="search" placeholder="Buscar número de orden" value={orderSearch} onChange={event => setOrderSearch(event.target.value)} />
              </label>
            </Toolbar>
            <DataTable caption="Órdenes de compra" rows={ordersShown} rowKey={row => row.id}
              onOpen={row => setSelectedOrderId(row.id)}
              empty={<><strong>Sin órdenes de compra</strong><span>{orderSearch ? 'Sin coincidencias.' : 'Aún no hay órdenes de compra.'}</span></>}
              columns={[
                { key: 'number', header: 'Orden', render: row => row.id },
                { key: 'supplier', header: 'Proveedor', render: row => data.suppliers.find(supplier => supplier.id === row.supplier_id)?.name ?? row.supplier_id },
                { key: 'lines', header: 'Materiales', render: row => row.lines.length, align: 'right' },
                { key: 'status', header: 'Estado', render: row => <StatusPill tone={row.status === 'received' ? 'ok' : 'warn'}>{row.status}</StatusPill> },
              ]} />
            {canCreate && <SidePanel open={activePanel === 'order'} title="Nueva orden" onClose={() => setActivePanel(null)}>
              <form className="catalog-editor" onSubmit={(event) => void createOrder(event)}>
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
            </SidePanel>}
            <section aria-labelledby="weighted-cost-title">
              <h3 id="weighted-cost-title">Costo promedio ponderado de materiales</h3>
              {data.historyStatus === 'unavailable' && (
                <p role="alert" className="alert alert-error">
                  No se pudo verificar el costo histórico. Los proveedores y órdenes siguen disponibles,
                  pero no se mostrará ningún promedio hasta recuperar la consulta del servidor.
                </p>
              )}
              {data.materials.length === 0 ? (
                <p role="status">No hay materiales para consultar costos históricos.</p>
              ) : (
                <ul>
                  {data.materials.map((material) => {
                    const historical = data.materialCosts.find((cost) => cost.material_id === material.id);
                    return (
                      <li key={material.id}>
                        {material.code} · {material.name}: {' '}
                        {data.historyStatus === 'verified' && historical?.state === 'KNOWN' && historical.average_unit_cost !== null
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
          </Card>
        </div>
      )}
      <SidePanel open={Boolean(selectedSupplier)} title={selectedSupplier?.name ?? 'Proveedor'} onClose={() => setSelectedSupplierId(null)}>
        {selectedSupplier && <dl className="ui-details">
          <div><dt>Código</dt><dd>{selectedSupplier.code}</dd></div>
          <div><dt>Nombre</dt><dd>{selectedSupplier.name}</dd></div>
          <div><dt>Estado</dt><dd><StatusPill tone={selectedSupplier.active ? 'ok' : 'neutral'}>{selectedSupplier.active ? 'Activo' : 'Inactivo'}</StatusPill></dd></div>
        </dl>}
      </SidePanel>
      <SidePanel open={Boolean(selectedOrder)} title={selectedOrder ? 'Orden ' + selectedOrder.id : 'Detalle de orden'} onClose={() => setSelectedOrderId(null)}>
        {selectedOrder && <>
          <p>Estado: <StatusPill tone={selectedOrder.status === 'received' ? 'ok' : 'warn'}>{selectedOrder.status}</StatusPill></p>
          <ul>{selectedOrder.lines.map(line => <li key={line.id}>
            {activeMaterials.find(material => material.id === line.material_id)?.name ?? line.material_id}
            {' · '}{line.quantity} · costo unitario {line.unit_cost}
          </li>)}</ul>
          {selectedOrder.status !== 'received' && canReceive && <button className="button" type="button" disabled={busy} onClick={() => void receiveOrder(selectedOrder)}>Registrar recepción</button>}
        </>}
      </SidePanel>
      <section className="catalog-card" aria-labelledby="synthetic-purchasing-title">
        <h3 id="synthetic-purchasing-title">Previews ficticios de compras</h3>
        <p className="muted">
          Solo demostración sintética habilitada en pruebas/desarrollo. No crea órdenes,
          no envía solicitudes, no aprueba, no paga ni persiste datos.
        </p>
        {(['quotation', 'request'] as const).map((kind) => {
          const preview = previewStates[kind];
          const title = kind === 'quotation' ? 'Cotización ficticia' : 'Solicitud ficticia';
          return (
            <section key={kind} aria-label={title}>
              <h4>{title}</h4>
              {!canCreate ? (
                <p role="alert">No tienes permiso para generar este preview.</p>
              ) : (
                <button
                  type="button"
                  className="button button-secondary"
                  disabled={preview.status === 'loading'}
                  onClick={() => void loadSyntheticPreview(kind)}
                >
                  {preview.status === 'loading' ? 'Consultando ejemplo…' : 'Consultar ejemplo sintético'}
                </button>
              )}
              {canCreate && preview.status === 'empty' && (
                <p role="status">Sin preview: consulta el ejemplo para ver la respuesta HTTP.</p>
              )}
              {preview.status === 'loading' && <output aria-live="polite">Cargando preview sintético…</output>}
              {preview.status === 'denied' && <p role="alert">Permiso denegado para este preview en la sede.</p>}
              {preview.status === 'error' && <p role="alert">{preview.message}</p>}
              {preview.status === 'ready' && (
                <output aria-live="polite">
                  {kind === 'quotation'
                    ? 'Cotización de ejemplo: ' + preview.lineCount + ' línea(s), ' + preview.totalCop + ' COP.'
                    : 'Solicitud de ejemplo pendiente: ' + preview.lineCount + ' línea(s).'}
                  {' '}Sin autorización para ordenar, aprobar, pagar, notificar o persistir.
                </output>
              )}
            </section>
          );
        })}
      </section>
    </section>
  );
}
