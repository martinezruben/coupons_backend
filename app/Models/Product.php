<?php

namespace App\Models;

use App\Models\Traits\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, HasUuidPrimaryKey, SoftDeletes;

    protected $fillable = ['sku', 'barcode', 'name', 'description', 'image_url', 'subfamily_id', 'price', 'cost', 'tax_rate', 'is_active', 'is_favorite'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'is_active' => 'boolean',
            'is_favorite' => 'boolean',
        ];
    }

    public function subfamily(): BelongsTo
    {
        return $this->belongsTo(Subfamily::class);
    }
}
