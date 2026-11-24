<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\CouponTransaction;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Shift;
use App\Support\AdminLocationScope;
use App\Support\AdminRbac;
use App\Support\SensitiveData;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Transacciones por localidad: todo lo consumido (cobros, reversas y recargas) en la localidad seleccionada en la barra
 * superior, o en todas las del permiso del administrador, con periodo, estado, cajero, dispositivo, surtidor, turno,
 * cliente y monto, buscador, totales de lo filtrado, paginación y exportación a CSV. Los números de cupón de barras
 * (secretos al portador) salen enmascarados salvo con el permiso de exportar números completos.
 */
class LocationTransactionsReportController extends Controller
{
    public const PERIODS = ['today' => 'Hoy', 'yesterday' => 'Ayer', '7d' => 'Últimos 7 días', '30d' => 'Últimos 30 días', 'month' => 'Este mes', 'custom' => 'Personalizado'];

    public const STATUSES = ['sold' => 'Cobros vigentes', 'reversed' => 'Cobros revertidos', 'reversal' => 'Reversas', 'topup' => 'Recargas'];

    private const SORTS = ['created_at', 'transaction_id', 'transaction_amount', 'coupon_number'];

    private const EXPORT_LIMIT = 200_000;

    public function index(Request $request): View|StreamedResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');
        abort_unless($admin->can(AdminRbac::permissionsForScreen('coupon-location-transactions')['view']), 403);

        $f = $request->validate([
            'period' => ['nullable', 'in:'.implode(',', array_keys(self::PERIODS))],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'status' => ['nullable', 'in:'.implode(',', array_keys(self::STATUSES))],
            'coupon_type' => ['nullable', 'in:QRCODE,CODBAR'],
            'user_id' => ['nullable', 'uuid'], 'device_id' => ['nullable', 'uuid'], 'customer_id' => ['nullable', 'uuid'],
            'shift_id' => ['nullable', 'integer'], 'dispenser' => ['nullable', 'in:none,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20'],
            'min' => ['nullable', 'numeric', 'min:0'], 'max' => ['nullable', 'numeric', 'min:0'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:'.implode(',', self::SORTS)], 'dir' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'in:25,50,100'],
        ]);

        [$from, $to, $period] = $this->range($f);
        $query = $this->filtered($admin, $f, $from, $to);
        $canFull = $admin->can(AdminRbac::actionPermissions('coupon-barcodes')['export_full']['permission'] ?? '__none__');
        $sort = $f['sort'] ?? 'created_at';
        $dir = ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($request->query('export') === 'csv') {
            abort_unless($admin->can(AdminRbac::actionPermissions('coupon-location-transactions')['export']['permission']), 403);

            return $this->export($admin, clone $query, $sort, $dir, $canFull, $f, $from, $to);
        }

        $totals = $this->totals(clone $query);
        $rows = (clone $query)->orderBy("coupon_transactions.$sort", $dir)->orderByDesc('coupon_transactions.transaction_id')
            ->paginate((int) ($f['per_page'] ?? 25))->withQueryString();

        $locIds = AdminLocationScope::effective($admin);

        return view('admin.reports.location-transactions', [
            'rows' => $rows, 'totals' => $totals, 'f' => $f + ['period' => $period], 'period' => $period,
            'from' => $from->format('Y-m-d\TH:i'), 'to' => $to->format('Y-m-d\TH:i'),
            'periods' => self::PERIODS, 'statuses' => self::STATUSES, 'sort' => $sort, 'dir' => $dir,
            'canFull' => $canFull,
            'scopeLabel' => $locIds === null ? 'todas las localidades' : (count($locIds) === 1 ? 'la localidad seleccionada' : 'tus localidades'),
            'locationNames' => AdminLocationScope::options($admin)->pluck('name', 'id'),
            'multiLocation' => $locIds === null || count($locIds) > 1,
            'cashiers' => DB::table('users')->whereIn('id', CouponTransaction::query()->visibleToLocations($admin)->whereNotNull('user_id')->select('user_id')->distinct()->limit(300))->orderBy('username')->pluck('username', 'id'),
            'devices' => Device::query()->withTrashed()->visibleToLocations($admin)->orderBy('name')->get(['id', 'name', 'device_fingerprint']),
            'customers' => Customer::query()->visibleTo($admin)->orderBy('name')->limit(500)->get(['id', 'name']),
            'shifts' => Shift::query()->visibleToLocations($admin)->with('location:id,name')->orderByDesc('starts_at')->limit(60)->get(),
            'exportUrl' => route('admin.reports.location-transactions', array_merge($request->query(), ['export' => 'csv', 'page' => null])),
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} */
    private function range(array $f): array
    {
        $period = $f['period'] ?? (isset($f['from']) || isset($f['to']) ? 'custom' : 'today');
        $now = now();
        [$from, $to] = match ($period) {
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            '30d' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'custom' => [isset($f['from']) ? Carbon::parse($f['from']) : $now->copy()->subDays(6)->startOfDay(), isset($f['to']) ? Carbon::parse($f['to']) : $now->copy()->endOfDay()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };

        return [$from, $to->lt($from) ? $from->copy()->endOfDay() : $to, $period];
    }

    /** Consulta con alcance (clientes y localidades), filtros y los datos de apoyo ya unidos. */
    private function filtered(AdminUser $admin, array $f, Carbon $from, Carbon $to): Builder
    {
        $t = 'coupon_transactions';
        $q = CouponTransaction::query()->visibleTo($admin)->visibleToLocations($admin)
            ->leftJoin('users as u', 'u.id', '=', "$t.user_id")
            ->leftJoin('admin_users as au', 'au.id', '=', "$t.admin_user_id")
            ->leftJoin('devices as d', 'd.id', '=', "$t.device_id")
            ->leftJoin('locations as l', 'l.id', '=', "$t.location_id")
            ->leftJoin('shifts as s', 's.id', '=', "$t.shift_id")
            ->leftJoin('customers as c', 'c.id', '=', "$t.customer_id")
            ->select("$t.*")
            ->selectRaw('u.username as cashier, au.name as admin_name, COALESCE(d.name, d.device_fingerprint) as device_name, l.name as location_name, s.number as shift_number, c.name as customer_name')
            ->selectRaw("CASE WHEN $t.kind = 'REDEEM' AND EXISTS (SELECT 1 FROM $t r WHERE r.reversed_of = $t.transaction_id) THEN 1 ELSE 0 END as is_reversed")
            ->whereBetween("$t.created_at", [$from, $to]);

        $reversedExists = fn ($w) => $w->whereRaw("EXISTS (SELECT 1 FROM $t r WHERE r.reversed_of = $t.transaction_id)");
        match ($f['status'] ?? null) {
            'sold' => $q->where("$t.kind", 'REDEEM')->whereRaw("NOT EXISTS (SELECT 1 FROM $t r WHERE r.reversed_of = $t.transaction_id)"),
            'reversed' => $q->where("$t.kind", 'REDEEM')->where($reversedExists),
            'reversal' => $q->where("$t.kind", 'REVERSAL'),
            'topup' => $q->where("$t.kind", 'TOPUP'),
            default => null,
        };

        $q->when($f['coupon_type'] ?? null, fn ($w, $v) => $w->where("$t.coupon_type", $v))
            ->when($f['user_id'] ?? null, fn ($w, $v) => $w->where("$t.user_id", $v))
            ->when($f['device_id'] ?? null, fn ($w, $v) => $w->where("$t.device_id", $v))
            ->when($f['customer_id'] ?? null, fn ($w, $v) => $w->where("$t.customer_id", $v))
            ->when($f['shift_id'] ?? null, fn ($w, $v) => $w->where("$t.shift_id", (int) $v))
            ->when(($f['dispenser'] ?? null) === 'none', fn ($w) => $w->whereNull("$t.dispenser"))
            ->when(isset($f['dispenser']) && $f['dispenser'] !== 'none', fn ($w) => $w->where("$t.dispenser", (int) $f['dispenser']))
            ->when(isset($f['min']) && $f['min'] !== '', fn ($w) => $w->whereRaw("ABS($t.transaction_amount) >= CAST(? AS DECIMAL(14,2))", [number_format((float) $f['min'], 2, '.', '')]))
            ->when(isset($f['max']) && $f['max'] !== '', fn ($w) => $w->whereRaw("ABS($t.transaction_amount) <= CAST(? AS DECIMAL(14,2))", [number_format((float) $f['max'], 2, '.', '')]));

        // Buscador: n.º de cupón, n.º de transacción, referencia de la venta o cajero
        $q->when(trim((string) ($f['q'] ?? '')) !== '', function ($w) use ($f, $t) {
            $term = trim((string) $f['q']);
            $like = '%'.addcslashes($term, '%_\\').'%';
            $w->where(function ($x) use ($term, $like, $t) {
                $x->where("$t.coupon_number", 'like', $like)->orWhere("$t.sale_ref", 'like', $like)->orWhere('u.username', 'like', $like)->orWhere('au.name', 'like', $like);
                if (ctype_digit($term)) {
                    $x->orWhere("$t.transaction_id", (int) $term);
                }
            });
        });

        return $q;
    }

    /** @return array{count: int, redemptions: int, redeemed: float, reversed: float, net: float, topups: float} */
    private function totals(Builder $query): array
    {
        $t = 'coupon_transactions';
        $r = (clone $query)->reorder()->toBase()->cloneWithout(['columns'])->selectRaw(
            "COUNT(*) as n, COALESCE(SUM(CASE WHEN $t.kind = 'REDEEM' THEN $t.transaction_amount ELSE 0 END), 0) as redeemed, COALESCE(-SUM(CASE WHEN $t.kind = 'REVERSAL' THEN $t.transaction_amount ELSE 0 END), 0) as reversed, COALESCE(SUM(CASE WHEN $t.kind = 'TOPUP' THEN $t.transaction_amount ELSE 0 END), 0) as topups, COUNT(CASE WHEN $t.kind = 'REDEEM' THEN 1 END) as redemptions"
        )->first();

        return [
            'count' => (int) $r->n, 'redemptions' => (int) $r->redemptions,
            'redeemed' => round((float) $r->redeemed, 2), 'reversed' => round((float) $r->reversed, 2),
            'net' => round((float) $r->redeemed - (float) $r->reversed, 2), 'topups' => round((float) $r->topups, 2),
        ];
    }

    private function export(AdminUser $admin, Builder $query, string $sort, string $dir, bool $canFull, array $f, Carbon $from, Carbon $to): StreamedResponse
    {
        $count = (clone $query)->reorder()->toBase()->cloneWithout(['columns'])->count();
        abort_if($count > self::EXPORT_LIMIT, 422, 'Son demasiados movimientos para exportar de una vez ('.number_format($count).'): acota el periodo.');

        AdminAuditLog::record('export', 'location_transactions', null, [
            'rows' => $count, 'period' => [$from->toDateTimeString(), $to->toDateTimeString()],
            'filters' => array_filter(array_diff_key($f, ['sort' => 1, 'dir' => 1, 'per_page' => 1]), fn ($v) => $v !== null && $v !== ''),
            'barcode_numbers' => $canFull ? 'completos' : 'enmascarados',
        ], $admin);

        $query->orderBy("coupon_transactions.$sort", $dir)->orderByDesc('coupon_transactions.transaction_id');
        $statusLabel = fn ($r) => match ($r->kind) {
            'REDEEM' => $r->is_reversed ? 'Cobro revertido' : 'Cobro vigente', 'REVERSAL' => 'Reversa', 'TOPUP' => 'Recarga', default => (string) $r->kind,
        };

        return response()->streamDownload(function () use ($query, $statusLabel, $canFull) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel abre bien los acentos
            fputcsv($out, ['Fecha', 'Hora', 'Transacción', 'Tipo', 'Estado', 'Cupón', 'Tipo de cupón', 'Monto', 'Saldo después', 'Localidad', 'Turno', 'Surtidor', 'Cajero', 'Dispositivo', 'Cliente', 'Venta']);
            foreach ($query->cursor() as $r) {
                $number = (string) $r->coupon_number;
                if ($r->coupon_type === 'CODBAR' && ! $canFull) {
                    $number = SensitiveData::maskNumber($number);
                }
                $row = [
                    Carbon::parse($r->created_at)->format('d/m/Y'), Carbon::parse($r->created_at)->format('H:i:s'), $r->transaction_id,
                    ['REDEEM' => 'Cobro', 'REVERSAL' => 'Reversa', 'TOPUP' => 'Recarga'][$r->kind] ?? $r->kind, $statusLabel($r), $number,
                    $r->coupon_type === 'QRCODE' ? 'QR' : 'Barras', number_format((float) $r->transaction_amount, 2, '.', ''),
                    $r->balance_after !== null ? number_format((float) $r->balance_after, 2, '.', '') : '', $r->location_name, $r->shift_number ? 'Turno '.$r->shift_number : '',
                    $r->dispenser, $r->cashier ?? ($r->admin_name ? 'Panel: '.$r->admin_name : ''), $r->device_name, $r->customer_name, $r->sale_ref,
                ];
                // Una celda que empieza con = + - @ se neutraliza para que la hoja de cálculo no la ejecute
                fputcsv($out, array_map(fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) ? "'".$v : $v, $row));
            }
            fclose($out);
        }, 'transacciones-localidad-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
