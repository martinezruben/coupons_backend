<?php

namespace App\Models;

use App\Models\Traits\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Promoción parametrizada que viaja al POS por sync/pull.
 * Scope: exactamente una de product_id / subfamily_id / family_id;
 * todas nulas = promoción global (todos los productos).
 */
class Promotion extends Model
{
    use HasUuidPrimaryKey, SoftDeletes;

    protected $fillable = ['name', 'description', 'type', 'value', 'buy_qty', 'pay_qty', 'product_id', 'subfamily_id', 'family_id', 'starts_at', 'ends_at', 'is_active'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'buy_qty' => 'decimal:2',
            'pay_qty' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function subfamily()
    {
        return $this->belongsTo(Subfamily::class);
    }

    public function family()
    {
        return $this->belongsTo(Family::class);
    }

    /** @return array{scopeType: string, scopeId: ?string} */
    public function scopeAttribute(): array
    {
        return match (true) {
            $this->product_id !== null => ['scopeType' => 'PRODUCT', 'scopeId' => $this->product_id],
            $this->subfamily_id !== null => ['scopeType' => 'SUBFAMILY', 'scopeId' => $this->subfamily_id],
            $this->family_id !== null => ['scopeType' => 'FAMILY', 'scopeId' => $this->family_id],
            default => ['scopeType' => 'GLOBAL', 'scopeId' => null],
        };
    }

    /** Nombre legible del objetivo según scope (para el payload del POS). */
    public function displayScopeName(): string
    {
        return match (true) {
            $this->product_id !== null => (string) ($this->product?->name ?? $this->product_id),
            $this->subfamily_id !== null => (string) ($this->subfamily?->name ?? $this->subfamily_id),
            $this->family_id !== null => (string) ($this->family?->name ?? $this->family_id),
            default => 'Todos los productos',
        };
    }

    /**
     * Descripción para el operario del POS. Usa la descripción definida en el
     * panel; si no existe, genera una a partir de las reglas de la promoción.
     */
    public function effectiveDescription(): string
    {
        if ($this->description !== null && $this->description !== '') {
            return (string) $this->description;
        }

        $scope = $this->displayScopeName();

        return match ($this->type) {
            'PERCENT' => sprintf('%s%% de descuento en %s.', rtrim(rtrim((string) $this->value, '0'), '.'), $scope),
            'AMOUNT' => sprintf('Descuento fijo de %s en %s.', rtrim(rtrim((string) $this->value, '0'), '.'), $scope),
            'PRICE' => sprintf('Precio de oferta en %s: paga %s por unidad.', $scope, rtrim(rtrim((string) $this->value, '0'), '.')),
            'BUNDLE' => sprintf(
                'Lleva %d unidades del mismo producto y paga %d. Las unidades van al mismo precio de lista, salvo precio de oferta fijado en la promoción.',
                (int) $this->buy_qty,
                (int) $this->pay_qty,
            ),
            default => $scope,
        };
    }
}
