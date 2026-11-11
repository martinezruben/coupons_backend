<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReverseRequest;
use App\Models\CouponTransaction;
use App\Services\CouponService;
use Illuminate\Http\JsonResponse;

class CouponTransactionsController extends Controller
{
    /** Revierte una transacción (MANAGER/ADMIN): devuelve el saldo y deja auditoría. */
    public function reverse(ReverseRequest $request, int $transaction): JsonResponse
    {
        $original = CouponTransaction::findOrFail($transaction);

        return response()->json(CouponService::reverse(
            $original,
            $request->input('reason'),
            $request->user(),
            $request->ip(),
        ));
    }
}
