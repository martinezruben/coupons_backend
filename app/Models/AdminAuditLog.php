<?php

namespace App\Models;

use App\Models\Traits\HasUuidPrimaryKey;
use App\Support\AuditLabels;
use Illuminate\Database\Eloquent\Model;

class AdminAuditLog extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $fillable = ['admin_user_id', 'admin_name', 'action', 'entity_type', 'entity_id', 'changes', 'ip', 'route', 'method', 'url', 'summary', 'input', 'user_agent', 'status_code'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'input' => 'array'];
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
        array $extra = [],
    ): self {
        $admin ??= auth('admin')->user();
        $request = request();
        $route = $request?->route()?->getName();

        return static::create($extra + [
            'summary' => AuditLabels::describeChange($action, $entityType),
            'route' => $route,
            'method' => $request?->method(),
            'url' => $request ? mb_substr($request->fullUrl(), 0, 500) : null,
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ] + [
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
