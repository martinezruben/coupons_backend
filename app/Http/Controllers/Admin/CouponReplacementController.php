<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\CouponException;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\CouponReplacement;
use App\Services\CouponReplacementService;
use App\Support\AdminRbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Solicitudes de reemplazo de cupón perdido: las aprueba (o rechaza) un administrador distinto de quien las pidió. */
class CouponReplacementController extends Controller
{
    public function index(Request $request): View
    {
        $admin = $request->user('admin');
        abort_unless($admin && $admin->can(AdminRbac::permissionsForScreen('coupon-replacements')['view']), 403);

        $status = in_array($request->query('status'), ['PENDING', 'APPROVED', 'REJECTED'], true) ? $request->query('status') : 'PENDING';
        $rows = CouponReplacement::query()->where('status', $status)->orderByDesc('id')->paginate(25)->withQueryString();

        return view('admin.replacements.index', [
            'rows' => $rows, 'status' => $status,
            'adminNames' => AdminUser::query()->whereIn('id', $rows->pluck('requested_by_admin_id')->merge($rows->pluck('decided_by_admin_id'))->filter())->pluck('name', 'id'),
            'canApprove' => $admin->can(AdminRbac::actionPermissions('coupon-replacements')['approve']['permission']),
            'me' => $admin->getKey(),
        ]);
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $admin = $this->approver($request);
        $rep = CouponReplacement::findOrFail($id);

        try {
            $done = CouponReplacementService::approve($rep, $admin, $request->ip());
        } catch (CouponException $e) {
            return back()->withErrors(['coupon' => $e->getMessage()]);
        }
        AdminAuditLog::record('approve', 'coupon_replacements', (string) $rep->id, ['old' => $done->old_coupon_number, 'new' => $done->new_coupon_number, 'balance' => (string) $done->balance], $admin);

        return back()->with('status', "Reemplazo aprobado: {$done->old_coupon_number} quedó anulado y su saldo pasó al cupón {$done->new_coupon_number}.")->with('new_coupon', [$done->routeType(), $done->new_coupon_id]);
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $admin = $this->approver($request);
        $rep = CouponReplacement::findOrFail($id);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;

        try {
            CouponReplacementService::reject($rep, $admin, $note);
        } catch (CouponException $e) {
            return back()->withErrors(['coupon' => $e->getMessage()]);
        }
        AdminAuditLog::record('reject', 'coupon_replacements', (string) $rep->id, ['coupon' => $rep->old_coupon_number], $admin);

        return back()->with('status', 'Reemplazo rechazado.');
    }

    private function approver(Request $request): AdminUser
    {
        /** @var AdminUser|null $admin */
        $admin = $request->user('admin');
        abort_unless($admin && $admin->can(AdminRbac::actionPermissions('coupon-replacements')['approve']['permission']), 403);

        return $admin;
    }
}
