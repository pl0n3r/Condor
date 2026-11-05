import { useEffect, useState } from 'react';

type Order = {
  id: string; status: 'draft' | 'completed'; target_quantity: number;
  completed_quantity: number | null; elapsed_lifecycle_minutes: number | null;
};
type Snapshot = {
  orders: Order[]; sample_size: number; completed_orders: number;
  completion_percent: number | null; sample_limited: boolean;
};
type Load = { kind: 'loading' } | { kind: 'denied' }
  | { kind: 'error'; message: string } | { kind: 'ready'; data: Snapshot };

function parseSnapshot(raw: unknown): Snapshot {
  const data = raw as Snapshot | null;
  if (!data || typeof data !== 'object' || !Array.isArray(data.orders)
    || !Number.isSafeInteger(data.sample_size)
    || !Number.isSafeInteger(data.completed_orders)
    || (data.completion_percent !== null
      && (typeof data.completion_percent !== 'number'
        || !Number.isFinite(data.completion_percent)))
    || typeof data.sample_limited !== 'boolean'
    || !data.orders.every((row) => row && typeof row.id === 'string'
      && (row.status === 'draft' || row.status === 'completed')
      && Number.isSafeInteger(row.target_quantity))) {
    throw new Error('Respuesta de operaciones inválida.');
  }
  return data;
}

export function OperationsSummaryPanel({
  branchId, permissions,
}: Readonly<{ branchId: string; permissions: string[] }>) {
  const allowed = permissions.includes('inventory.view');
  const [state, setState] = useState<Load>({ kind: 'loading' });

  useEffect(() => {
    if (!allowed) return;
    if (!/^[0-9A-HJKMNP-TV-Z]{26}$/.test(branchId)) {
      setState({ kind: 'error', message: 'La sede no es válida.' });
      return;
    }
    const controller = new AbortController();
    setState({ kind: 'loading' });
    void fetch('/api/v1/branches/' + encodeURIComponent(branchId)
      + '/production/operations-summary', {
      credentials: 'same-origin', headers: { Accept: 'application/json' },
      signal: controller.signal,
    }).then(async (response) => {
      if (response.status === 401 || response.status === 403) throw new Error('denied');
      if (!response.ok) throw new Error('No fue posible consultar las operaciones.');
      return parseSnapshot(await response.json() as unknown);
    }).then((data) => {
      if (!controller.signal.aborted) setState({ kind: 'ready', data });
    }).catch((error: unknown) => {
      if (controller.signal.aborted) return;
      setState(error instanceof Error && error.message === 'denied'
        ? { kind: 'denied' }
        : { kind: 'error', message: error instanceof Error
          ? error.message : 'Error de consulta.' });
    });
    return () => controller.abort();
  }, [branchId, allowed]);

  if (!allowed || state.kind === 'denied') {
    return <p role="alert">No tienes permiso para ver operaciones.</p>;
  }
  if (state.kind === 'loading') return <p role="status">Cargando operaciones…</p>;
  if (state.kind === 'error') return <p role="alert">{state.message}</p>;
  const { data } = state;
  return <section aria-label="Resumen de producción Lite">
    <h2>Órdenes de producción</h2>
    <p>Terminadas: {data.completed_orders} de {data.sample_size}.
      Avance de unidades: {data.completion_percent === null ? 'Sin datos' : String(data.completion_percent) + '%'}.</p>
    <p>Responsables, tiempo laboral y productividad: no verificables.</p>
    {data.sample_limited && <p>Solo las 50 órdenes más recientes.</p>}
    {data.orders.length === 0
      ? <p role="status">No hay órdenes de producción para esta sede.</p>
      : <table><caption>Lectura de Producción Lite</caption>
        <thead><tr><th>Orden</th><th>Estado</th><th>Unidades</th>
          <th>Minutos de ciclo</th><th>Responsable</th></tr></thead>
        <tbody>{data.orders.map((row) => <tr key={row.id}>
          <th scope="row">{row.id}</th><td>{row.status}</td>
          <td>{row.completed_quantity ?? 0}/{row.target_quantity}</td>
          <td>{row.elapsed_lifecycle_minutes ?? 'No disponible'}</td>
          <td>Sin asignación acreditada</td>
        </tr>)}</tbody>
      </table>}
  </section>;
}
