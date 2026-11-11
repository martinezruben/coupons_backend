<?php

namespace App\Models;

use App\Models\Traits\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    protected $fillable = [
        'external_id', 'location_id', 'device_id', 'shift_id', 'user_id', 'turn_number', 'status', 'total', 'occurred_at', 'is_synced', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'occurred_at' => 'datetime',
            'is_synced' => 'boolean',
            'synced_at' => 'datetime',
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

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function payments()
    {
        return $this->hasMany(TransactionPayment::class);
    }
}
