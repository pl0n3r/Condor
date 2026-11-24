import type { AdminNavEntry, AdminNavItem } from './AdminShell';

/**
 * Catálogo canónico compartible. Solo describe destinos existentes;
 * autorización de servidor + ModuleAccess siguen siendo obligatorias.
 */
export const TENANT_ROOTS = [
  'Escritorio', 'Ventas', 'Catálogo', 'Inventario', 'Operaciones',
  'Personal', 'Finanzas e informes', 'Sitio web', 'Empresa',
] as const;

export type TenantNavigationOptions = Readonly<{
  section: 'home' | 'site';
  activeModule: string;
  development: boolean;
  customerServiceRealDataEnabled: boolean;
  canViewProduction: boolean;
  assetsRealDataEnabled: boolean;
  processesRealDataEnabled: boolean;
  hrRealDataEnabled: boolean;
  hrSyntheticPreviewEnabled: boolean;
  payrollRealDataEnabled: boolean;
  contractsRealDataEnabled: boolean;
  socialSecurityRealDataEnabled: boolean;
  financeRealDataEnabled: boolean;
  ohsRealDataEnabled: boolean;
  legalComplianceRealDataEnabled: boolean;
  governanceRealDataEnabled: boolean;
}>;

const link = (id: string, label: string): AdminNavItem =>
  ({ href: '/admin#' + id, label });

/** El mapa no concede permisos. Un caller debe aplicar los permisos efectivos SSR. */
export function buildTenantNavigation(flags: TenantNavigationOptions): AdminNavEntry[] {
  const dev = flags.development;
  return [
    { href: '/admin', label: 'Escritorio', icon: 'home',
      current: flags.section === 'home' && flags.activeModule === '' },
    { group: 'Ventas', icon: 'sales', items: [
      link('orders', 'Pedidos'), link('commerce', 'Clientes y precios'),
      ...(flags.customerServiceRealDataEnabled
        ? [link('customer-service', 'Servicio al cliente')]
        : dev ? [link('customer-service', 'Servicio al cliente (no habilitado)')] : []),
    ] },
    { ...link('catalog', 'Catálogo'), icon: 'catalog',
      current: flags.section === 'home' && flags.activeModule === 'catalog' },
    { group: 'Inventario', icon: 'catalog', items: [
      link('inventory', 'Existencias y movimientos'),
      link('stock-alerts', 'Alertas de stock'),
      link('stock-transfers', 'Transferencias'),
    ] },
    { group: 'Operaciones', icon: 'operations', items: [
      link('purchasing', 'Compras'),
      ...(flags.canViewProduction ? [link('production', 'Producción')] : []),
      ...(flags.assetsRealDataEnabled ? [link('assets-management', 'Activos')]
        : dev ? [link('assets-management', 'Activos (no habilitado)')] : []),
      ...(flags.processesRealDataEnabled ? [link('operations-processes', 'Procesos')]
        : dev ? [link('operations-processes', 'Procesos (no habilitado)')] : []),
      link('workflows', 'Flujos de trabajo'),
    ] },
    { group: 'Personal', icon: 'people', items: [
      link('employees-management', 'Empleados y asistencia'),
      ...(flags.hrRealDataEnabled ? [link('human-resources-management', 'Talento humano')]
        : flags.hrSyntheticPreviewEnabled
          ? [link('human-resources-management', 'Catálogos RR. HH. (pruebas)')] : []),
      ...(flags.contractsRealDataEnabled ? [link('contracts-management', 'Contratación')]
        : dev ? [link('contracts-management', 'Contratación (pruebas)')] : []),
      ...(flags.payrollRealDataEnabled ? [link('payroll-management', 'Nómina')]
        : dev ? [link('payroll-management', 'Nómina (pruebas)')] : []),
      ...(flags.socialSecurityRealDataEnabled ? [link('social-security-management', 'Seguridad social')]
        : dev ? [link('social-security-management', 'Seguridad social (pruebas)')] : []),
    ] },
    { group: 'Finanzas e informes', icon: 'finance', items: [
      ...(flags.financeRealDataEnabled ? [link('finance-management', 'Finanzas')]
        : dev ? [link('finance-management', 'Finanzas (pruebas)')] : []),
      link('sales-reports', 'Informes de ventas'),
      link('reports-downloads', 'Descargas CSV'),
    ] },
    { group: 'Sitio web', icon: 'site', items: [
      { href: '/admin/sitio', label: 'Sitio y tienda en línea',
        current: flags.section === 'site' },
      link('seo-settings', 'SEO'),
    ] },
    { group: 'Empresa', icon: 'admin', items: [
      link('roles', 'Roles y permisos'),
      ...(flags.ohsRealDataEnabled ? [link('compliance-management', 'SG-SST')]
        : dev ? [link('compliance-management', 'SG-SST (no habilitado)')] : []),
      ...(flags.legalComplianceRealDataEnabled
        ? [link('compliance-evidence-management', 'Cumplimiento legal')]
        : dev ? [link('compliance-evidence-management', 'Cumplimiento legal (no habilitado)')] : []),
      ...(flags.governanceRealDataEnabled ? [link('governance-management', 'Gobierno corporativo')]
        : dev ? [link('governance-management', 'Gobierno corporativo (no habilitado)')] : []),
      ...(dev ? [link('documents', 'Gestión documental (pruebas)')] : []),
    ] },
  ];
}

/** Solo los destinos visibles autorizan consultas de pendientes del escritorio. */
export function visibleTenantHrefs(entries: readonly AdminNavEntry[]): Set<string> {
  const hrefs = new Set<string>();
  for (const entry of entries) {
    if ('items' in entry) {
      for (const item of entry.items) hrefs.add(item.href);
    } else {
      hrefs.add(entry.href);
    }
  }
  return hrefs;
}
