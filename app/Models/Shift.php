<?php

namespace App\Models;

use App\Models\Concerns\ScopedToLocations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Turno de una localidad: abierto (el vigente) o cerrado con la hora de corte y la foto de lo que entró. */
class Shift extends Model
{
    use ScopedToLocations;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'closed_at' => 'datetime', 'created_at' => 'datetime', 'details' => 'array'];
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
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
