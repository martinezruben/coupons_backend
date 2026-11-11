<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiRequestLog;
use App\Support\AdminGridCell;
use App\Support\AdminRbac;
use Illuminate\Http\JsonResponse;

class ApiRequestLogDetailController extends Controller
{
    public function show(string $id): JsonResponse
    {
        $screen = 'api-request-logs';
        $user = auth('admin')->user();
        abort_unless($user, 403);
        $p = AdminRbac::permissionsForScreen($screen);
        abort_unless($user->can($p['view']), 403);

        $log = ApiRequestLog::query()
            ->with(['location', 'device'])
            ->findOrFail($id);

        $cfg = config("admin_screens.$screen", []);

        return response()->json([
            'id' => (string) $log->getKey(),
            'method' => $log->method,
            'path' => $log->path,
            'parameters' => $log->parameters,
            'parameters_json' => self::formatJsonish($log->parameters),
            'response_status' => $log->response_status,
            'response_summary' => $log->response_summary,
            'response_json' => self::formatJsonish($log->response_summary),
            'location_id' => $log->location_id !== null ? (string) $log->location_id : null,
            'location_name' => $log->location !== null
                ? AdminGridCell::display($log, 'location_id', $cfg)
                : null,
            'device_id' => $log->device_id !== null ? (string) $log->device_id : null,
            'device_label' => $log->device !== null
                ? AdminGridCell::display($log, 'device_id', $cfg)
                : null,
            'device_fingerprint' => $log->device_fingerprint,
            'ip_address' => $log->ip_address,
            'duration_ms' => $log->duration_ms,
            'created_at' => $log->created_at?->toIso8601String(),
            'updated_at' => $log->updated_at?->toIso8601String(),
        ]);
    }

    private static function formatJsonish(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            return $encoded !== false ? $encoded : $raw;
        }

        return $raw;
    }
}
