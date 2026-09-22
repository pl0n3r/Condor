<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use DomainException;

final class PermissionCatalog
{
    /** @var array<string, string> */
    private const MODULES = [
        'roles' => 'Roles y permisos',
        'users' => 'Usuarios',
        'branches' => 'Sedes',
        'catalog' => 'Catálogo',
        'inventory' => 'Inventario',
        'customers' => 'Clientes',
        'pricing' => 'Precios',
        'orders' => 'Pedidos',
        'site' => 'Sitio y e-commerce',
        'settings' => 'Configuración',
    ];

    /** @var array<string, string> */
    private const ACTIONS = [
        'view' => 'Ver',
        'create' => 'Crear',
        'update' => 'Editar',
        'delete' => 'Eliminar',
    ];

    /** @return list<string> */
    public static function moduleKeys(): array
    {
        return array_keys(self::MODULES);
    }

    /** @return list<string> */
    public static function actionKeys(): array
    {
        return array_keys(self::ACTIONS);
    }

    public static function normalizeModule(string $module): string
    {
        $module = trim($module);
        if (!in_array($module, self::moduleKeys(), true)) {
            throw new DomainException('El módulo solicitado no existe en el catálogo de permisos.');
        }

        return $module;
    }

    /** @param array<mixed> $actions @return list<string> */
    public static function normalizeActions(array $actions): array
    {
        $known = array_flip(self::actionKeys());
        $normalized = [];

        foreach ($actions as $action) {
            if (!is_string($action) || !isset($known[$action])) {
                throw new DomainException('La acción solicitada no existe en el catálogo de permisos.');
            }

            $normalized[$action] = true;
        }

        $result = array_keys($normalized);
        sort($result, SORT_STRING);

        return array_values($result);
    }

    /** @return list<string> */
    public static function all(): array
    {
        $permissions = [];
        foreach (array_keys(self::MODULES) as $module) {
            foreach (array_keys(self::ACTIONS) as $action) {
                $permissions[] = $module.'.'.$action;
            }
        }

        return $permissions;
    }

    /**
     * @return list<array{
     *   key: string,
     *   label: string,
     *   permissions: list<array{key: string, action: string, label: string}>
     * }>
     */
    public static function definitions(): array
    {
        $definitions = [];
        foreach (self::MODULES as $module => $label) {
            $permissions = [];
            foreach (self::ACTIONS as $action => $actionLabel) {
                $permissions[] = [
                    'key' => $module.'.'.$action,
                    'action' => $action,
                    'label' => $actionLabel,
                ];
            }

            $definitions[] = [
                'key' => $module,
                'label' => $label,
                'permissions' => $permissions,
            ];
        }

        return $definitions;
    }

    /** @param array<mixed> $permissions @return list<string> */
    public static function normalize(array $permissions): array
    {
        $known = array_flip(self::all());
        $normalized = [];

        foreach ($permissions as $permission) {
            if (!is_string($permission) || !isset($known[$permission])) {
                throw new DomainException('El rol contiene un permiso no reconocido.');
            }

            $normalized[$permission] = true;
        }

        $result = array_keys($normalized);
        sort($result, SORT_STRING);

        return array_values($result);
    }
}
