<?php

namespace App\Support;

use App\Models\AdminUser;
use App\Models\Location;
use Illuminate\Support\Collection;

/**
 * Alcance por localidad del panel. Cada administrador tiene «todas» las localidades o solo las que se le asignaron
 * (`allowed`), y entre ellas elige con qué localidad trabaja (selector de la barra superior, guardado en la sesión).
 * Todas las pantallas actúan sobre la selección vigente (`effective`): la localidad elegida o, si no eligió ninguna,
 * todas las suyas. Los registros sin localidad (acciones del panel) solo los ve quien no está restringido.
 */
final class AdminLocationScope
{
    public const SESSION = 'admin_location_id';

    /** @return list<string>|null null = todas las localidades */
    public static function allowed(?AdminUser $admin): ?array
    {
        return $admin?->allowedLocationIds();
    }

    /** Localidad elegida en el selector (solo si el administrador puede usarla). */
    public static function selected(?AdminUser $admin): ?string
    {
        $id = session(self::SESSION);
        if (! is_string($id) || $id === '') {
            return null;
        }
        $allowed = self::allowed($admin);

        return $allowed === null || in_array($id, $allowed, true) ? $id : null;
    }

    /** @return list<string>|null IDs sobre los que actúan las pantallas; null = todas */
    public static function effective(?AdminUser $admin): ?array
    {
        $selected = self::selected($admin);

        return $selected !== null ? [$selected] : self::allowed($admin);
    }

    /** Localidades que puede elegir en el selector (todas, o las asignadas). */
    public static function options(?AdminUser $admin): Collection
    {
        $allowed = self::allowed($admin);

        return Location::query()->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->orderBy('name')->get(['id', 'name']);
    }

    /** Limita una consulta (Eloquent o del constructor) a la selección vigente por la columna de localidad indicada. */
    public static function apply($query, string $column, ?AdminUser $admin)
    {
        $ids = self::effective($admin);

        return $ids === null ? $query : $query->whereIn($column, $ids);
    }

    /** ¿Puede este administrador trabajar con esa localidad (aunque no sea la seleccionada)? */
    public static function permits(?AdminUser $admin, ?string $locationId): bool
    {
        $allowed = self::allowed($admin);

        return $allowed === null || ($locationId !== null && in_array($locationId, $allowed, true));
    }
}
