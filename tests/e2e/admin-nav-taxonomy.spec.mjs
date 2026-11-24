import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';

const catalog = readFileSync(new URL('../../frontend/admin/CondorNavigationCatalog.ts', import.meta.url), 'utf8');
const app = readFileSync(new URL('../../frontend/admin/AdminApp.tsx', import.meta.url), 'utf8');
const directory = readFileSync(new URL('../../frontend/admin/ModuleDirectory.tsx', import.meta.url), 'utf8');

test('contrato offline: nueve raíces aprobadas, sin redundancias', () => {
  const rootMatch = catalog.match(/export const TENANT_ROOTS = \[([\s\S]*?)\] as const;/);
  expect(rootMatch).toBeTruthy();
  const roots = [...rootMatch[1].matchAll(/'([^']+)'/g)].map(match => match[1]);
  expect(roots).toEqual([
    'Escritorio', 'Ventas', 'Catálogo', 'Inventario', 'Operaciones',
    'Personal', 'Finanzas e informes', 'Sitio web', 'Empresa',
  ]);
  expect(new Set(roots).size).toBe(9);
  expect(catalog).toContain("...link('catalog', 'Catálogo')");
  expect(catalog).not.toContain("group: 'Catálogo e inventario'");
  expect(app).toContain('buildTenantNavigation({');
});

test('contrato offline: permisos SSR y pendientes usan navegación visible', () => {
  expect(app).toContain('enabled.has(key)');
  expect(app).toContain('mayOpen(item.href');
  expect(app).toContain('if (items.length === 0) return [];');
  expect(app).toContain('if (items.length === 1)');
  expect(directory).toContain('visibleTenantHrefs(entries)');
  expect(directory).toContain("permissions.includes('orders.view')");
  expect(directory).toContain("permissions.includes('inventory.view')");
  expect(directory).toContain('payrollRealDataEnabled');
  expect(catalog).toContain("link('roles', 'Roles y permisos')");
});
