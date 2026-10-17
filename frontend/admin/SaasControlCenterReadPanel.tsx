import { useEffect, useState } from 'react';

type Projection = {
  state: 'ready';
  tenant_id: string;
  plan: {
    key: string;
    version: string;
    currency: string;
    price_minor: number;
  };
  add_ons: Array<{ key: string; price_minor: number; active: boolean }>;
  usage: Array<{ metric: string; used: number; limit: number }>;
  entitlements: Array<{ key: string; allowed: boolean }>;
};

type State =
  | { status: 'loading' }
  | { status: 'ready'; data: Projection }
  | { status: 'empty' }
  | { status: 'permission-denied' }
  | { status: 'not-found' }
  | { status: 'error' };

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function isKey(value: unknown): value is string {
  return typeof value === 'string' && /^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,79}$/.test(value);
}

function isCount(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
}

/** Validate the entire server payload before rendering commercial facts. */
function isProjection(value: unknown, tenantId: string): value is Projection {
  if (!isRecord(value) || !isRecord(value.plan)) return false;
  const plan = value.plan;
  if (value.state !== 'ready' || value.tenant_id !== tenantId
      || !isKey(plan.key) || !isKey(plan.version)
      || typeof plan.currency !== 'string' || !/^[A-Z]{3}$/.test(plan.currency)
      || !isCount(plan.price_minor)
      || !Array.isArray(value.add_ons) || value.add_ons.length > 128
      || !Array.isArray(value.usage) || value.usage.length > 128
      || !Array.isArray(value.entitlements) || value.entitlements.length > 128) {
    return false;
  }
  return value.add_ons.every((row: unknown) =>
    isRecord(row) && isKey(row.key) && isCount(row.price_minor)
    && typeof row.active === 'boolean')
    && value.usage.every((row: unknown) =>
      isRecord(row) && isKey(row.metric) && isCount(row.used) && isCount(row.limit))
    && value.entitlements.every((row: unknown) =>
      isRecord(row) && isKey(row.key) && typeof row.allowed === 'boolean');
}

function money(minor: number, currency: string): string {
  if (!Number.isSafeInteger(minor) || minor < 0 || !/^[A-Z]{3}$/.test(currency)) {
    return 'UNKNOWN';
  }
  try {
    return new Intl.NumberFormat('es-CO', {
      style: 'currency',
      currency,
    }).format(minor / 100);
  } catch {
    return 'UNKNOWN';
  }
}

/**
 * Independent read-only request. Never guesses tenant scope, quotas or rights.
 * The server is the sole authority; navigation aborts an obsolete request.
 */
export function SaasControlCenterReadPanel({ tenantId }: Readonly<{ tenantId: string }>) {
  const [state, setState] = useState<State>({ status: 'loading' });
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    setState({ status: 'loading' });

    async function load() {
      try {
        const response = await fetch(
          '/adminpl0n3r/api/tenants/'
            + encodeURIComponent(tenantId)
            + '/saas-control-center',
          {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
          },
        );

        if (controller.signal.aborted) return;
        if (response.status === 403) {
          setState({ status: 'permission-denied' });
          return;
        }
        if (response.status === 404) {
          setState({ status: 'not-found' });
          return;
        }
        if (!response.ok) {
          setState({ status: 'error' });
          return;
        }

        const body: unknown = await response.json();
        if (controller.signal.aborted) return;
        if (body && typeof body === 'object') {
          const candidate = body as Record<string, unknown>;
          if (candidate.state === 'empty' && candidate.tenant_id === tenantId) {
            setState({ status: 'empty' });
            return;
          }
        }
        if (!isProjection(body, tenantId)) {
          setState({ status: 'error' });
          return;
        }
        setState({ status: 'ready', data: body });
      } catch {
        if (!controller.signal.aborted) setState({ status: 'error' });
      }
    }

    void load();
    return () => controller.abort();
  }, [tenantId, attempt]);

  return (
    <section className="platform-section" aria-labelledby="saas-control-center-title">
      <div className="section-heading compact">
        <div>
          <span className="eyebrow">Visibilidad comercial</span>
          <h2 id="saas-control-center-title">Control SaaS por empresa</h2>
        </div>
        <span className="status-pill">Solo lectura</span>
      </div>
      {state.status === 'loading' && <p role="status">Cargando información comercial…</p>}
      {state.status === 'permission-denied' && (
        <p role="alert">Tu sesión no tiene permiso para consultar esta empresa.</p>
      )}
      {state.status === 'not-found' && (
        <p role="alert">Esta empresa no se encontró. Información comercial: UNKNOWN.</p>
      )}
      {state.status === 'empty' && (
        <p className="platform-empty">
          No hay un plan canónico configurado. Precios, límites y permisos: UNKNOWN.
        </p>
      )}
      {state.status === 'error' && (
        <div role="alert">
          <p>No se pudieron verificar los datos comerciales. Estado: UNKNOWN.</p>
          <button className="button" type="button" onClick={() => setAttempt((n) => n + 1)}>
            Reintentar consulta
          </button>
        </div>
      )}
      {state.status === 'ready' && (
        <div className="tenant-grid">
          <article className="tenant-card">
            <h3>Plan vigente</h3>
            <dl>
              <div><dt>Clave</dt><dd>{state.data.plan.key}</dd></div>
              <div><dt>Versión</dt><dd>{state.data.plan.version}</dd></div>
              <div>
                <dt>Precio mensual</dt>
                <dd>{money(state.data.plan.price_minor, state.data.plan.currency)}</dd>
              </div>
            </dl>
          </article>
          <article className="tenant-card">
            <h3>Add-ons canónicos</h3>
            {state.data.add_ons.length === 0 ? (
              <p>Sin add-ons registrados; selección externa: UNKNOWN.</p>
            ) : (
              <ul>
                {state.data.add_ons.map((item) => (
                  <li key={item.key}>
                    {item.key}: {money(item.price_minor, state.data.plan.currency)}
                    {' · '}{item.active ? 'incluido' : 'no activo'}
                  </li>
                ))}
              </ul>
            )}
          </article>
          <article className="tenant-card">
            <h3>Uso y límites</h3>
            {state.data.usage.length === 0 ? (
              <p>Uso o límite no observado: UNKNOWN.</p>
            ) : (
              <dl>
                {state.data.usage.map((item) => (
                  <div key={item.metric}>
                    <dt>{item.metric}</dt>
                    <dd>{item.used} / {item.limit}</dd>
                  </div>
                ))}
              </dl>
            )}
          </article>
          <article className="tenant-card">
            <h3>Entitlements efectivos</h3>
            {state.data.entitlements.length === 0 ? (
              <p>Derechos no observados: UNKNOWN.</p>
            ) : (
              <dl>
                {state.data.entitlements.map((item) => (
                  <div key={item.key}>
                    <dt>{item.key}</dt>
                    <dd>{item.allowed ? 'Permitido' : 'Denegado'}</dd>
                  </div>
                ))}
              </dl>
            )}
          </article>
        </div>
      )}
    </section>
  );
}
