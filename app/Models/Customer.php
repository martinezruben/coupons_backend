<?php

namespace App\Models;

use App\Models\Traits\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Cliente al que se asignan y activan cupones (registro simple, sin acceso propio). */
class Customer extends Model
{
    use HasUuidPrimaryKey, SoftDeletes;

    protected $fillable = ['name', 'document', 'email', 'phone', 'notes', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
