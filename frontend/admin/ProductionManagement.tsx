import {
  FormEvent,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';

import { Card, DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';import {
  inventoryPath,
  productionBomsPath,
  productionMaterialAdjustmentPath,
  productionMaterialPath,
  productionMaterialsPath,
  productionOrderCompletePath,
  productionOrdersPath,
} from './api';

type Material = {
  id: string;
  code: string;
  name: string;
  unit: string;
  active: boolean;
};

type MaterialBalance = {
  material_id: string;
  quantity: string;
};

type MaterialSnapshot = {
  branch: { id: string; name: string };
  source: { id: string; name: string; slug: string };
  units: string[];
  materials: Material[];
  balances: MaterialBalance[];
  movements: Array<{
    id: string;
    material_id: string;
    type: string;
    delta: string;
    balance_after: string;
    created_at: string;
  }>;
};

type Variant = {
  id: string;
  sku: string;
  name: string;
  product_name: string;
};

type BomLine = {
  material_id: string;
  material_code: string;
  material_name: string;
  quantity: string;
  unit: string;
};

type Bom = {
  id: string;
  variant_id: string;
  variant_sku: string;
  variant_name: string;
  version: number;
  active: boolean;
  lines: BomLine[];
  created_at: string;
};

type BomSnapshot = {
  boms: Bom[];
};

type ProductionOrder = {
  id: string;
  bom_id: string;
  bom_version: number;
  variant_id: string;
  variant_sku: string;
  source_id: string;
  target_quantity: number;
  completed_quantity: number | null;
  status: string;
  completion_idempotency_key: string | null;
  created_at: string;
  completed_at: string | null;
};

type OrderSnapshot = { orders: ProductionOrder[] };

type OrderCost = {
  total_cost: string;
  unit_cost: string;
  consumptions: Array<{
    material_id: string;
    quantity: string;
    unit_cost: string;
    line_cost: string;
  }>;
};
type OrderCostState =
  | { status: 'loading' }
  | { status: 'unknown' }
  | { status: 'error' }
  | { status: 'ready'; data: OrderCost };

type InventorySnapshot = {
  variants: Variant[];
};

type ProductionData = {
  materials: MaterialSnapshot;
  boms: BomSnapshot;
  orders: OrderSnapshot;
  variants: Variant[];
};

type Props = Readonly<{
  branchId: string;
  permissions: string[];
  csrfToken: string;
}>;

type State =
  | { status: 'loading' }
  | { status: 'ready'; data: ProductionData }
  | { status: 'disabled'; message: string }
  | { status: 'error'; message: string };

type Notice = { kind: 'success' | 'error'; text: string };

type IdempotencyState = {
  signature: string;
  key: string;
};

type BomComponentDraft = {
  materialId: string;
  quantity: string;
  unit: string;
};

class ProductionUnavailable extends Error {}

const moneyPattern = /^(?:0|[1-9]\d*)\.\d{4}$/;
const quantityPattern = /^(?:0|[1-9]\d*)\.\d{6}$/;

// Money is displayed only from the validated, server-persisted completion snapshot.
// Never derive historical prices from mutable BOM or current purchasing data.
function verifiedOrderCost(payload: unknown, orderId: string): OrderCostState {
  if (payload === null || typeof payload !== 'object' || Array.isArray(payload)) {
    return { status: 'error' };
  }
  const response = payload as Record<string, unknown>;
  if (response.order_id !== orderId) return { status: 'error' };
  if (response.state === 'UNKNOWN'
    && response.cost === null && response.snapshot_persisted === false) {
    return { status: 'unknown' };
  }
  if (response.state !== 'ESTIMATED_COST'
    || response.snapshot_persisted !== true
    || response.provenance !== 'production_completion_snapshot'
    || response.cost === null || typeof response.cost !== 'object'
    || Array.isArray(response.cost)) return { status: 'error' };

  const cost = response.cost as Record<string, unknown>;
  if (cost.state !== 'ESTIMATED_COST' || cost.order_id !== orderId
    || typeof cost.total_cost !== 'string' || !moneyPattern.test(cost.total_cost)
    || typeof cost.unit_cost !== 'string' || !moneyPattern.test(cost.unit_cost)
    || !Array.isArray(cost.consumptions)
    || cost.consumptions.some((line: unknown) => {
      if (line === null || typeof line !== 'object' || Array.isArray(line)) return true;
      const row = line as Record<string, unknown>;
      return typeof row.material_id !== 'string' || row.material_id === ''
        || typeof row.quantity !== 'string' || !quantityPattern.test(row.quantity)
        || typeof row.unit_cost !== 'string' || !moneyPattern.test(row.unit_cost)
        || typeof row.line_cost !== 'string' || !moneyPattern.test(row.line_cost);
    })) return { status: 'error' };

  return { status: 'ready', data: cost as OrderCost };
}

function copAmount(value: string): string {
  const [integer, fraction] = value.split('.');
  // Linear grouping avoids backtracking on large values from the cost snapshot.
  const groups: string[] = [];
  for (let end = integer.length; end > 0; end -= 3) {
    groups.push(integer.slice(Math.max(0, end - 3), end));
  }
  return groups.reverse().join('.') + ',' + fraction + ' COP';
}

function OrderCostDetails({
  cost, materials,
}: Readonly<{ cost: OrderCostState; materials: ReadonlyMap<string, Material> }>) {
  if (cost.status === 'loading') {
    return <output className="muted">Consultando costo de la orden…</output>;
  }
  if (cost.status === 'unknown') {
    return <output className="muted">Costo no disponible: sin comprobante verificable.</output>;
  }
  if (cost.status === 'error') {
    return <p role="alert">No fue posible verificar el costo de la orden.</p>;
  }
  return (
    <div className="production-cost-breakdown">
      <p><strong>Costo total: {copAmount(cost.data.total_cost)}</strong></p>
      <p>Costo por unidad: {copAmount(cost.data.unit_cost)}</p>
      <strong>Desglose por material</strong>
      {cost.data.consumptions.length === 0 ? (
        <output className="muted">No se registraron consumos de material.</output>
      ) : (
        <ul>
          {cost.data.consumptions.map((line) => (
            <li key={line.material_id}>
              {materials.get(line.material_id)?.name ?? 'Material sin referencia'}:
              {' '}{line.quantity} × {copAmount(line.unit_cost)}
              {' '}= {copAmount(line.line_cost)}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function ProductionManagement({
  branchId,
  permissions,
  csrfToken,
}: Props) {
  const can = (permission: string) => permissions.includes(permission);
  const canView = can('inventory.view');
  const canCreate = can('inventory.create');
  const canUpdate = can('inventory.update');
  const canDelete = can('inventory.delete');

  const [state, setState] = useState<State>({ status: 'loading' });
  const [orderCosts, setOrderCosts] = useState<Record<string, OrderCostState>>({});
  const costRequestId = useRef(0);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [activePanel, setActivePanel] = useState<'material' | 'editMaterial' | 'adjustMaterial' | 'bom' | 'order' | null>(null);
  const [search, setSearch] = useState('');
  const [selectedMaterialId, setSelectedMaterialId] = useState<string | null>(null);
  const [selectedBomId, setSelectedBomId] = useState<string | null>(null);
  const [selectedOrderId, setSelectedOrderId] = useState<string | null>(null);

  const [materialCode, setMaterialCode] = useState('');
  const [materialName, setMaterialName] = useState('');
  const [materialUnit, setMaterialUnit] = useState('unit');

  const [editMaterialId, setEditMaterialId] = useState('');
  const [editCode, setEditCode] = useState('');
  const [editName, setEditName] = useState('');
  const [editUnit, setEditUnit] = useState('unit');

  const [adjustMaterialId, setAdjustMaterialId] = useState('');
  const [adjustDelta, setAdjustDelta] = useState('1');
  const [adjustReason, setAdjustReason] = useState('');
  const adjustmentKey = useRef<IdempotencyState>({
    signature: '',
    key: '',
  });

  const [bomVariantId, setBomVariantId] = useState('');
  const [bomComponents, setBomComponents] = useState<BomComponentDraft[]>([
    { materialId: '', quantity: '1', unit: 'unit' },
  ]);

  const [orderBomId, setOrderBomId] = useState('');
  const [orderQuantity, setOrderQuantity] = useState('1');
  const [completionQuantities, setCompletionQuantities] = useState<
    Record<string, string>
  >({});
  const completionKeys = useRef<
    Map<string, IdempotencyState>
  >(new Map());

  async function loadProduction() {
    const request = ++costRequestId.current;
    setOrderCosts({});
    if (!canView) {
      return;
    }

    setState({ status: 'loading' });
    try {
      const [
        materials,
        boms,
        orders,
        inventory,
      ] = await Promise.all([
        getJson<MaterialSnapshot>(productionMaterialsPath(branchId)),
        getJson<BomSnapshot>(productionBomsPath(branchId)),
        getJson<OrderSnapshot>(productionOrdersPath(branchId)),
        getJson<InventorySnapshot>(inventoryPath(branchId)),
      ]);

      if (request !== costRequestId.current) return;
      const data = {
        materials,
        boms,
        orders,
        variants: inventory.variants,
      };
      setState({ status: 'ready', data });
      hydrateSelections(data);

      const completed = orders.orders.filter((order) => order.status === 'completed');
      setOrderCosts(Object.fromEntries(completed.map((order) => [
        order.id, { status: 'loading' as const },
      ])));
      for (const order of completed) {
        // Cost failures must not hide the underlying production workflow.
        void getJson<unknown>(
          productionOrdersPath(branchId) + '/' + encodeURIComponent(order.id) + '/cost',
        ).then((payload) => verifiedOrderCost(payload, order.id))
          .catch((): OrderCostState => ({ status: 'error' }))
          .then((next) => {
            if (request !== costRequestId.current) return;
            setOrderCosts((current) => ({ ...current, [order.id]: next }));
          });
      }
    } catch (error) {
      if (request !== costRequestId.current) return;
      if (error instanceof ProductionUnavailable) {
        setState({ status: 'disabled', message: error.message });
        return;
      }

      setState({
        status: 'error',
        message: error instanceof Error
          ? error.message
          : 'No fue posible cargar Producción Lite.',
      });
    }
  }

  function hydrateSelections(data: ProductionData) {
    const activeMaterials = data.materials.materials.filter(
      (material) => material.active,
    );
    const firstMaterial = activeMaterials[0];
    const firstVariant = data.variants[0];
    const firstBom = data.boms.boms.find((bom) => bom.active);

    setMaterialUnit((current) => (
      data.materials.units.includes(current)
        ? current
        : data.materials.units[0] ?? 'unit'
    ));
    setAdjustMaterialId((current) => current || firstMaterial?.id || '');
    setBomVariantId((current) => current || firstVariant?.id || '');
    setOrderBomId((current) => current || firstBom?.id || '');

    setBomComponents((current) => current.map((component) => {
      if (component.materialId !== '') {
        return component;
      }
      return {
        materialId: firstMaterial?.id ?? '',
        quantity: component.quantity,
        unit: firstMaterial?.unit ?? 'unit',
      };
    }));

    setCompletionQuantities((current) => {
      const next = { ...current };
      for (const order of data.orders.orders) {
        if (order.status === 'draft' && next[order.id] === undefined) {
          next[order.id] = String(order.target_quantity);
        }
      }
      return next;
    });
  }

  useEffect(() => {
    setNotice(null);
    setState({ status: 'loading' });
    adjustmentKey.current = { signature: '', key: '' };
    completionKeys.current.clear();

    if (canView) {
      void loadProduction();
    }
    return () => { costRequestId.current += 1; };
  }, [branchId, canView]);

  const ready = state.status === 'ready' ? state.data : null;

  const materialById = useMemo(() => {
    const map = new Map<string, Material>();
    for (const material of ready?.materials.materials ?? []) {
      map.set(material.id, material);
    }
    return map;
  }, [ready]);

  const balanceByMaterial = useMemo(() => {
    const map = new Map<string, string>();
    for (const balance of ready?.materials.balances ?? []) {
      map.set(balance.material_id, balance.quantity);
    }
    return map;
  }, [ready]);

  const activeMaterials = ready?.materials.materials.filter(
    (material) => material.active,
  ) ?? [];
  const activeBoms = ready?.boms.boms.filter((bom) => bom.active) ?? [];
  const draftOrders = ready?.orders.orders.filter(
    (order) => order.status === 'draft',
  ) ?? [];

  async function createMaterial(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || busy) {
      return;
    }

    const ok = await mutate(
      productionMaterialsPath(branchId),
      'POST',
      {
        code: materialCode,
        name: materialName,
        unit: materialUnit,
      },
      'Material creado.',
    );
    if (ok) {
      setMaterialCode('');
      setMaterialName('');
    }
  }

  function chooseMaterialForEdit(id: string) {
    setEditMaterialId(id);
    const material = materialById.get(id);
    setEditCode(material?.code ?? '');
    setEditName(material?.name ?? '');
    setEditUnit(material?.unit ?? 'unit');
  }

  async function updateMaterial(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canUpdate || busy || editMaterialId === '') {
      return;
    }

    await mutate(
      productionMaterialPath(branchId, editMaterialId),
      'PATCH',
      { code: editCode, name: editName, unit: editUnit },
      'Material actualizado.',
    );
  }

  async function deactivateMaterial() {
    if (!canDelete || busy || editMaterialId === '') {
      return;
    }

    const ok = await mutate(
      productionMaterialPath(branchId, editMaterialId),
      'DELETE',
      undefined,
      'Material desactivado.',
    );
    if (ok) {
      chooseMaterialForEdit('');
    }
  }

  async function adjustMaterial(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canUpdate || busy || adjustMaterialId === '') {
      return;
    }

    const draft = {
      material_id: adjustMaterialId,
      delta: adjustDelta,
      reason: adjustReason,
    };
    adjustmentKey.current = stableIdempotency(
      adjustmentKey.current,
      'material-adjust',
      JSON.stringify(draft),
    );

    const ok = await mutate(
      productionMaterialAdjustmentPath(branchId, adjustMaterialId),
      'POST',
      {
        delta: adjustDelta,
        reason: adjustReason,
        idempotency_key: adjustmentKey.current.key,
      },
      'Stock de material ajustado.',
    );
    if (ok) {
      adjustmentKey.current = { signature: '', key: '' };
      setAdjustReason('');
    }
  }

  function setBomComponent(
    index: number,
    patch: Partial<BomComponentDraft>,
  ) {
    setBomComponents((current) => current.map((component, candidate) => {
      if (candidate !== index) {
        return component;
      }

      const next = { ...component, ...patch };
      if (patch.materialId !== undefined) {
        const material = materialById.get(patch.materialId);
        if (material) {
          next.unit = material.unit;
        }
      }
      return next;
    }));
  }

  function addBomComponent() {
    const first = activeMaterials[0];
    setBomComponents((current) => [
      ...current,
      {
        materialId: first?.id ?? '',
        quantity: '1',
        unit: first?.unit ?? 'unit',
      },
    ]);
  }

  function removeBomComponent(index: number) {
    setBomComponents((current) => (
      current.length === 1
        ? current
        : current.filter((_, candidate) => candidate !== index)
    ));
  }

  async function createBom(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || busy || bomVariantId === '') {
      return;
    }

    const ok = await mutate(
      productionBomsPath(branchId),
      'POST',
      {
        variant_id: bomVariantId,
        components: bomComponents.map((component) => ({
          material_id: component.materialId,
          quantity: component.quantity,
          unit: component.unit,
        })),
      },
      'Nueva versión de BOM creada.',
    );
    if (ok) {
      const first = activeMaterials[0];
      setBomComponents([{
        materialId: first?.id ?? '',
        quantity: '1',
        unit: first?.unit ?? 'unit',
      }]);
    }
  }

  async function createOrder(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || busy || orderBomId === '') {
      return;
    }

    const quantity = Number(orderQuantity);
    if (!Number.isInteger(quantity) || quantity <= 0) {
      setNotice({
        kind: 'error',
        text: 'La cantidad objetivo debe ser un entero positivo.',
      });
      return;
    }

    await mutate(
      productionOrdersPath(branchId),
      'POST',
      { bom_id: orderBomId, target_quantity: quantity },
      'Orden de producción creada.',
    );
  }

  async function completeOrder(order: ProductionOrder) {
    if (!canUpdate || busy) {
      return;
    }

    const rawQuantity =
      completionQuantities[order.id] ?? String(order.target_quantity);
    const quantity = Number(rawQuantity);
    if (!Number.isInteger(quantity) || quantity <= 0) {
      setNotice({
        kind: 'error',
        text: 'La cantidad terminada debe ser un entero positivo.',
      });
      return;
    }

    const signature = JSON.stringify({
      order: order.id,
      completed_quantity: quantity,
    });
    const current = completionKeys.current.get(order.id) ?? {
      signature: '',
      key: '',
    };
    const next = stableIdempotency(
      current,
      'production-complete',
      signature,
    );
    completionKeys.current.set(order.id, next);

    const ok = await mutate(
      productionOrderCompletePath(branchId, order.id),
      'POST',
      {
        completed_quantity: quantity,
        idempotency_key: next.key,
      },
      'Orden completada.',
    );
    if (ok) {
      completionKeys.current.delete(order.id);
    }
  }

  async function mutate(
    url: string,
    method: 'POST' | 'PATCH' | 'DELETE',
    payload: Record<string, unknown> | undefined,
    success: string,
  ): Promise<boolean> {
    setBusy(true);
    setNotice(null);
    try {
      const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          ...(payload === undefined
            ? {}
            : { 'Content-Type': 'application/json' }),
          'X-CSRF-Token': csrfToken,
        },
        ...(payload === undefined
          ? {}
          : { body: JSON.stringify(payload) }),
      });

      if (!response.ok) {
        throw new Error(await responseMessage(
          response,
          'No fue posible completar la operación de producción.',
        ));
      }

      setNotice({ kind: 'success', text: success });
      setActivePanel(null);
      await loadProduction();
      return true;
    } catch (error) {
      setNotice({
        kind: 'error',
        text: error instanceof Error
          ? error.message
          : 'No fue posible completar la operación de producción.',
      });
      return false;
    } finally {
      setBusy(false);
    }
  }

  if (!canView) {
    return null;
  }

  return (
    <section
      id="production"
      className="catalog-panel production-panel ui-page"
      aria-label="Producción Lite"
    >
      <PageHeader title="Producción Lite"
        description="Materiales, recetas y órdenes de producción de la sede activa."
        actions={ready && <>
          {canCreate && <button type="button" className="button button-secondary" onClick={() => setActivePanel('material')}>Nuevo material</button>}
          {canCreate && activeMaterials.length > 0 && ready.variants.length > 0 && <button type="button" className="button button-secondary" onClick={() => setActivePanel('bom')}>Nueva versión de BOM</button>}
          {canCreate && activeBoms.length > 0 && <button type="button" className="button" onClick={() => setActivePanel('order')}>Nueva orden</button>}
        </>} />

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
        <output className="catalog-state muted" aria-live="polite">
          Cargando Producción Lite…
        </output>
      )}

      {state.status === 'disabled' && (
        <div className="catalog-state" role="status">
          <strong>Producción Lite no está habilitada.</strong>
          <span className="muted">{state.message}</span>
        </div>
      )}

      {state.status === 'error' && (
        <div className="catalog-state catalog-error" role="alert">
          <strong>No fue posible cargar Producción Lite.</strong>
          <span>{state.message}</span>
          <button
            className="button button-secondary"
            type="button"
            onClick={() => void loadProduction()}
          >
            Reintentar
          </button>
        </div>
      )}

      {ready && (
        <>
          <MetricStrip label="Resumen de producción" items={[
            { label: 'Materiales activos', value: activeMaterials.length },
            { label: 'BOM activas', value: activeBoms.length },
            { label: 'Órdenes abiertas', value: draftOrders.length, tone: 'warn' },
          ]} />

          <div className="production-layout">
            <Card title="Materiales">
              <p className="muted">Stock en {ready.materials.source.name}</p>

              <Toolbar trailing={<span className="ui-count">{ready.materials.materials.length} materiales</span>}>
                <label className="ui-search"><span className="ui-sr-only">Buscar material</span>
                  <input type="search" placeholder="Buscar código o material" value={search} onChange={event => setSearch(event.target.value)} />
                </label>
              </Toolbar>
              <DataTable caption="Materiales de producción"
                rows={ready.materials.materials.filter(material => (material.code + ' ' + material.name)
                  .toLocaleLowerCase('es-CO').includes(search.toLocaleLowerCase('es-CO')))}
                rowKey={material => material.id} onOpen={material => setSelectedMaterialId(material.id)}
                empty={<><strong>Sin materiales</strong><span>Aún no hay materias primas registradas o no coinciden con la búsqueda.</span></>}
                columns={[
                  { key: 'name', header: 'Material', render: material => <span className="ui-cell-main"><strong>{material.name}</strong><small>{material.code}</small></span> },
                  { key: 'stock', header: 'Stock', render: material => (balanceByMaterial.get(material.id) ?? '0') + ' ' + material.unit, align: 'right' },
                  { key: 'state', header: 'Estado', render: material => <StatusPill tone={material.active ? 'ok' : 'neutral'}>{material.active ? 'Activo' : 'Inactivo'}</StatusPill> },
                ]} />
              {(canUpdate || canDelete) && <button type="button" className="button button-secondary" onClick={() => setActivePanel('editMaterial')}>Editar material</button>}
              {canUpdate && activeMaterials.length > 0 && <button type="button" className="button button-secondary" onClick={() => setActivePanel('adjustMaterial')}>Ajustar materia prima</button>}
              <SidePanel open={Boolean(selectedMaterialId)} title="Detalle de material" onClose={() => setSelectedMaterialId(null)}>
                {ready.materials.materials.filter(material => material.id === selectedMaterialId).map(material => <dl key={material.id} className="ui-details">
                  <div><dt>Código</dt><dd>{material.code}</dd></div><div><dt>Nombre</dt><dd>{material.name}</dd></div>
                  <div><dt>Unidad</dt><dd>{material.unit}</dd></div>
                  <div><dt>Stock</dt><dd>{balanceByMaterial.get(material.id) ?? '0'} {material.unit}</dd></div>
                  <div><dt>Estado</dt><dd>{material.active ? 'Activo' : 'Inactivo'}</dd></div>
                </dl>)}
              </SidePanel>

              {canCreate && <SidePanel open={activePanel === 'material'} title="Nuevo material" onClose={() => setActivePanel(null)}>
                <form className="catalog-editor" onSubmit={(event) => void createMaterial(event)}>
                  <div className="catalog-form-grid">
                    <label className="field">
                      <span>Código</span>
                      <input
                        value={materialCode}
                        onChange={(event) => setMaterialCode(event.target.value)}
                        required
                        maxLength={64}
                      />
                    </label>
                    <label className="field">
                      <span>Nombre</span>
                      <input
                        value={materialName}
                        onChange={(event) => setMaterialName(event.target.value)}
                        required
                        maxLength={160}
                      />
                    </label>
                    <label className="field">
                      <span>Unidad</span>
                      <select
                        value={materialUnit}
                        onChange={(event) => setMaterialUnit(event.target.value)}
                      >
                        {ready.materials.units.map((unit) => (
                          <option value={unit} key={unit}>{unit}</option>
                        ))}
                      </select>
                    </label>
                  </div>
                  <button className="button" type="submit" disabled={busy}>
                    Crear material
                  </button>
                </form>
              </SidePanel>}

              {(canUpdate || canDelete) && ready.materials.materials.length > 0 && <SidePanel open={activePanel === 'editMaterial'} title="Editar material" onClose={() => setActivePanel(null)}>
                <form className="catalog-editor" onSubmit={(event) => void updateMaterial(event)}>
                  <label className="field">
                    <span>Material</span>
                    <select
                      value={editMaterialId}
                      onChange={(event) => chooseMaterialForEdit(event.target.value)}
                    >
                      <option value="">Selecciona…</option>
                      {ready.materials.materials.map((material) => (
                        <option value={material.id} key={material.id}>
                          {material.code} · {material.name}
                        </option>
                      ))}
                    </select>
                  </label>
                  {editMaterialId !== '' && (
                    <>
                      <div className="catalog-form-grid">
                        <label className="field">
                          <span>Código</span>
                          <input
                            value={editCode}
                            onChange={(event) => setEditCode(event.target.value)}
                            disabled={!canUpdate}
                          />
                        </label>
                        <label className="field">
                          <span>Nombre</span>
                          <input
                            value={editName}
                            onChange={(event) => setEditName(event.target.value)}
                            disabled={!canUpdate}
                          />
                        </label>
                        <label className="field">
                          <span>Unidad</span>
                          <select
                            value={editUnit}
                            onChange={(event) => setEditUnit(event.target.value)}
                            disabled={!canUpdate}
                          >
                            {ready.materials.units.map((unit) => (
                              <option value={unit} key={unit}>{unit}</option>
                            ))}
                          </select>
                        </label>
                      </div>
                      <div className="catalog-actions">
                        {canUpdate && (
                          <button className="button" type="submit" disabled={busy}>
                            Guardar cambios
                          </button>
                        )}
                        {canDelete && materialById.get(editMaterialId)?.active && (
                          <button
                            className="button button-secondary"
                            type="button"
                            disabled={busy}
                            onClick={() => void deactivateMaterial()}
                          >
                            Desactivar
                          </button>
                        )}
                      </div>
                    </>
                  )}
                </form>
              </SidePanel>}

              {canUpdate && activeMaterials.length > 0 && <SidePanel open={activePanel === 'adjustMaterial'} title="Ajustar materia prima" onClose={() => setActivePanel(null)}>
                <form className="catalog-editor" onSubmit={(event) => void adjustMaterial(event)}>
                  <div className="catalog-form-grid">
                    <label className="field">
                      <span>Material</span>
                      <select
                        value={adjustMaterialId}
                        onChange={(event) => setAdjustMaterialId(event.target.value)}
                      >
                        {activeMaterials.map((material) => (
                          <option value={material.id} key={material.id}>
                            {material.code} · {material.name}
                          </option>
                        ))}
                      </select>
                    </label>
                    <label className="field">
                      <span>Delta</span>
                      <input
                        value={adjustDelta}
                        onChange={(event) => setAdjustDelta(event.target.value)}
                        inputMode="decimal"
                        required
                      />
                    </label>
                    <label className="field catalog-form-wide">
                      <span>Motivo</span>
                      <input
                        value={adjustReason}
                        onChange={(event) => setAdjustReason(event.target.value)}
                        required
                        maxLength={240}
                      />
                    </label>
                  </div>
                  <button className="button" type="submit" disabled={busy}>
                    Aplicar ajuste
                  </button>
                </form>
              </SidePanel>}
            </Card>

            <Card title="BOM / recetas">
              <p className="muted">Versiones por variante</p>

              <DataTable caption="Versiones BOM por variante" rows={ready.boms.boms}
                rowKey={bom => bom.id} onOpen={bom => setSelectedBomId(bom.id)}
                empty={<><strong>Sin BOM</strong><span>Aún no hay BOM registradas.</span></>}
                columns={[
                  { key: 'variant', header: 'Variante', render: bom => <span className="ui-cell-main"><strong>{bom.variant_sku}</strong><small>{bom.variant_name}</small></span> },
                  { key: 'version', header: 'Versión', render: bom => bom.version, align: 'right' },
                  { key: 'components', header: 'Componentes', render: bom => bom.lines.length, align: 'right' },
                  { key: 'state', header: 'Estado', render: bom => <StatusPill tone={bom.active ? 'ok' : 'neutral'}>{bom.active ? 'Activa' : 'Anterior'}</StatusPill> },
                ]} />
              <SidePanel open={selectedBomId !== null} title="Detalle de BOM" onClose={() => setSelectedBomId(null)}>
                {ready.boms.boms.filter(bom => bom.id === selectedBomId).map(bom => <div key={bom.id}>
                  <p>{bom.variant_sku} · versión {bom.version}</p>
                  <ul>{bom.lines.map(line => <li key={line.material_id}>
                    {line.material_code}: {line.quantity} {line.unit}
                  </li>)}</ul>
                </div>)}
              </SidePanel>

              {canCreate && ready.variants.length > 0 && activeMaterials.length > 0 && <SidePanel open={activePanel === 'bom'} title="Nueva versión de BOM" onClose={() => setActivePanel(null)}>
                <form className="catalog-editor" onSubmit={(event) => void createBom(event)}>
                  <label className="field">
                    <span>Variante</span>
                    <select
                      value={bomVariantId}
                      onChange={(event) => setBomVariantId(event.target.value)}
                    >
                      {ready.variants.map((variant) => (
                        <option value={variant.id} key={variant.id}>
                          {variant.sku} · {variant.product_name}
                        </option>
                      ))}
                    </select>
                  </label>

                  <div className="production-components">
                    {bomComponents.map((component, index) => (
                      <div className="production-component-row" key={index}>
                        <label className="field">
                          <span>Material</span>
                          <select
                            value={component.materialId}
                            onChange={(event) => setBomComponent(
                              index,
                              { materialId: event.target.value },
                            )}
                          >
                            {activeMaterials.map((material) => (
                              <option value={material.id} key={material.id}>
                                {material.code} · {material.name}
                              </option>
                            ))}
                          </select>
                        </label>
                        <label className="field">
                          <span>Cantidad</span>
                          <input
                            value={component.quantity}
                            onChange={(event) => setBomComponent(
                              index,
                              { quantity: event.target.value },
                            )}
                            inputMode="decimal"
                            required
                          />
                        </label>
                        <label className="field">
                          <span>Unidad</span>
                          <select
                            value={component.unit}
                            onChange={(event) => setBomComponent(
                              index,
                              { unit: event.target.value },
                            )}
                          >
                            {ready.materials.units.map((unit) => (
                              <option value={unit} key={unit}>{unit}</option>
                            ))}
                          </select>
                        </label>
                        <button
                          className="button button-secondary"
                          type="button"
                          disabled={bomComponents.length === 1}
                          onClick={() => removeBomComponent(index)}
                        >
                          Quitar
                        </button>
                      </div>
                    ))}
                  </div>
                  <div className="catalog-actions">
                    <button
                      className="button button-secondary"
                      type="button"
                      onClick={addBomComponent}
                    >
                      Añadir componente
                    </button>
                    <button className="button" type="submit" disabled={busy}>
                      Crear BOM
                    </button>
                  </div>
                </form>
              </SidePanel>}
            </Card>

            <Card title="Órdenes">
              <p className="muted">Crear y completar lotes</p>

              <DataTable caption="Órdenes de producción" rows={ready.orders.orders}
                rowKey={order => order.id} onOpen={order => setSelectedOrderId(order.id)}
                empty={<><strong>Sin órdenes</strong><span>Aún no hay órdenes de producción.</span></>}
                columns={[
                  { key: 'sku', header: 'Variante', render: order => order.variant_sku },
                  { key: 'bom', header: 'BOM', render: order => 'v' + order.bom_version },
                  { key: 'target', header: 'Objetivo', render: order => order.target_quantity, align: 'right' },
                  { key: 'completed', header: 'Terminado', render: order => order.completed_quantity ?? 'Pendiente', align: 'right' },
                  { key: 'status', header: 'Estado', render: order =>
                    <StatusPill tone={order.status === 'completed' ? 'ok' : 'warn'}>{order.status}</StatusPill> },
                ]} />
              <SidePanel open={selectedOrderId !== null} title="Detalle de orden" onClose={() => setSelectedOrderId(null)}>
                {ready.orders.orders.filter(order => order.id === selectedOrderId).map(order => <div key={order.id}>
                  <dl className="ui-details">
                    <div><dt>Variante</dt><dd>{order.variant_sku}</dd></div>
                    <div><dt>BOM</dt><dd>v{order.bom_version}</dd></div>
                    <div><dt>Objetivo</dt><dd>{order.target_quantity}</dd></div>
                    <div><dt>Terminado</dt><dd>{order.completed_quantity ?? 'Pendiente'}</dd></div>
                  </dl>
                  {order.status === 'completed' && <div className="production-order-cost" aria-label="Costo de orden terminada">
                    <OrderCostDetails cost={orderCosts[order.id] ?? { status: 'loading' }} materials={materialById} />
                  </div>}
                  {canUpdate && order.status === 'draft' && <div className="production-complete">
                    <label className="field"><span>Cantidad terminada</span>
                      <input value={completionQuantities[order.id] ?? String(order.target_quantity)}
                        onChange={event => setCompletionQuantities(current => ({
                          ...current, [order.id]: event.target.value,
                        }))} inputMode="numeric" />
                    </label>
                    <button className="button" type="button" disabled={busy}
                      onClick={() => void completeOrder(order)}>Completar</button>
                  </div>}
                </div>)}
              </SidePanel>

              {canCreate && activeBoms.length > 0 && <SidePanel open={activePanel === 'order'} title="Nueva orden" onClose={() => setActivePanel(null)}>
                <form className="catalog-editor" onSubmit={(event) => void createOrder(event)}>
                  <div className="catalog-form-grid">
                    <label className="field">
                      <span>BOM activa</span>
                      <select
                        value={orderBomId}
                        onChange={(event) => setOrderBomId(event.target.value)}
                      >
                        {activeBoms.map((bom) => (
                          <option value={bom.id} key={bom.id}>
                            {bom.variant_sku} · BOM v{bom.version}
                          </option>
                        ))}
                      </select>
                    </label>
                    <label className="field">
                      <span>Cantidad objetivo</span>
                      <input
                        value={orderQuantity}
                        onChange={(event) => setOrderQuantity(event.target.value)}
                        inputMode="numeric"
                        required
                      />
                    </label>
                  </div>
                  <button className="button" type="submit" disabled={busy}>
                    Crear orden
                  </button>
                </form>
              </SidePanel>}
            </Card>
          </div>
        </>
      )}
    </section>
  );
}

async function getJson<T>(url: string): Promise<T> {
  const response = await fetch(url, {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });
  if (!response.ok) {
    const message = await responseMessage(
      response,
      'No fue posible cargar Producción Lite.',
    );
    if (
      response.status === 422
      && message.includes('Producción Lite no está habilitada')
    ) {
      throw new ProductionUnavailable(message);
    }
    throw new Error(message);
  }

  return await response.json() as T;
}

function stableIdempotency(
  current: IdempotencyState,
  prefix: string,
  signature: string,
): IdempotencyState {
  if (current.signature === signature && current.key !== '') {
    return current;
  }

  const random = typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : String(Date.now());

  return {
    signature,
    key: prefix + '-' + random,
  };
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
