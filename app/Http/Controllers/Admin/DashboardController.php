<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivationOrder;
use App\Models\AdminUser;
use App\Models\CouponTransaction;
use App\Models\Customer;
use App\Support\CouponStats;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Inicio del panel: indicadores y gráficas de cupones (por periodo y, opcionalmente, por cliente). */
class DashboardController extends Controller
{
    private const PERIODS = [7, 14, 30];

    public function index(Request $request): View
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');
        $filters = $request->validate(['days' => ['nullable', 'integer', 'in:'.implode(',', self::PERIODS)], 'customer' => ['nullable', 'uuid']]);
        $days = (int) ($filters['days'] ?? 14);

        // Un cliente fuera del alcance del administrador equivale a no existir
        $customerId = $filters['customer'] ?? null;
        if ($customerId !== null && ! Customer::query()->visibleTo($admin)->whereKey($customerId)->exists()) {
            abort(404);
        }

        $start = CarbonImmutable::now()->subDays($days - 1)->startOfDay();
        $statuses = CouponStats::byStatus($admin, $customerId);

        $moves = fn () => CouponStats::transactions($admin, $customerId);
        $net = fn ($from) => (float) $moves()->where('created_at', '>=', $from)->whereIn('kind', ['REDEEM', 'REVERSAL'])->sum('transaction_amount');
        $topups = (float) $moves()->where('created_at', '>=', $start)->where('kind', 'TOPUP')->sum('transaction_amount');

        $pending = ActivationOrder::query()->visibleTo($admin)->where('status', 'pending')
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId));

        $kpis = [
            ['label' => 'Sin asignar', 'value' => number_format($statuses['GENERATED']['count'] ?? 0), 'hint' => 'generados, aún sin cliente'],
            ['label' => 'Asignados', 'value' => number_format($statuses['ASSIGNED']['count'] ?? 0), 'hint' => 'reservados, sin activar'],
            ['label' => 'Activos', 'value' => number_format($statuses['ACTIVE']['count'] ?? 0), 'hint' => 'cobrables ahora'],
            ['label' => 'Saldo en circulación', 'value' => Format::money($statuses['ACTIVE']['balance'] ?? 0), 'hint' => 'de los cupones activos'],
            ['label' => 'Cobrado hoy', 'value' => Format::money($net(CarbonImmutable::now()->startOfDay())), 'hint' => 'neto de reversas'],
            ['label' => "Cobrado ({$days} días)", 'value' => Format::money($net($start)), 'hint' => 'neto de reversas'],
            ['label' => "Recargado ({$days} días)", 'value' => Format::money($topups), 'hint' => 'no cuenta como cobro'],
            ['label' => 'Activaciones programadas', 'value' => number_format((clone $pending)->count()), 'hint' => number_format((int) (clone $pending)->sum('coupons_count')).' cupones por activar'],
        ];

        // Series diarias (rellenadas con ceros) para las gráficas
        $labels = collect(range(0, $days - 1))->map(fn ($i) => $start->addDays($i)->toDateString());
        $perDay = fn ($rows) => $labels->map(fn ($d) => round((float) ($rows[$d] ?? 0), 2))->all();

        $redeemed = $moves()->where('created_at', '>=', $start)->whereIn('kind', ['REDEEM', 'REVERSAL'])
            ->selectRaw('DATE(created_at) as d, SUM(transaction_amount) as v')->groupBy('d')->pluck('v', 'd');
        $toppedUp = $moves()->where('created_at', '>=', $start)->where('kind', 'TOPUP')
            ->selectRaw('DATE(created_at) as d, SUM(transaction_amount) as v')->groupBy('d')->pluck('v', 'd');

        $activated = [];
        foreach (CouponStats::TABLES as $table) {
            DB::table($table)->where('activated_at', '>=', $start)
                ->when($admin->allowedCustomerIds() !== null, fn ($q) => $q->whereIn('customer_id', $admin->allowedCustomerIds()))
                ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
                ->selectRaw('DATE(activated_at) as d, COUNT(*) as v')->groupBy('d')->get()
                ->each(function ($r) use (&$activated) {
                    $activated[$r->d] = ($activated[$r->d] ?? 0) + (int) $r->v;
                });
        }

        $topCustomers = collect();
        foreach (CouponStats::TABLES as $table) {
            DB::table($table)->where('status', 'ACTIVE')->whereNotNull('customer_id')
                ->when($admin->allowedCustomerIds() !== null, fn ($q) => $q->whereIn('customer_id', $admin->allowedCustomerIds()))
                ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
                ->selectRaw('customer_id, COUNT(*) as n, SUM(coupon_balance) as bal')->groupBy('customer_id')->get()
                ->each(fn ($r) => $topCustomers->push($r));
        }
        $names = Customer::query()->whereIn('id', $topCustomers->pluck('customer_id')->unique())->pluck('name', 'id');
        $topCustomers = $topCustomers->groupBy('customer_id')
            ->map(fn ($g, $id) => ['name' => $names[$id] ?? '—', 'count' => (int) $g->sum('n'), 'balance' => (float) $g->sum('bal')])
            ->sortByDesc('balance')->take(5)->values();

        $statusLabels = ['GENERATED' => 'Sin asignar', 'ASSIGNED' => 'Asignados', 'ACTIVE' => 'Activos', 'INACTIVE' => 'Inactivos', 'VOID' => 'Anulados'];

        return view('admin.dashboard-welcome', [
            'kpis' => $kpis,
            'days' => $days,
            'periods' => self::PERIODS,
            'customerId' => $customerId,
            'customers' => Customer::query()->visibleTo($admin)->orderBy('name')->get(['id', 'name']),
            'charts' => [
                'labels' => $labels->all(),
                'redeemed' => $perDay($redeemed),
                'topups' => $perDay($toppedUp),
                'activated' => $perDay($activated),
                'status' => [
                    'labels' => array_values($statusLabels),
                    'values' => array_map(fn ($k) => $statuses[$k]['count'] ?? 0, array_keys($statusLabels)),
                ],
            ],
            'topCustomers' => $topCustomers,
            'upcoming' => (clone $pending)->with(['batch', 'customer'])->orderBy('activate_at')->limit(5)->get(),
            'recent' => CouponTransaction::query()->visibleTo($admin)->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
                ->with(['user', 'location'])->latest('transaction_id')->limit(8)->get(),
        ]);
    }
}
