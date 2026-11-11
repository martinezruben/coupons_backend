<?php

namespace App\Models;

use App\Models\Traits\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Método de pago gestionable desde el panel; viaja al POS por sync/pull
 * para renderizar un botón por método. Los IDs legacy (pm-cash…) se
 * conservaron al migrar desde config/sync_catalog.php.
 */
class PaymentMethod extends Model
{
    use HasUuidPrimaryKey, SoftDeletes;

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'name', 'type', 'color', 'is_enabled'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    /** Nombres para las categorías legacy que el POS enviaba antes de la tabla. */
    private const LEGACY_LABELS = ['CASH' => 'Efectivo', 'CARD' => 'Tarjeta', 'TRANSFER' => 'Transferencia', 'OTHER' => 'Otro'];

    /**
     * Nombre visible de cada valor de `transaction_payments.payment_method`, que
     * puede ser un ID de esta tabla (incluidos borrados) o una categoría legacy.
     *
     * @param  iterable<string>  $codes
     * @return array<string, string>
     */
    public static function labelsFor(iterable $codes): array
    {
        $codes = collect($codes)->filter()->map(fn ($c) => (string) $c)->unique()->values();
        $names = static::withTrashed()->whereIn('id', $codes->all())->pluck('name', 'id');

        return $codes
            ->mapWithKeys(fn (string $c) => [$c => $names[$c] ?? self::LEGACY_LABELS[$c] ?? $c])
            ->all();
    }
}
