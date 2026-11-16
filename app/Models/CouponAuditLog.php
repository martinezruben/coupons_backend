<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponAuditLog extends Model
{
    public $timestamps = false;

    public const ACTIONS = [
        'batch_create' => 'Lote creado', 'batch_generate' => 'Lote generado', 'batch_failed' => 'Generación fallida', 'batch_download' => 'Archivo descargado', 'export_expired' => 'Archivo vencido',
        'assign' => 'Asignación', 'schedule' => 'Activación programada', 'activate' => 'Activación', 'cancel_activation' => 'Activación cancelada',
        'unassign' => 'Liberación', 'deactivate' => 'Desactivación', 'void_batch' => 'Lote anulado',
        'topup' => 'Recarga', 'restore' => 'Ajuste de saldo', 'reverse' => 'Reversa',
    ];

    public const DESCRIPTIONS = [
        'batch_create' => 'Se creó el lote y se encoló su generación.', 'batch_generate' => 'Terminó la generación de los cupones del lote y quedó listo el archivo CSV.',
        'batch_failed' => 'La generación falló: se borraron los cupones parciales y el lote quedó fallido.', 'batch_download' => 'Se descargó el archivo CSV del lote (una sola vez).',
        'export_expired' => 'El archivo CSV venció sin descargarse o ya se descargó y se purgó.', 'assign' => 'Cupones del lote se reservaron para un cliente, sin activarlos.',
        'schedule' => 'Se programó la activación de cupones para una fecha.', 'activate' => 'Cupones del lote se activaron para un cliente.', 'cancel_activation' => 'Se canceló una activación programada.',
        'unassign' => 'Cupones asignados volvieron a estar libres.', 'deactivate' => 'Un cupón se desactivó y no se puede cobrar.', 'void_batch' => 'Se anularon los cupones aún no activos del lote.',
        'topup' => 'Se sumó saldo a un cupón.', 'restore' => 'Un administrador fijó el saldo de un cupón a otro valor.', 'reverse' => 'Se revirtió un cobro y el saldo volvió al cupón.',
    ];

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CouponBatch::class, 'batch_id');
    }
}
