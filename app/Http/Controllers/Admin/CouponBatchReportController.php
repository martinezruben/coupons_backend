<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\StreamsCsv;
use App\Http\Controllers\Controller;
use App\Models\CouponBatch;
use App\Support\AdminRbac;
use App\Support\CouponStats;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Reporte por lote: cuántos cupones hay en cada estado, saldo en circulación y cobrado neto. */
class CouponBatchReportController extends Controller
{
    use StreamsCsv;

    public function index(Request $request): View|StreamedResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin && $admin->can(AdminRbac::permissionsForScreen('coupon-batch-report')['view']), 403);

        $f = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'in:generating,ready,failed,void'], 'coupon_type' => ['nullable', 'in:QRCODE,CODBAR'],
        ]);
        $from = Carbon::parse($f['from'] ?? now()->subDays(89)->toDateString())->startOfDay();
        $to = Carbon::parse($f['to'] ?? now()->toDateString())->endOfDay();

        $batches = CouponBatch::query()->whereBetween('created_at', [$from, $to])
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['coupon_type'] ?? null, fn ($q, $v) => $q->where('coupon_type', $v))
            ->orderByDesc('created_at')->limit(500)->get();

        $allowed = $admin->allowedCustomerIds();
        $stats = [];
        $net = [];
        foreach (CouponStats::TABLES as $type => $table) {
            $ids = $batches->where('coupon_type', $type)->pluck('id');
            if ($ids->isEmpty()) {
                continue;
            }
            DB::table($table)->whereIn('batch_id', $ids)
                ->when($allowed !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('customer_id', $allowed)->orWhere(fn ($g) => $g->where('status', 'GENERATED')->whereNull('customer_id'))))
                ->selectRaw('batch_id, status, COUNT(*) as n, COALESCE(SUM(coupon_balance), 0) as bal')->groupBy('batch_id', 'status')->get()
                ->each(function ($r) use (&$stats) {
                    $stats[$r->batch_id][$r->status] = ['n' => (int) $r->n, 'bal' => (float) $r->bal];
                });

            DB::table('coupon_transactions as t')->join("{$table} as c", 'c.coupon_id', '=', 't.coupon_id')
                ->where('t.coupon_type', $type)->whereIn('c.batch_id', $ids)->whereIn('t.kind', ['REDEEM', 'REVERSAL'])
                ->when($allowed !== null, fn ($q) => $q->whereIn('t.customer_id', $allowed))
                ->selectRaw('c.batch_id, SUM(t.transaction_amount) as v')->groupBy('c.batch_id')->get()
                ->each(function ($r) use (&$net) {
                    $net[$r->batch_id] = (float) $r->v;
                });
        }

        $rows = $batches->map(function (CouponBatch $b) use ($stats, $net) {
            $s = $stats[$b->id] ?? [];
            $n = fn (string $status) => $s[$status]['n'] ?? 0;

            return (object) [
                'batch' => $b,
                'generated' => $n('GENERATED'), 'assigned' => $n('ASSIGNED'), 'active' => $n('ACTIVE'), 'inactive' => $n('INACTIVE'), 'void' => $n('VOID'),
                'circulating' => (float) ($s['ACTIVE']['bal'] ?? 0),
                'redeemed' => round((float) ($net[$b->id] ?? 0), 2),
            ];
        });

        if ($request->query('export') === 'csv') {
            return $this->streamCsv("reporte-lotes-{$from->format('Ymd')}_{$to->format('Ymd')}.csv",
                ['Lote', 'Nombre', 'Tipo', 'Creado', 'Estado', 'Cantidad', 'Sin asignar', 'Asignados', 'Activos', 'Inactivos', 'Anulados', 'Saldo en circulación', 'Cobrado neto'],
                $rows->map(fn ($r) => [
                    $r->batch->code, $r->batch->name, $r->batch->coupon_type === 'QRCODE' ? 'QR' : 'Barras', $r->batch->created_at->format('Y-m-d'), $r->batch->status,
                    $r->batch->quantity, $r->generated, $r->assigned, $r->active, $r->inactive, $r->void,
                    number_format($r->circulating, 2, '.', ''), number_format($r->redeemed, 2, '.', ''),
                ])->all());
        }

        return view('admin.reports.batches', [
            'rows' => $rows,
            'totals' => [
                'quantity' => $rows->sum(fn ($r) => $r->batch->quantity), 'generated' => $rows->sum('generated'), 'assigned' => $rows->sum('assigned'),
                'active' => $rows->sum('active'), 'circulating' => $rows->sum('circulating'), 'redeemed' => $rows->sum('redeemed'),
            ],
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'status' => $f['status'] ?? '', 'couponType' => $f['coupon_type'] ?? '',
            'exportUrl' => route('admin.reports.batches', array_merge($request->query(), ['export' => 'csv'])),
            'truncated' => $batches->count() === 500,
        ]);
    }
}
