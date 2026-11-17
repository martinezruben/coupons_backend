<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estructura del menú del panel: grupos (padres) y opciones (hijos). Se guarda en `menu_groups` y
 * `menu_items`; sin datos, o con pantallas nuevas que aún no están en la tabla, se completa con los
 * valores de config/admin_nav_groups.php. Una opción puede ser una pantalla, un acceso especial o un enlace propio.
 *
 * @phpstan-type Item array{kind: string, ref: ?string, label: ?string, icon: ?string, url: ?string, permission: ?string, new_tab: bool, visible: bool}
 * @phpstan-type Group array{key: string, label: string, icon: string, visible: bool, items: list<Item>}
 */
final class MenuStructure
{
    /** Accesos del menú que no son una pantalla del grid: etiqueta, icono, ruta y patrón de activo. */
    public const SPECIALS = [
        'rbac-matrix' => ['label' => 'Permisos por rol', 'icon' => 'adjustments-horizontal', 'route' => 'admin.rbac.matrix.index', 'pattern' => 'admin.rbac.matrix.*'],
        'rbac-users' => ['label' => 'Permisos por usuario', 'icon' => 'finger-print', 'route' => 'admin.rbac.users.index', 'pattern' => 'admin.rbac.users.*'],
        'system-settings' => ['label' => 'Parámetros del sistema', 'icon' => 'cog-6-tooth', 'route' => 'admin.system-settings.edit', 'pattern' => 'admin.system-settings.*'],
        'pulse' => ['label' => 'Pulse (métricas)', 'icon' => 'bolt', 'route' => 'pulse', 'pattern' => 'pulse'],
        'menu-editor' => ['label' => 'Menú del panel', 'icon' => 'list-bullet', 'route' => 'admin.menu.index', 'pattern' => 'admin.menu.*'],
    ];

    /** El editor nunca se puede ocultar: si no, nadie podría arreglar un menú mal configurado. */
    public const LOCKED = ['menu-editor'];

    /** Estructura original (la del código). */
    public static function defaults(): array
    {
        $groups = [];
        foreach (config('admin_nav_groups', []) as $g) {
            $items = [];
            foreach ($g['screens'] ?? [] as $screen) {
                if (self::navigableScreen($screen)) {
                    $items[] = self::item('screen', $screen);
                }
            }
            if (($g['key'] ?? '') === 'system') {
                foreach (array_keys(self::SPECIALS) as $special) {
                    $items[] = self::item('special', $special);
                }
            }
            $groups[] = ['key' => $g['key'], 'label' => $g['label'], 'icon' => $g['icon'] ?? 'squares-2x2', 'visible' => true, 'items' => $items];
        }

        return $groups;
    }

    /** Estructura vigente: la guardada, más lo que el código tenga y todavía no esté en ella. */
    public static function load(): array
    {
        $groups = self::stored() ?? self::defaults();
        $placed = collect($groups)->pluck('items')->flatten(1)->map(fn ($i) => $i['kind'].':'.$i['ref'])->all();

        foreach (self::defaults() as $default) {
            foreach ($default['items'] as $item) {
                if (in_array($item['kind'].':'.$item['ref'], $placed, true)) {
                    continue;
                }
                $idx = collect($groups)->search(fn ($g) => $g['key'] === $default['key']);
                $idx = $idx === false ? array_key_last($groups) : $idx;
                if ($idx === null) {
                    continue;
                }
                $groups[$idx]['items'][] = $item;
            }
        }

        return $groups;
    }

    /** @return list<array>|null null si la tabla no existe o está vacía */
    private static function stored(): ?array
    {
        if (! Schema::hasTable('menu_groups') || ! DB::table('menu_groups')->exists()) {
            return null;
        }

        $items = DB::table('menu_items')->orderBy('position')->orderBy('id')->get()->groupBy('menu_group_id');

        return DB::table('menu_groups')->orderBy('position')->orderBy('id')->get()->map(fn ($g) => [
            'key' => $g->key, 'label' => $g->label, 'icon' => $g->icon, 'visible' => (bool) $g->is_visible,
            'items' => ($items[$g->id] ?? collect())->map(fn ($i) => [
                'kind' => $i->kind, 'ref' => $i->ref, 'label' => $i->label, 'icon' => $i->icon, 'url' => $i->url,
                'permission' => $i->permission, 'new_tab' => (bool) $i->new_tab, 'visible' => (bool) $i->is_visible,
            ])->values()->all(),
        ])->values()->all();
    }

    /** Guarda la estructura completa (reemplaza la anterior). */
    public static function saveStructure(array $groups): void
    {
        DB::transaction(function () use ($groups) {
            DB::table('menu_items')->delete();
            DB::table('menu_groups')->delete();
            foreach (array_values($groups) as $gi => $g) {
                $id = DB::table('menu_groups')->insertGetId([
                    'key' => $g['key'], 'label' => $g['label'], 'icon' => $g['icon'], 'position' => $gi, 'is_visible' => $g['visible'] ?? true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach (array_values($g['items']) as $ii => $i) {
                    DB::table('menu_items')->insert([
                        'menu_group_id' => $id, 'kind' => $i['kind'], 'ref' => $i['ref'] ?? null, 'label' => $i['label'] ?? null, 'icon' => $i['icon'] ?? null,
                        'url' => $i['url'] ?? null, 'permission' => $i['permission'] ?? null, 'new_tab' => (bool) ($i['new_tab'] ?? false), 'position' => $ii,
                        'is_visible' => $i['visible'] ?? true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    /** Nombre e icono originales de una opción (pantalla o acceso especial). */
    public static function meta(string $kind, ?string $ref): array
    {
        if ($kind === 'special') {
            $s = self::SPECIALS[$ref] ?? null;

            return ['label' => $s['label'] ?? (string) $ref, 'icon' => $s['icon'] ?? 'squares-2x2'];
        }
        $screen = config("admin_screens.$ref");

        return ['label' => $screen['label'] ?? (string) $ref, 'icon' => $screen['icon'] ?? 'squares-2x2'];
    }

    public static function navigableScreen(string $key): bool
    {
        $screen = config("admin_screens.$key");

        return is_array($screen) && $key !== 'dashboard' && empty($screen['exclude_from_nav']);
    }

    /** Todas las pantallas y accesos que deben estar en el menú (para validar el guardado). */
    public static function requiredRefs(): array
    {
        return collect(self::defaults())->pluck('items')->flatten(1)->map(fn ($i) => $i['kind'].':'.$i['ref'])->all();
    }

    private static function item(string $kind, string $ref): array
    {
        return ['kind' => $kind, 'ref' => $ref, 'label' => null, 'icon' => null, 'url' => null, 'permission' => null, 'new_tab' => false, 'visible' => true];
    }
}
