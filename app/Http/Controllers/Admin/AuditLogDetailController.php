<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Support\AdminRbac;
use Illuminate\Http\JsonResponse;

class AuditLogDetailController extends Controller
{
    public function show(string $id): JsonResponse
    {
        $user = auth('admin')->user();
        abort_unless($user, 403);
        $p = AdminRbac::permissionsForScreen('audit-log');
        abort_unless($user->can($p['view']), 403);

        $log = AdminAuditLog::query()->findOrFail($id);

        return response()->json([
            'id' => (string) $log->getKey(),
            'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            'admin_name' => $log->admin_name ?? '—',
            'action' => $log->action,
            'entity_type' => $log->entity_type,
            'entity_id' => (string) ($log->entity_id ?? '—'),
            'ip' => $log->ip ?? '—',
            'changes' => $log->changes,
        ]);
    }
}
