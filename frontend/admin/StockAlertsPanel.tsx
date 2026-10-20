import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';

type StockAlert = {
  id: string;
  variant_id: string;
  source_id: string;
  movement_id: string;
  minimum: number;
  quantity: number;
  status: 'pending' | 'delivered';
};

type StockMinimum = {
  variant_id: string;
  source_id: string;
  minimum: number;
};

type StockData = {
  sourceId: string;
  alerts: StockAlert[];
  minimums: StockMinimum[];
};

type Props = Readonly<{
  branchId: string;
  permissions: string[];
  csrfToken: string;
}>;

type PanelState =
  | { status: 'loading' }
  | { status: 'ready'; data: StockData }
  | { status: 'denied' }
  | { status: 'error'; message: string };

type Message = { kind: 'success' | 'error'; text: string };
type ApiError = Error & { status?: number };

function objectValue(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function isIntegerInRange(value: unknown): value is number {
  return typeof value === 'number'
    && Number.isSafeInteger(value)
    && value >= 0
    && value <= 2147483647;
}

function stockAlert(value: unknown, sourceId: string): value is StockAlert {
  return objectValue(value)
    && typeof value.id === 'string' && value.id.length > 0
    && typeof value.variant_id === 'string' && value.variant_id.length > 0
    && typeof value.movement_id === 'string'
    && value.source_id === sourceId
    && isIntegerInRange(value.minimum)
    && typeof value.quantity === 'number' && Number.isSafeInteger(value.quantity)
    && (value.status === 'pending' || value.status === 'delivered');
}

function stockMinimum(value: unknown, sourceId: string): value is StockMinimum {
  return objectValue(value)
    && typeof value.variant_id === 'string' && value.variant_id.length > 0
    && value.source_id === sourceId
    && isIntegerInRange(value.minimum);
}

function readStockData(alerts: unknown, minimums: unknown): StockData {
  if (!objectValue(alerts) || !objectValue(minimums)
    || typeof alerts.source_id !== 'string'
    || alerts.source_id.length === 0
    || minimums.source_id !== alerts.source_id
    || !Array.isArray(alerts.alerts)
    || !Array.isArray(minimums.minimums)) {
    throw new Error('La respuesta de alertas no es válida para esta sede.');
  }
  // Capture the narrowed identifier: TypeScript does not preserve a mutable
  // record property narrow across Array.every callbacks.
  const sourceId = alerts.source_id;
  if (!alerts.alerts.every((item) => stockAlert(item, sourceId))
    || !minimums.minimums.every((item) => stockMinimum(item, sourceId))) {
    throw new Error('La respuesta de alertas no es válida para esta sede.');
  }
  return {
    sourceId,
    alerts: alerts.alerts,
    minimums: minimums.minimums,
  };
}

function apiError(status: number): ApiError {
  const error = new Error(
    status === 401 || status === 403
      ? 'No tienes autorización para consultar o modificar alertas.'
      : status === 404
        ? 'La sede o la variante ya no están disponibles.'
        : status === 422
          ? 'El mínimo debe ser un número entero no negativo.'
          : 'No fue posible conectar con el servidor de inventario.',
  ) as ApiError;
  error.status = status;
  return error;
}

async function responseJson(response: Response): Promise<unknown> {
  if (!response.ok) {
    throw apiError(response.status);
  }
  try {
    return await response.json() as unknown;
  } catch {
    throw new Error('El servidor devolvió una respuesta no válida.');
  }
}

function parseMinimum(value: string): number | null {
  if (!/^(0|[1-9][0-9]*)$/.test(value)) {
    return null;
  }
  const minimum = Number(value);
  return isIntegerInRange(minimum) ? minimum : null;
}

export function StockAlertsPanel({ branchId, permissions, csrfToken }: Props) {
  const canView = permissions.includes('inventory.view');
  const canUpdate = permissions.includes('inventory.update');
  const [state, setState] = useState<PanelState>({ status: 'loading' });
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState<Message | null>(null);
  const [savingVariant, setSavingVariant] = useState<string | null>(null);
  const requestSequence = useRef(0);
  const branchPath = '/api/v1/branches/' + encodeURIComponent(branchId)
    + '/inventory/stock-alerts';
  const minimumsPath = branchPath + '/minimums';

  async function load() {
    if (!canView) return;
    const requestId = ++requestSequence.current;
    setState({ status: 'loading' });
    setNotice(null);
    try {
      const [alertsResponse, minimumsResponse] = await Promise.all([
        fetch(branchPath, {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        }),
        fetch(minimumsPath, {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        }),
      ]);
      const [alerts, minimums] = await Promise.all([
        responseJson(alertsResponse),
        responseJson(minimumsResponse),
      ]);
      const data = readStockData(alerts, minimums);
      if (requestId !== requestSequence.current) return;
      setDrafts(Object.fromEntries(data.minimums.map((item) => [
        item.variant_id, String(item.minimum),
      ])));
      setState({ status: 'ready', data });
    } catch (error) {
      if (requestId !== requestSequence.current) return;
      if ((error as ApiError).status === 401 || (error as ApiError).status === 403) {
        setState({ status: 'denied' });
      } else {
        setState({
          status: 'error',
          message: error instanceof Error
            ? error.message
            : 'No fue posible consultar las alertas de stock.',
        });
      }
    }
  }

  useEffect(() => {
    if (canView) {
      void load();
    }
    return () => {
      // An obsolete branch's responses must never populate this panel.
      requestSequence.current += 1;
    };
  }, [branchId, canView]);

  const ready = state.status === 'ready' ? state.data : null;
  const displayed = useMemo(() => {
    const values = new Map<string, { variantId: string; minimum: number; alert?: StockAlert }>();
    for (const item of ready?.minimums ?? []) {
      values.set(item.variant_id, {
        variantId: item.variant_id,
        minimum: item.minimum,
      });
    }
    for (const alert of ready?.alerts ?? []) {
      const existing = values.get(alert.variant_id);
      values.set(alert.variant_id, {
        variantId: alert.variant_id,
        minimum: existing?.minimum ?? alert.minimum,
        alert,
      });
    }
    return [...values.values()];
  }, [ready]);

  async function saveMinimum(event: FormEvent<HTMLFormElement>, variantId: string) {
    event.preventDefault();
    if (!canUpdate || savingVariant !== null || ready === null) return;
    const minimum = parseMinimum(drafts[variantId] ?? String(
      displayed.find((item) => item.variantId === variantId)?.minimum ?? '',
    ));
    if (minimum === null) {
      setNotice({
        kind: 'error',
        text: 'El mínimo debe ser un entero entre 0 y 2147483647.',
      });
      return;
    }
    const requestId = requestSequence.current;
    setSavingVariant(variantId);
    setNotice(null);
    try {
      const response = await fetch(
        minimumsPath + '/' + encodeURIComponent(variantId),
        {
          method: 'PUT',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
          },
          body: JSON.stringify({ minimum }),
        },
      );
      const result = await responseJson(response);
      if (!objectValue(result) || !stockMinimum(result.minimum, ready.sourceId)
        || result.minimum.variant_id !== variantId
        || result.minimum.minimum !== minimum) {
        throw new Error('El servidor no confirmó el nuevo mínimo.');
      }
      if (requestId !== requestSequence.current) return;
      setState((current) => {
        if (current.status !== 'ready' || current.data.sourceId !== ready.sourceId) {
          return current;
        }
        const next = current.data.minimums.filter((item) => item.variant_id !== variantId);
        next.push(result.minimum as StockMinimum);
        return { status: 'ready', data: { ...current.data, minimums: next } };
      });
      setDrafts((current) => ({ ...current, [variantId]: String(minimum) }));
      setNotice({ kind: 'success', text: 'Mínimo de stock actualizado.' });
    } catch (error) {
      if (requestId !== requestSequence.current) return;
      if ((error as ApiError).status === 401 || (error as ApiError).status === 403) {
        setState({ status: 'denied' });
      } else {
        setNotice({
          kind: 'error',
          text: error instanceof Error
            ? error.message
            : 'No fue posible actualizar el mínimo.',
        });
      }
    } finally {
      if (requestId === requestSequence.current) setSavingVariant(null);
    }
  }

  return (
    <section id="stock-alerts" className="catalog-panel" aria-labelledby="stock-alerts-title">
      <div className="catalog-heading">
        <div>
          <span className="eyebrow">Inventario · sede activa</span>
          <h2 id="stock-alerts-title">Alertas de stock bajo</h2>
          <p className="muted">Alertas del inventario autorizado, sin notificaciones externas.</p>
        </div>
      </div>
      {!canView && (
        <div role="alert" className="alert alert-error">
          No tienes permiso para consultar alertas de inventario.
        </div>
      )}
      {canView && state.status === 'loading' && (
        <output aria-live="polite" className="muted">Cargando alertas de stock…</output>
      )}
      {canView && state.status === 'denied' && (
        <div role="alert" className="alert alert-error">
          Acceso denegado a las alertas de esta sede.
        </div>
      )}
      {canView && state.status === 'error' && (
        <div role="alert" className="alert alert-error">
          <p>{state.message}</p>
          <button className="button" type="button" onClick={() => void load()}>
            Reintentar
          </button>
        </div>
      )}
      {notice?.kind === 'error' && (
        <div role="alert" className="alert alert-error">{notice.text}</div>
      )}
      {notice?.kind === 'success' && (
        <output aria-live="polite" className="alert alert-success">{notice.text}</output>
      )}
      {canView && ready !== null && (
        <>
          {ready.alerts.length === 0 && (
            <p className="muted">No hay alertas activas de stock bajo para esta sede.</p>
          )}
          {displayed.length === 0 ? (
            <p className="muted">Aún no hay mínimos configurados para esta sede.</p>
          ) : (
            <div className="catalog-list">
              {displayed.map(({ variantId, minimum, alert }) => (
                <article className="catalog-card" key={variantId}>
                  <div>
                    <span className="eyebrow">
                      {alert ? 'Alerta de stock' : 'Mínimo configurado'}
                    </span>
                    <h3>Variante {variantId}</h3>
                    <p className="muted">Sede activa · fuente {ready.sourceId}</p>
                    {alert && (
                      <p>
                        Existencia: <strong>{alert.quantity}</strong> ·
                        Mínimo al alertar: <strong>{alert.minimum}</strong> ·
                        Estado: {alert.status === 'pending' ? 'Pendiente' : 'Entregada'}
                      </p>
                    )}
                    <p>Mínimo actual: <strong>{minimum}</strong></p>
                  </div>
                  {canUpdate && (
                    <form
                      className="catalog-editor"
                      onSubmit={(event) => void saveMinimum(event, variantId)}
                    >
                      <label className="field">
                        <span>Mínimo de stock de la variante</span>
                        <input
                          aria-label={'Mínimo de stock ' + variantId}
                          type="number"
                          required
                          min="0"
                          max="2147483647"
                          step="1"
                          inputMode="numeric"
                          value={drafts[variantId] ?? String(minimum)}
                          onChange={(event) => setDrafts((current) => ({
                            ...current, [variantId]: event.target.value,
                          }))}
                          disabled={savingVariant !== null}
                        />
                      </label>
                      <button className="button" type="submit" disabled={savingVariant !== null}>
                        {savingVariant === variantId ? 'Guardando…' : 'Guardar mínimo'}
                      </button>
                    </form>
                  )}
                </article>
              ))}
            </div>
          )}
        </>
      )}
    </section>
  );
}
