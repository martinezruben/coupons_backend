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

        foreach (MenuStructure::load() as $group) {
            if (! $group['visible']) {
                continue;
            }
            $items = [];

            foreach ($group['items'] as $entry) {
                if (! ($entry['visible'] ?? true) && ! in_array($entry['ref'], MenuStructure::LOCKED, true)) {
                    continue;
                }
                $meta = MenuStructure::meta($entry['kind'], $entry['ref']);
                $label = $entry['label'] ?: $meta['label'];
                $icon = MenuIcons::valid($entry['icon']) ? $entry['icon'] : $meta['icon'];

                if ($entry['kind'] === 'screen') {
                    $key = $entry['ref'];
                    $screen = config("admin_screens.$key");
                    if (! $screen || ! empty($screen['exclude_from_nav']) || ! AdminRbac::canAccessScreen($admin, $key)) {
                        continue;
                    }
                    $route = $screen['route'] ?? null;
                    $items[] = [
                        'label' => $label,
                        'description' => $screen['description'] ?? null,
                        'icon' => $icon,
                        'url' => $route ? route($route) : route('admin.screens.index', $key),
                        'active' => $currentScreen === $key || ($route && request()->routeIs($screen['active_pattern'] ?? $route.'*')),
                        // Los grids CRUD aceptan ?q= para buscar registros
                        'searchable' => ! $route && ! empty($screen['model']),
                        'searchRank' => ($rank = array_search($key, self::SEARCH_FIRST, true)) === false ? 99 : $rank,
                        'new_tab' => false,
                    ];
                } elseif ($entry['kind'] === 'special') {
                    $special = MenuStructure::SPECIALS[$entry['ref']] ?? null;
                    if ($special === null || ! self::specialAllowed($entry['ref'], $admin)) {
                        continue;
                    }
                    $items[] = [
                        'label' => $label, 'description' => null, 'icon' => $icon, 'url' => route($special['route']),
                        'active' => request()->routeIs($special['pattern']), 'searchable' => false, 'searchRank' => 99, 'new_tab' => false,
                    ];
                } else { // enlace propio
                    if (empty($entry['url']) || ($entry['permission'] && ! $admin->can($entry['permission']))) {
                        continue;
                    }
                    $items[] = [
                        'label' => $label, 'description' => null, 'icon' => $icon, 'url' => $entry['url'],
                        'active' => rtrim((string) parse_url($entry['url'], PHP_URL_PATH), '/') === rtrim('/'.request()->path(), '/') && ! str_starts_with($entry['url'], 'http'),
                        'searchable' => false, 'searchRank' => 99, 'new_tab' => (bool) $entry['new_tab'],
                    ];
                }
            }

            $groups[] = [
                'key' => $group['key'], 'label' => $group['label'],
                'icon' => MenuIcons::valid($group['icon']) ? $group['icon'] : 'squares-2x2', 'items' => $items,
            ];
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
     * Si la opción del menú se renombró, el título de su pantalla cambia igual: se sustituye el título
     * original («Usuarios administradores») por el nombre que se le puso en el menú. Los títulos
     * dinámicos («Lote LOT-…», «Editar regla…») no coinciden con ninguna opción y se quedan como están.
     */
    public static function renamedTitle(string $title): string
    {
        $request = request();
        $map = $request->attributes->get('menu_renames');
        if ($map === null) {
            $map = [];
            foreach (MenuStructure::load() as $group) {
                foreach ($group['items'] as $item) {
                    if ($item['kind'] === 'link' || empty($item['label'])) {
                        continue;
                    }
                    $original = MenuStructure::meta($item['kind'], $item['ref'])['label'];
                    if ($original !== $item['label']) {
                        $map[$original] = $item['label'];
                    }
                }
            }
            $request->attributes->set('menu_renames', $map);
        }

        return $map[$title] ?? $title;
    }

    private static function specialAllowed(string $key, Authenticatable $admin): bool
    {
        return match ($key) {
            'rbac-matrix' => $admin->can('roles.edit'),
            'rbac-users', 'menu-editor' => AdminRbac::isSuperAdmin($admin),
            'system-settings' => $admin->can('system_settings.view'),
            'pulse' => Gate::forUser($admin)->allows('viewPulse'),
            default => false,
        };
    }
}
