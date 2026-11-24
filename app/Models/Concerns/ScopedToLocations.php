<?php

namespace App\Models\Concerns;

use App\Models\AdminUser;
use App\Support\AdminLocationScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Alcance por localidad: la consulta se limita a la selección vigente del administrador (ver AdminLocationScope).
 * `$locationColumn` indica la columna con la localidad (en Location, su propia clave).
 */
trait ScopedToLocations
{
    public function scopeVisibleToLocations(Builder $query, ?AdminUser $admin): Builder
    {
        $ids = AdminLocationScope::effective($admin);

        return $ids === null ? $query : $query->whereIn($this->qualifyColumn($this->locationColumn ?? 'location_id'), $ids);
    }
}
