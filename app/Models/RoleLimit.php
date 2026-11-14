<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Límites de un rol de Spatie (role_id): tamaño de activaciones y de lotes. null = sin límite propio. */
class RoleLimit extends Model
{
    protected $fillable = ['role_id', 'max_activation_size', 'max_batch_quantity', 'max_batch_value'];
}
