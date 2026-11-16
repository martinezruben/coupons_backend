<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivationOrder;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\CouponAuditLog;
use App\Models\CouponBatch;
use App\Models\Customer;
use App\Models\User;
use App\Support\AdminRbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Detalle completo de un registro de la auditoría de cupones, para el modal. */
class CouponAuditDetailController extends Controller
{
    public function show(int $id): JsonResponse
    {
        $admin = auth('admin')->user();
        abort_unless($admin && $admin->can(AdminRbac::permissionsForScreen('coupon-audit-logs')['view']), 403);

        $log = CouponAuditLog::query()->findOrFail($id);
        $batch = $log->batch_id ? CouponBatch::with('creator')->find($log->batch_id) : null;
        $actor = $log->admin_user_id ? AdminUser::find($log->admin_user_id) : null;
        $appUser = $log->user_id ? User::find($log->user_id) : null;

        // Activación a la que pertenece (asignar, activar, programar, cancelar)
        $order = $log->activation_order_id ? ActivationOrder::with(['customer'])->find($log->activation_order_id) : null;
        $orderData = $order ? [
            'id' => $order->id,
            'mode' => ['assign' => 'Solo asignar', 'now' => 'Activar ahora', 'scheduled' => 'Activación programada'][$order->mode] ?? $order->mode,
            'status' => ['pending' => 'Pendiente', 'applied' => 'Aplicada', 'cancelled' => 'Cancelada'][$order->status] ?? $order->status,
            'customer' => Customer::withTrashed()->find($order->customer_id)?->name,
            'coupons' => (int) $order->coupons_count,
            'selection' => ['all' => 'Todos los elegibles', 'range' => 'Rango por secuencia', 'quantity' => 'Cantidad', 'list' => 'Lista de números'][$order->selection_type] ?? $order->selection_type,
            'selection_detail' => $order->selection,
            'activate_at' => $order->activate_at?->format('d/m/Y H:i'),
            'applied_at' => $order->applied_at?->format('d/m/Y H:i:s'),
            'cancelled_at' => $order->cancelled_at?->format('d/m/Y H:i:s'),
            'requested_by' => AdminUser::find($order->requested_by_admin_id)?->name,
            'cancelled_by' => $order->cancelled_by_admin_id ? AdminUser::find($order->cancelled_by_admin_id)?->name : null,
            'reason' => $order->reason,
        ] : null;

        // Lo que la persona hizo en el panel en ese momento (ruta, datos enviados, navegador)
        $panel = null;
        if ($log->admin_user_id) {
            $at = Carbon::parse($log->created_at);
            $row = AdminAuditLog::query()->where('admin_user_id', $log->admin_user_id)->where('action', 'submit')
                ->whereBetween('created_at', [$at->copy()->subSeconds(5), $at->copy()->addSeconds(5)])->orderByDesc('created_at')->first();
            $panel = $row ? ['route' => $row->route, 'method' => $row->method, 'url' => $row->url, 'summary' => $row->summary, 'input' => $row->input, 'user_agent' => $row->user_agent, 'status' => $row->status_code] : null;
        }

        $couponUrl = null;
        if ($log->coupon_number) {
            $table = $log->coupon_type === 'QRCODE' ? 'coupons' : 'coupons_codbar';
            $couponId = DB::table($table)->where('coupon_number', $log->coupon_number)->value('coupon_id');
            $screen = $log->coupon_type === 'QRCODE' ? 'coupons' : 'coupon-barcodes';
            if ($couponId && $admin->can(AdminRbac::actionPermissions($screen)['detail']['permission'])) {
                $couponUrl = route('admin.coupons.show', [$log->coupon_type === 'QRCODE' ? 'qr' : 'barcode', $couponId]);
            }
        }

        return response()->json([
            'id' => $log->id,
            'created_at' => Carbon::parse($log->created_at)->format('d/m/Y H:i:s'),
            'action' => $log->action,
            'action_label' => CouponAuditLog::ACTIONS[$log->action] ?? $log->action,
            'description' => CouponAuditLog::DESCRIPTIONS[$log->action] ?? null,
            'actor' => $actor ? ['name' => $actor->name, 'email' => $actor->email, 'kind' => 'Administrador del panel'] : ($appUser ? ['name' => $appUser->username, 'email' => null, 'kind' => 'Usuario de la app'] : null),
            'ip' => $log->ip_address,
            'coupon_type' => $log->coupon_type ? ($log->coupon_type === 'QRCODE' ? 'Cupón QR' : 'Cupón de barras') : null,
            'coupon_number' => $log->coupon_number,
            'coupon_url' => $couponUrl,
            'old_value' => $log->old_value,
            'new_value' => $log->new_value,
            'reason' => $log->reason_full ?: $log->reason,
            'batch' => $batch ? [
                'code' => $batch->code, 'name' => $batch->name, 'status' => $batch->status, 'quantity' => $batch->quantity, 'amount' => number_format((float) $batch->amount, 2),
                'created_by' => $batch->creator?->name, 'url' => $admin->can('coupon_batches.view') ? route('admin.coupon-batches.show', $batch) : null,
            ] : null,
            'order' => $orderData,
            'panel' => $panel,
        ]);
    }
}
