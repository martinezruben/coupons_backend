<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\StreamsCsv;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\AdminRbac;
use App\Support\CouponStats;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Reporte por cliente: cupones asignados y activos, saldo, cobrado neto, última activación y programadas. */
class CouponCustomerReportController extends Controller
{
    use StreamsCsv;

    public function index(Request $request): View|StreamedResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin && $admin->can(AdminRbac::permissionsForScreen('coupon-customer-report')['view']), 403);

        $f = $request->validate(['active' => ['nullable', 'in:1,0'], 'q' => ['nullable', 'string', 'max:100']]);
        $customers = Customer::query()->visibleTo($admin)
            ->when(($f['active'] ?? null) !== null && ($f['active'] ?? '') !== '', fn ($q) => $q->where('is_active', (bool) $f['active']))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'like', "%{$v}%")->orWhere('document', 'like', "%{$v}%")))
            ->orderBy('name')->limit(1000)->get();
        $ids = $customers->pluck('id');

        $by = [];
        $last = [];
        foreach (CouponStats::TABLES as $table) {
            if ($ids->isEmpty()) {
                break;
            }
            DB::table($table)->whereIn('customer_id', $ids)->selectRaw('customer_id, status, COUNT(*) as n, COALESCE(SUM(coupon_balance), 0) as bal, MAX(activated_at) as last')
                ->groupBy('customer_id', 'status')->get()->each(function ($r) use (&$by, &$last) {
                    $by[$r->customer_id][$r->status]['n'] = ($by[$r->customer_id][$r->status]['n'] ?? 0) + (int) $r->n;
                    $by[$r->customer_id][$r->status]['bal'] = ($by[$r->customer_id][$r->status]['bal'] ?? 0) + (float) $r->bal;
                    if ($r->last && (! isset($last[$r->customer_id]) || $r->last > $last[$r->customer_id])) {
                        $last[$r->customer_id] = $r->last;
                    }
                });
        }

        $net = $ids->isEmpty() ? collect() : CouponStats::transactions($admin)->whereIn('customer_id', $ids)->whereIn('kind', ['REDEEM', 'REVERSAL'])
            ->selectRaw('customer_id, SUM(transaction_amount) as v')->groupBy('customer_id')->pluck('v', 'customer_id');
        $pending = $ids->isEmpty() ? collect() : DB::table('activation_orders')->where('status', 'pending')->whereIn('customer_id', $ids)
            ->selectRaw('customer_id, SUM(coupons_count) as n')->groupBy('customer_id')->pluck('n', 'customer_id');

        $rows = $customers->map(function (Customer $c) use ($by, $last, $net, $pending) {
            $s = $by[$c->id] ?? [];

            return (object) [
                'customer' => $c,
                'assigned' => $s['ASSIGNED']['n'] ?? 0, 'active' => $s['ACTIVE']['n'] ?? 0, 'inactive' => $s['INACTIVE']['n'] ?? 0,
                'circulating' => (float) ($s['ACTIVE']['bal'] ?? 0),
                'redeemed' => round((float) ($net[$c->id] ?? 0), 2),
                'last' => isset($last[$c->id]) ? Carbon::parse($last[$c->id]) : null,
                'scheduled' => (int) ($pending[$c->id] ?? 0),
            ];
        });

        if ($request->query('export') === 'csv') {
            return $this->streamCsv('reporte-clientes-'.now()->format('Ymd').'.csv',
                ['Cliente', 'Documento', 'Activo', 'Asignados', 'Activos', 'Inactivos', 'Programados', 'Saldo en circulación', 'Cobrado neto', 'Última activación'],
                $rows->map(fn ($r) => [
                    $r->customer->name, $r->customer->document, $r->customer->is_active ? 'Sí' : 'No', $r->assigned, $r->active, $r->inactive, $r->scheduled,
                    number_format($r->circulating, 2, '.', ''), number_format($r->redeemed, 2, '.', ''), $r->last?->format('Y-m-d H:i'),
                ])->all());
        }

        return view('admin.reports.customers', [
            'rows' => $rows,
            'active' => $f['active'] ?? '', 'q' => $f['q'] ?? '',
            'totals' => ['assigned' => $rows->sum('assigned'), 'active' => $rows->sum('active'), 'circulating' => $rows->sum('circulating'), 'redeemed' => $rows->sum('redeemed'), 'scheduled' => $rows->sum('scheduled')],
            'exportUrl' => route('admin.reports.customers', array_merge($request->query(), ['export' => 'csv'])),
        ]);
    }
}
