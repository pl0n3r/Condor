import { useEffect, useState } from 'react';
import { AccessManagement } from './AccessManagement';
import { contextPath } from './api';

type AdminAppProps = Readonly<{
  version: string;
  logoutToken: string;
  accessToken: string;
}>;

type Branch = {
  id: string;
  name: string;
  slug: string;
  is_default: boolean;
};

type TenantContextResponse = {
  tenant: {
    id: string;
    name: string;
    slug: string;
  };
  branches: Branch[];
  active_branch: Branch;
  permissions: string[];
  version: string;
};

type ContextState =
  | { status: 'loading' }
  | { status: 'ready'; data: TenantContextResponse }
  | {
      status: 'denied';
      tenant: TenantContextResponse['tenant'];
      message: string;
    }
  | { status: 'error' };

export function AdminApp({
  version,
  logoutToken,
  accessToken,
}: AdminAppProps) {
  const [context, setContext] = useState<ContextState>({
    status: 'loading',
  });

  async function loadContext(branchId?: string) {
    try {
      const response = await fetch(contextPath(branchId), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });

      if (response.status === 403) {
        const denied = await response.json() as {
          error?: string;
          tenant?: TenantContextResponse['tenant'];
        };
        if (denied.tenant) {
          setContext({
            status: 'denied',
            tenant: denied.tenant,
            message:
              denied.error ??
              'No tienes una sede asignada en esta empresa.',
          });
          return;
        }
      }

      if (!response.ok) {
        throw new Error('No fue posible cargar el contexto');
      }

      const data = await response.json() as TenantContextResponse;
      setContext({ status: 'ready', data });
    } catch {
      setContext({ status: 'error' });
    }
  }

  useEffect(() => {
    void loadContext();
  }, []);

  return (
    <section className="admin-shell" aria-label="Administrador de Condor">
      <aside className="sidebar">
        <div className="brand-block">
          <strong>Condor App</strong>
          <span>V {version}</span>
        </div>

        <nav aria-label="Navegación principal">
          <a href="/admin" aria-current="page">
            Inicio
          </a>
          <a href="#roles">Roles y permisos</a>
        </nav>

        <form method="post" action="/admin/logout">
          <input
            type="hidden"
            name="_csrf_token"
            value={logoutToken}
          />
          <button className="button button-secondary" type="submit">
            Cerrar sesión
          </button>
        </form>
      </aside>

      <div className="workspace">
        <span className="eyebrow">Administrador</span>

        {context.status === 'loading' && (
          <>
            <h1>Cargando empresa…</h1>
            <output className="muted" aria-live="polite">
              Preparando tu espacio de trabajo.
            </output>
          </>
        )}

        {context.status === 'error' && (
          <div className="alert alert-error" role="alert">
            No pudimos cargar el contexto de tu empresa. Recarga la
            página para intentarlo de nuevo.
          </div>
        )}

        {context.status === 'denied' && (
          <>
            <h1>{context.tenant.name}</h1>
            <div className="alert alert-error" role="alert">
              <strong>Acceso a sedes no disponible.</strong>{' '}
              <span>{context.message}</span>
            </div>
          </>
        )}

        {context.status === 'ready' && (
          <>
            <div className="workspace-heading">
              <div>
                <h1>{context.data.tenant.name}</h1>
                <p className="muted">
                  Contexto activo:{' '}
                  <strong>{context.data.tenant.slug}</strong>
                </p>
              </div>

              <label className="branch-picker">
                <span>Sede activa</span>
                <select
                  value={context.data.active_branch.id}
                  onChange={(event) => {
                    setContext({ status: 'loading' });
                    void loadContext(event.target.value);
                  }}
                >
                  {context.data.branches.map((branch) => (
                    <option value={branch.id} key={branch.id}>
                      {branch.name}
                      {branch.is_default ? ' · principal' : ''}
                    </option>
                  ))}
                </select>
              </label>
            </div>

            <div
              className="foundation-grid"
              aria-label="Estado de la empresa"
            >
              <article>
                <strong>Tenant</strong>
                <span>Aislamiento y contexto activos</span>
              </article>
              <article>
                <strong>Sede activa</strong>
                <span>{context.data.active_branch.name}</span>
              </article>
              <article>
                <strong>Permisos efectivos</strong>
                <span>
                  {context.data.permissions.length} capacidades en esta
                  sede
                </span>
              </article>
            </div>

            <AccessManagement
              key={context.data.active_branch.id}
              branchId={context.data.active_branch.id}
              permissions={context.data.permissions}
              csrfToken={accessToken}
            />
          </>
        )}
      </div>
    </section>
  );
}
