import { type FormEvent, useEffect, useRef, useState } from 'react';

/**
 * Portal de suscripción de la empresa. Slice de interfaz, exclusivamente
 * sintético: la API comercial existente es la autoridad de precios, planes,
 * derechos, permisos y auditoría. No crea ni persiste un catálogo paralelo.
 *
 * Los adaptadores se inyectan para no inventar una ruta HTTP/contrato de cobro
 * mientras la API tenant-safe correspondiente siga siendo independiente.
 */
export type SubscriptionPortalApi = Readonly<{
  load: (tenantId: string, signal: AbortSignal) => Promise<unknown>;
  requestPlanChange: (
    request: Readonly<{ tenantId: string; planKey: string; csrfToken: string }>,
    signal: AbortSignal,
  ) => Promise<unknown>;
}>;

type Money = number | null;

type CommercialSnapshot = {
  classification: 'synthetic';
  source: 'commercial-catalog';
  tenant_id: string;
  plan: { key: string; version: string; currency: 'COP'; monthly_minor: Money };
  usage: Array<{ key: string; used: number; limit: number | null }>;
  add_ons: Array<{ key: string; active: boolean; monthly_minor: Money }>;
  entitlements: Array<{ key: string; allowed: boolean }>;
  renewal_at: string | null;
  permissions: { can_request_plan_change: boolean };
  allowed_plan_changes: Array<{ key: string; monthly_minor: Money }>;
};

type PortalState =
  | { status: 'loading' }
  | { status: 'ready'; snapshot: CommercialSnapshot }
  | { status: 'empty' }
  | { status: 'denied' }
  | { status: 'error' };

type Submission =
  | { status: 'idle' }
  | { status: 'sending' }
  | { status: 'success'; auditId: string }
  | { status: 'error'; message: string };

const TENANT_ID = /^[0-9A-HJKMNP-TV-Z]{26}$/;
const KEY = /^[a-z][a-z0-9_-]{0,63}$/;
const VERSION = /^[0-9]+(?:\.[0-9]+){1,3}$/;
const AUDIT_ID = /^SYN-AUDIT-[A-Z0-9]{4,40}$/;
const SYNTHETIC_RENEWAL = /^20\d{2}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01])$/;

function record(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function exactKeys(value: Record<string, unknown>, keys: string[]): boolean {
  return Object.keys(value).length === keys.length
    && Object.keys(value).every((key) => keys.includes(key));
}

function safeKey(value: unknown): value is string {
  return typeof value === 'string' && KEY.test(value);
}

function nonNegativeInt(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
}

function moneyValue(value: unknown): value is Money {
  return value === null || nonNegativeInt(value);
}

function rows(value: unknown, max: number, valid: (item: unknown) => boolean): boolean {
  if (!Array.isArray(value) || value.length > max) return false;
  const seen = new Set<string>();
  return value.every((item: unknown) => {
    if (!valid(item) || !record(item) || !safeKey(item.key) || seen.has(item.key)) {
      return false;
    }
    seen.add(item.key);
    return true;
  });
}

function validRenewal(value: unknown): boolean {
  if (value === null) return true;
  if (typeof value !== 'string' || !SYNTHETIC_RENEWAL.test(value)) return false;
  const date = new Date(value + 'T00:00:00Z');
  return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === value;
}

/**
 * Rechaza cualquier dato no clasificado como sintético, campos no declarados,
 * tenant distinto, cifras ambiguas o procedencia comercial no canónica.
 * Identidades personales, direcciones, correos y NIT no forman parte del modelo.
 */
export function validSubscriptionSnapshot(
  value: unknown,
  tenantId: string,
): value is CommercialSnapshot {
  if (!TENANT_ID.test(tenantId) || !record(value)
      || !exactKeys(value, [
        'classification', 'source', 'tenant_id', 'plan', 'usage',
        'add_ons', 'entitlements', 'renewal_at', 'permissions',
        'allowed_plan_changes',
      ])
      || value.classification !== 'synthetic'
      || value.source !== 'commercial-catalog'
      || value.tenant_id !== tenantId
      || !record(value.plan)
      || !exactKeys(value.plan, ['key', 'version', 'currency', 'monthly_minor'])
      || !safeKey(value.plan.key)
      || typeof value.plan.version !== 'string'
      || !VERSION.test(value.plan.version)
      || value.plan.currency !== 'COP'
      || !moneyValue(value.plan.monthly_minor)
      || !record(value.permissions)
      || !exactKeys(value.permissions, ['can_request_plan_change'])
      || typeof value.permissions.can_request_plan_change !== 'boolean'
      || !validRenewal(value.renewal_at)) {
    return false;
  }

  return rows(value.usage, 64, (item) => record(item)
    && exactKeys(item, ['key', 'used', 'limit'])
    && safeKey(item.key)
    && nonNegativeInt(item.used)
    && (item.limit === null || nonNegativeInt(item.limit)))
    && rows(value.add_ons, 64, (item) => record(item)
      && exactKeys(item, ['key', 'active', 'monthly_minor'])
      && safeKey(item.key)
      && typeof item.active === 'boolean'
      && moneyValue(item.monthly_minor))
    && rows(value.entitlements, 64, (item) => record(item)
      && exactKeys(item, ['key', 'allowed'])
      && safeKey(item.key)
      && typeof item.allowed === 'boolean')
    && rows(value.allowed_plan_changes, 32, (item) => record(item)
      && exactKeys(item, ['key', 'monthly_minor'])
      && safeKey(item.key)
      && moneyValue(item.monthly_minor));
}

function validAuditReceipt(value: unknown, tenantId: string, planKey: string): value is {
  classification: 'synthetic'; tenant_id: string; plan_key: string; audit_id: string;
} {
  return record(value) && exactKeys(value, [
    'classification', 'tenant_id', 'plan_key', 'audit_id',
  ]) && value.classification === 'synthetic'
    && value.tenant_id === tenantId && value.plan_key === planKey
    && typeof value.audit_id === 'string' && AUDIT_ID.test(value.audit_id);
}

function price(minor: Money): string {
  if (minor === null) return 'UNKNOWN';
  return new Intl.NumberFormat('es-CO', {
    style: 'currency', currency: 'COP', maximumFractionDigits: 0,
  }).format(minor / 100);
}

type Props = Readonly<{
  tenantId: string;
  csrfToken: string;
  api: SubscriptionPortalApi;
}>;

export function SubscriptionPortal({ tenantId, csrfToken, api }: Props) {
  const [state, setState] = useState<PortalState>({ status: 'loading' });
  const [submission, setSubmission] = useState<Submission>({ status: 'idle' });
  const [selectedPlan, setSelectedPlan] = useState('');
  const [reload, setReload] = useState(0);
  const inFlight = useRef<string | null>(null);
  const currentTenant = useRef(tenantId);
  currentTenant.current = tenantId;

  useEffect(() => {
    const controller = new AbortController();
    setState({ status: 'loading' });
    setSelectedPlan('');
    setSubmission({ status: 'idle' });
    inFlight.current = null;

    if (!TENANT_ID.test(tenantId)) {
      setState({ status: 'error' });
      return () => controller.abort();
    }

    void api.load(tenantId, controller.signal).then((payload) => {
      if (controller.signal.aborted || currentTenant.current !== tenantId) return;
      if (record(payload) && exactKeys(payload, ['classification', 'tenant_id', 'state'])
          && payload.classification === 'synthetic'
          && payload.tenant_id === tenantId && payload.state === 'empty') {
        setState({ status: 'empty' });
      } else if (validSubscriptionSnapshot(payload, tenantId)) {
        setState({ status: 'ready', snapshot: payload });
      } else {
        setState({ status: 'error' });
      }
    }).catch((error: unknown) => {
      if (controller.signal.aborted || currentTenant.current !== tenantId) return;
      // Only a canonical 403 authorization failure may be shown as denied.
      const status = record(error) ? error.status : null;
      setState({ status: status === 403 ? 'denied' : 'error' });
    });
    return () => controller.abort();
  }, [api, tenantId, reload]);

  // An obsolete tenant response must never render even for a single React frame.
  const snapshot = state.status === 'ready' && state.snapshot.tenant_id === tenantId
    ? state.snapshot : null;
  const canRequest = snapshot?.permissions.can_request_plan_change === true;
  const available = snapshot?.allowed_plan_changes ?? [];

  async function requestChange(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (inFlight.current !== null || snapshot === null
        || !canRequest || !csrfToken
        || currentTenant.current !== tenantId
        || !available.some((option) => option.key === selectedPlan)) {
      setSubmission({ status: 'error', message: 'Solicitud no autorizada o incompleta.' });
      return;
    }

    const requestedTenant = tenantId;
    const requestedPlan = selectedPlan;
    inFlight.current = requestedTenant;
    setSubmission({ status: 'sending' });
    const controller = new AbortController();
    try {
      // Solo se solicita el cambio. El servidor valida permisos, datos y audita.
      // Nunca se cambia el plan localmente ni se marca éxito sin recibo auditable.
      const receipt: unknown = await api.requestPlanChange({
        tenantId: requestedTenant, planKey: requestedPlan, csrfToken,
      }, controller.signal);
      if (currentTenant.current !== requestedTenant) return;
      if (!validAuditReceipt(receipt, requestedTenant, requestedPlan)) {
        setSubmission({ status: 'error', message: 'Auditoría no verificable: UNKNOWN.' });
        return;
      }
      setSubmission({ status: 'success', auditId: receipt.audit_id });
    } catch (error: unknown) {
      if (currentTenant.current !== requestedTenant) return;
      const status = record(error) ? error.status : null;
      setSubmission({
        status: 'error',
        message: status === 403
          ? 'No tienes permiso para solicitar este cambio.'
          : 'No se pudo verificar la solicitud. Estado: UNKNOWN.',
      });
    } finally {
      if (inFlight.current === requestedTenant) inFlight.current = null;
    }
  }

  return (
    <section className="platform-section" aria-labelledby="subscription-portal-title">
      <div className="section-heading compact">
        <div>
          <span className="eyebrow">Administración de la empresa</span>
          <h2 id="subscription-portal-title">Mi suscripción</h2>
        </div>
        <span className="status-pill">Datos de demostración</span>
      </div>

      {state.status === 'loading' && <output aria-live="polite">Consultando suscripción…</output>}
      {state.status === 'denied' && <p role="alert">No tienes permiso para ver esta suscripción.</p>}
      {state.status === 'empty' && (
        <p role="status">No hay suscripción verificada. Plan y renovación: UNKNOWN.</p>
      )}
      {state.status === 'error' && (
        <div role="alert">
          <p>Datos no verificables. Por seguridad, la suscripción se muestra como UNKNOWN.</p>
          <button className="button" type="button" onClick={() => setReload((n) => n + 1)}>
            Reintentar
          </button>
        </div>
      )}

      {snapshot && (
        <>
          <div className="tenant-grid">
            <article className="tenant-card">
              <h3>Plan actual</h3>
              <dl>
                <div><dt>Plan</dt><dd>{snapshot.plan.key}</dd></div>
                <div><dt>Versión</dt><dd>{snapshot.plan.version}</dd></div>
                <div><dt>Valor mensual estimado</dt><dd>{price(snapshot.plan.monthly_minor)}</dd></div>
                <div><dt>Próxima renovación</dt><dd>{snapshot.renewal_at ?? 'UNKNOWN'}</dd></div>
              </dl>
            </article>
            <article className="tenant-card">
              <h3>Consumo y límites</h3>
              {snapshot.usage.length === 0 ? <p>Consumo: UNKNOWN.</p> : (
                <dl>{snapshot.usage.map((item) => (
                  <div key={item.key}>
                    <dt>{item.key}</dt>
                    <dd>{item.used} / {item.limit === null ? 'UNKNOWN' : item.limit}</dd>
                  </div>
                ))}</dl>
              )}
            </article>
            <article className="tenant-card">
              <h3>Add-ons</h3>
              {snapshot.add_ons.length === 0 ? <p>Sin add-ons verificados.</p> : (
                <ul>{snapshot.add_ons.map((item) => (
                  <li key={item.key}>{item.key}: {item.active ? 'activo' : 'inactivo'}
                    {' · '}{price(item.monthly_minor)}</li>
                ))}</ul>
              )}
            </article>
            <article className="tenant-card">
              <h3>Capacidades del plan</h3>
              {snapshot.entitlements.length === 0 ? <p>Capacidades: UNKNOWN.</p> : (
                <ul>{snapshot.entitlements.map((item) => (
                  <li key={item.key}>{item.key}: {item.allowed ? 'habilitada' : 'no habilitada'}</li>
                ))}</ul>
              )}
            </article>
          </div>

          <section aria-labelledby="plan-request-title">
            <h3 id="plan-request-title">Solicitar cambio de plan</h3>
            <p>La solicitud requiere permiso y auditoría del servidor. No se realizan cobros.</p>
            {!canRequest && <p role="status">No tienes permiso para solicitar cambios.</p>}
            {canRequest && available.length === 0 && <p>Opciones verificadas: UNKNOWN.</p>}
            {canRequest && available.length > 0 && (
              <form onSubmit={(event) => { void requestChange(event); }}>
                <label htmlFor="subscription-plan-choice">Plan disponible</label>
                <select id="subscription-plan-choice" value={selectedPlan}
                  onChange={(event) => setSelectedPlan(event.target.value)}
                  disabled={submission.status === 'sending'}>
                  <option value="">Selecciona un plan</option>
                  {available.map((item) => (
                    <option key={item.key} value={item.key}>
                      {item.key} · {price(item.monthly_minor)}
                    </option>
                  ))}
                </select>
                <button className="button" type="submit"
                  disabled={!selectedPlan || !csrfToken || submission.status === 'sending'}>
                  {submission.status === 'sending' ? 'Enviando…' : 'Enviar solicitud'}
                </button>
              </form>
            )}
            {submission.status === 'error' && <p role="alert">{submission.message}</p>}
            {submission.status === 'success' && (
              <p role="status">Solicitud recibida y auditada: {submission.auditId}. La suscripción todavía no cambió.</p>
            )}
          </section>
        </>
      )}
    </section>
  );
}
