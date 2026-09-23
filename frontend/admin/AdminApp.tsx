import { useEffect, useState } from 'react';
import { AccessManagement } from './AccessManagement';
import { CatalogManagement } from './CatalogManagement';
import { InventoryManagement } from './InventoryManagement';
import { AdminShell } from './AdminShell';
import { OverviewGrid } from './OverviewGrid';
import { contextPath } from './api';

type AdminAppProps = Readonly<{
  version: string;
  logoutToken: string;
  accessToken: string;
}>;

type LegalEntityContext = {
  id: string;
  name: string;
};

type Branch = {
  id: string;
  name: string;
  slug: string;
  is_default: boolean;
  legal_entity: LegalEntityContext | null;
};

type TenantContextResponse = {
  tenant: {
    id: string;
    name: string;
    slug: string;
  };
  legal_entities: LegalEntityContext[];
  active_legal_entity: LegalEntityContext | null;
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
  const [contextRequest] = useState(() => ({ latest: 0 }));

  async function loadContext(branchId?: string) {
    const requestId = ++contextRequest.latest;

    try {
      const response = await fetch(contextPath(branchId), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });

      if (response.status === 403) {
        const denied = await response.json() as {
          error?: string;
          message?: string;
          tenant?: TenantContextResponse['tenant'];
          details?: {
            tenant?: TenantContextResponse['tenant'];
          };
        };
        const tenant = denied.details?.tenant ?? denied.tenant;
        if (tenant) {
          if (requestId === contextRequest.latest) {
            setContext({
              status: 'denied',
              tenant,
              message:
                denied.message ??
                denied.error ??
                'No tienes una sede asignada en esta empresa.',
            });
          }
          return;
        }
      }

      if (!response.ok) {
        throw new Error('No fue posible cargar el contexto');
      }

      const data = await response.json() as TenantContextResponse;
      if (requestId === contextRequest.latest) {
        setContext({ status: 'ready', data });
      }
    } catch {
      if (requestId === contextRequest.latest) {
        setContext({ status: 'error' });
      }
    }
  }

  useEffect(() => {
    void loadContext();
  }, []);

  const readyContext = context.status === 'ready'
    ? context.data
    : null;
  const visibleBranches = readyContext === null
    || readyContext.legal_entities.length <= 1
    ? readyContext?.branches ?? []
    : readyContext.branches.filter((branch) => (
        branch.legal_entity === null
        || branch.legal_entity.id
        === readyContext.active_legal_entity?.id
      ));

  return (
    <AdminShell
      version={version}
      logoutToken={logoutToken}
      ariaLabel="Administrador de Condor"
      navLabel="Navegación principal"
      navItems={[
        { href: '/admin', label: 'Inicio', current: true },
        { href: '#catalog', label: 'Catálogo' },
        { href: '#inventory', label: 'Inventario' },
        { href: '#roles', label: 'Roles y permisos' },
      ]}
    >
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

            <div className="role-actions">
              {context.data.legal_entities.length > 1 && (
                <label className="branch-picker">
                  <span>Razón social</span>
                  <select
                    aria-label="Razón social activa"
                    value={
                      context.data.active_legal_entity?.id
                      ?? '__legacy__'
                    }
                    onChange={(event) => {
                      const branch = context.data.branches.find(
                        (candidate) => (
                          candidate.legal_entity?.id
                          === event.target.value
                        ),
                      );
                      if (!branch) {
                        return;
                      }
                      setContext({ status: 'loading' });
                      void loadContext(branch.id);
                    }}
                  >
                    {context.data.active_legal_entity === null && (
                      <option value="__legacy__" disabled>
                        Sin razón social asignada
                      </option>
                    )}
                    {context.data.legal_entities.map((legalEntity) => (
                      <option
                        value={legalEntity.id}
                        key={legalEntity.id}
                      >
                        {legalEntity.name}
                      </option>
                    ))}
                  </select>
                </label>
              )}

              <label className="branch-picker">
                <span>Sede activa</span>
                <select
                  aria-label="Sede activa"
                  value={context.data.active_branch.id}
                  onChange={(event) => {
                    setContext({ status: 'loading' });
                    void loadContext(event.target.value);
                  }}
                >
                  {visibleBranches.map((branch) => (
                    <option value={branch.id} key={branch.id}>
                      {branch.name}
                      {branch.is_default ? ' · principal' : ''}
                    </option>
                  ))}
                </select>
              </label>
            </div>
          </div>

          <OverviewGrid
            ariaLabel="Estado de la empresa"
            items={[
              {
                label: 'Tenant',
                value: 'Activo',
                detail: 'Aislamiento y contexto aplicados',
              },
              {
                label: 'Sede activa',
                value: context.data.active_branch.name,
              },
              {
                label: 'Permisos efectivos',
                value: context.data.permissions.length,
                detail: 'capacidades en esta sede',
              },
            ]}
          />

          <CatalogManagement
            key={'catalog-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />

          <InventoryManagement
            key={'inventory-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />

          <AccessManagement
            key={'access-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />
        </>
      )}
    </AdminShell>
  );
}
