<?php

use App\Support\SensitiveData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los logs de la API guardaban en claro el token Bearer y la clave de licencia
 * de los dispositivos (login/registro) y los hashes de PIN (sync/pull).
 * Enmascara lo ya guardado; lo que quedó recortado y no se puede analizar
 * como JSON se reemplaza si contiene algún campo sensible.
 */
return new class extends Migration
{
    private const SENSITIVE_PATTERN = '/"[^"]*(password|token|authorization|license_?key|licenseKey|secret|api_?key|pin)[^"]*"\s*:/i';

    public function up(): void
    {
        DB::table('api_request_logs')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $parameters = $this->scrub($row->parameters, 16000);
                $response = $this->scrub($row->response_summary, 2000);

                if ($parameters !== $row->parameters || $response !== $row->response_summary) {
                    DB::table('api_request_logs')->where('id', $row->id)->update([
                        'parameters' => $parameters,
                        'response_summary' => $response,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Irreversible: los valores enmascarados no se guardaron.
    }

    private function scrub(?string $value, int $limit): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (is_array(json_decode($value, true))) {
            return SensitiveData::maskJson($value, $limit);
        }

        return preg_match(self::SENSITIVE_PATTERN, $value) ? '[depurado: contenía credenciales]' : $value;
    }
};
