<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use App\Support\AuditLabels;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bitácora de movimientos del panel: cada página que abre y cada acción que envía un administrador
 * (usuario, fecha, ruta, método, resumen, datos enviados sin secretos y resultado). Los cambios de datos
 * (antes y después) los registra aparte el observador de modelos.
 */
class AuditAdminActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->log($request, $response);
        } catch (\Throwable $e) {
            report($e); // la bitácora nunca debe tumbar la operación
        }

        return $response;
    }

    private function log(Request $request, Response $response): void
    {
        $admin = $request->user('admin');
        $route = (string) $request->route()?->getName();
        if (! $admin || in_array($route, AuditLabels::SKIP_ROUTES, true) || $request->isMethod('OPTIONS') || $request->isMethod('HEAD')) {
            return;
        }

        $isRead = $request->isMethod('GET');
        $input = $isRead ? $request->query() : $request->except(['_token']);

        $params = $request->route()?->parameters() ?? [];
        if (isset($params['type'], $params['id'])) {
            $entityId = "{$params['type']}:{$params['id']}"; // cupones: el id solo es único por tipo
        } else {
            $param = $params['id'] ?? collect($params)->first(fn ($v) => $v instanceof Model) ?? collect($params)->first(fn ($v) => is_scalar($v));
            $entityId = $param instanceof Model ? (string) $param->getKey() : (is_scalar($param) ? (string) $param : null);
        }

        AdminAuditLog::record(
            $isRead ? 'view' : 'submit',
            'Panel',
            $entityId,
            null,
            $admin,
            [
                'summary' => AuditLabels::describeRequest($request),
                'input' => $input === [] ? null : AuditLabels::sanitize($input),
                'status_code' => $response->getStatusCode(),
            ],
        );
    }
}
