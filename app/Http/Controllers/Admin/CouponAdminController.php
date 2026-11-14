<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\CouponException;
use App\Http\Controllers\Controller;
use App\Models\BarcodeCoupon;
use App\Models\Coupon;
use App\Models\CouponTransaction;
use App\Services\CouponService;
use App\Support\AdminRbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Acciones del panel sobre cupones: emitir, activar/desactivar, ajustar saldo y revertir cobros.
 * Cada acción tiene su permiso (`coupons.toggle`, `coupons.adjust`,
 * `coupon_barcodes.*`, `coupon_transactions.reverse`) y deja auditoría con el administrador.
 */
class CouponAdminController extends Controller
{
    private const SCREENS = ['qr' => 'coupons', 'barcode' => 'coupon-barcodes'];

    public function toggle(Request $request, string $type, int $id): RedirectResponse
    {
        $screen = $this->screenFor($type);
        $this->authorizeAction($screen, 'toggle');
        $data = $request->validate(['active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:3', 'max:255']]);
        $model = $type === 'qr' ? Coupon::class : BarcodeCoupon::class;
        abort_unless($model::query()->visibleTo($request->user('admin'))->where('coupon_id', $id)->exists(), 404);

        return $this->run(fn () => $this->service($type)->setActive($id, (bool) $data['active'], $data['reason'], $request->user('admin'), $request->ip()),
            $screen, $data['active'] ? 'Cupón activado.' : 'Cupón desactivado.');
    }

    public function adjust(Request $request, string $type, int $id): RedirectResponse
    {
        $screen = $this->screenFor($type);
        $this->authorizeAction($screen, 'adjust');
        $data = $request->validate([
            'balance' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [], ['balance' => 'saldo', 'reason' => 'motivo']);

        $model = $type === 'qr' ? Coupon::class : BarcodeCoupon::class;
        $number = $model::query()->visibleTo($request->user('admin'))->where('coupon_id', $id)->value('coupon_number');
        abort_if($number === null, 404);

        return $this->run(fn () => $this->service($type)->restore($number, (string) $data['balance'], $data['reason'], $request->user('admin'), $request->ip()),
            $screen, 'Saldo ajustado.');
    }

    public function topUp(Request $request, string $type, int $id): RedirectResponse
    {
        $screen = $this->screenFor($type);
        $this->authorizeAction($screen, 'topup');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:'.config('coupons.max_amount'), 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:150'],
        ], [], ['amount' => 'monto', 'note' => 'nota']);

        $model = $type === 'qr' ? Coupon::class : BarcodeCoupon::class;
        $number = $model::query()->visibleTo($request->user('admin'))->where('coupon_id', $id)->value('coupon_number');
        abort_if($number === null, 404);

        // Desde el panel no hay dispositivo ni localidad: no se exige token ni se evalúan reglas de localidad.
        return $this->run(fn () => $this->service($type)->topUp($number, null, (string) $data['amount'], null, $request->user('admin'), $request->ip(), false, $data['note'] ?? null),
            $screen, 'Cupón recargado.');
    }

    public function reverse(Request $request, int $id): RedirectResponse
    {
        $this->authorizeAction('coupon-transactions', 'reverse');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']], [], ['reason' => 'motivo']);
        $original = CouponTransaction::query()->visibleTo($request->user('admin'))->findOrFail($id);

        return $this->run(fn () => CouponService::reverse($original, $data['reason'], $request->user('admin'), $request->ip()),
            'coupon-transactions', 'Cobro revertido.');
    }

    private function run(callable $action, string $screen, string $okMessage): RedirectResponse
    {
        try {
            $action();
        } catch (CouponException $e) {
            return redirect()->route('admin.screens.index', $screen)->withErrors(['coupon' => $e->getMessage()]);
        }

        return redirect()->route('admin.screens.index', $screen)->with('status', $okMessage);
    }

    private function screenFor(string $type): string
    {
        return self::SCREENS[$type] ?? abort(404);
    }

    private function service(string $type): CouponService
    {
        return $type === 'qr' ? CouponService::qr() : CouponService::barcode();
    }

    private function authorizeAction(string $screen, string $action): void
    {
        $permission = AdminRbac::actionPermissions($screen)[$action]['permission'] ?? null;
        $user = auth('admin')->user();

        abort_unless($permission && $user && $user->can($permission), 403);
    }
}
