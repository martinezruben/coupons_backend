<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityAlert extends Model
{
    public $timestamps = false;

    protected $table = 'coupon_security_alerts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime', 'resolved_at' => 'datetime'];
    }
}
