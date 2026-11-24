<?php

namespace App\Models;

use App\Models\Traits\HasUuidPrimaryKey;
use App\Support\AdminLocationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class License extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    protected $fillable = ['device_id', 'valid_from', 'valid_to', 'status'];

    /** Alcance por localidad: las licencias de los terminales de las localidades vigentes. */
    public function scopeVisibleToLocations(Builder $query, ?AdminUser $admin): Builder
    {
        $ids = AdminLocationScope::effective($admin);

        return $ids === null ? $query : $query->whereHas('device', fn ($d) => $d->withTrashed()->whereIn('location_id', $ids));
    }

    protected function casts(): array
    {
        return ['valid_from' => 'datetime', 'valid_to' => 'datetime'];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (License $license): void {
            $license->license_key = (string) $license->getKey();
        });

        static::saving(function (License $license): void {
            if ($license->exists) {
                $license->license_key = (string) $license->getKey();
            }
        });
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function isValidForOperation(): bool
    {
        if ($this->status !== 'ACTIVE') {
            return false;
        }

        return $this->valid_from <= now() && $this->valid_to >= now();
    }
}
