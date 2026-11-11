<?php

namespace App\Models;

use App\Models\Traits\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class AdminAuditLog extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $fillable = ['admin_user_id', 'admin_name', 'action', 'entity_type', 'entity_id', 'changes', 'ip'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function adminUser()
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    /** Registro central del log de auditoría. */
    public static function record(
        string $action,
        string $entityType,
        ?string $entityId = null,
        ?array $changes = null,
        ?AdminUser $admin = null,
    ): self {
        $admin ??= auth('admin')->user();

        return static::create([
            'admin_user_id' => $admin?->getKey(),
            'admin_name' => $admin?->name,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'changes' => $changes,
            'ip' => request()?->ip(),
        ]);
    }
}
