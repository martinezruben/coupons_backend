<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key: un reintento con la misma clave y el mismo cuerpo devuelve la
 * respuesta original sin volver a cobrar. Misma clave con otro cuerpo => 422.
 */
class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || $key === '') {
            if (config('coupons.require_idempotency_key')) {
                return response()->json(['error' => 'Idempotency-Key header is required'], 400);
            }

            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key)) {
            return response()->json(['error' => 'Invalid Idempotency-Key'], 422);
        }

        $userId = $request->user()->id;
        $hash = hash('sha256', $request->method().$request->path().$request->getContent());

        DB::table('idempotency_keys')
            ->where('created_at', '<', now()->subHours(config('coupons.idempotency_ttl_hours')))
            ->delete();

        try {
            $id = DB::table('idempotency_keys')->insertGetId([
                'user_id' => $userId,
                'key' => $key,
                'route' => $request->path(),
                'request_hash' => $hash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $row = DB::table('idempotency_keys')->where('user_id', $userId)->where('key', $key)->first();

            if (! $row || $row->request_hash !== $hash) {
                return response()->json(['error' => 'Idempotency-Key reused with a different request'], 422);
            }
            if ($row->response_status === null) {
                return response()->json(['error' => 'A request with this Idempotency-Key is in progress'], 409);
            }

            return response($row->response_body, $row->response_status, ['Content-Type' => 'application/json'])
                ->header('Idempotent-Replayed', 'true');
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::table('idempotency_keys')->where('id', $id)->delete();
            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            // Fallo del servidor: permitir que el cliente reintente con la misma clave.
            DB::table('idempotency_keys')->where('id', $id)->delete();
        } else {
            DB::table('idempotency_keys')->where('id', $id)->update([
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'updated_at' => now(),
            ]);
        }

        return $response;
    }
}
