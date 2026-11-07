import { useEffect, useRef, useState } from 'react';
import { AdminShell } from './AdminShell';
import { OverviewGrid } from './OverviewGrid';
import { Card, DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';
import { SystemHealthPanel } from './SystemHealthPanel';
import { SaasControlCenterReadPanel } from './SaasControlCenterReadPanel';
import {
  FunctionalSignalsPanel,
  type FunctionalSignalCounts,
} from './FunctionalSignalsPanel';
import { PlatformStaffPanel } from './PlatformStaffPanel';
import { PlatformTenantCreationPanel } from './PlatformTenantCreationPanel';
import { PlatformSubscriptionsPanel, isSyntheticTenant } from './PlatformSubscriptionsPanel';
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

type CommercialUsageRow = {
  tenant_id: string;
  metric: string;
  aggregation: string;
  quantity: number;
  window_start: string;
  window_end: string;
  observed_at: string;
};

type CommercialPortfolioItem = {
  tenant: {
    id: string;
    name: string;
    slug: string;
  };
  commercial_subscription: CommercialSubscriptionOverview;
  usage: CommercialUsageRow[];
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
  commercial_portfolio: CommercialPortfolioItem[];
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

function trialCreationErrorMessage(status: number): string {
  if (status === 403) {
    return 'Tu sesión no puede iniciar este trial.';
  }

  if (status === 422) {
    return 'No fue posible iniciar el trial para esta empresa.';
  }

  return 'No fue posible iniciar el trial.';
}

const SUBSCRIPTION_STATES = [
  'trialing',
  'active',
  'past_due',
  'grace_period',
  'suspended',
  'cancelled',
] as const;

type SubscriptionStateName = (typeof SUBSCRIPTION_STATES)[number];

function subscriptionStateErrorMessage(status: number): string {
  if (status === 403) {
    return 'Tu sesión no puede cambiar este estado.';
  }

  if (status === 422) {
    return 'El servidor rechazó esta transición de estado.';
  }

  return 'No fue posible cambiar el estado.';
}

type SubscriptionStateTransitionControlProps = Readonly<{
  tenantId: string;
  page: number;
  csrfToken: string;
  onRefresh: (
    tenantId: string,
    page: number,
    showLoading: boolean,
  ) => Promise<void>;
}>;

function SubscriptionStateTransitionControl({
  tenantId,
  page,
  csrfToken,
  onRefresh,
}: SubscriptionStateTransitionControlProps) {
  const [targetState, setTargetState] = useState<SubscriptionStateName | ''>('');
  const [transition, setTransition] = useState<
    { status: 'idle' | 'saving' } | { status: 'error'; message: string }
  >({ status: 'idle' });
  const inFlight = useRef(false);

  async function transitionSubscriptionState() {
    if (targetState === '' || inFlight.current) {
      return;
    }

    inFlight.current = true;
    setTransition({ status: 'saving' });
    try {
      const response = await fetch(
        '/adminpl0n3r/api/tenants/'
          + encodeURIComponent(tenantId)
          + '/commercial-subscription/state',
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
          },
          body: JSON.stringify({
            target_state: targetState,
          }),
        },
      );

      if (!response.ok) {
        setTransition({
          status: 'error',
          message: subscriptionStateErrorMessage(response.status),
        });
        return;
      }

      setTargetState('');
      setTransition({ status: 'idle' });
      await onRefresh(tenantId, page, false);
    } catch {
      setTransition({
        status: 'error',
        message: 'No fue posible completar el cambio de estado.',
      });
    } finally {
      inFlight.current = false;
    }
  }

  return (
    <form
      className="platform-form"
      onSubmit={(event) => {
        event.preventDefault();
        void transitionSubscriptionState();
      }}
    >
      <div>
        <strong>Cambiar estado</strong>
        <p className="muted">
          El servidor valida si la transición solicitada es válida.
        </p>
      </div>

      <label className="field">
        <span>Estado objetivo</span>
        <select
          value={targetState}
          onChange={(event) => {
            setTargetState(event.target.value as SubscriptionStateName | '');
            setTransition({ status: 'idle' });
          }}
          disabled={transition.status === 'saving'}
          required
        >
          <option value="">Selecciona un estado</option>
          {SUBSCRIPTION_STATES.map((subscriptionState) => (
            <option key={subscriptionState} value={subscriptionState}>
              {subscriptionState.replaceAll('_', ' ')}
            </option>
          ))}
        </select>
      </label>

      <div className="platform-form-actions">
        <button
          className="button"
          type="submit"
          disabled={targetState === '' || transition.status === 'saving'}
        >
          {transition.status === 'saving' ? 'Cambiando…' : 'Cambiar estado'}
        </button>
        {transition.status === 'error' && (
          <span className="muted" role="alert">
            {transition.message}
          </span>
        )}
      </div>
    </form>
  );
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

type PlatformOwnerSection = 'control' | 'empresas' | 'staff' | 'suscripciones' | 'catalogo';

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
  const [tenantSearch, setTenantSearch] = useState('');
  const [tenantScopeFilter, setTenantScopeFilter] = useState<'all' | 'with-branches' | 'without-branches'>('all');
  const [tenantPreview, setTenantPreview] = useState<TenantSummary | null>(null);
  const [creatingTenant, setCreatingTenant] = useState(false);
  const [subscriptionSearch, setSubscriptionSearch] = useState('');
  const [subscriptionStatusFilter, setSubscriptionStatusFilter] = useState<'all' | 'configured' | 'missing'>('all');
  const [subscriptionPreview, setSubscriptionPreview] = useState<TenantSummary | null>(null);
  const [commercialSearch, setCommercialSearch] = useState('');
  const [commercialPreview, setCommercialPreview] = useState<CommercialPortfolioItem | null>(null);
  const [catalogPreview, setCatalogPreview] = useState<CommercialPlan | null>(null);
  // Any successful tenant refresh invalidates cached read-only SaaS entitlements.
  const [saasPanelRevision, setSaasPanelRevision] = useState(0);
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
      if (requestId !== requestSequence.current) {
        return;
      }
      if (data.mode === 'global') {
        globalPage.current = data.tenant_pagination.page;
      }
      setState({ status: 'ready', data });
      if (tenantId !== undefined) {
        setSaasPanelRevision((current) => current + 1);
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

  // main.tsx still passes "control" for unrecognized sections; derive the two
  // owner-only navigation destinations here without widening another claim.
  const currentSection: PlatformOwnerSection =
    window.location.pathname === '/adminpl0n3r/suscripciones'
      ? 'suscripciones'
      : window.location.pathname === '/adminpl0n3r/catalogo'
        ? 'catalogo'
        : section;
  const selected = selectedTenantFromState(state);
  const filteredTenants = state.status === 'ready'
    ? state.data.tenants.filter((tenant) => (
      (tenantScopeFilter === 'all'
        || (tenantScopeFilter === 'with-branches' ? tenant.branch_count > 0 : tenant.branch_count === 0))
      && (tenant.name + ' ' + tenant.slug).toLocaleLowerCase('es-CO')
        .includes(tenantSearch.trim().toLocaleLowerCase('es-CO'))
    ))
    : [];

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
          message: trialCreationErrorMessage(response.status),
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
          group: 'Resumen',
          icon: 'home',
          items: [
            {
              href: '/adminpl0n3r',
              label: 'Centro de control',
              current: currentSection === 'control',
            },
          ],
        },
        {
          group: 'Empresas',
          icon: 'people',
          items: [
            {
              href: '/adminpl0n3r/empresas',
              label: 'Empresas',
              current: currentSection === 'empresas',
            },
          ],
        },
        {
          group: 'Comercial',
          icon: 'sales',
          items: [
            {
              href: '/adminpl0n3r/planes',
              label: 'Planes y precios',
            },
            {
              href: '/adminpl0n3r/suscripciones',
              label: 'Plan y suscripciones',
              current: currentSection === 'suscripciones',
            },
            {
              href: '/adminpl0n3r/catalogo',
              label: 'Catálogo comercial',
              current: currentSection === 'catalogo',
            },
          ],
        },
        {
          group: 'Plataforma',
          icon: 'admin',
          items: [
            {
              href: '/adminpl0n3r/staff',
              label: 'Staff y permisos',
              current: currentSection === 'staff',
            },
            {
              href: '/adminpl0n3r/diagnosticos',
              label: 'Diagnósticos',
            },
          ],
        },
        // Cumplimiento y Soporte no se muestran hasta tener pantallas
        // del propietario; tampoco hay enlaces ficticios a Ingresos,
        // Auditoría o Estado del sistema.
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

          {!selected && currentSection === 'control' && (
            <section className="ui-page" aria-label="Centro de control de plataforma">
              <PageHeader title="Centro de control"
                description="Visibilidad global de empresas, accesos y funcionamiento de Condor."
                actions={<a className="button button-secondary" href="/adminpl0n3r/diagnosticos">Abrir diagnósticos</a>}
              />
              <MetricStrip label="Métricas globales de Condor"
                items={[
                  {
                    label: 'Empresas',
                    value: state.data.metrics.tenant_count,
                    hint: 'empresas registradas',
                  },
                  {
                    label: 'Usuarios',
                    value: state.data.metrics.user_count,
                    hint: 'identidades de Condor',
                  },
                  {
                    label: 'Sedes',
                    value: state.data.metrics.branch_count,
                    hint: 'puntos operativos',
                  },
                  {
                    label: 'Membresías activas',
                    value: state.data.metrics.active_membership_count,
                    hint: 'accesos vigentes',
                  },
                ]}
              />

              <SystemHealthPanel />

              <FunctionalSignalsPanel
                signals={state.data.signals_last_30_days}
              />
            </section>
          )}

          {!selected && currentSection === 'catalogo' && (
            <section className="platform-section ui-page" aria-label="Catálogo comercial de plataforma">
              <PageHeader title="Catálogo comercial"
                description="Planes vigentes, cartera de empresas y uso observado. Datos canónicos solo de lectura."
                actions={<a className="button button-secondary"
                  href="/adminpl0n3r/planes">Editar planes y tarifas</a>} />
              <MetricStrip label="Resumen del catálogo comercial" items={[
                { label: 'Planes vigentes', value: state.data.commercial_catalog.length },
                { label: 'Empresas en cartera', value: state.data.commercial_portfolio.length,
                  hint: 'cartera de esta página' },
                { label: 'Con suscripción', value: state.data.commercial_portfolio.filter((entry) =>
                  entry.commercial_subscription.status === 'configured').length },
              ]} />
              <Card title="Cartera comercial">
                <Toolbar trailing={<span className="ui-count">Búsqueda en esta página · {state.data.commercial_portfolio.length} empresas</span>}>
                  <label className="ui-search">
                    <span className="ui-sr-only">Buscar en la cartera comercial</span>
                    <input type="search" value={commercialSearch} placeholder="Buscar empresa o slug"
                      onChange={(e) => setCommercialSearch(e.target.value)} />
                  </label>
                </Toolbar>
                <DataTable caption="Cartera comercial de esta página"
                  rows={state.data.commercial_portfolio.filter((entry) =>
                    (entry.tenant.name + ' ' + entry.tenant.slug).toLocaleLowerCase('es-CO')
                      .includes(commercialSearch.toLocaleLowerCase('es-CO').trim()))}
                  rowKey={(entry) => entry.tenant.id}
                  onOpen={(entry) => setCommercialPreview(entry)}
                  empty={<><strong>No hay empresas en la cartera mostrada.</strong>
                    <span>Comprueba los filtros o cambia de página en Empresas.</span></>}
                  columns={[
                    { key: 'empresa', header: 'Empresa', render: (entry) => <strong>{entry.tenant.name}</strong> },
                    { key: 'plan', header: 'Plan', render: (entry) =>
                      entry.commercial_subscription.status === 'configured'
                        ? entry.commercial_subscription.subscription.plan.name
                        : 'Sin plan' },
                    { key: 'estado', header: 'Suscripción', render: (entry) =>
                      <StatusPill tone={entry.commercial_subscription.status === 'configured' ? 'ok' : 'warn'}>
                        {entry.commercial_subscription.status === 'configured'
                          ? entry.commercial_subscription.subscription.state.replaceAll('_', ' ')
                          : 'Sin configurar'}
                      </StatusPill> },
                    { key: 'uso', header: 'Uso observado', render: (entry) =>
                      entry.usage.length === 0 ? 'Sin observaciones'
                        : entry.usage.length + ' métricas' },
                  ]} />
              </Card>
              <SidePanel open={commercialPreview !== null}
                title={commercialPreview?.tenant.name ?? 'Empresa'}
                onClose={() => setCommercialPreview(null)}>
                {commercialPreview && <>
                  <p>Empresa: <strong>{commercialPreview.tenant.name}</strong> · {commercialPreview.tenant.slug}</p>
                  <p>Suscripción: {commercialPreview.commercial_subscription.status === 'configured'
                    ? commercialPreview.commercial_subscription.subscription.plan.name
                      + ' · ' + commercialPreview.commercial_subscription.subscription.state
                    : 'Sin configurar'}</p>
                  <h4>Uso observado</h4>
                  {commercialPreview.usage.length === 0
                    ? <p>No hay métricas observadas.</p>
                    : <ul>{commercialPreview.usage.map((usage) =>
                      <li key={usage.metric + usage.window_start + usage.window_end}>
                        <code>{usage.metric}</code> · {usage.quantity} · {usage.aggregation}
                      </li>)}</ul>}
                </>}
              </SidePanel>
              <Card title="Catálogo vigente">
                <DataTable caption="Catálogo de planes vigentes"
                  rows={state.data.commercial_catalog} rowKey={(plan) => plan.plan_version_id}
                  onOpen={(plan) => setCatalogPreview(plan)}
                  empty={<><strong>No hay planes comerciales vigentes.</strong>
                    <span>El catálogo se consulta desde la API real.</span></>}
                  columns={[
                    { key: 'name', header: 'Plan', render: (plan) => <strong>{plan.name}</strong> },
                    { key: 'version', header: 'Versión', render: (plan) => 'v' + plan.version },
                    { key: 'monthly', header: 'Mensual', render: (plan) =>
                      plan.quote_required ? 'Cotizar' : commercialMoney(plan.monthly_amount, plan.currency) },
                    { key: 'annual', header: 'Anual', render: (plan) =>
                      plan.quote_required ? 'Cotizar' : commercialMoney(plan.annual_amount, plan.currency) },
                    { key: 'status', header: 'Tarifa', render: (plan) =>
                      <StatusPill tone={plan.quote_required ? 'info' : 'ok'}>
                        {plan.quote_required ? 'Cotizar' : 'Configurada'}
                      </StatusPill> },
                  ]} />
              </Card>
              <SidePanel open={catalogPreview !== null}
                title={catalogPreview?.name ?? 'Plan comercial'}
                onClose={() => setCatalogPreview(null)}>
                {catalogPreview && <>
                  <p>Versión v{catalogPreview.version} · {catalogPreview.key}</p>
                  <p>Mensual: {catalogPreview.quote_required ? 'Cotizar'
                    : commercialMoney(catalogPreview.monthly_amount, catalogPreview.currency)}</p>
                  <p>Anual: {catalogPreview.quote_required ? 'Cotizar'
                    : commercialMoney(catalogPreview.annual_amount, catalogPreview.currency)}</p>
                  <a className="button button-secondary" href="/adminpl0n3r/planes">
                    Abrir editor de planes
                  </a>
                </>}
              </SidePanel>
            </section>
          )}

          {!selected && currentSection === 'empresas' && (
            <section className="ui-page" aria-label="Gestión de empresas">
              <PageHeader
                title="Empresas"
                description="Consulta las empresas registradas y elige el contexto que necesitas revisar."
                actions={<button className="button" type="button" onClick={() => setCreatingTenant(true)}>Crear cliente</button>}
              />
              <MetricStrip label="Resumen de empresas" items={[
                { label: 'Empresas registradas', value: state.data.tenant_pagination.total },
                { label: 'En esta página', value: state.data.tenants.length },
                { label: 'Con sedes', value: state.data.tenants.filter((tenant) => tenant.branch_count > 0).length },
              ]} />
              <Card title="Clientes de la plataforma">
                <Toolbar trailing={<span className="ui-count">{filteredTenants.length} de {state.data.tenants.length} en esta página</span>}>
                  <span className="muted">Filtros solo sobre esta página.</span>
                  <label className="ui-search">
                    <span className="ui-sr-only">Buscar empresas</span>
                    <input type="search" placeholder="Buscar por empresa o identificador"
                      value={tenantSearch} onChange={(event) => setTenantSearch(event.target.value)} />
                  </label>
                  <label>
                    <span className="ui-sr-only">Sedes registradas</span>
                    <select value={tenantScopeFilter}
                      onChange={(event) => setTenantScopeFilter(event.target.value as typeof tenantScopeFilter)}>
                      <option value="all">Todas las empresas</option>
                      <option value="with-branches">Con sedes</option>
                      <option value="without-branches">Sin sedes</option>
                    </select>
                  </label>
                </Toolbar>
                <DataTable
                  caption="Empresas de esta página"
                  rows={filteredTenants}
                  rowKey={(tenant) => tenant.id}
                  onOpen={setTenantPreview}
                  empty={<><strong>Sin empresas para mostrar en esta página.</strong><span>Comprueba los filtros o cambia de página.</span></>}
                  columns={[
                    { key: 'company', header: 'Empresa', render: (tenant) => <span className="ui-cell-main"><strong>{tenant.name}</strong><small>{tenant.slug}</small></span> },
                    { key: 'branches', header: 'Sedes', render: (tenant) => tenant.branch_count },
                    { key: 'access', header: 'Membresías activas', render: (tenant) => tenant.active_membership_count },
                    { key: 'state', header: 'Accesos', render: (tenant) =>
                      <StatusPill tone={tenant.active_membership_count > 0 ? 'ok' : 'neutral'}>
                        {tenant.active_membership_count > 0 ? 'Con accesos' : 'Sin accesos activos'}
                      </StatusPill> },
                  ]}
                />
              </Card>
              {state.data.tenant_pagination.total > 0 && (
                <nav className="tenant-pagination" aria-label="Paginación de empresas">
                  <button className="button button-secondary" type="button"
                    disabled={!state.data.tenant_pagination.has_previous}
                    onClick={() => { setTenantPreview(null); void loadContext(undefined, state.data.tenant_pagination.page - 1); }}>
                    Anterior
                  </button>
                  <span className="muted">Página {state.data.tenant_pagination.page}</span>
                  <button className="button button-secondary" type="button"
                    disabled={!state.data.tenant_pagination.has_next}
                    onClick={() => { setTenantPreview(null); void loadContext(undefined, state.data.tenant_pagination.page + 1); }}>
                    Siguiente
                  </button>
                </nav>
              )}
              <SidePanel open={tenantPreview !== null} title={tenantPreview?.name ?? 'Empresa'}
                onClose={() => setTenantPreview(null)}
                footer={<>
                  <button className="button button-secondary" type="button" onClick={() => setTenantPreview(null)}>Cerrar</button>
                  <button className="button" type="button" disabled={!tenantPreview}
                    onClick={() => {
                      if (!tenantPreview) return;
                      const id = tenantPreview.id;
                      setTenantPreview(null);
                      void loadContext(id, state.data.tenant_pagination.page);
                    }}>Ver como propietario</button>
                </>}>
                {tenantPreview && <dl className="ui-details">
                  <div><dt>Empresa</dt><dd>{tenantPreview.name}</dd></div>
                  <div><dt>Identificador</dt><dd>{tenantPreview.slug}</dd></div>
                  <div><dt>Sedes</dt><dd>{tenantPreview.branch_count}</dd></div>
                  <div><dt>Membresías activas</dt><dd>{tenantPreview.active_membership_count}</dd></div>
                </dl>}
              </SidePanel>
              <SidePanel open={creatingTenant} title="Crear cliente" onClose={() => setCreatingTenant(false)}>
                {creatingTenant && <PlatformTenantCreationPanel
                  csrfToken={tenantToken}
                  onCreated={() => {
                    // El enlace de invitación es de un solo uso: mantener el
                    // panel abierto hasta que el propietario pueda copiarlo.
                    setPlatformRevision((current) => current + 1);
                    void loadContext(undefined, 1, false);
                  }}
                />}
              </SidePanel>
            </section>
          )}

          {!selected && currentSection === 'staff' && (
            <PlatformStaffPanel
              csrfToken={staffToken}
              refreshKey={platformRevision}
            />
          )}

          {!selected && currentSection === 'suscripciones' && (
            <section className="platform-section ui-page" aria-label="Plan y suscripciones">
              <PageHeader title="Plan y suscripciones"
                description="Consulta el estado, plan y vigencia de cada empresa. Los cambios se validan en el servidor."
                actions={<button className="button button-secondary" type="button"
                  onClick={() => void loadContext(undefined, state.data.tenant_pagination.page)}>
                  Actualizar suscripciones
                </button>}
              />
              <MetricStrip label="Resumen de suscripciones de esta página" items={[
                { label: 'Empresas visibles', value: state.data.tenants.length },
                { label: 'Con suscripción', value: state.data.tenants.filter((tenant) =>
                  state.data.commercial_portfolio.some((entry) =>
                    entry.tenant.id === tenant.id && entry.commercial_subscription.status === 'configured')).length },
                { label: 'Sin configurar', value: state.data.tenants.filter((tenant) =>
                  !state.data.commercial_portfolio.some((entry) =>
                    entry.tenant.id === tenant.id && entry.commercial_subscription.status === 'configured')).length },
              ]} />
              <Card title="Suscripciones por empresa">
                <Toolbar trailing={<span className="ui-count">Página {state.data.tenant_pagination.page} · filtros en esta página</span>}>
                  <label className="ui-search">
                    <span className="ui-sr-only">Buscar empresa en suscripciones</span>
                    <input type="search" value={subscriptionSearch}
                      placeholder="Buscar empresa" onChange={(e) => setSubscriptionSearch(e.target.value)} />
                  </label>
                  <label>Estado
                    <select value={subscriptionStatusFilter}
                      onChange={(e) => setSubscriptionStatusFilter(e.target.value as typeof subscriptionStatusFilter)}>
                      <option value="all">Todos</option>
                      <option value="configured">Configuradas</option>
                      <option value="missing">Sin configurar</option>
                    </select>
                  </label>
                </Toolbar>
                <DataTable caption="Suscripciones de esta página"
                  rows={state.data.tenants.filter((tenant) => {
                    const configured = state.data.commercial_portfolio.some((entry) =>
                      entry.tenant.id === tenant.id && entry.commercial_subscription.status === 'configured');
                    return (tenant.name + ' ' + tenant.slug).toLocaleLowerCase('es-CO')
                      .includes(subscriptionSearch.toLocaleLowerCase('es-CO').trim())
                      && (subscriptionStatusFilter === 'all'
                        || (subscriptionStatusFilter === 'configured' ? configured : !configured));
                  })}
                  rowKey={(tenant) => tenant.id}
                  onOpen={(tenant) => setSubscriptionPreview(tenant)}
                  empty={<><strong>No hay empresas para mostrar.</strong>
                    <span>Comprueba los filtros o cambia de página.</span></>}
                  columns={[
                    { key: 'empresa', header: 'Empresa', render: (tenant) => <strong>{tenant.name}</strong> },
                    { key: 'plan', header: 'Plan', render: (tenant) => {
                      const entry = state.data.commercial_portfolio.find((item) => item.tenant.id === tenant.id);
                      return entry?.commercial_subscription.status === 'configured'
                        ? entry.commercial_subscription.subscription.plan.name
                          + ' · v' + entry.commercial_subscription.subscription.plan.version
                        : 'Sin plan';
                    } },
                    { key: 'estado', header: 'Estado', render: (tenant) => {
                      const entry = state.data.commercial_portfolio.find((item) => item.tenant.id === tenant.id);
                      return entry?.commercial_subscription.status === 'configured'
                        ? <StatusPill tone="ok">{entry.commercial_subscription.subscription.state.replaceAll('_', ' ')}</StatusPill>
                        : <StatusPill tone="warn">Sin configurar</StatusPill>;
                    } },
                    { key: 'vigencia', header: 'Vigencia / último cambio', render: (tenant) => {
                      const entry = state.data.commercial_portfolio.find((item) => item.tenant.id === tenant.id);
                      if (entry?.commercial_subscription.status !== 'configured') return 'Pendiente';
                      return entry.commercial_subscription.subscription.trial_ends_at
                        ?? entry.commercial_subscription.subscription.last_changed_at;
                    } },
                    { key: 'accion', header: 'Gestión', render: (tenant) =>
                      <button type="button" className="button button-secondary"
                        onClick={() => { setSubscriptionPreview(null); void loadContext(
                          tenant.id, state.data.tenant_pagination.page); }}>Ver suscripción</button> },
                  ]} />
              </Card>
              <SidePanel open={subscriptionPreview !== null}
                title={subscriptionPreview?.name ?? 'Detalle de suscripción'}
                onClose={() => setSubscriptionPreview(null)}>
                {subscriptionPreview && (() => {
                  const entry = state.data.commercial_portfolio.find((item) =>
                    item.tenant.id === subscriptionPreview.id);
                  return <>
                    <p>Resumen canónico de la empresa <strong>{subscriptionPreview.name}</strong>.</p>
                    <p>Plan: {entry?.commercial_subscription.status === 'configured'
                      ? entry.commercial_subscription.subscription.plan.name : 'Sin configurar'}</p>
                    <p>Estado: {entry?.commercial_subscription.status === 'configured'
                      ? entry.commercial_subscription.subscription.state.replaceAll('_', ' ')
                      : 'Sin suscripción'}</p>
                    <p>Vigencia: {entry?.commercial_subscription.status === 'configured'
                      ? entry.commercial_subscription.subscription.trial_ends_at
                        ?? entry.commercial_subscription.subscription.last_changed_at
                      : 'Pendiente'}</p>
                    <button className="button" type="button"
                      onClick={() => { setSubscriptionPreview(null); void loadContext(
                        subscriptionPreview.id, state.data.tenant_pagination.page); }}>
                      Abrir gestión de suscripción
                    </button>
                  </>;
                })()}
              </SidePanel>
              <nav className="tenant-pagination" aria-label="Paginación de suscripciones">
                <button className="button button-secondary" type="button"
                  disabled={!state.data.tenant_pagination.has_previous}
                  onClick={() => { setSubscriptionPreview(null); void loadContext(
                    undefined, state.data.tenant_pagination.page - 1); }}>Anterior</button>
                <span className="muted">Página {state.data.tenant_pagination.page}</span>
                <button className="button button-secondary" type="button"
                  disabled={!state.data.tenant_pagination.has_next}
                  onClick={() => { setSubscriptionPreview(null); void loadContext(
                    undefined, state.data.tenant_pagination.page + 1); }}>Siguiente</button>
              </nav>
            </section>
          )}

          {selected && (
            <>
              {currentSection === 'suscripciones' && isSyntheticTenant(selected.id) && (
                <PlatformSubscriptionsPanel
                  tenantId={selected.id}
                  owner={state.data.actor.role === 'platform_owner'}
                  syntheticOnly={true}
                  csrfToken={subscriptionToken}
                  planVersions={state.data.commercial_catalog.map((plan) => ({
                    id: plan.plan_version_id,
                    name: plan.name,
                    synthetic: plan.plan_version_id.startsWith('synthetic-plan-version-')
                      && plan.name.startsWith('Synthetic '),
                  }))}
                  onChanged={() => loadContext(selected.id, state.data.tenant_pagination.page, false)}
                />
              )}
              {currentSection === 'suscripciones' && (
              <SaasControlCenterReadPanel
                key={`${selected.id}:${saasPanelRevision}`}
                tenantId={selected.id}
              />
              )}
              {currentSection === 'empresas' && (
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
              )}
              {currentSection === 'suscripciones' && (
                <>
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
                  <>
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

                    <SubscriptionStateTransitionControl
                      key={selected.id}
                      tenantId={selected.id}
                      page={state.data.tenant_pagination.page}
                      csrfToken={subscriptionToken}
                      onRefresh={loadContext}
                    />
                  </>
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
                </>
              )}

              {currentSection === 'empresas' && (
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
                  Este contexto muestra sedes y productos en modo de consulta.
                  Para gestionar una suscripción, abre Plan y suscripciones.
                </div>
              </section>
              )}
            </>
          )}
        </>
      )}
    </AdminShell>
  );
}
