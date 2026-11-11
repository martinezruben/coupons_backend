<?php

namespace App\Models;

use App\Models\Traits\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncState extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    protected $fillable = [
        'location_id', 'device_id', 'last_pull_at', 'last_push_at', 'last_success_at', 'last_error_at', 'last_error_message',
    ];

    protected function casts(): array
    {
        return [
            'last_pull_at' => 'datetime',
            'last_push_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
