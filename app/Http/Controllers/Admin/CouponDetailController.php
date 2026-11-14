<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivationOrder;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\BarcodeCoupon;
use App\Models\Coupon;
use App\Models\CouponAuditLog;
use App\Models\CouponBatch;
use App\Models\CouponTransaction;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Location;
use App\Models\User;
use App\Support\AdminRbac;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Detalle de un cupón: datos actuales, resumen de uso y la línea de tiempo completa (creación, asignación,
 * activación, cobros, recargas, reversas, ajustes, cambios de datos y quién lo consultó), con localidad,
 * dispositivo, valores y saldo tras cada movimiento.
 */
class CouponDetailController extends Controller
{
    private const MAX_EVENTS = 500;

    private const AUDIT_TITLES = [
        'restore' => 'Saldo ajustado por un administrador', 'activate' => 'Cupón activado', 'deactivate' => 'Cupón desactivado',
        'topup' => 'Auditoría de la recarga', 'reverse' => 'Auditoría de la reversa', 'void' => 'Cupón anulado', 'unassign' => 'Cupón liberado',
        'assign' => 'Cupón asignado', 'schedule' => 'Activación programada', 'batch_void' => 'Cupón anulado con el lote',
    ];

    public function show(Request $request, string $type, int $id): View
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');
        $isQr = $type === 'qr';
        $screen = $isQr ? 'coupons' : 'coupon-barcodes';
        abort_unless($admin->can(AdminRbac::permissionsForScreen($screen)['view']), 403);

        $model = $isQr ? Coupon::class : BarcodeCoupon::class;
        $coupon = $model::query()->visibleTo($admin)->where('coupon_id', $id)->firstOrFail();
        $couponType = $isQr ? 'QRCODE' : 'CODBAR';

        $batch = $coupon->batch_id ? CouponBatch::with('creator')->find($coupon->batch_id) : null;
        $customer = $coupon->customer_id ? Customer::withTrashed()->find($coupon->customer_id) : null;
        $order = $coupon->activation_order_id ? ActivationOrder::find($coupon->activation_order_id) : null;

        $transactions = CouponTransaction::query()->where('coupon_id', $id)->where('coupon_type', $couponType)
            ->orderByDesc('created_at')->orderByDesc('transaction_id')->limit(self::MAX_EVENTS)->get()->sortBy([['created_at', 'asc'], ['transaction_id', 'asc']])->values();
        $locations = Location::query()->whereIn('id', $transactions->pluck('location_id')->filter()->unique())->pluck('name', 'id');
        $devices = Device::query()->whereIn('id', $transactions->pluck('device_id')->filter()->unique())->get(['id', 'name', 'device_fingerprint'])->keyBy('id');
        $appUsers = User::query()->whereIn('id', $transactions->pluck('user_id')->filter()->unique())->pluck('username', 'id');
        $customerNames = Customer::withTrashed()->whereIn('id', $transactions->pluck('customer_id')->filter()->unique())->pluck('name', 'id');

        $audits = CouponAuditLog::query()->where('coupon_number', $coupon->coupon_number)->where('coupon_type', $couponType)
            ->orderByDesc('id')->limit(self::MAX_EVENTS)->get()->reverse()->values();
        $auditAdmins = AdminUser::query()->whereIn('id', $audits->pluck('admin_user_id')->filter()->unique())->pluck('name', 'id');
        $auditUsers = User::query()->whereIn('id', $audits->pluck('user_id')->filter()->unique())->pluck('username', 'id');
        $txAdmins = AdminUser::query()->whereIn('id', $transactions->pluck('admin_user_id')->filter()->unique())->pluck('name', 'id');

        $dataChanges = AdminAuditLog::query()->where('entity_type', class_basename($model))->where('entity_id', (string) $id)
            ->whereNotIn('action', ['view', 'submit'])->orderByDesc('created_at')->limit(self::MAX_EVENTS)->get()->reverse()->values();
        $views = AdminAuditLog::query()->where('entity_type', 'Panel')->where('entity_id', "{$type}:{$id}")
            ->orderByDesc('created_at')->limit(100)->get();

        $topups = (float) $transactions->where('kind', 'TOPUP')->sum('transaction_amount');
        $initial = round((float) $coupon->coupon_amount - $topups, 2);

        $events = [];

        // Creación
        $createdAt = $batch?->generated_at ?? ($coupon->coupon_date ? Carbon::parse($coupon->coupon_date)->startOfDay() : null);
        $events[] = [
            'at' => $createdAt, 'order' => 0, 'kind' => 'created', 'title' => $batch ? 'Cupón generado' : 'Cupón emitido',
            'lines' => array_values(array_filter([
                $batch ? 'Lote '.$batch->code.' — '.$batch->name.($coupon->seq ? " · n.º {$coupon->seq} de ".number_format($batch->quantity) : '') : 'Sin lote (emitido o importado antes de los lotes)',
                'Monto inicial: $'.number_format($initial, 2),
                $coupon->coupon_expire_date ? 'Vence el '.Carbon::parse($coupon->coupon_expire_date)->format('d/m/Y') : 'Sin vencimiento',
            ])),
            'actor' => $batch?->creator?->name, 'balance' => $initial,
        ];

        // Asignación y activación (según la última orden que lo tocó)
        if ($coupon->assigned_at) {
            $events[] = [
                'at' => Carbon::parse($coupon->assigned_at), 'order' => 1, 'kind' => 'assigned', 'title' => 'Asignado a '.($customer?->name ?? 'un cliente'),
                'lines' => array_values(array_filter([
                    $order ? 'Modo: '.['assign' => 'solo asignar', 'now' => 'activar ahora', 'scheduled' => 'activación programada'][$order->mode] ?? $order->mode : null,
                    $order?->reason ? 'Motivo: '.$order->reason : null,
                ])),
                'actor' => $order?->requested_by_admin_id ? AdminUser::find($order->requested_by_admin_id)?->name : null,
            ];
        }
        if ($coupon->activated_at) {
            $events[] = [
                'at' => Carbon::parse($coupon->activated_at), 'order' => 2, 'kind' => 'activated', 'title' => 'Activado',
                'lines' => array_values(array_filter([$order && $order->mode === 'scheduled' && $order->activate_at ? 'Programado para el '.$order->activate_at->format('d/m/Y H:i') : null])),
                'actor' => $order?->requested_by_admin_id ? AdminUser::find($order->requested_by_admin_id)?->name : null,
            ];
        }

        foreach ($transactions as $t) {
            $kind = $t->kind ?? 'REDEEM';
            $amount = (float) $t->transaction_amount;
            $device = $t->device_id ? ($devices[$t->device_id] ?? null) : null;
            $events[] = [
                'at' => $t->created_at, 'order' => 5, 'kind' => ['REDEEM' => 'redeem', 'TOPUP' => 'topup', 'REVERSAL' => 'reversal'][$kind] ?? 'redeem',
                'title' => ['REDEEM' => 'Cobro', 'TOPUP' => 'Recarga', 'REVERSAL' => 'Reversa de cobro'][$kind] ?? $kind,
                'amount' => $kind === 'TOPUP' ? $amount : -$amount, // + suma al saldo, − lo descuenta
                'delta' => $kind === 'TOPUP' ? $amount : -$amount,
                'lines' => array_values(array_filter([
                    $t->location_id ? 'Localidad: '.($locations[$t->location_id] ?? 'desconocida') : ($t->legacy_site_id ? "Localidad heredada n.º {$t->legacy_site_id}" : ($t->admin_user_id ? 'Desde el panel (sin localidad)' : null)),
                    $device ? 'Dispositivo: '.($device->name ?: $device->device_fingerprint) : ($t->legacy_device_id ? "Dispositivo heredado n.º {$t->legacy_device_id}" : null),
                    $t->reversed_of ? "Reversa del cobro n.º {$t->reversed_of}" : null,
                    $t->customer_id ? 'Cliente: '.($customerNames[$t->customer_id] ?? '—') : null,
                    $t->ip_address ? 'IP: '.$t->ip_address : null,
                    'Transacción n.º '.$t->transaction_id,
                ])),
                'actor' => $t->user_id ? ($appUsers[$t->user_id] ?? 'Usuario de la app').' (app)' : ($t->admin_user_id ? $txAdmins[$t->admin_user_id] ?? 'Administrador' : null),
            ];
        }

        foreach ($audits as $a) {
            $events[] = [
                'at' => Carbon::parse($a->created_at), 'order' => 4, 'kind' => $a->action === 'restore' ? 'adjust' : 'status', 'title' => self::AUDIT_TITLES[$a->action] ?? ucfirst($a->action),
                'lines' => array_values(array_filter([
                    ($a->old_value !== null || $a->new_value !== null) ? 'Antes: '.($a->old_value ?? '—').' → Después: '.($a->new_value ?? '—') : null,
                    $a->reason ? 'Motivo: '.$a->reason : null,
                    $a->ip_address ? 'IP: '.$a->ip_address : null,
                ])),
                'actor' => $a->admin_user_id ? ($auditAdmins[$a->admin_user_id] ?? 'Administrador') : ($a->user_id ? ($auditUsers[$a->user_id] ?? 'Usuario de la app').' (app)' : null),
                'set_balance' => $a->action === 'restore' && is_numeric($a->new_value) ? (float) $a->new_value : null,
            ];
        }

        foreach ($dataChanges as $log) {
            $events[] = [
                'at' => $log->created_at, 'order' => 6, 'kind' => 'change', 'title' => 'Cambio de datos registrado',
                'lines' => array_values(array_filter([$log->summary, $log->route ? 'Ruta: '.$log->route : null, $log->ip ? 'IP: '.$log->ip : null])),
                'actor' => $log->admin_name, 'changes' => $log->changes,
            ];
        }

        // Orden cronológico y saldo tras cada movimiento (calculado)
        usort($events, fn ($a, $b) => [$a['at']?->getTimestamp() ?? 0, $a['order']] <=> [$b['at']?->getTimestamp() ?? 0, $b['order']]);
        $running = $initial;
        foreach ($events as &$e) {
            if (isset($e['set_balance']) && $e['set_balance'] !== null) {
                $running = $e['set_balance'];
                $e['balance'] = $running;
            } elseif (isset($e['delta'])) {
                $running = round($running + $e['delta'], 2);
                $e['balance'] = $running;
            }
        }
        unset($e);

        $redeemed = (float) $transactions->where('kind', 'REDEEM')->sum('transaction_amount');
        $reversed = (float) $transactions->where('kind', 'REVERSAL')->sum('transaction_amount');

        return view('admin.coupons.show', [
            'coupon' => $coupon, 'type' => $type, 'couponType' => $couponType, 'screen' => $screen,
            'batch' => $batch, 'customer' => $customer, 'order' => $order,
            'events' => array_reverse($events), // lo más reciente primero
            'views' => $views,
            'initial' => $initial,
            'summary' => [
                'redeemed' => round($redeemed + $reversed, 2), // cobrado neto de reversas
                'topups' => round($topups, 2),
                'transactions' => $transactions->count(),
                'locations' => $transactions->pluck('location_id')->filter()->unique()->map(fn ($l) => $locations[$l] ?? null)->filter()->values()->all(),
                'last_use' => $transactions->where('kind', 'REDEEM')->last()?->created_at,
            ],
            'balanceCheck' => abs($running - (float) $coupon->coupon_balance) < 0.005,
            'truncated' => $transactions->count() >= self::MAX_EVENTS || $audits->count() >= self::MAX_EVENTS,
            'can' => [
                'toggle' => $admin->can(AdminRbac::actionPermissions($screen)['toggle']['permission'] ?? '-'),
                'adjust' => $admin->can(AdminRbac::actionPermissions($screen)['adjust']['permission'] ?? '-'),
                'topup' => $admin->can(AdminRbac::actionPermissions($screen)['topup']['permission'] ?? '-'),
            ],
        ]);
    }
}
