<?php

namespace App\Models;

use App\Models\Traits\HasUuidPrimaryKey;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

class Device extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait, HasApiTokens, HasFactory, HasUuidPrimaryKey, SoftDeletes;

    protected $fillable = ['location_id', 'device_fingerprint', 'app_version', 'app_version_seen_at', 'name', 'is_enabled', 'disabled_reason', 'disabled_at', 'disabled_by', 'last_sync_at', 'registered_at'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_sync_at' => 'datetime',
            'registered_at' => 'datetime',
            'disabled_at' => 'datetime',
            'app_version_seen_at' => 'datetime',
        ];
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function licenses()
    {
        return $this->hasMany(License::class);
    }

    public function activeLicense()
    {
        return $this->hasOne(License::class)
            ->where('status', 'ACTIVE')
            ->where('valid_from', '<=', now())
            ->where('valid_to', '>=', now())
            ->latest('valid_to');
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
