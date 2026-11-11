<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/** Error de negocio con respuesta JSON controlada (sin filtrar detalles internos). */
class CouponException extends Exception
{
    public function __construct(
        string $message,
        private int $status = 400,
        private array $extra = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => $this->getMessage()] + $this->extra, $this->status);
    }
}
