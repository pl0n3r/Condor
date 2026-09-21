import { useEffect, useRef, useState } from 'react';
import { AdminShell } from './AdminShell';
import { OverviewGrid } from './OverviewGrid';
import { platformOwnerContextPath } from './api';

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
  tenants: TenantSummary[];
  selected_tenant: SelectedTenant | null;
  version: string;
};

type State =
  | { status: 'loading' }
  | { status: 'ready'; data: PlatformContextResponse }
  | { status: 'permission-denied' }
  | { status: 'not-found' }
  | { status: 'error' };

type PlatformOwnerAppProps = Readonly<{
  version: string;
  logoutToken: string;
}>;

export function PlatformOwnerApp({
  version,
  logoutToken,
}: PlatformOwnerAppProps) {
  const [state, setState] = useState<State>({ status: 'loading' });
  const requestSequence = useRef(0);

  async function loadContext(tenantId?: string) {
    const requestId = ++requestSequence.current;
    setState({ status: 'loading' });

    try {
      const response = await fetch(platformOwnerContextPath(tenantId), {
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

      if (!response.ok) {
        throw new Error('No fue posible cargar el centro de control');
      }

      const data = await response.json() as PlatformContextResponse;
      if (requestId === requestSequence.current) {
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

  const selected =
    state.status === 'ready' ? state.data.selected_tenant : null;

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
          current: true,
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
            onClick={() => void loadContext()}
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
        <div className="alert alert-error" role="alert">
          No pudimos cargar el centro de control. Recarga la página para
          intentarlo de nuevo.
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

          {!selected && (
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

              <section className="platform-section" aria-labelledby="tenant-list-title">
                <div className="section-heading compact">
                  <div>
                    <span className="eyebrow">Empresas</span>
                    <h2 id="tenant-list-title">Clientes de la plataforma</h2>
                  </div>
                  <span className="muted">
                    {state.data.tenants.length} registradas
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
                          onClick={() => void loadContext(tenant.id)}
                        >
                          Ver como propietario
                        </button>
                      </article>
                    ))}
                  </div>
                )}
              </section>
            </>
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
                    value: 'Solo lectura',
                    detail: 'primer slice seguro',
                  },
                ]}
              />

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

                <div className="platform-readonly-note" role="note">
                  Este primer contexto es deliberadamente de solo lectura.
                  Los módulos compartidos se habilitarán aquí
                  progresivamente cuando sus contratos server-side estén
                  listos.
                </div>
              </section>
            </>
          )}
        </>
      )}
    </AdminShell>
  );
}
