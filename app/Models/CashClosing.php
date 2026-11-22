<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cierre de caja de una localidad. Inmutable: no se edita ni se elimina. */
class CashClosing extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_from' => 'datetime', 'period_to' => 'datetime', 'created_at' => 'datetime', 'details' => 'array'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'closed_by_admin_id');
    }
}
