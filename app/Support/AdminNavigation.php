<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Páginas del panel que puede ver un administrador, agrupadas como el menú
 * lateral. Lo usan el sidebar y el buscador (⌘K) para que ambos respeten
 * los mismos permisos.
 */
class AdminNavigation
{
    /** Grids que el buscador ofrece primero al buscar registros. */
    private const SEARCH_FIRST = ['android-users', 'locations', 'devices'];

    /**
     * @return list<array{key: string, label: string, icon: string, items: list<array{label: string, description: ?string, icon: string, url: string, active: bool, searchable: bool, searchRank: int}>}>
     */
    public static function groups(?Authenticatable $admin): array
    {
        if (! $admin) {
            return [];
        }

        $currentScreen = request()->route('screen');
        $groups = [];

        foreach (config('admin_nav_groups', []) as $group) {
            $items = [];

            foreach ($group['screens'] ?? [] as $key) {
                $screen = config("admin_screens.$key");
                if (! $screen || $key === 'dashboard' || ! empty($screen['exclude_from_nav']) || ! AdminRbac::canAccessScreen($admin, $key)) {
                    continue;
                }
                $route = $screen['route'] ?? null;
                $items[] = [
                    'label' => $screen['label'],
                    'description' => $screen['description'] ?? null,
                    'icon' => $screen['icon'] ?? 'squares-2x2',
                    'url' => $route ? route($route) : route('admin.screens.index', $key),
                    'active' => $currentScreen === $key || ($route && request()->routeIs($screen['active_pattern'] ?? $route.'*')),
                    // Los grids CRUD aceptan ?q= para buscar registros
                    'searchable' => ! $route && ! empty($screen['model']),
                    'searchRank' => ($rank = array_search($key, self::SEARCH_FIRST, true)) === false ? 99 : $rank,
                ];
            }

            $key = $group['key'] ?? '';
            if ($key === 'system' && $admin->can('roles.edit')) {
                $items[] = self::item('Permisos por rol', 'adjustments-horizontal', 'admin.rbac.matrix.index', 'admin.rbac.*');
            }
            if ($key === 'system' && $admin->can('system_settings.view')) {
                $items[] = self::item('Parámetros del sistema', 'cog-6-tooth', 'admin.system-settings.edit', 'admin.system-settings.*');
            }
            if ($key === 'system' && Gate::forUser($admin)->allows('viewPulse')) {
                $items[] = self::item('Pulse (métricas)', 'bolt', 'pulse', 'pulse');
            }

            $groups[] = ['key' => $key, 'label' => $group['label'], 'icon' => $group['icon'] ?? 'squares-2x2', 'items' => $items];
        }

        return $groups;
    }

    /**
     * Entradas del buscador: inicio más todas las páginas del menú.
     *
     * @return list<array{label: string, group: string, url: string, searchable: bool, searchRank: int}>
     */
    public static function searchEntries(?Authenticatable $admin): array
    {
        if (! $admin) {
            return [];
        }

        $entries = [];
        $entries[] = ['label' => 'Inicio', 'group' => 'Dashboard', 'url' => route('admin.dashboard'), 'searchable' => false, 'searchRank' => 99];

        foreach (self::groups($admin) as $group) {
            foreach ($group['items'] as $item) {
                $entries[] = [
                    'label' => $item['label'],
                    'group' => $group['label'],
                    'url' => $item['url'],
                    'searchable' => $item['searchable'],
                    'searchRank' => $item['searchRank'] ?? 99,
                ];
            }
        }

        return $entries;
    }

    /**
     * @return array{label: string, icon: string, url: string, active: bool, searchable: bool, searchRank: int}
     */
    private static function item(string $label, string $icon, string $route, string $activePattern): array
    {
        return [
            'label' => $label,
            'description' => null,
            'icon' => $icon,
            'url' => route($route),
            'active' => request()->routeIs($activePattern),
            'searchable' => false,
            'searchRank' => 99,
        ];
    }
}
