import { lazy, Suspense, useEffect, useState } from 'react';
import { AccessManagement } from './AccessManagement';
import { GovernanceManagement } from './GovernanceManagement';
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
import { HumanResourcesManagement } from './HumanResourcesManagement';
import { ProductionManagement } from './ProductionManagement';
import { PurchasingManagement } from './PurchasingManagement';

import { PayrollManagement } from './PayrollManagement';
import { ContractsRealManagement } from './ContractsManagement';
import { SocialSecurityRealManagement } from './SocialSecurityManagement';
import { FinanceRealManagement } from './FinanceManagement';
import { OccupationalRealManagement as ComplianceManagement, LegalComplianceRealManagement } from './ComplianceManagement';
import { AdminShell, type AdminNavEntry } from './AdminShell';
import { buildTenantNavigation } from './CondorNavigationCatalog';
import { ModuleDirectory } from './ModuleDirectory';
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

const DevContractsManagement = import.meta.env.DEV
  ? lazy(async () => ({ default: (await import('./ContractsManagement')).ContractsManagement }))
  : null;

const DevContractsLifecyclePreview = import.meta.env.DEV
  ? lazy(async () => ({ default: (await import('./ContractsLifecyclePreview')).ContractsLifecyclePreview }))
  : null;

const AsyncOperationsProcessesPanel = lazy(async () => ({
  default: (await import('./OperationsProcessesPanel')).OperationsProcessesPanel,
}));

const AsyncCustomerServiceManagement = lazy(async () => ({
  default: (await import('./CustomerServiceManagement')).CustomerServiceManagement,
}));

const AsyncAssetsManagement = lazy(async () => ({
  default: (await import('./AssetsManagement')).AssetsManagement,
}));

const DevDocumentsManagement = import.meta.env.DEV
  ? lazy(async () => ({ default: (await import('./DocumentsManagement')).DocumentsManagement }))
  : null;

const DevFinanceManagement = import.meta.env.DEV
  ? lazy(async () => ({ default: (await import('./FinanceManagement')).FinanceManagement }))
  : null;

export function AdminApp({
  version,
  logoutToken,
  accessToken,
  section,
}: AdminAppProps) {
  // A single hash selector controls which panel is mounted. AdminShell keeps
  // the existing navigation, breadcrumbs and browser history semantics.
  const [activeModule, setActiveModule] = useState(() =>
    typeof window === 'undefined' ? '' : window.location.hash.slice(1));
  useEffect(() => {
    const syncModule = () => setActiveModule(window.location.hash.slice(1));
    window.addEventListener('hashchange', syncModule);
    return () => window.removeEventListener('hashchange', syncModule);
  }, []);

  // The server alone sets this signal; absent/any other value means OFF.
  const hrRealDataEnabled = typeof document !== 'undefined'
    && document.getElementById('condor-admin-root')?.dataset.hrRealDataEnabled === 'true';
  const payrollRealDataEnabled = typeof document !== 'undefined'
    && document.getElementById('condor-admin-root')?.dataset.payrollRealDataEnabled === 'true';
  const contractsRealDataEnabled = typeof document !== 'undefined'
    && document.getElementById('condor-admin-root')?.dataset.contractsRealDataEnabled === 'true';
  const socialSecurityRealDataEnabled = typeof document !== 'undefined'
    && document.getElementById('condor-admin-root')?.dataset.socialSecurityRealDataEnabled === 'true';
  const financeRealDataEnabled = typeof document !== 'undefined'
    && document.getElementById('condor-admin-root')?.dataset.financeRealDataEnabled === 'true';
  const ohsRealDataEnabled = typeof document !== 'undefined'
    && document.getElementById('condor-admin-root')?.dataset.ohsRealDataEnabled === 'true';
  const legalComplianceRealDataEnabled = typeof document !== 'undefined'
    && document.getElementById('condor-admin-root')?.dataset.legalComplianceRealDataEnabled === 'true';
  const governanceRealDataEnabled = typeof document !== 'undefined'
    && document.getElementById('condor-admin-root')?.dataset.governanceRealDataEnabled === 'true';
  const customerServiceRealDataEnabled = typeof document !== 'undefined'
    && document.getElementById('condor-admin-root')?.dataset.customerServiceRealDataEnabled === 'true';
  const assetsRealDataEnabled = typeof document !== 'undefined'
    && document.getElementById('condor-admin-root')?.dataset.assetsRealDataEnabled === 'true';
  const processesRealDataEnabled = typeof document !== 'undefined'
    && document.getElementById('condor-admin-root')?.dataset.operationsProcessesRealDataEnabled === 'true';
  // Synthetic catalogs are available only in dev with an explicit root opt-in.
  // This flag never enables the HR real-data API or overrides server permissions.
  const hrSyntheticPreviewEnabled = import.meta.env.DEV && typeof document !== 'undefined'
    && document.getElementById('condor-admin-root')?.dataset.hrSyntheticPreviewEnabled === 'true';
  // Solo DEV + opt-in SSR del servidor; la API de contratación real no habilita previews.
  const contractsSyntheticPreviewEnabled = import.meta.env.DEV && !contractsRealDataEnabled
    && typeof document !== 'undefined'
    && document.getElementById('condor-admin-root')?.dataset.contractsSyntheticApiEnabled === 'true';
  const [context, setContext] = useState<ContextState>({
    status: 'loading',
  });
  const activeCompanyId = context.status === 'ready'
    ? (context.data.active_legal_entity?.id ?? '__unassigned__') : '__unassigned__';
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

  // SSR is authoritative: no client-supplied tenant/user selector can add permissions.
  const granted = typeof document === 'undefined' ? undefined
    : document.getElementById('condor-admin-root')?.dataset.enabledModules;
  const enabled = granted === undefined ? null : new Set(granted.split(',').filter(Boolean));
  // La ausencia del alcance SSR NO equivale a un permiso. El catálogo
  // sólo representa módulos que el servidor autorizó para este tenant.
  const mayOpen = (key: string) => {
    if (key === '') return true; // Escritorio sin consulta de módulo.
    if (key === 'roles') {
      return readyContext?.permissions.some((permission) =>
        permission === 'roles.view' || permission === 'users.view') === true;
    }
    if (key === 'documents' || key === 'design-preview') return import.meta.env.DEV;
    return enabled?.has(key) === true;
  };

  const navEntries: AdminNavEntry[] = buildTenantNavigation({
    section,
    activeModule,
    development: import.meta.env.DEV,
    customerServiceRealDataEnabled,
    canViewProduction: readyContext?.permissions.includes('inventory.view') ?? false,
    assetsRealDataEnabled,
    processesRealDataEnabled,
    hrRealDataEnabled,
    hrSyntheticPreviewEnabled,
    payrollRealDataEnabled,
    contractsRealDataEnabled,
    socialSecurityRealDataEnabled,
    financeRealDataEnabled,
    ohsRealDataEnabled,
    legalComplianceRealDataEnabled,
    governanceRealDataEnabled,
  });
  // Secciones vacías se ocultan. Las de un solo destino son enlaces directos,
  // sin acordeones redundantes. SSR decide qué módulos existen para el tenant.
  const visibleNavEntries: AdminNavEntry[] = navEntries.flatMap((entry): AdminNavEntry[] => {
    if (!('items' in entry)) {
      const key = entry.href === '/admin/sitio' ? 'site' : entry.href.split('#')[1] ?? '';
      return mayOpen(key) ? [entry] : [];
    }
    const items = entry.items.filter((item) =>
      mayOpen(item.href === '/admin/sitio' ? 'site' : item.href.split('#')[1] ?? ''));
    if (items.length === 0) return [];
    if (items.length === 1) {
      const item = items[0];
      return [{ ...item, icon: entry.icon,
        current: item.href === '/admin/sitio' ? section === 'site'
          : section === 'home' && item.href === '/admin#' + activeModule }];
    }
    return [{ ...entry, items }];
  });

  return (
    <AdminShell
      version={version}
      logoutToken={logoutToken}
      ariaLabel="Administrador de Condor"
      navLabel="Navegación principal"
      navItems={visibleNavEntries}
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
            !mayOpen(activeModule) ? (
              <section className="ui-page" role="alert" aria-label="Módulo no habilitado">
                <h2>Módulo no habilitado</h2>
                <p>Este módulo está desactivado para tu empresa o usuario.</p>
                <a className="button button-secondary" href="/admin">Volver al inicio</a>
              </section>
            ) : <>
          {activeModule === '' && (<OverviewGrid
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
          />)}

          {activeModule === '' && <ModuleDirectory entries={visibleNavEntries} />}

          {activeModule === 'documents' && DevDocumentsManagement && (
            <section id="documents-module" aria-label="Gestión documental (pruebas)">
              <Suspense fallback={<p role="status">Cargando gestión documental ficticia…</p>}>
                <DevDocumentsManagement
                  key={'documents-' + context.data.tenant.id + '-' + (context.data.active_branch.legal_entity?.id ?? '__unassigned__') + '-' + context.data.active_branch.id}
                  tenantId={context.data.tenant.id}
                  legalEntityId={context.data.active_branch.legal_entity?.id ?? '__unassigned__'}
                  branchId={context.data.active_branch.id}
                  permissions={context.data.permissions}
                  csrfToken={accessToken}
                />
              </Suspense>
            </section>
          )}

          {activeModule === 'catalog' && (<CatalogManagement
            key={'catalog-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />)}

          {activeModule === 'inventory' && (<InventoryManagement
            key={'inventory-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />)}

          {activeModule === 'stock-alerts' && (<StockAlertsPanel
            key={'stock-alerts-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />)}

          {activeModule === 'stock-transfers' && (<StockTransferPanel
            key={'stock-transfers-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />)}

          {activeModule === 'workflows' && (<WorkflowsPanel
            key={'workflows-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />)}

          {activeModule === 'operations-processes' && (processesRealDataEnabled || import.meta.env.DEV) && (
            <Suspense fallback={<p role="status">Cargando procesos…</p>}>
              <AsyncOperationsProcessesPanel
              key={'operations-processes-' + context.data.tenant.id + '-'
                + activeCompanyId + '-'
                + context.data.active_branch.id}
              enabled={processesRealDataEnabled}
              tenantId={context.data.tenant.id}
              companyId={context.data.active_legal_entity?.id !== undefined
                && context.data.active_branch.legal_entity?.id === context.data.active_legal_entity.id
                ? context.data.active_legal_entity.id : '__unassigned__'}
              branchId={context.data.active_branch.id}
              permissions={context.data.permissions}
              csrfToken={accessToken}
            />
            </Suspense>
          )}

          {activeModule === 'seo-settings' && (<SeoSettingsPanel
            key={'seo-settings-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />)}

          {activeModule === 'production' && context.data.permissions.includes('inventory.view') && (
            <ProductionManagement
              key={'production-' + context.data.active_branch.id}
              branchId={context.data.active_branch.id}
              permissions={context.data.permissions}
              csrfToken={accessToken}
            />
          )}

          {activeModule === 'purchasing' && (<PurchasingManagement
            key={'purchasing-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />)}

          {activeModule === 'customer-service' && (customerServiceRealDataEnabled || import.meta.env.DEV) && (
            <Suspense fallback={<p role="status">Cargando servicio al cliente…</p>}>
            <AsyncCustomerServiceManagement
              key={'customer-service-' + context.data.tenant.id + '-'
                + activeCompanyId + '-' + context.data.active_branch.id}
              enabled={customerServiceRealDataEnabled}
              tenantId={context.data.tenant.id}
              companyId={context.data.active_legal_entity?.id !== undefined
                && context.data.active_branch.legal_entity?.id === context.data.active_legal_entity.id
                ? context.data.active_legal_entity.id : '__unassigned__'}
              branchId={context.data.active_branch.id}
              permissions={context.data.permissions}
              csrfToken={accessToken}
            />
            </Suspense>
          )}

          {activeModule === 'assets-management' && (assetsRealDataEnabled || import.meta.env.DEV) && (
            <Suspense fallback={<p role="status">Cargando módulo de activos…</p>}>
              <AsyncAssetsManagement
                key={'assets-' + context.data.tenant.id + '-'
                  + activeCompanyId + '-'
                  + context.data.active_branch.id}
                enabled={assetsRealDataEnabled}
                tenantId={context.data.tenant.id}
                companyId={context.data.active_legal_entity?.id !== undefined
                  && context.data.active_branch.legal_entity?.id === context.data.active_legal_entity.id
                  ? context.data.active_legal_entity.id : '__unassigned__'}
                branchId={context.data.active_branch.id}
                permissions={context.data.permissions}
                csrfToken={accessToken}
              />
            </Suspense>
          )}

          {activeModule === 'payroll-management' && (payrollRealDataEnabled || import.meta.env.DEV) && (
            <PayrollManagement
              key={'payroll-' + context.data.tenant.id + '-' + activeCompanyId + '-' + context.data.active_branch.id}
              tenantId={context.data.tenant.id}
              companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}
              branchId={context.data.active_branch.id}
              permissions={context.data.permissions}
              csrfToken={accessToken}
              enabled={payrollRealDataEnabled}
            />
          )}

          {activeModule === 'social-security-management' && (socialSecurityRealDataEnabled || import.meta.env.DEV) && (
            <SocialSecurityRealManagement
              key={'social-real-' + context.data.tenant.id + '-' + activeCompanyId + '-' + context.data.active_branch.id}
              tenantId={context.data.tenant.id}
              companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}
              branchId={context.data.active_branch.id}
              permissions={context.data.permissions}
              csrfToken={accessToken}
              enabled={socialSecurityRealDataEnabled}
            />
          )}

          {activeModule === 'contracts-management' && contractsRealDataEnabled && (
            <ContractsRealManagement
              key={'contracts-real-' + context.data.tenant.id + '-' + activeCompanyId + '-' + context.data.active_branch.id}
              tenantId={context.data.tenant.id}
              companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}
              branchId={context.data.active_branch.id}
              permissions={context.data.permissions}
              csrfToken={accessToken}
              enabled={contractsRealDataEnabled}
            />
          )}
          {activeModule === 'compliance-management' && (ohsRealDataEnabled || import.meta.env.DEV) && (
            <ComplianceManagement
              key={'compliance-real-' + context.data.tenant.id + '-' + activeCompanyId + '-' + context.data.active_branch.id}
              tenantId={context.data.tenant.id}
              companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}
              branchId={context.data.active_branch.id}
              permissions={context.data.permissions}
              csrfToken={accessToken}
              enabled={ohsRealDataEnabled}
            />
          )}

          {import.meta.env.DEV && (
            <>
              {activeModule === 'contracts-management' && import.meta.env.DEV && !contractsRealDataEnabled && DevContractsManagement && (
                <Suspense fallback={<p role="status">Cargando contratación ficticia…</p>}>
                  <DevContractsManagement
                    key={'contracts-' + context.data.tenant.id + '-' + activeCompanyId + '-' + context.data.active_branch.id}
                    tenantId={context.data.tenant.id}
                    companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}
                    branchId={context.data.active_branch.id}
                    permissions={context.data.permissions}
                  />
                </Suspense>
              )}
              {activeModule === 'contracts-management' && contractsSyntheticPreviewEnabled && DevContractsLifecyclePreview
                && context.data.permissions.includes('inventory.view')
                && context.data.active_legal_entity?.id !== undefined
                && context.data.active_branch.legal_entity?.id === context.data.active_legal_entity?.id && (
                <Suspense fallback={<p role="status">Cargando ciclo contractual de prueba…</p>}>
                  <DevContractsLifecyclePreview
                    key={'contracts-lifecycle-' + context.data.tenant.id + '-' + activeCompanyId + '-' + context.data.active_branch.id}
                    tenantId={context.data.tenant.id}
                    companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}
                    branchId={context.data.active_branch.id}
                    permissions={context.data.permissions}
                  />
                </Suspense>
              )}
              {activeModule === 'human-resources-management' && hrSyntheticPreviewEnabled && !hrRealDataEnabled && (
                <Suspense fallback={<p role="status">Cargando catálogos sintéticos…</p>}>
                  <HumanResourcesManagement
                    key={'m01-synthetic-' + context.data.tenant.id + '-' + context.data.active_branch.id}
                    tenantId={context.data.tenant.id}
                    companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}
                    branchId={context.data.active_branch.id}
                    permissions={context.data.permissions}
                  />
                </Suspense>
              )}
            </>
          )}


              {activeModule === 'human-resources-management' && (
                <>
                  {hrRealDataEnabled && (
                    <Suspense fallback={<p role="status">Cargando talento humano…</p>}>
                      <HumanResourcesManagement
                        key={'human-resources-' + context.data.tenant.id + '-' + activeCompanyId + '-' + context.data.active_branch.id}
                        tenantId={context.data.tenant.id}
                        companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}
                        branchId={context.data.active_branch.id}
                        permissions={context.data.permissions}
                      />
                    </Suspense>
                  )}
                </>
              )}

          {activeModule === 'governance-management' && (governanceRealDataEnabled || import.meta.env.DEV) && (
            <GovernanceManagement
              key={'governance-real-' + context.data.tenant.id + '-'
                + activeCompanyId + '-'
                + context.data.active_branch.id}
              enabled={governanceRealDataEnabled}
              tenantId={context.data.tenant.id}
              companyId={context.data.active_legal_entity?.id !== undefined
                && context.data.active_branch.legal_entity?.id === context.data.active_legal_entity.id
                ? context.data.active_legal_entity.id : '__unassigned__'}
              branchId={context.data.active_branch.id}
              permissions={context.data.permissions}
              csrfToken={accessToken}
            />
          )}

          {activeModule === 'compliance-evidence-management' && (legalComplianceRealDataEnabled ? (
            <LegalComplianceRealManagement
              key={'legal-real-' + context.data.tenant.id + '-' + activeCompanyId + '-' + context.data.active_branch.id}
              enabled={legalComplianceRealDataEnabled}
              tenantId={context.data.tenant.id}
              companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}
              branchId={context.data.active_branch.id}
              permissions={context.data.permissions}
              csrfToken={accessToken}
            />
          ) : import.meta.env.DEV ? (
            <section id="compliance-evidence-management" className="ui-page catalog-panel" aria-label="Cumplimiento legal no habilitado">
              <h2>Cumplimiento legal</h2>
              <p role="status">Este módulo aún no está habilitado. No se han consultado ni mostrado registros.</p>
            </section>
          ) : null)}

          {activeModule === 'finance-management' && financeRealDataEnabled && (
            <FinanceRealManagement
              key={'finance-real-' + context.data.tenant.id + '-' + activeCompanyId + '-' + context.data.active_branch.id}
              enabled={financeRealDataEnabled}
              tenantId={context.data.tenant.id}
              companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}
              branchId={context.data.active_branch.id}
              permissions={context.data.permissions}
              csrfToken={accessToken}
            />
          )}

          {activeModule === 'finance-management' && import.meta.env.DEV && DevFinanceManagement && !financeRealDataEnabled && (
            <Suspense fallback={<p role="status">Cargando finanzas ficticias…</p>}>
              <DevFinanceManagement
                key={'finance-' + context.data.tenant.id + '-' + activeCompanyId + '-' + context.data.active_branch.id}
                tenantId={context.data.tenant.id}
                companyId={context.data.active_legal_entity?.id ?? '__unassigned__'}
                branchId={context.data.active_branch.id}
                permissions={context.data.permissions}
              />
            </Suspense>
          )}

          {activeModule === 'commerce' && (<CommerceManagement
            key={'commerce-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />)}

          {activeModule === 'orders' && (<OrdersManagement
            key={'orders-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />)}

          {activeModule === 'sales-reports' && (<SalesReportPanel
            key={'sales-reports-' + context.data.active_branch.id}
            permissions={context.data.permissions}
          />)}

          {activeModule === 'reports-downloads' && (<ReportsDownloads
            key={'reports-downloads-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
          />)}

          {activeModule === 'roles' && (<AccessManagement
            key={'access-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            permissions={context.data.permissions}
            csrfToken={accessToken}
          />)}

          {activeModule === 'employees-management' && (<EmployeesManagement
            key={'employees-' + context.data.active_branch.id}
            branchId={context.data.active_branch.id}
            csrfToken={accessToken}
          />)}
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
