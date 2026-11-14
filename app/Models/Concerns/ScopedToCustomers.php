<?php

namespace App\Models\Concerns;

use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Builder;

/**
 * Alcance por cliente: un administrador con `customer_scope = selected` solo ve los registros de sus
 * clientes. `$customerColumn` indica la columna que apunta al cliente (en Customer, su propia clave).
 */
trait ScopedToCustomers
{
    public function scopeVisibleTo(Builder $query, ?AdminUser $admin): Builder
    {
        $allowed = $admin?->allowedCustomerIds();

        if ($allowed === null) {
            return $query;
        }

        return $query->whereIn($this->qualifyColumn($this->customerColumn ?? 'customer_id'), $allowed);
    }
}
