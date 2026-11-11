<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Support\AdminGridCell;
use App\Support\AdminRbac;
use App\Support\Format;
use Illuminate\Http\JsonResponse;

class TransactionLineItemsController extends Controller
{
    public function show(Transaction $transaction): JsonResponse
    {
        $screen = 'transactions';
        $user = auth('admin')->user();
        abort_unless($user, 403);
        $p = AdminRbac::permissionsForScreen($screen);
        abort_unless($user->can($p['view']), 403);

        $transaction->load(['items', 'payments', 'location', 'device', 'user']);

        // Valores listos para mostrar en el modal (español, $1,234.56, fecha local)
        $method = fn (?string $code) => AdminGridCell::valueLabel('payment_method', $code);

        return response()->json([
            'id' => (string) $transaction->getKey(),
            'external_id' => $transaction->external_id,
            'occurred_at' => Format::dateTime($transaction->occurred_at, true),
            'status' => AdminGridCell::valueLabel('status', $transaction->status),
            'total' => Format::money($transaction->total),
            'payment_methods' => $transaction->payments->pluck('payment_method')->unique()->map($method)->values()->all(),
            'location_name' => $transaction->location?->name,
            'device_label' => $transaction->device !== null
                ? (($transaction->device->name ?? '') !== ''
                    ? $transaction->device->name
                    : $transaction->device->device_fingerprint)
                : null,
            'items' => $transaction->items->map(fn ($i) => [
                'product_name' => $i->product_name,
                'product_sku' => $i->product_sku,
                'qty' => Format::number($i->qty),
                'unit_price' => Format::money($i->unit_price),
                'discount' => Format::money($i->discount),
                'tax' => Format::money($i->tax),
                'line_total' => Format::money($i->line_total),
            ])->values(),
            'payments' => $transaction->payments->map(fn ($p) => [
                'payment_method' => $method($p->payment_method),
                'amount' => Format::money($p->amount),
                'reference' => $p->reference,
            ])->values(),
        ]);
    }
}
