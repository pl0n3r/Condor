import { useEffect, useRef, useState } from 'react';
import { SaasControlCenterReadPanel } from './SaasControlCenterReadPanel';

type CatalogPlan = Readonly<{ id: string; name: string; synthetic: boolean }>;
type Operation = 'create' | 'trial';
type OperationState = 'idle' | 'saving' | 'verifying' | 'unknown';

export type PlatformSubscriptionsPanelProps = Readonly<{
  tenantId: string;
  owner: boolean;
  syntheticOnly: boolean;
  csrfToken: string;
  planVersions: ReadonlyArray<CatalogPlan>;
  onChanged?: () => void | Promise<void>;
}>;

// An arbitrary UUID, e-mail address, or customer name is not a synthetic tenant.
export function isSyntheticTenant(value: unknown): value is string {
  return typeof value === 'string'
    && /^synthetic-tenant-[a-z0-9]+(?:-[a-z0-9]+)*$/.test(value)
    && value.length <= 96;
}

function isSafePlan(value: unknown): value is CatalogPlan {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    return false;
  }
  const row = value as Record<string, unknown>;
  return row.synthetic === true
    && typeof row.id === 'string'
    && /^synthetic-plan-version-[a-z0-9]+(?:-[a-z0-9]+)*$/.test(row.id)
    && row.id.length <= 96
    && typeof row.name === 'string'
    && /^Synthetic [a-zA-Z0-9 -]{1,70}$/.test(row.name);
}

/** Always recheck when submitting, not just when rendering disabled controls. */
export function canMutateSyntheticSubscription(
  owner: boolean,
  syntheticOnly: boolean,
  tenantId: unknown,
  csrfToken: unknown,
  chosenPlan: unknown,
  catalog: ReadonlyArray<CatalogPlan>,
): boolean {
  return owner === true && syntheticOnly === true
    && isSyntheticTenant(tenantId)
    && typeof csrfToken === 'string' && csrfToken.trim().length >= 16
    && Array.isArray(catalog) && catalog.length <= 128
    && catalog.every(isSafePlan)
    && isSafePlan(chosenPlan)
    && catalog.some((plan) => plan.id === chosenPlan.id);
}

/**
 * Owner-only, synthetic manual UI. The existing PlatformOwnerApp owns route
 * /suscripciones and tenant selection. Canonical prices, add-ons, usage and
 * entitlements are read exclusively via SaasControlCenterReadPanel.
 * Never fabricate audit evidence or real customer records on the client.
 */
export function PlatformSubscriptionsPanel({
  tenantId,
  owner,
  syntheticOnly,
  csrfToken,
  planVersions,
  onChanged,
}: PlatformSubscriptionsPanelProps) {
  const [selectedPlan, setSelectedPlan] = useState('');
  const [status, setStatus] = useState<OperationState>('idle');
  const [revision, setRevision] = useState(0);
  const inFlight = useRef(false);
  const pending = useRef<AbortController | null>(null);

  useEffect(() => {
    pending.current?.abort();
    pending.current = null;
    inFlight.current = false;
    setSelectedPlan('');
    setStatus('idle');
    return () => pending.current?.abort();
  }, [tenantId]);

  const tenantAuthorized = owner === true && syntheticOnly === true
    && isSyntheticTenant(tenantId);
  if (!tenantAuthorized) {
    return (
      <section className="platform-section" aria-label="Suscripciones SaaS">
        <p role="alert">UNKNOWN: se requiere propietario y empresa sintética verificada.</p>
      </section>
    );
  }

  const availablePlans = planVersions.length <= 128 && planVersions.every(isSafePlan)
    ? planVersions
    : [];
  const chosen = availablePlans.find((row) => row.id === selectedPlan);
  const canCreate = status === 'idle' && !inFlight.current
    && canMutateSyntheticSubscription(
      owner, syntheticOnly, tenantId, csrfToken, chosen, availablePlans,
    );
  const canTrial = status === 'idle' && !inFlight.current
    && owner && syntheticOnly && isSyntheticTenant(tenantId)
    && typeof csrfToken === 'string' && csrfToken.trim().length >= 16;

  async function submit(operation: Operation) {
    if (inFlight.current || status !== 'idle' || !owner || !syntheticOnly
      || !isSyntheticTenant(tenantId)
      || typeof csrfToken !== 'string' || csrfToken.trim().length < 16
      || (operation === 'create' && !canMutateSyntheticSubscription(
        owner, syntheticOnly, tenantId, csrfToken, chosen, availablePlans,
      ))) {
      return;
    }

    inFlight.current = true;
    const controller = new AbortController();
    pending.current = controller;
    setStatus('saving');
    try {
      const endpoint = '/adminpl0n3r/api/tenants/'
        + encodeURIComponent(tenantId)
        + '/commercial-subscription'
        + (operation === 'trial' ? '/trial' : '');
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        body: JSON.stringify(operation === 'trial' ? {} : { plan_version_id: chosen?.id }),
        signal: controller.signal,
      });
      if (controller.signal.aborted) return;
      if (!response.ok) {
        // No HTTP response, including 403/409/422/5xx, can fabricate success.
        setStatus('unknown');
        return;
      }
      setStatus('verifying');
      // Server is authoritative for permissions, transition, audit and storage.
      if (onChanged) await onChanged();
      if (controller.signal.aborted) return;
      setRevision((current) => current + 1);
      setSelectedPlan('');
      setStatus('idle');
    } catch {
      if (!controller.signal.aborted) setStatus('unknown');
    } finally {
      if (pending.current === controller) {
        pending.current = null;
        inFlight.current = false;
      }
    }
  }

  return (
    <section className="platform-section" aria-labelledby="synthetic-subscriptions-title">
      <div className="section-heading compact">
        <div>
          <span className="eyebrow">SaaS Control Center · datos sintéticos</span>
          <h2 id="synthetic-subscriptions-title">Suscripción de empresa</h2>
        </div>
        <span className="status-pill">Gestión manual</span>
      </div>
      <p>Tenant de prueba: <code>{tenantId}</code>. Los precios y límites proceden solo de la API.</p>
      <SaasControlCenterReadPanel key={tenantId + ':' + revision} tenantId={tenantId} />
      <div className="platform-form">
        <label className="field">
          <span>PlanVersion del catálogo sintético</span>
          <select value={selectedPlan}
            onChange={(e: { target: { value: string } }) => setSelectedPlan(e.target.value)}
            disabled={status === 'saving' || status === 'verifying'}>
            <option value="">Seleccionar plan canónico</option>
            {availablePlans.map((plan) => (
              <option value={plan.id} key={plan.id}>{plan.name}</option>
            ))}
          </select>
        </label>
        <div className="platform-form-actions">
          <button type="button" className="button" disabled={!canCreate}
            onClick={() => void submit('create')}>
            Crear suscripción manual
          </button>
          <button type="button" className="button button-secondary" disabled={!canTrial}
            onClick={() => void submit('trial')}>
            Iniciar trial de prueba
          </button>
        </div>
      </div>
      {status === 'saving' && <output>Enviando al servidor autorizado…</output>}
      {status === 'verifying' && <output>Actualizando lectura canónica; auditoría a cargo del servidor…</output>}
      {status === 'unknown' && (
        <div role="alert">
          <p>UNKNOWN: no se confirmó el cambio; verifica auditoría y estado servidor.</p>
          <button type="button" className="button button-secondary"
            onClick={() => { setStatus('idle'); setRevision((x) => x + 1); }}>
            Releer datos canónicos
          </button>
        </div>
      )}
    </section>
  );
}
