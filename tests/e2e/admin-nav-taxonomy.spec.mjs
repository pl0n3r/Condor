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
  expect(app).toContain('enabled?.has(key) === true');
  expect(app).not.toContain('enabled === null || enabled.has(key)');
  expect(app).toContain("permission === 'roles.view' || permission === 'users.view'");
  expect(app).toContain("if (key === 'documents' || key === 'design-preview') return import.meta.env.DEV;");
  expect(app).toContain('mayOpen(item.href');
  expect(app).toContain('if (items.length === 0) return [];');
  expect(app).toContain('if (items.length === 1)');
  expect(directory).toContain('visibleTenantHrefs(entries)');
  expect(directory).toContain("permissions.includes('orders.view')");
  expect(directory).toContain("permissions.includes('inventory.view')");
  expect(directory).toContain('payrollRealDataEnabled');
  expect(catalog).toContain("link('roles', 'Roles y permisos')");
});

test('seguridad del menú: backend sigue siendo autoridad y empleados no son disponibles', () => {
  const moduleCatalog = readFileSync(
    new URL('../../src/Application/Platform/ModuleCatalog.php',import.meta.url),'utf8');
  const adminController = readFileSync(
    new URL('../../src/Http/Controller/AdminController.php',import.meta.url),'utf8');
  expect(moduleCatalog).toContain("key !== 'employees-management'");
  expect(adminController).toContain('$this->moduleAccess->allowed($user, $tenant)');
  expect(adminController).toContain("'allowed_modules' => $allowedModules");
  expect(catalog).not.toMatch(/link\('employees-management'[^)]*,\s*'Empleados[^']*'\)\s*,\s*\/\//);
  expect(app).toContain("return enabled?.has(key) === true;");
  expect(directory).toContain('visibleTenantHrefs(entries)');
});
