<?php

namespace App\Models;

use App\Models\Traits\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subfamily extends Model
{
    use HasFactory, HasUuidPrimaryKey, SoftDeletes;

    protected $fillable = ['family_id', 'name'];

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Etiqueta para selects y grid (familia · subfamilia). */
    public function getAdminLabelAttribute(): string
    {
        $this->loadMissing('family');
        $fam = $this->family?->name;

        return $fam ? ($fam.' · '.$this->name) : $this->name;
    }
}
