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
use Illuminate\View\View;

/**
 * Acciones del panel sobre cupones: emitir, activar/desactivar, ajustar saldo y revertir cobros.
 * Cada acción tiene su permiso (`coupons.issue`, `coupons.toggle`, `coupons.adjust`,
 * `coupon_barcodes.*`, `coupon_transactions.reverse`) y deja auditoría con el administrador.
 */
class CouponAdminController extends Controller
{
    private const SCREENS = ['qr' => 'coupons', 'barcode' => 'coupon-barcodes'];

    public function issueForm(string $type): View
    {
        $screen = $this->screenFor($type);
        $this->authorizeAction($screen, 'issue');

        return view('admin.coupons.issue', ['type' => $type, 'screen' => $screen, 'issued' => null]);
    }

    public function issue(Request $request, string $type): View
    {
        $screen = $this->screenFor($type);
        $this->authorizeAction($screen, 'issue');

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:500'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:'.config('coupons.max_amount'), 'decimal:0,2'],
            'expire_date' => ['nullable', 'date', 'after:today'],
            'prefix' => ['nullable', 'alpha_num', 'max:6'],
        ], [], ['quantity' => 'cantidad', 'amount' => 'monto', 'expire_date' => 'fecha de vencimiento', 'prefix' => 'prefijo']);

        $issued = $this->service($type)->issue(
            (int) $data['quantity'],
            (string) $data['amount'],
            $data['expire_date'] ?? null,
            strtoupper($data['prefix'] ?? ''),
            $request->user('admin'),
            $request->ip(),
        );

        // Los tokens QR solo se muestran aquí: no se guardan en ningún otro lugar visible.
        return view('admin.coupons.issue', ['type' => $type, 'screen' => $screen, 'issued' => $issued, 'amount' => $data['amount']]);
    }

    public function toggle(Request $request, string $type, int $id): RedirectResponse
    {
        $screen = $this->screenFor($type);
        $this->authorizeAction($screen, 'toggle');
        $data = $request->validate(['active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:3', 'max:255']]);

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
        $number = $model::where('coupon_id', $id)->value('coupon_number');
        abort_if($number === null, 404);

        return $this->run(fn () => $this->service($type)->restore($number, (string) $data['balance'], $data['reason'], $request->user('admin'), $request->ip()),
            $screen, 'Saldo ajustado.');
    }

    public function reverse(Request $request, int $id): RedirectResponse
    {
        $this->authorizeAction('coupon-transactions', 'reverse');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']], [], ['reason' => 'motivo']);
        $original = CouponTransaction::findOrFail($id);

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
