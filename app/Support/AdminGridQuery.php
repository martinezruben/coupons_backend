<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AdminGridQuery
{
    public static function apply(Builder $query, Request $request, string $screen, array $cfg): void
    {
        self::applyFilters($query, $request, $cfg);
        self::applySearch($query, $request, $cfg);
        self::applySort($query, $request, $screen, $cfg);
    }

    /**
     * Opciones para selects de filtro (id + label, y 'parent' si el filtro es dependiente).
     *
     * @return array<string, Collection<int, array{id: string, label: string, parent?: string}>>
     */
    public static function filterOptions(array $cfg): array
    {
        $out = [];
        foreach ($cfg['grid']['filters'] ?? [] as $key => $def) {
            if (($def['type'] ?? '') !== 'select') {
                continue;
            }
            $parent = $def['depends'] ?? null; // clave del filtro padre (cascada)
            if (isset($def['model'])) {
                /** @var class-string<Model> $modelClass */
                $modelClass = $def['model'];
                $orderBy = $def['order_by'] ?? 'name';
                $labelCol = $def['label_column'] ?? 'name';
                $fallback = $def['fallback_column'] ?? null;
                $parentColumn = $def['parent_column'] ?? null;
                $rows = $modelClass::query()
                    ->orderBy($orderBy)
                    ->get()
                    ->map(function (Model $row) use ($labelCol, $fallback, $parentColumn): array {
                        $label = (string) data_get($row, $labelCol);
                        if ($label === '' && $fallback !== null) {
                            $label = (string) data_get($row, $fallback);
                        }
                        if ($label === '') {
                            $label = (string) $row->getKey();
                        }

                        $opt = ['id' => (string) $row->getKey(), 'label' => $label];
                        if ($parentColumn !== null) {
                            $opt['parent'] = (string) data_get($row, $parentColumn);
                        }

                        return $opt;
                    });
                $out[$key] = collect([['id' => '', 'label' => 'Todos']])->merge($rows);
            } elseif (isset($def['options']) && is_array($def['options'])) {
                $opts = collect();
                foreach ($def['options'] as $id => $label) {
                    $opts->push(['id' => (string) $id, 'label' => (string) $label]);
                }
                if (! array_key_exists('', $def['options'])) {
                    $opts->prepend(['id' => '', 'label' => 'Todos']);
                }
                $out[$key] = $opts;
            }
        }

        return $out;
    }

    private static function applyFilters(Builder $query, Request $request, array $cfg): void
    {
        foreach ($cfg['grid']['filters'] ?? [] as $param => $def) {
            $val = $request->input('filter.'.$param);
            if ($val === null || $val === '') {
                continue;
            }
            $apply = $def['apply'] ?? [];
            $type = $apply['type'] ?? 'column';

            if ($type === 'column') {
                $col = $apply['column'];
                if (str_ends_with((string) $col, 'is_active') || str_ends_with((string) $col, 'is_enabled') || str_ends_with((string) $col, 'is_synced')) {
                    $query->where($col, (bool) (int) $val);
                } elseif ($col === 'response_status') {
                    $query->where($col, (int) $val);
                } else {
                    $query->where($col, $val);
                }

                continue;
            }

            // Filtro por un scope de Eloquent del modelo (definido en config, nunca por el usuario)
            if ($type === 'scope') {
                $query->{$apply['scope']}();

                continue;
            }

            if ($type === 'date_from') {
                $query->whereDate($apply['column'], '>=', $val);

                continue;
            }

            if ($type === 'date_to') {
                $query->whereDate($apply['column'], '<=', $val);

                continue;
            }

            if ($type === 'whereHas') {
                $relation = $apply['relation'];
                $column = $apply['column'];
                $query->whereHas($relation, function (Builder $q) use ($column, $val): void {
                    $q->where($column, $val);
                });
            }
        }
    }

    private static function applySearch(Builder $query, Request $request, array $cfg): void
    {
        $q = $request->string('q')->trim();
        if ($q->isEmpty() || empty($cfg['fields'])) {
            return;
        }

        $skipLike = ['is_active', 'is_synced', 'records_count', 'latitude', 'longitude', 'created_at', 'response_status', 'duration_ms', 'last_activity_at', 'location_id'];
        $fkFields = array_keys($cfg['foreign_labels'] ?? []);
        $grid = $cfg['grid'] ?? [];
        if (! empty($grid['search_columns']) && is_array($grid['search_columns'])) {
            $searchFields = array_values(array_filter(
                $grid['search_columns'],
                function (string $f) use ($skipLike, $fkFields, $cfg): bool {
                    if (in_array($f, $skipLike, true) || in_array($f, $fkFields, true)) {
                        return false;
                    }
                    if (! empty($cfg['foreign_labels'][$f]['virtual'])) {
                        return false;
                    }

                    return true;
                }
            ));
        } else {
            $searchFields = array_values(array_filter(
                array_slice($cfg['fields'], 0, 8),
                function (string $f) use ($skipLike, $fkFields, $cfg): bool {
                    if (in_array($f, $skipLike, true) || in_array($f, $fkFields, true)) {
                        return false;
                    }
                    if (! empty($cfg['foreign_labels'][$f]['virtual'])) {
                        return false;
                    }

                    return true;
                }
            ));
        }

        $query->where(function (Builder $w) use ($searchFields, $q, $cfg): void {
            foreach ($searchFields as $field) {
                $w->orWhere($field, 'like', '%'.$q.'%');
            }
            foreach ($cfg['foreign_labels'] ?? [] as $meta) {
                if (! empty($meta['virtual']) && ! empty($meta['chain'])) {
                    $chain = $meta['chain'];
                    $first = array_shift($chain);
                    $attr = $meta['attribute'];
                    $fallback = $meta['fallback_attribute'] ?? null;
                    $w->orWhere(function (Builder $w2) use ($first, $chain, $attr, $fallback, $q): void {
                        $w2->whereHas($first, function (Builder $rel) use ($chain, $attr, $fallback, $q): void {
                            self::applyNestedWhereHas($rel, $chain, $attr, $fallback, $q);
                        });
                    });
                }
            }
            foreach ($cfg['foreign_labels'] ?? [] as $meta) {
                if (! empty($meta['virtual'])) {
                    continue;
                }
                $w->orWhereHas($meta['relation'], function (Builder $rel) use ($meta, $q): void {
                    $rel->where($meta['attribute'], 'like', '%'.$q.'%');
                    if (! empty($meta['fallback_attribute'])) {
                        $rel->orWhere($meta['fallback_attribute'], 'like', '%'.$q.'%');
                    }
                });
            }
        });
    }

    /**
     * @param  list<string>  $remaining
     */
    private static function applyNestedWhereHas(Builder $query, array $remaining, string $attr, ?string $fallback, string $search): void
    {
        if ($remaining === []) {
            $query->where($attr, 'like', '%'.$search.'%');
            if ($fallback !== null) {
                $query->orWhere($fallback, 'like', '%'.$search.'%');
            }

            return;
        }

        $next = array_shift($remaining);
        $query->whereHas($next, function (Builder $rel) use ($remaining, $attr, $fallback, $search): void {
            self::applyNestedWhereHas($rel, $remaining, $attr, $fallback, $search);
        });
    }

    private static function applySort(Builder $query, Request $request, string $screen, array $cfg): void
    {
        $grid = $cfg['grid'] ?? [];

        /** @var Model $base */
        $base = $cfg['model'];
        $table = (new $base)->getTable();

        if ($grid === []) {
            self::defaultTableSort($query, $table);

            return;
        }

        $sortable = $grid['sortable'] ?? [];
        $default = $grid['default_sort'] ?? ['key' => 'updated_at', 'direction' => 'desc'];

        if ($sortable === []) {
            if (Schema::hasColumn($table, $default['key'])) {
                $query->orderBy(
                    $table.'.'.$default['key'],
                    $default['direction'] === 'asc' ? 'asc' : 'desc'
                );
            } else {
                self::defaultTableSort($query, $table);
            }

            return;
        }

        $sort = $request->string('sort')->toString();
        $dir = strtolower($request->string('dir')->toString()) === 'asc' ? 'asc' : 'desc';

        if ($sort === '' || ! in_array($sort, $sortable, true)) {
            $sort = $default['key'];
            $dir = $default['direction'] === 'asc' ? 'asc' : 'desc';
        }

        if (self::applySpecialSort($query, $screen, $table, $sort, $dir)) {
            return;
        }

        if (Schema::hasColumn($table, $sort)) {
            $query->orderBy($table.'.'.$sort, $dir);

            return;
        }

        if (Schema::hasColumn($table, $default['key'])) {
            $query->orderBy($table.'.'.$default['key'], $default['direction'] === 'asc' ? 'asc' : 'desc');
        }
    }

    private static function defaultTableSort(Builder $query, string $table): void
    {
        if (Schema::hasColumn($table, 'updated_at')) {
            $query->orderBy($table.'.updated_at', 'desc');
        } elseif (Schema::hasColumn($table, 'created_at')) {
            $query->orderBy($table.'.created_at', 'desc');
        } else {
            $query->orderBy($table.'.id');
        }
    }

    private static function applySpecialSort(Builder $query, string $screen, string $table, string $sort, string $dir): bool
    {
        return match ($screen) {
            'licenses' => self::sortLicenses($query, $table, $sort, $dir),
            'devices' => self::sortDevices($query, $table, $sort, $dir),
            'api-request-logs' => self::sortApiRequestLogs($query, $table, $sort, $dir),
            'android-users' => self::sortAndroidUsers($query, $table, $sort, $dir),
            default => false,
        };
    }

    /**
     * Subconsulta correlacionada: última actividad (api_request_logs en localidades del usuario).
     */
    public static function androidUsersLastActivitySql(): string
    {
        return '(SELECT MAX(api_request_logs.created_at) FROM api_request_logs
            INNER JOIN user_locations ul_ar ON ul_ar.location_id = api_request_logs.location_id AND ul_ar.user_id = users.id)';
    }

    private static function sortAndroidUsers(Builder $query, string $table, string $sort, string $dir): bool
    {
        $preserveLastActivity = static function (Builder $q) use ($table): void {
            $q->select($table.'.*')
                ->selectRaw(self::androidUsersLastActivitySql().' as last_activity_at');
        };

        if ($sort === 'last_activity_at') {
            $query->orderBy('last_activity_at', $dir);

            return true;
        }
        if ($sort === 'location_id') {
            $query->leftJoin('locations as ag_au_l', $table.'.location_id', '=', 'ag_au_l.id')
                ->orderBy('ag_au_l.name', $dir);
            $preserveLastActivity($query);

            return true;
        }

        return false;
    }

    private static function sortApiRequestLogs(Builder $query, string $table, string $sort, string $dir): bool
    {
        if ($sort === 'location_id') {
            $query->leftJoin('locations as ag_arl_l', $table.'.location_id', '=', 'ag_arl_l.id')
                ->orderBy('ag_arl_l.name', $dir)
                ->select($table.'.*');

            return true;
        }
        if ($sort === 'device_id') {
            $query->leftJoin('devices as ag_arl_d', $table.'.device_id', '=', 'ag_arl_d.id')
                ->orderByRaw('COALESCE(NULLIF(ag_arl_d.name, ""), ag_arl_d.device_fingerprint) '.$dir)
                ->select($table.'.*');

            return true;
        }

        return false;
    }

    private static function sortLicenses(Builder $query, string $table, string $sort, string $dir): bool
    {
        if ($sort === 'location_name') {
            $query->leftJoin('devices as ag_lic_d', $table.'.device_id', '=', 'ag_lic_d.id')
                ->leftJoin('locations as ag_lic_l', 'ag_lic_d.location_id', '=', 'ag_lic_l.id')
                ->orderBy('ag_lic_l.name', $dir)
                ->select($table.'.*');

            return true;
        }

        if ($sort === 'device_id') {
            $query->leftJoin('devices as ag_lic_d2', $table.'.device_id', '=', 'ag_lic_d2.id')
                ->orderByRaw('COALESCE(NULLIF(ag_lic_d2.name, ""), ag_lic_d2.device_fingerprint) '.$dir)
                ->select($table.'.*');

            return true;
        }

        if ($sort === 'status') {
            $query->orderBy($table.'.status', $dir);

            return true;
        }

        return false;
    }

    private static function sortDevices(Builder $query, string $table, string $sort, string $dir): bool
    {
        if ($sort === 'location_id') {
            $query->leftJoin('locations as ag_dev_l', $table.'.location_id', '=', 'ag_dev_l.id')
                ->orderBy('ag_dev_l.name', $dir)
                ->select($table.'.*');

            return true;
        }

        return false;
    }

    public static function sortUrl(string $screen, string $field, ?string $currentSort, ?string $currentDir): string
    {
        $nextDir = ($currentSort === $field && $currentDir === 'asc') ? 'desc' : 'asc';
        $qs = array_merge(
            request()->except('page'),
            ['sort' => $field, 'dir' => $nextDir]
        );

        return route('admin.screens.index', ['screen' => $screen]).'?'.http_build_query($qs);
    }
}
