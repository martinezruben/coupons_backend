<?php

namespace App\Models;

use App\Models\Concerns\ScopedToLocations;
use App\Models\Traits\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use HasFactory, HasUuidPrimaryKey, ScopedToLocations, SoftDeletes;

    protected string $locationColumn = 'id';

    protected $fillable = [
        'name',
        'address',
        'phone',
        'dispensers',
        'latitude',
        'longitude',
        'is_active',
        'last_sync_at',
        'contingency_started_at',
        'contingency_reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'dispensers' => 'integer',
            'last_sync_at' => 'datetime',
            'contingency_started_at' => 'datetime',
            'contingency_reminder_sent_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /** Datos de la localidad que baja a la app por la API. */
    public function toApi(): array
    {
        return ['location_id' => $this->id, 'location_name' => $this->name, 'dispensers' => (int) $this->dispensers];
    }

    public function devices()
    {
        return $this->hasMany(Device::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_locations')->withTimestamps();
    }

    public function contingencyAuditLogs()
    {
        return $this->hasMany(ContingencyAuditLog::class);
    }

    public function isInContingency(): bool
    {
        return $this->contingency_started_at !== null;
    }
}
