<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Error de negocio con respuesta JSON controlada (sin filtrar detalles internos).
 * La respuesta lleva `error` (texto en inglés, para personas) y `code` (estable, para que la app lo traduzca).
 */
class CouponException extends Exception
{
    /** Códigos estables por mensaje; la app decide el texto que ve el usuario. */
    private const CODES = [
        'Coupon not found' => 'coupon_not_found',
        'Coupon is inactive or expired' => 'coupon_inactive_or_expired',
        'Insufficient balance' => 'insufficient_balance',
        'Balance limit exceeded' => 'balance_limit_exceeded',
        'Balance cannot exceed the coupon amount' => 'balance_exceeds_coupon_amount',
        'Device not authorized' => 'device_not_authorized',
        'User is not assigned to the location of this device' => 'user_wrong_location',
        'Supervisor approval required for this amount' => 'supervisor_approval_required',
        'Daily limit of redemptions for this coupon reached' => 'coupon_daily_limit_reached',
        'Daily amount limit for this user reached' => 'user_daily_limit_reached',
        'Daily amount limit for this location reached' => 'location_daily_limit_reached',
        'You cannot reverse your own transaction' => 'cannot_reverse_own_transaction',
        'This transaction cannot be reversed' => 'transaction_not_reversible',
        'Transaction already reversed' => 'transaction_already_reversed',
        'You cannot approve your own request' => 'cannot_approve_own_request',
        'This request was already decided' => 'request_already_decided',
        'Top-ups are not allowed at this location' => 'topup_location_not_allowed',
        'Top-ups are not allowed on this weekday' => 'topup_weekday_not_allowed',
        'Daily top-up limit exceeded' => 'topup_daily_limit_exceeded',
        'No top-up rule allows this operation' => 'topup_not_allowed',
    ];

    private const BY_STATUS = [400 => 'bad_request', 403 => 'forbidden', 404 => 'not_found', 409 => 'conflict', 422 => 'unprocessable', 429 => 'rate_limited'];

    public function __construct(
        string $message,
        private int $status = 400,
        private array $extra = [],
        private ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function extra(): array
    {
        return $this->extra;
    }

    public function errorCode(): string
    {
        if ($this->errorCode !== null) {
            return $this->errorCode;
        }
        if (str_starts_with($this->getMessage(), 'Reversal window of')) {
            return 'reversal_window_expired';
        }

        return self::CODES[$this->getMessage()] ?? self::BY_STATUS[$this->status] ?? 'error';
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => $this->getMessage(), 'code' => $this->errorCode()] + $this->extra, $this->status);
    }
}
