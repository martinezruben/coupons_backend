<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NcfSequence extends Model
{
    protected $table = 'ncf_sequences';

    protected $fillable = [
        'type',
        'location_id',
        'establishment',
        'start',
        'end',
        'current',
    ];

    protected $casts = [
        'location_id' => 'string',
        'start' => 'integer',
        'end' => 'integer',
        'current' => 'integer',
    ];

    /**
     * Scope: tipo SRI (01/04/05/07 EC, E31/E32/E33/E34 DO).
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: por localidad (NULL = global).
     */
    public function scopeAtLocation($query, ?string $locId)
    {
        return $query->where('location_id', $locId);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * ¿Agotado?
     */
    public function isExhausted(): bool
    {
        return $this->current > $this->end;
    }

    /**
     * ¿Cerca del agotamiento?
     */
    public function isNearExhausted(int $threshold = 100): bool
    {
        return ($this->end - $this->current + 1) <= $threshold;
    }
}
