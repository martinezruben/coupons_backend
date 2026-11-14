<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/** Prefijo reutilizable para los números de los cupones de un lote (catálogo administrable). */
class CouponPrefix extends Model
{
    protected $fillable = ['prefix', 'description', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected function prefix(): Attribute
    {
        return Attribute::make(set: fn ($v) => strtoupper(trim((string) $v)));
    }
}
