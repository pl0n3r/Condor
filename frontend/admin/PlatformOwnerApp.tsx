import { useEffect, useRef, useState } from 'react';
import { AdminShell } from './AdminShell';
import { OverviewGrid } from './OverviewGrid';
import {
  FunctionalSignalsPanel,
  type FunctionalSignalCounts,
} from './FunctionalSignalsPanel';
import { PlatformStaffPanel } from './PlatformStaffPanel';
import { PlatformTenantCreationPanel } from './PlatformTenantCreationPanel';
import { platformOwnerContextPath } from './api';

type CommercialPlan = {
  plan_version_id: string;
  key: string;
  name: string;
  version: number;
  currency: string;
  monthly_amount: number | null;
  annual_amount: number | null;
  quote_required: boolean;
};

type CommercialSubscriptionOverview =
  | {
      status: 'not_configured';
      subscription: null;
    }
  | {
      status: 'configured';
      subscription: {
        state: string;
        plan: {
          key: string;
          name: string;
          version: number;
          currency: string;
          monthly_amount: number | null;
          annual_amount: number | null;
          quote_required: boolean;
        };
        last_changed_at: string;
        trial_started_at?: string;
        trial_ends_at?: string;
      };
    };

type CommercialSubscriptionChange = {
  id: string;
  direction: 'upgrade' | 'downgrade';
  status: 'effective' | 'scheduled' | 'pending_resolution';
  current_plan: { key: string; name: string; version: number };
  target_plan: { key: string; name: string; version: number };
  requested_at: string;
  effective_at: string | null;
  blockers: string[];
};

type TenantSummary = {
  id: string;
  name: string;
  slug: string;
  branch_count: number;
  active_membership_count: number;
};

type SelectedTenant = TenantSummary & {
  branches: Array<{
    id: string;
    name: string;
    slug: string;
    is_default: boolean;
  }>;
  catalog: Array<{
    id: string;
    name: string;
    slug: string;
    description: string | null;
    variants: Array<{
      id: string;
      sku: string;
      name: string;
    }>;
  }>;
  commercial_subscription: CommercialSubscriptionOverview;
  commercial_subscription_changes: CommercialSubscriptionChange[];
};

type PlatformContextResponse = {
  mode: 'global' | 'tenant';
  actor: {
    id: string;
    name: string;
    role: 'platform_owner';
  };
  metrics: {
    tenant_count: number;
    user_count: number;
    branch_count: number;
    active_membership_count: number;
  };
  signals_last_30_days: FunctionalSignalCounts;
  commercial_catalog: CommercialPlan[];
  tenants: TenantSummary[];
  tenant_pagination: {
    page: number;
    per_page: number;
    total: number;
    has_previous: boolean;
    has_next: boolean;
  };
  selected_tenant: SelectedTenant | null;
  version: string;
};

type OwnerDiagnostic = {
  incident_id: string;
  request_id: string;
  status: number;
  route: string;
  exception: string;
  message: string;
  version: string;
  release_sha: string;
};

type InternalErrorPayload = {
  error_id: string;
  diagnostic?: OwnerDiagnostic;
};

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function isNonEmptyString(value: unknown): value is string {
  return typeof value === 'string' && value.trim() !== '';
}

function isOwnerDiagnostic(value: unknown): value is OwnerDiagnostic {
  if (!isRecord(value)) {
    return false;
  }

  return (
    isNonEmptyString(value.incident_id) &&
    isNonEmptyString(value.request_id) &&
    typeof value.status === 'number' &&
    Number.isInteger(value.status) &&
    value.status >= 500 &&
    value.status <= 599 &&
    isNonEmptyString(value.route) &&
    isNonEmptyString(value.exception) &&
    typeof value.message === 'string' &&
    isNonEmptyString(value.version) &&
    isNonEmptyString(value.release_sha)
  );
}

function parseInternalErrorPayload(value: unknown): InternalErrorPayload | null {
  if (!isRecord(value) || !isNonEmptyString(value.error_id)) {
    return null;
  }

  if (value.diagnostic === undefined) {
    return { error_id: value.error_id };
  }

  if (
    !isOwnerDiagnostic(value.diagnostic) ||
    value.diagnostic.incident_id !== value.error_id
  ) {
    return { error_id: value.error_id };
  }

  return {
    error_id: value.error_id,
    diagnostic: value.diagnostic,
  };
}

function subscriptionSubmitLabel(
  status: 'idle' | 'saving' | 'error',
): string {
  return status === 'saving' ? 'Creando…' : 'Crear suscripción';
}

function commercialMoney(amount: number | null, currency: string): string {
  if (amount === null) {
    return 'Sin precio publicado';
  }

  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency,
    maximumFractionDigits: 0,
  }).format(amount);
}

type State =
  | { status: 'loading' }
  | { status: 'ready'; data: PlatformContextResponse }
  | { status: 'permission-denied' }
  | { status: 'not-found' }
  | {
      status: 'error';
      errorId?: string;
      diagnostic?: OwnerDiagnostic;
    };

type PlatformOwnerSection = 'control' | 'empresas' | 'staff';

function selectedTenantFromState(state: State): SelectedTenant | null {
  return state.status === 'ready' ? state.data.selected_tenant : null;
}

type PlatformOwnerAppProps = Readonly<{
  version: string;
  logoutToken: string;
  staffToken: string;
  tenantToken: string;
  subscriptionToken: string;
  section: PlatformOwnerSection;
}>;

export function PlatformOwnerApp({
  version,
  logoutToken,
  staffToken,
  tenantToken,
  subscriptionToken,
  section,
}: PlatformOwnerAppProps) {
  const [state, setState] = useState<State>({ status: 'loading' });
  const [platformRevision, setPlatformRevision] = useState(0);
  const [selectedPlanVersionId, setSelectedPlanVersionId] = useState('');
  const [subscriptionCreation, setSubscriptionCreation] = useState<
    { status: 'idle' | 'saving' } | { status: 'error'; message: string }
  >({ status: 'idle' });
  const [trialCreation, setTrialCreation] = useState<
    { status: 'idle' | 'saving' } | { status: 'error'; message: string }
  >({ status: 'idle' });
  const commercialCreationInFlight = useRef(false);
  const requestSequence = useRef(0);
  const globalPage = useRef(1);

  function beginContextRequest(showLoading: boolean): number {
    const requestId = ++requestSequence.current;
    if (showLoading) {
      setState({ status: 'loading' });
    }

    return requestId;
  }

  async function loadContext(
    tenantId?: string,
    page = 1,
    showLoading = true,
  ) {
    const requestId = beginContextRequest(showLoading);

    try {
      const response = await fetch(platformOwnerContextPath(tenantId, page), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });

      if (requestId !== requestSequence.current) {
        return;
      }

      if (response.status === 403) {
        setState({ status: 'permission-denied' });
        return;
      }

      if (response.status === 404) {
        setState({ status: 'not-found' });
        return;
      }

      if (response.status >= 500) {
        let payload: InternalErrorPayload | null = null;
        try {
          const rawPayload: unknown = await response.json();
          payload = parseInternalErrorPayload(rawPayload);
        } catch {
          payload = null;
        }

        if (requestId === requestSequence.current) {
          setState({
            status: 'error',
            errorId: payload?.error_id,
            diagnostic: payload?.diagnostic,
          });
        }
        return;
      }

      if (!response.ok) {
        throw new Error('No fue posible cargar el centro de control');
      }

      const data = await response.json() as PlatformContextResponse;
      if (requestId === requestSequence.current) {
        if (data.mode === 'global') {
          globalPage.current = data.tenant_pagination.page;
        }
        setState({ status: 'ready', data });
      }
    } catch {
      if (requestId === requestSequence.current) {
        setState({ status: 'error' });
      }
    }
  }

  useEffect(() => {
    void loadContext();
  }, []);

  const selected = selectedTenantFromState(state);

  useEffect(() => {
    setSelectedPlanVersionId('');
    setSubscriptionCreation({ status: 'idle' });
    setTrialCreation({ status: 'idle' });
  }, [selected?.id]);

  async function createInitialSubscription() {
    if (
      state.status !== 'ready'
      || selected?.commercial_subscription.status !== 'not_configured'
      || selectedPlanVersionId === ''
      || trialCreation.status === 'saving'
      || commercialCreationInFlight.current
    ) {
      return;
    }

    commercialCreationInFlight.current = true;
    setTrialCreation({ status: 'idle' });
    setSubscriptionCreation({ status: 'saving' });
    try {
      const response = await fetch(
        '/adminpl0n3r/api/tenants/'
          + encodeURIComponent(selected.id)
          + '/commercial-subscription',
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-Token': subscriptionToken,
          },
          body: JSON.stringify({
            plan_version_id: selectedPlanVersionId,
          }),
        },
      );

      if (!response.ok) {
        setSubscriptionCreation({
          status: 'error',
          message: response.status === 403
            ? 'Tu sesión no puede crear esta suscripción.'
            : 'No fue posible crear la suscripción con ese plan vigente.',
        });
        return;
      }

      setSelectedPlanVersionId('');
      setSubscriptionCreation({ status: 'idle' });
      await loadContext(
        selected.id,
        state.data.tenant_pagination.page,
        false,
      );
    } catch {
      setSubscriptionCreation({
        status: 'error',
        message: 'No fue posible completar la creación.',
      });
    } finally {
      commercialCreationInFlight.current = false;
    }
  }

  async function startBusinessTrial() {
    if (
      state.status !== 'ready'
      || selected?.commercial_subscription.status !== 'not_configured'
      || subscriptionCreation.status === 'saving'
      || commercialCreationInFlight.current
    ) {
      return;
    }

    commercialCreationInFlight.current = true;
    setSubscriptionCreation({ status: 'idle' });
    setTrialCreation({ status: 'saving' });
    try {
      const response = await fetch(
        '/adminpl0n3r/api/tenants/'
          + encodeURIComponent(selected.id)
          + '/commercial-subscription/trial',
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-Token': subscriptionToken,
          },
          body: JSON.stringify({}),
        },
      );

      if (!response.ok) {
        setTrialCreation({
          status: 'error',
          message: response.status === 403
            ? 'Tu sesión no puede iniciar este trial.'
            : response.status === 422
              ? 'No fue posible iniciar el trial para esta empresa.'
              : 'No fue posible iniciar el trial.',
        });
        return;
      }

      setSelectedPlanVersionId('');
      setTrialCreation({ status: 'idle' });
      await loadContext(
        selected.id,
        state.data.tenant_pagination.page,
        false,
      );
    } catch {
      setTrialCreation({
        status: 'error',
        message: 'No fue posible completar el inicio del trial.',
      });
    } finally {
      commercialCreationInFlight.current = false;
    }
  }

  return (
    <AdminShell
      version={version}
      logoutToken={logoutToken}
      ariaLabel="Centro de control de Condor"
      navLabel="Navegación de plataforma"
      variant="platform"
      navItems={[
        {
          href: '/adminpl0n3r',
          label: 'Centro de control',
          current: section === 'control',
        },
        {
          href: '/adminpl0n3r/empresas',
          label: 'Empresas',
          current: section === 'empresas',
        },
        {
          href: '/adminpl0n3r/staff',
          label: 'Staff',
          current: section === 'staff',
        },
        {
          href: '/adminpl0n3r/diagnosticos',
          label: 'Diagnósticos',
        },
      ]}
    >
      {selected && (
        <output className="platform-context-bar" aria-live="polite">
          <span className="platform-context-copy">
            <strong>Modo propietario · Contexto de empresa</strong>
            <span>{selected.name}</span>
          </span>
          <button
            className="button button-context"
            type="button"
            onClick={() => void loadContext(undefined, globalPage.current)}
          >
            Volver al centro global
          </button>
        </output>
      )}

      <span className="eyebrow">Propietario de plataforma</span>

      {state.status === 'loading' && (
        <>
          <h1>Preparando centro de control…</h1>
          <output className="muted" aria-live="polite">
            Cargando estado real de Condor.
          </output>
        </>
      )}

      {state.status === 'permission-denied' && (
        <div className="alert alert-error" role="alert">
          <strong>Acceso no autorizado.</strong>{' '}
          Tu sesión no tiene permisos para consultar este contexto de
          plataforma.
        </div>
      )}

      {state.status === 'not-found' && (
        <div className="alert alert-error" role="alert">
          <strong>Empresa no encontrada.</strong>{' '}
          El tenant seleccionado ya no existe o dejó de estar disponible.
        </div>
      )}

      {state.status === 'error' && (
        <div className="alert alert-error platform-error" role="alert">
          <strong>No pudimos cargar el centro de control.</strong>
          <span>
            El incidente quedó registrado de forma segura.
            {state.errorId && (
              <> Referencia: <code>{state.errorId}</code>.</>
            )}
          </span>

          {state.diagnostic && (
            <div className="platform-error-diagnostic">
              <p>
                Detalle sanitizado visible solo para el propietario de
                plataforma.
              </p>
              <dl>
                <div>
                  <dt>HTTP</dt>
                  <dd>{state.diagnostic.status}</dd>
                </div>
                <div>
                  <dt>Ruta</dt>
                  <dd><code>{state.diagnostic.route}</code></dd>
                </div>
                <div>
                  <dt>Excepción</dt>
                  <dd><code>{state.diagnostic.exception}</code></dd>
                </div>
                <div>
                  <dt>Mensaje</dt>
                  <dd><code>{state.diagnostic.message}</code></dd>
                </div>
                <div>
                  <dt>Request ID</dt>
                  <dd><code>{state.diagnostic.request_id}</code></dd>
                </div>
                <div>
                  <dt>Versión</dt>
                  <dd>{state.diagnostic.version}</dd>
                </div>
                <div>
                  <dt>Release SHA</dt>
                  <dd><code>{state.diagnostic.release_sha}</code></dd>
                </div>
              </dl>
              <a
                className="button button-secondary platform-error-link"
                href="/adminpl0n3r/diagnosticos"
              >
                Abrir diagnósticos
              </a>
            </div>
          )}
        </div>
      )}

      {state.status === 'ready' && (
        <>
          <div className="platform-heading">
            <div>
              <span className="control-kicker">CONTROL CENTER</span>
              <h1>
                {selected ? selected.name : 'Administración global'}
              </h1>
              <p className="muted">
                {selected
                  ? 'Vista privilegiada del tenant sin cambiar tu identidad ni suplantar usuarios.'
                  : 'Visibilidad global de empresas, usuarios, sedes y operación de la plataforma.'}
              </p>
            </div>
            <div className="actor-chip" aria-label="Identidad activa">
              <span>Actor real</span>
              <strong>{state.data.actor.name}</strong>
            </div>
          </div>

          {!selected && section === 'control' && (
            <>
              <OverviewGrid
                ariaLabel="Métricas globales de Condor"
                variant="control"
                items={[
                  {
                    label: 'Empresas',
                    value: state.data.metrics.tenant_count,
                    detail: 'tenants registrados',
                  },
                  {
                    label: 'Usuarios',
                    value: state.data.metrics.user_count,
                    detail: 'identidades de Condor',
                  },
                  {
                    label: 'Sedes',
                    value: state.data.metrics.branch_count,
                    detail: 'puntos operativos',
                  },
                  {
                    label: 'Membresías activas',
                    value: state.data.metrics.active_membership_count,
                    detail: 'accesos vigentes',
                  },
                ]}
              />

              <FunctionalSignalsPanel
                signals={state.data.signals_last_30_days}
              />

              <section
                className="platform-section"
                aria-labelledby="commercial-catalog-title"
              >
                <div className="section-heading compact">
                  <div>
                    <span className="eyebrow">Oferta comercial</span>
                    <h2 id="commercial-catalog-title">Catálogo vigente</h2>
                  </div>
                  <span className="status-pill">Solo lectura</span>
                </div>

                {state.data.commercial_catalog.length === 0 ? (
                  <div className="platform-empty">
                    No hay planes comerciales vigentes.
                  </div>
                ) : (
                  <div className="tenant-grid">
                    {state.data.commercial_catalog.map((plan) => (
                      <article className="tenant-card" key={plan.key}>
                        <div>
                          <span className="tenant-slug">
                            {plan.key} · v{plan.version}
                          </span>
                          <h3>{plan.name}</h3>
                        </div>
                        {plan.quote_required ? (
                          <p><strong>Propuesta personalizada</strong></p>
                        ) : (
                          <dl>
                            <div>
                              <dt>Mensual</dt>
                              <dd>{commercialMoney(plan.monthly_amount, plan.currency)}</dd>
                            </div>
                            <div>
                              <dt>Anual</dt>
                              <dd>{commercialMoney(plan.annual_amount, plan.currency)}</dd>
                            </div>
                          </dl>
                        )}
                      </article>
                    ))}
                  </div>
                )}
              </section>
            </>
          )}

          {!selected && section === 'empresas' && (
            <>
              <PlatformTenantCreationPanel
                csrfToken={tenantToken}
                onCreated={() => {
                  setPlatformRevision((current) => current + 1);
                  void loadContext(undefined, 1, false);
                }}
              />

              <section className="platform-section" aria-labelledby="tenant-list-title">
                <div className="section-heading compact">
                  <div>
                    <span className="eyebrow">Empresas</span>
                    <h2 id="tenant-list-title">Clientes de la plataforma</h2>
                  </div>
                  <span className="muted">
                    {state.data.tenants.length} de{' '}
                    {state.data.tenant_pagination.total} registradas
                  </span>
                </div>

                {state.data.tenants.length === 0 ? (
                  <div className="platform-empty">
                    Aún no hay empresas registradas.
                  </div>
                ) : (
                  <div className="tenant-grid">
                    {state.data.tenants.map((tenant) => (
                      <article className="tenant-card" key={tenant.id}>
                        <div>
                          <span className="tenant-slug">{tenant.slug}</span>
                          <h3>{tenant.name}</h3>
                        </div>
                        <dl>
                          <div>
                            <dt>Sedes</dt>
                            <dd>{tenant.branch_count}</dd>
                          </div>
                          <div>
                            <dt>Membresías activas</dt>
                            <dd>{tenant.active_membership_count}</dd>
                          </div>
                        </dl>
                        <button
                          className="button button-secondary tenant-open"
                          type="button"
                          onClick={() => (
                            void loadContext(
                              tenant.id,
                              state.data.tenant_pagination.page,
                            )
                          )}
                        >
                          Ver como propietario
                        </button>
                      </article>
                    ))}
                  </div>
                )}

                {state.data.tenant_pagination.total > 0 && (
                  <nav
                    className="tenant-pagination"
                    aria-label="Paginación de empresas"
                  >
                    <button
                      className="button button-secondary"
                      type="button"
                      disabled={
                        !state.data.tenant_pagination.has_previous
                      }
                      onClick={() => (
                        void loadContext(
                          undefined,
                          state.data.tenant_pagination.page - 1,
                        )
                      )}
                    >
                      Anterior
                    </button>
                    <span className="muted">
                      Página {state.data.tenant_pagination.page}
                    </span>
                    <button
                      className="button button-secondary"
                      type="button"
                      disabled={!state.data.tenant_pagination.has_next}
                      onClick={() => (
                        void loadContext(
                          undefined,
                          state.data.tenant_pagination.page + 1,
                        )
                      )}
                    >
                      Siguiente
                    </button>
                  </nav>
                )}
              </section>
            </>
          )}

          {!selected && section === 'staff' && (
            <PlatformStaffPanel
              csrfToken={staffToken}
              refreshKey={platformRevision}
            />
          )}

          {selected && (
            <>
              <OverviewGrid
                ariaLabel="Resumen de empresa seleccionada"
                variant="control"
                items={[
                  {
                    label: 'Tenant',
                    value: selected.slug,
                    detail: 'contexto explícito de plataforma',
                  },
                  {
                    label: 'Sedes',
                    value: selected.branch_count,
                  },
                  {
                    label: 'Membresías activas',
                    value: selected.active_membership_count,
                  },
                  {
                    label: 'Modo',
                    value: 'Gestión manual',
                    detail: 'creación inicial controlada',
                  },
                ]}
              />

              <section
                className="platform-section"
                aria-labelledby="commercial-subscription-title"
              >
                <div className="section-heading compact">
                  <div>
                    <span className="eyebrow">SaaS Control Center</span>
                    <h2 id="commercial-subscription-title">
                      Suscripción comercial
                    </h2>
                  </div>
                  <span className="status-pill">Gestión manual</span>
                </div>

                {selected.commercial_subscription.status === 'not_configured' ? (
                  <form
                    className="platform-form"
                    onSubmit={(event) => {
                      event.preventDefault();
                      void createInitialSubscription();
                    }}
                  >
                    <div>
                      <strong>Crear suscripción inicial</strong>
                      <p className="muted">
                        Sin suscripción configurada. Selecciona una PlanVersion
                        para activación manual o inicia el Trial Negocio.
                        El servidor define tenant, estado y timestamps.
                      </p>
                    </div>

                    {state.data.commercial_catalog.length === 0 ? (
                      <div className="platform-empty">
                        No hay PlanVersion vigentes disponibles.
                      </div>
                    ) : (
                      <label className="field">
                        <span>Plan vigente</span>
                        <select
                          value={selectedPlanVersionId}
                          onChange={(event) => {
                            setSelectedPlanVersionId(event.target.value);
                            setSubscriptionCreation({ status: 'idle' });
                          }}
                          disabled={
                            subscriptionCreation.status === 'saving'
                            || trialCreation.status === 'saving'
                          }
                          required
                        >
                          <option value="">Selecciona un plan</option>
                          {state.data.commercial_catalog.map((plan) => (
                            <option
                              key={plan.plan_version_id}
                              value={plan.plan_version_id}
                            >
                              {plan.name} · v{plan.version}
                            </option>
                          ))}
                        </select>
                      </label>
                    )}

                    <div className="platform-form-actions">
                      <button
                        className="button"
                        type="submit"
                        disabled={
                          selectedPlanVersionId === ''
                          || subscriptionCreation.status === 'saving'
                          || trialCreation.status === 'saving'
                        }
                      >
                        {subscriptionSubmitLabel(
                          subscriptionCreation.status,
                        )}
                      </button>
                      <button
                        className="button button-secondary"
                        type="button"
                        disabled={
                          subscriptionCreation.status === 'saving'
                          || trialCreation.status === 'saving'
                        }
                        onClick={() => void startBusinessTrial()}
                      >
                        {trialCreation.status === 'saving'
                          ? 'Iniciando trial…'
                          : 'Iniciar trial Negocio · 14 días'}
                      </button>
                      {subscriptionCreation.status === 'error' && (
                        <span className="muted" role="alert">
                          {subscriptionCreation.message}
                        </span>
                      )}
                      {trialCreation.status === 'error' && (
                        <span className="muted" role="alert">
                          {trialCreation.message}
                        </span>
                      )}
                    </div>
                  </form>
                ) : (
                  <div className="tenant-grid">
                    <article className="tenant-card">
                      <div>
                        <span className="tenant-slug">
                          {selected.commercial_subscription.subscription.plan.key}
                          {' · v'}
                          {selected.commercial_subscription.subscription.plan.version}
                        </span>
                        <h3>
                          {selected.commercial_subscription.subscription.plan.name}
                        </h3>
                      </div>
                      <dl>
                        <div>
                          <dt>Estado</dt>
                          <dd>
                            {selected.commercial_subscription.subscription.state
                              .replaceAll('_', ' ')}
                          </dd>
                        </div>
                        <div>
                          <dt>Mensual</dt>
                          <dd>
                            {selected.commercial_subscription.subscription.plan.quote_required
                              ? 'Propuesta personalizada'
                              : commercialMoney(
                                  selected.commercial_subscription.subscription.plan.monthly_amount,
                                  selected.commercial_subscription.subscription.plan.currency,
                                )}
                          </dd>
                        </div>
                        <div>
                          <dt>Anual</dt>
                          <dd>
                            {selected.commercial_subscription.subscription.plan.quote_required
                              ? 'Propuesta personalizada'
                              : commercialMoney(
                                  selected.commercial_subscription.subscription.plan.annual_amount,
                                  selected.commercial_subscription.subscription.plan.currency,
                                )}
                          </dd>
                        </div>
                        {selected.commercial_subscription.subscription.state === 'trialing'
                          && selected.commercial_subscription.subscription.trial_started_at
                          && selected.commercial_subscription.subscription.trial_ends_at && (
                            <>
                              <div>
                                <dt>Inicio trial</dt>
                                <dd>
                                  <code>
                                    {selected.commercial_subscription.subscription.trial_started_at}
                                  </code>
                                </dd>
                              </div>
                              <div>
                                <dt>Fin trial</dt>
                                <dd>
                                  <code>
                                    {selected.commercial_subscription.subscription.trial_ends_at}
                                  </code>
                                </dd>
                              </div>
                            </>
                          )}
                        <div>
                          <dt>Último cambio</dt>
                          <dd>
                            <code>
                              {selected.commercial_subscription.subscription.last_changed_at}
                            </code>
                          </dd>
                        </div>
                      </dl>
                    </article>
                  </div>
                )}
              </section>

              <section
                className="platform-section"
                aria-labelledby="commercial-change-history-title"
              >
                <div className="section-heading compact">
                  <div>
                    <span className="eyebrow">Auditoría comercial</span>
                    <h2 id="commercial-change-history-title">Historial de cambios</h2>
                  </div>
                  <span className="status-pill">Solo lectura</span>
                </div>

                {selected.commercial_subscription_changes.length === 0 ? (
                  <div className="platform-empty">
                    Sin cambios comerciales registrados.
                  </div>
                ) : (
                  <div className="tenant-grid">
                    {selected.commercial_subscription_changes.map((change) => (
                      <article className="tenant-card" key={change.id}>
                        <div>
                          <span className="tenant-slug">
                            {change.direction.replaceAll('_', ' ')} · {change.status.replaceAll('_', ' ')}
                          </span>
                          <h3>
                            {change.current_plan.name} → {change.target_plan.name}
                          </h3>
                        </div>
                        <dl>
                          <div>
                            <dt>Solicitado</dt>
                            <dd><code>{change.requested_at}</code></dd>
                          </div>
                          <div>
                            <dt>Efectivo</dt>
                            <dd>{change.effective_at ? <code>{change.effective_at}</code> : 'Pendiente'}</dd>
                          </div>
                          <div>
                            <dt>Bloqueos</dt>
                            <dd>{change.blockers.length > 0 ? change.blockers.join(', ') : 'Ninguno'}</dd>
                          </div>
                        </dl>
                      </article>
                    ))}
                  </div>
                )}
              </section>

              <section className="platform-section" aria-labelledby="branch-list-title">
                <div className="section-heading compact">
                  <div>
                    <span className="eyebrow">Contexto de empresa</span>
                    <h2 id="branch-list-title">Sedes visibles</h2>
                  </div>
                </div>

                {selected.branches.length === 0 ? (
                  <div className="platform-empty">
                    Esta empresa todavía no tiene sedes configuradas.
                  </div>
                ) : (
                  <div className="tenant-grid">
                    {selected.branches.map((branch) => (
                      <article className="tenant-card" key={branch.id}>
                        <div>
                          <span className="tenant-slug">{branch.slug}</span>
                          <h3>{branch.name}</h3>
                        </div>
                        <p className="muted">
                          {branch.is_default
                            ? 'Sede principal'
                            : 'Sede operativa'}
                        </p>
                      </article>
                    ))}
                  </div>
                )}

                <section
                  className="platform-section platform-section-nested"
                  aria-labelledby="platform-catalog-title"
                >
                  <div className="section-heading compact">
                    <div>
                      <span className="eyebrow">Catálogo</span>
                      <h2 id="platform-catalog-title">
                        Productos visibles
                      </h2>
                    </div>
                    <span className="status-pill">Solo lectura</span>
                  </div>

                  {selected.catalog.length === 0 ? (
                    <div className="platform-empty">
                      Esta empresa todavía no tiene productos activos.
                    </div>
                  ) : (
                    <div className="tenant-grid">
                      {selected.catalog.map((product) => (
                        <article className="tenant-card" key={product.id}>
                          <div>
                            <span className="tenant-slug">
                              {product.slug}
                            </span>
                            <h3>{product.name}</h3>
                            {product.description && (
                              <p className="muted">
                                {product.description}
                              </p>
                            )}
                          </div>
                          <div className="platform-catalog-variants">
                            <strong>
                              {product.variants.length === 1
                                ? '1 variante'
                                : `${product.variants.length} variantes`}
                            </strong>
                            {product.variants.map((variant) => (
                              <span key={variant.id}>
                                <code>{variant.sku}</code>
                                {' · '}
                                {variant.name}
                              </span>
                            ))}
                          </div>
                        </article>
                      ))}
                    </div>
                  )}
                </section>

                <div className="platform-readonly-note" role="note">
                  Este contexto sigue siendo mayormente de solo lectura.
                  La creación inicial de suscripción es la única acción
                  comercial habilitada; los demás módulos se incorporarán
                  cuando sus contratos server-side estén listos.
                </div>
              </section>
            </>
          )}
        </>
      )}
    </AdminShell>
  );
}
