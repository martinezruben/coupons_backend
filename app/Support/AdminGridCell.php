<?php

namespace App\Support;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\ApiRequestLog;
use App\Models\Device;
use App\Models\Location;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AdminGridCell
{
    /**
     * @return class-string<Model>
     */
    public static function relationModel(string $relation): string
    {
        return match ($relation) {
            'location' => Location::class,
            'device' => Device::class,
            'user' => User::class,
            'adminUser' => AdminUser::class,
            default => throw new \InvalidArgumentException("Unknown foreign relation: {$relation}"),
        };
    }

    /**
     * Label for a related model row (grid and select options).
     */
    public static function selectOptionLabel(Model $model, array $meta): string
    {
        $attr = $meta['attribute'];
        $val = data_get($model, $attr);

        if (($val === null || $val === '') && ! empty($meta['fallback_attribute'])) {
            $val = data_get($model, $meta['fallback_attribute']);
        }

        if ($val === null || $val === '') {
            return (string) $model->getKey();
        }

        $prefix = $meta['prefix'] ?? '';

        return $prefix.(string) $val;
    }

    /**
     * Options for an admin select: list of id + label (id is string UUID).
     *
     * @return Collection<int, array{id: string, label: string}>
     */
    public static function selectOptions(array $cfg, string $field): Collection
    {
        $meta = $cfg['foreign_labels'][$field] ?? null;
        if ($meta === null || ! empty($meta['virtual'])) {
            return collect();
        }

        $modelClass = self::relationModel($meta['relation']);
        /** @var Builder $query */
        $query = $modelClass::query();

        $query->orderBy($meta['attribute']);

        return $query->get()->map(fn (Model $m): array => array_filter([
            'id' => (string) $m->getKey(),
            'label' => self::selectOptionLabel($m, $meta),
            'parent' => ! empty($meta['parent_column']) ? (string) data_get($m, $meta['parent_column']) : null,
        ], fn ($v) => $v !== null));
    }

    public static function display(Model $item, string $field, array $cfg): string
    {
        if ($item instanceof User && $field === 'last_activity_at') {
            $raw = $item->getAttribute('last_activity_at');
            if ($raw === null || $raw === '') {
                return '—';
            }
            if ($raw instanceof \DateTimeInterface) {
                return Format::dateTime($raw);
            }

            return (string) $raw;
        }

        if ($item instanceof AdminUser) {
            if ($field === 'roles_list') {
                return $item->roles->pluck('name')->implode(', ') ?: '—';
            }
            if ($field === 'totp_status') {
                return $item->totp_enabled ? 'Activada' : 'No';
            }
            if ($field === 'last_login_at') {
                $raw = $item->getAttribute('last_login_at');

                return $raw === null || $raw === '' ? 'Nunca' : Format::dateTime(Carbon::parse($raw));
            }
        }

        if ($item instanceof User && $field === 'pin_status') {
            return $item->hasOfflinePin() ? 'Listo' : 'Restablecer contraseña';
        }

        if ($item instanceof AdminAuditLog) {
            if ($field === 'created_at' && $item->created_at !== null) {
                return Format::dateTime($item->created_at, true);
            }
            if ($field === 'changes' && is_array($item->changes)) {
                $parts = [];
                foreach ($item->changes as $campo => [$antes, $despues]) {
                    $parts[] = $campo.': '.Str::limit((string) $antes, 24).' → '.Str::limit((string) $despues, 24);
                }

                return Str::limit(implode(' · ', $parts), 160) ?: '—';
            }
        }

        if ($item instanceof ApiRequestLog) {
            if (in_array($field, ['created_at', 'updated_at'], true) && $item->{$field} !== null) {
                $v = $item->{$field};

                return $v instanceof \DateTimeInterface ? Format::dateTime($v, true) : '—';
            }
            if ($field === 'parameters' && $item->parameters !== null) {
                return Str::limit((string) $item->parameters, 140);
            }
            if ($field === 'response_summary' && $item->response_summary !== null) {
                return Str::limit((string) $item->response_summary, 160);
            }
        }

        $foreign = $cfg['foreign_labels'][$field] ?? null;

        if ($foreign !== null && ! empty($foreign['virtual']) && empty($foreign['chain'])) {
            $raw = $item->{$field};

            if ($raw instanceof \DateTimeInterface) {
                return Format::dateTime($raw);
            }

            if (is_scalar($raw) || $raw === null) {
                return $raw === null ? '—' : (string) $raw;
            }

            return '—';
        }

        if ($foreign !== null) {
            if (! empty($foreign['chain'])) {
                /** @var Model|null $related */
                $related = $item;
                foreach ($foreign['chain'] as $relName) {
                    $related = $related?->{$relName};
                }

                if ($related === null) {
                    return '—';
                }

                $attr = $foreign['attribute'];
                $val = data_get($related, $attr);

                if (($val === null || $val === '') && ! empty($foreign['fallback_attribute'])) {
                    $val = data_get($related, $foreign['fallback_attribute']);
                }

                if ($val === null || $val === '') {
                    return '—';
                }

                $prefix = $foreign['prefix'] ?? '';

                return $prefix.(string) $val;
            }

            $relation = $foreign['relation'];
            /** @var Model|null $related */
            $related = $item->{$relation};

            if ($related === null) {
                if (! empty($foreign['fallback_to_own_column'])) {
                    $own = $item->{$field};

                    return ($own !== null && $own !== '') ? (string) $own : '—';
                }

                return '—';
            }

            $attr = $foreign['attribute'];
            $val = data_get($related, $attr);

            if (($val === null || $val === '') && ! empty($foreign['fallback_attribute'])) {
                $val = data_get($related, $foreign['fallback_attribute']);
            }

            if ($val === null || $val === '') {
                return '—';
            }

            $prefix = $foreign['prefix'] ?? '';

            return $prefix.(string) $val;
        }

        $raw = $item->{$field};

        if ($raw instanceof \DateTimeInterface) {
            return Format::dateTime($raw);
        }

        if (is_numeric($raw) && in_array($field, $cfg['grid']['money'] ?? [], true)) {
            return Format::money($raw);
        }

        // Valores enumerados guardados en inglés/código se muestran con su texto (config/admin_labels.php)
        if (is_string($raw) && config("admin_labels.values.$field") !== null) {
            return self::valueLabel($field, $raw);
        }

        if (is_scalar($raw) || $raw === null) {
            return $raw === null ? '—' : (string) $raw;
        }

        return '—';
    }

    public static function headerLabel(string $field, array $cfg): string
    {
        if (isset($cfg['foreign_labels'][$field]['header'])) {
            return $cfg['foreign_labels'][$field]['header'];
        }
        if (isset($cfg['labels'][$field])) {
            return $cfg['labels'][$field];
        }

        // Etiquetas comunes en español (config/admin_labels.php) antes del nombre técnico
        return config("admin_labels.fields.$field") ?? Str::ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * Nombres de atributo para los mensajes de validación: las mismas etiquetas
     * que ve el usuario en el formulario.
     *
     * @return array<string, string>
     */
    public static function validationAttributes(array $cfg): array
    {
        $fields = array_unique(array_merge($cfg['fields'] ?? [], array_keys($cfg['labels'] ?? [])));

        return collect($fields)->mapWithKeys(fn (string $f) => [$f => self::headerLabel($f, $cfg)])->all();
    }

    /**
     * Texto visible de un valor enumerado guardado en inglés (PAID → Cobrada).
     * Métodos de pago creados en el panel se muestran por su nombre.
     */
    public static function valueLabel(string $field, mixed $raw): string
    {
        if (! is_scalar($raw)) {
            return '—';
        }
        $raw = (string) $raw;
        $known = config("admin_labels.values.$field.".strtoupper($raw));
        if ($known !== null) {
            return $known;
        }

        return $raw;
    }

    /**
     * @return list<string>
     */
    public static function eagerRelations(array $cfg): array
    {
        $rels = collect();

        foreach ($cfg['foreign_labels'] ?? [] as $meta) {
            if (! empty($meta['virtual']) && ! empty($meta['chain'])) {
                $rels->push(implode('.', $meta['chain']));

                continue;
            }
            if (! empty($meta['relation'])) {
                $rels->push($meta['relation']);
            }
        }

        return $rels->filter()->unique()->values()->all();
    }

    public static function isVirtualGridField(string $field, array $cfg): bool
    {
        return ! empty($cfg['foreign_labels'][$field]['virtual']);
    }
}
