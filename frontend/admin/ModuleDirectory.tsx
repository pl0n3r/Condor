import type { AdminNavEntry } from './AdminShell';

/** Descripción de una línea por módulo, para el directorio del inicio. */
const HINTS: Record<string, string> = {
  commerce: 'Clientes, categorías y listas de precios.',
  orders: 'Crear, seguir y exportar pedidos.',
  'sales-reports': 'Ventas por periodo y por sede.',
  'reports-downloads': 'Descarga de informes en CSV.',
  'customer-service': 'Casos y atención a clientes.',
  catalog: 'Productos, variantes y políticas de stock.',
  inventory: 'Existencias, fuentes y ajustes.',
  'stock-alerts': 'Avisos de stock bajo.',
  'stock-transfers': 'Traslados entre sedes.',
  production: 'Recetas y órdenes de producción.',
  purchasing: 'Solicitudes y órdenes de compra.',
  'assets-management': 'Activos de la empresa.',
  'seo-settings': 'Títulos y descripciones para buscadores.',
  'employees-management': 'Empleados, cargos y asistencia.',
  'human-resources-management': 'Vinculaciones, novedades y movimientos.',
  'payroll-management': 'Nómina y novedades de pago.',
  'contracts-management': 'Contratos de trabajo y su vigencia.',
  'social-security-management': 'Afiliaciones y aportes.',
  'finance-management': 'Asientos, periodos y cierres.',
  'compliance-management': 'Seguridad y salud en el trabajo.',
  'compliance-evidence-management': 'Evidencia de cumplimiento legal.',
  'governance-management': 'Órganos, actas y decisiones.',
  workflows: 'Flujos de trabajo y aprobaciones.',
  'operations-processes': 'Procesos y procedimientos.',
  roles: 'Roles, permisos y accesos.',
  documents: 'Expedientes y evidencias sintéticas; sin firma legal.',
};

function moduleId(href: string): string {
  const at = href.indexOf('#');
  return at >= 0 ? href.slice(at + 1) : '';
}

/** Mapa del administrador: cada módulo agrupado igual que el menú, con una línea que explica para qué sirve. */
export function ModuleDirectory({ entries }: Readonly<{ entries: readonly AdminNavEntry[] }>) {
  const groups = entries.filter((entry): entry is Extract<AdminNavEntry, { group: string }> => 'group' in entry);
  return (
    <section className="module-directory" aria-labelledby="module-directory-title">
      <h2 id="module-directory-title">Módulos</h2>
      <div className="module-directory-groups">
        {groups.map((group) => (
          <div className="module-directory-group" key={group.group}>
            <h3>{group.group}</h3>
            <ul>
              {group.items.map((item) => (
                <li key={item.href}>
                  <a href={item.href}>
                    <strong>{item.label.replace(' (pruebas)', '')}</strong>
                    <span>{HINTS[moduleId(item.href)] ?? ''}</span>
                    {item.label.includes('(pruebas)') && <em>En pruebas</em>}
                  </a>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>
    </section>
  );
}
