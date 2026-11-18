<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\CouponException;
use App\Http\Controllers\Controller;
use App\Models\AdjustmentRequest;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\User;
use App\Services\CouponService;
use App\Support\AdminRbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Solicitudes de ajuste de saldo: las revisa y aprueba (o rechaza) un administrador distinto de quien las pidió. */
class CouponAdjustmentController extends Controller
{
    public function index(Request $request): View
    {
        $admin = $request->user('admin');
        abort_unless($admin && $admin->can(AdminRbac::permissionsForScreen('coupon-adjustments')['view']), 403);

        $status = in_array($request->query('status'), ['PENDING', 'APPROVED', 'REJECTED'], true) ? $request->query('status') : 'PENDING';
        $rows = AdjustmentRequest::query()->where('status', $status)->orderByDesc('id')->paginate(25)->withQueryString();

        return view('admin.adjustments.index', [
            'rows' => $rows,
            'status' => $status,
            'adminNames' => AdminUser::query()->whereIn('id', $rows->pluck('requested_by_admin_id')->merge($rows->pluck('decided_by_admin_id'))->filter())->pluck('name', 'id'),
            'userNames' => User::query()->whereIn('id', $rows->pluck('requested_by_user_id')->filter())->pluck('username', 'id'),
            'canApprove' => $admin->can(AdminRbac::actionPermissions('coupon-adjustments')['approve']['permission']),
            'me' => $admin->getKey(),
        ]);
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        return $this->decide($request, $id, function (AdjustmentRequest $req, AdminUser $admin) use ($request) {
            CouponService::approveAdjustment($req, $admin, $request->ip());
            AdminAuditLog::record('approve', 'coupon_adjustments', (string) $req->id, ['coupon' => $req->coupon_number, 'new_balance' => (string) $req->new_balance], $admin);

            return 'Ajuste aprobado y aplicado.';
        });
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        return $this->decide($request, $id, function (AdjustmentRequest $req, AdminUser $admin) use ($request) {
            CouponService::rejectAdjustment($req, $admin, $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null);
            AdminAuditLog::record('reject', 'coupon_adjustments', (string) $req->id, ['coupon' => $req->coupon_number], $admin);

            return 'Ajuste rechazado.';
        });
    }

    private function decide(Request $request, int $id, callable $do): RedirectResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');
        abort_unless($admin->can(AdminRbac::actionPermissions('coupon-adjustments')['approve']['permission']), 403);
        $req = AdjustmentRequest::findOrFail($id);

        try {
            $message = $do($req, $admin);
        } catch (CouponException $e) {
            return back()->withErrors(['coupon' => $e->getMessage()]);
        }

        return back()->with('status', $message);
    }
}
