import { lazy, Suspense, useEffect, useState } from 'react';
import { AccessManagement } from './AccessManagement';
import { CatalogManagement } from './CatalogManagement';
import { InventoryManagement } from './InventoryManagement';
import { StockAlertsPanel } from './StockAlertsPanel';
import { StockTransferPanel } from './StockTransferPanel';
import { WorkflowsPanel } from './WorkflowsPanel';
import { SeoSettingsPanel } from './SeoSettingsPanel';
import { CommerceManagement } from './CommerceManagement';
import { CmsManagement } from './CmsManagement';
import { OrdersManagement } from './OrdersManagement';
import { SalesReportPanel } from './SalesReportPanel';
import { ReportsDownloads } from './ReportsDownloads';
import { EmployeesManagement } from './EmployeesManagement';
import { ProductionManagement } from './ProductionManagement';
import { PurchasingManagement } from './PurchasingManagement';
import { CustomerServiceManagement } from './CustomerServiceManagement';
import { AssetsManagement } from './AssetsManagement';
import { PayrollManagement } from './PayrollManagement';
import { ComplianceManagement } from './ComplianceManagement';
import { AdminShell } from './AdminShell';
import { OverviewGrid } from './OverviewGrid';
import { contextPath } from './api';

type AdminAppProps = Readonly<{
  version: string;
  logoutToken: string;
  accessToken: string;
  section: 'home' | 'site';
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

const DevGovernanceManagement = import.meta.env.DEV
  ? lazy(async () => ({ default: (await import('./GovernanceManagement')).GovernanceManagement }))
  : null;

const DevHumanResourcesManagement = import.meta.env.DEV
  ? lazy(async () => ({ default: (await import('./HumanResourcesManagement')).HumanResourcesManagement }))
  : null;

const DevContractsManagement = import.meta.env.DEV
  ? lazy(async () => ({ default: (await import('./ContractsManagement')).ContractsManagement }))
  : null;

const DevSocialSecurityManagement = import.meta.env.DEV
  ? lazy(async () => ({ default: (await import('./SocialSecurityManagement')).SocialSecurityManagement }))
  : null;

const DevFinanceManagement = import.meta.env.DEV
  ? lazy(async () => ({ default: (await import('./FinanceManagement')).FinanceManagement }))
  : null;

const DevComplianceEvidenceManagement = import.meta.env.DEV
  ? lazy(async () => ({ default: (await import('./ComplianceManagement')).ComplianceEvidenceManagement }))
  : null;

export function AdminApp({
  version,
  logoutToken,
  accessToken,
  section,
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
        { href: '/admin', label: 'Inicio', current: section === 'home' },
        { href: '/admin/sitio', label: 'Sitio', current: section === 'site' },
        { href: '/admin#catalog', label: 'Catálogo' },
        { href: '/admin#inventory', label: 'Inventario' },
        { href: '/admin#stock-alerts', label: 'Alertas de stock' },
        { href: '/admin#stock-transfers', label: 'Transferencias' },
        { href: '/admin#workflows', label: 'Workflows' },
        { href: '/admin#seo-settings', label: 'SEO' },
        { href: '/admin#production', label: 'Producción' },
        { href: '/admin#purchasing', label: 'Compras' },
        ...(import.meta.env.DEV ? [{ href: '/admin#customer-service', label: 'Servicio al cliente (pruebas)' }] : []),
        ...(import.meta.env.DEV ? [{ href: '/admin#assets-management', label: 'Activos (pruebas)' }] : []),
        ...(import.meta.env.DEV ? [{ href: '/admin#payroll-management', label: 'Nómina (pruebas)' }] : []),
        ...(import.meta.env.DEV ? [{ href: '/admin#compliance-management', label: 'SG-SST (pruebas)' }] : []),
        ...(import.meta.env.DEV ? [{ href: '/admin#compliance-evidence-management', label: 'Cumplimiento legal (pruebas)' }] : []),
        ...(import.meta.env.DEV ? [{ href: '/admin#governance-management', label: 'Gobierno corporativo (pruebas)' }] : []),
        ...(import.meta.env.DEV ? [{ href: '/admin#human-resources-management', label: 'Talento Humano (pruebas)' }] : []),
        ...(import.meta.env.DEV ? [{ href: '/admin#contracts-management', label: 'Contratación (pruebas)' }] : []),
        ...(import.meta.env.DEV ? [{ href: '/admin#finance-management', label: 'Finanzas (pruebas)' }] : []),
        ...(import.meta.env.DEV ? [{ href: '/admin#social-security-management', label: 'Seguridad social (pruebas)' }] : []),
        { href: '/admin#commerce', label: 'Comercial' },
        { href: '/admin#orders', label: 'Pedidos' },
        { href: '/admin#sales-reports', label: 'Reportes de ventas' },
        { href: '/admin#reports-downloads', label: 'Descargas CSV' },
        { href: '/admin#roles', label: 'Roles y permisos' },
        { href: '/admin#employees-management', label: 'Empleados y asistencia' },
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

          {section === 'home' && (
            <>
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

          <StockAlertsPanel
            key={'stock-alerts-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />

          <StockTransferPanel
            key={'stock-transfers-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />

          <WorkflowsPanel
            key={'workflows-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />

          <SeoSettingsPanel
            key={'seo-settings-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />

          {context.data.permissions.includes('inventory.view') && (
            <ProductionManagement
              key={'production-' + context.data.active_branch.id}
              branchId={context.data.active_branch.id}
              permissions={context.data.permissions}
              csrfToken={accessToken}
            />
          )}

          <PurchasingManagement
            key={'purchasing-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />

          {import.meta.env.DEV && (
            <CustomerServiceManagement
              key={'customer-service-' + context.data.tenant.id + '-' + context.data.active_branch.id}
              tenantId={context.data.tenant.id}
              branchId={context.data.active_branch.id}
              permissions={context.data.permissions}
            />
          )}

          {import.meta.env.DEV && (
            <AssetsManagement
              key={'assets-' + context.data.tenant.id + '-' + (context.data.active_legal_entity?.id ?? '__unassigned__') + '-' + context.data.active_branch.id}
              tenantId={context.data.tenant.id}
              companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}
              branchId={context.data.active_branch.id}
              permissions={context.data.permissions}
            />
          )}

          {import.meta.env.DEV && (
            <PayrollManagement
              key={'payroll-' + context.data.tenant.id + '-' + (context.data.active_legal_entity?.id ?? '__unassigned__') + '-' + context.data.active_branch.id}
              tenantId={context.data.tenant.id}
              companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}
              branchId={context.data.active_branch.id}
              permissions={context.data.permissions}
            />
          )}

          {import.meta.env.DEV && (
            <ComplianceManagement
              key={'compliance-' + context.data.tenant.id + '-' + (context.data.active_legal_entity?.id ?? '__unassigned__') + '-' + context.data.active_branch.id}
              tenantId={context.data.tenant.id}
              companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}
              branchId={context.data.active_branch.id}
              permissions={context.data.permissions}
            />
          )}

          {import.meta.env.DEV && DevGovernanceManagement && DevComplianceEvidenceManagement && (
            <>
              {import.meta.env.DEV && DevContractsManagement && (
                <Suspense fallback={<p role="status">Cargando contratación ficticia…</p>}>
                  <DevContractsManagement
                    key={'contracts-' + context.data.tenant.id + '-' + (context.data.active_legal_entity?.id ?? '__unassigned__') + '-' + context.data.active_branch.id}
                    tenantId={context.data.tenant.id}
                    companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}
                    branchId={context.data.active_branch.id}
                    permissions={context.data.permissions}
                  />
                </Suspense>
              )}
              {import.meta.env.DEV && DevHumanResourcesManagement && (
                <Suspense fallback={<p role="status">Cargando talento humano ficticio…</p>}>
                <DevHumanResourcesManagement
                  key={'human-resources-' + context.data.tenant.id + '-' + (context.data.active_legal_entity?.id ?? '__unassigned__') + '-' + context.data.active_branch.id}
                  tenantId={context.data.tenant.id}
                  companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}
                  branchId={context.data.active_branch.id}
                  permissions={context.data.permissions}
                />
              </Suspense>
              )}
              {import.meta.env.DEV && DevSocialSecurityManagement && (                <Suspense fallback={<p role="status">Cargando seguridad social ficticia…</p>}>                  <DevSocialSecurityManagement                    key={'social-security-' + context.data.tenant.id + '-' + (context.data.active_legal_entity?.id ?? '__unassigned__') + '-' + context.data.active_branch.id}                    tenantId={context.data.tenant.id}                    companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}                    branchId={context.data.active_branch.id}                    permissions={context.data.permissions}                  />                </Suspense>              )}
              <Suspense fallback={<p role="status">Cargando gobierno ficticio…</p>}>
                            <DevGovernanceManagement
                              key={'governance-' + context.data.tenant.id + '-' + (context.data.active_legal_entity?.id ?? '__unassigned__') + '-' + context.data.active_branch.id}
                              tenantId={context.data.tenant.id}
                              companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}
                              branchId={context.data.active_branch.id}
                              permissions={context.data.permissions}
                            />
                          </Suspense>
              <Suspense fallback={<p role="status">Cargando cumplimiento ficticio…</p>}>
                            <DevComplianceEvidenceManagement
                              key={'compliance-evidence-' + context.data.tenant.id + '-' + (context.data.active_legal_entity?.id ?? '__unassigned__') + '-' + context.data.active_branch.id}
                              branchId={context.data.active_branch.id}
                              permissions={context.data.permissions}
                              csrfToken={accessToken}
                            />
                          </Suspense>
            </>
          )}

          {import.meta.env.DEV && DevFinanceManagement && (
            <Suspense fallback={<p role="status">Cargando finanzas ficticias…</p>}>
              <DevFinanceManagement
                key={'finance-' + context.data.tenant.id + '-' + (context.data.active_legal_entity?.id ?? '__unassigned__') + '-' + context.data.active_branch.id}
                tenantId={context.data.tenant.id}
                companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}
                branchId={context.data.active_branch.id}
                permissions={context.data.permissions}
              />
            </Suspense>
          )}

          <CommerceManagement
            key={'commerce-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />

          <OrdersManagement
            key={'orders-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />

          <SalesReportPanel
            key={'sales-reports-' + context.data.active_branch.id}
            permissions={context.data.permissions}
          />

          <ReportsDownloads
            key={'reports-downloads-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
          />

          <AccessManagement
            key={'access-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />

          <EmployeesManagement
            key={'employees-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            csrfToken={accessToken}
          />
            </>
          )}

          {section === 'site' && (
            <CmsManagement
              key={'cms-' + context.data.active_branch.id}
              branchId={context.data.active_branch.id}
              permissions={context.data.permissions}
              csrfToken={accessToken}
            />
          )}
        </>
      )}
    </AdminShell>
  );
}
