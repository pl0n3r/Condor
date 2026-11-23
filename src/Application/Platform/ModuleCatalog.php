<?php

declare(strict_types=1);

namespace App\Application\Platform;

/**
 * Single owner-maintained registry. Keys are the existing admin route hashes;
 * API prefixes are matched on segment boundaries, most specific first.
 *
 * Capabilities without a commercial equivalent remain legacy-enabled until
 * the owner explicitly overrides them. No entry may grant a role permission.
 */
final class ModuleCatalog
{
    /** @var array<string, array{name:string,group:string,capability:?string,permission:?string,api:list<string>}> */
    private const MODULES = [
        'commerce' => ['name' => 'Comercial', 'group' => 'Ventas', 'capability' => 'contacts', 'permission' => 'customers.view', 'api' => ['customers', 'pricing', 'sales']],
        'orders' => ['name' => 'Pedidos', 'group' => 'Ventas', 'capability' => 'sales-orders', 'permission' => 'orders.view', 'api' => ['orders']],
        'sales-reports' => ['name' => 'Reportes de ventas', 'group' => 'Ventas', 'capability' => 'reports', 'permission' => null, 'api' => ['reports/sales']],
        'reports-downloads' => ['name' => 'Descargas CSV', 'group' => 'Ventas', 'capability' => 'reports', 'permission' => null, 'api' => ['reports']],
        'customer-service' => ['name' => 'Servicio al cliente', 'group' => 'Ventas', 'capability' => null, 'permission' => null, 'api' => ['customer-service', 'customer-service-real']],
        'catalog' => ['name' => 'Catálogo', 'group' => 'Catálogo e inventario', 'capability' => 'catalog', 'permission' => 'catalog.view', 'api' => ['catalog']],
        'stock-alerts' => ['name' => 'Alertas de stock', 'group' => 'Catálogo e inventario', 'capability' => 'inventory', 'permission' => 'inventory.view', 'api' => ['inventory/stock-alerts']],
        'stock-transfers' => ['name' => 'Transferencias', 'group' => 'Catálogo e inventario', 'capability' => 'transfers', 'permission' => 'inventory.view', 'api' => ['inventory/branch-transfers']],
        'inventory' => ['name' => 'Inventario', 'group' => 'Catálogo e inventario', 'capability' => 'inventory', 'permission' => 'inventory.view', 'api' => ['inventory']],
        'production' => ['name' => 'Producción', 'group' => 'Catálogo e inventario', 'capability' => 'manufacturing', 'permission' => 'inventory.view', 'api' => ['production']],
        'purchasing' => ['name' => 'Compras', 'group' => 'Catálogo e inventario', 'capability' => 'purchasing', 'permission' => 'purchasing.view', 'api' => ['purchasing']],
        'assets-management' => ['name' => 'Activos', 'group' => 'Catálogo e inventario', 'capability' => null, 'permission' => null, 'api' => ['assets', 'assets-real']],
        'site' => ['name' => 'Sitio / E-commerce', 'group' => 'Sitio web', 'capability' => 'catalog', 'permission' => null, 'api' => ['cms', 'storefront']],
        'seo-settings' => ['name' => 'SEO', 'group' => 'Sitio web', 'capability' => 'custom-domain', 'permission' => null, 'api' => ['seo']],
        'employees-management' => ['name' => 'Empleados y asistencia', 'group' => 'Personas', 'capability' => null, 'permission' => null, 'api' => ['employees']],
        'human-resources-management' => ['name' => 'Talento humano', 'group' => 'Personas', 'capability' => null, 'permission' => 'users.view', 'api' => ['hr']],
        'payroll-management' => ['name' => 'Nómina', 'group' => 'Personas', 'capability' => null, 'permission' => 'users.view', 'api' => ['payroll']],
        'contracts-management' => ['name' => 'Contratación', 'group' => 'Personas', 'capability' => null, 'permission' => 'users.view', 'api' => ['contracts']],
        'social-security-management' => ['name' => 'Seguridad social', 'group' => 'Personas', 'capability' => null, 'permission' => 'users.view', 'api' => ['social-security']],
        'finance-management' => ['name' => 'Finanzas', 'group' => 'Finanzas y cumplimiento', 'capability' => null, 'permission' => 'settings.view', 'api' => ['finance']],
        'compliance-management' => ['name' => 'SG-SST', 'group' => 'Finanzas y cumplimiento', 'capability' => null, 'permission' => null, 'api' => ['sgsst']],
        'compliance-evidence-management' => ['name' => 'Cumplimiento legal', 'group' => 'Finanzas y cumplimiento', 'capability' => 'legal-cases', 'permission' => null, 'api' => ['legal-compliance', 'compliance']],
        'governance-management' => ['name' => 'Gobierno', 'group' => 'Finanzas y cumplimiento', 'capability' => null, 'permission' => null, 'api' => ['governance', 'governance-real']],
        'operations-processes' => ['name' => 'Procesos', 'group' => 'Operación', 'capability' => null, 'permission' => null, 'api' => ['operations/processes', 'operations-processes-real']],
        'workflows' => ['name' => 'Workflows', 'group' => 'Operación', 'capability' => null, 'permission' => null, 'api' => ['workflows']],
    ];

    /** @return list<array{key:string,name:string,group:string,capability:?string,permission:?string}> */
    public static function all(): array
    {
        $result = [];
        foreach (self::MODULES as $key => $module) {
            $result[] = [
                'key' => $key, 'name' => $module['name'],
                'group' => $module['group'], 'capability' => $module['capability'],
                'permission' => $module['permission'],
            ];
        }

        return $result;
    }

    public static function exists(string $key): bool
    {
        return isset(self::MODULES[$key]);
    }

    /**
     * A registered entry may still lack an authorized persistent adapter.
     * An owner override cannot activate employees until the privacy gate.
     */
    public static function isAvailable(string $key): bool
    {
        return self::exists($key) && $key !== 'employees-management';
    }

    public static function capability(string $key): ?string
    {
        return self::MODULES[$key]['capability'] ?? null;
    }

    public static function forApiPath(string $path): ?string
    {
        // The global endpoints below predate the branch-scoped API.
        // Keep the entire legacy HR namespace inside this security boundary.
        // Narrowing it could accidentally expose sibling HR APIs.
        if (str_starts_with($path, '/api/v1/hr/')) {
            return 'employees-management';
        }
        if (str_starts_with($path, '/api/reports/sales-')) {
            return 'sales-reports';
        }
        if (str_starts_with($path, '/api/catalog/import/')) {
            return 'catalog';
        }
        if (preg_match('~^/api/v1/branches/[^/]+/(.+)$~D', $path, $match) !== 1) {
            return null;
        }
        $tail = $match[1];
        $best = null;
        $bestLength = -1;
        foreach (self::MODULES as $key => $module) {
            foreach ($module['api'] as $prefix) {
                if (($tail === $prefix || str_starts_with($tail, $prefix.'/'))
                    && strlen($prefix) > $bestLength) {
                    $best = $key;
                    $bestLength = strlen($prefix);
                }
            }
        }

        return $best;
    }
}
