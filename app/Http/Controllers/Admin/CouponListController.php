<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\StreamsCsv;
use App\Http\Controllers\Controller;
use App\Models\ActivationOrder;
use App\Models\AdminUser;
use App\Models\CouponBatch;
use App\Models\Customer;
use App\Support\AdminRbac;
use App\Support\Format;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Lista única de cupones (QR y de barras) con el tipo como icono y filtro por tipo. Cada fila abre su detalle completo. */
class CouponListController extends Controller
{
    use StreamsCsv;

    public const TYPES = ['QRCODE' => ['table' => 'coupons', 'screen' => 'coupons', 'slug' => 'qr', 'label' => 'QR'], 'CODBAR' => ['table' => 'coupons_codbar', 'screen' => 'coupon-barcodes', 'slug' => 'barcode', 'label' => 'Barras']];

    private const SORTS = ['number' => 'coupon_number', 'status' => 'status', 'expire' => 'coupon_expire_date', 'activated' => 'activated_at', 'amount' => 'coupon_amount', 'balance' => 'coupon_balance', 'created' => 'coupon_date'];

    public function index(Request $request): View|StreamedResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        // Tipos que esta cuenta puede ver (cada uno tiene su permiso)
        $allowed = collect(self::TYPES)->filter(fn ($t) => $admin->can(AdminRbac::permissionsForScreen($t['screen'])['view']))->keys();
        abort_if($allowed->isEmpty(), 403);

        $f = $request->validate([
            'type' => ['nullable', 'in:QRCODE,CODBAR'],
            'status' => ['nullable', 'in:GENERATED,ASSIGNED,ACTIVE,INACTIVE,VOID'],
            'customer' => ['nullable', 'uuid'],
            'batch' => ['nullable', 'uuid'],
            'order' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:64'],
            'expire_to' => ['nullable', 'date'],
            'sort' => ['nullable', 'in:'.implode(',', array_keys(self::SORTS))],
            'dir' => ['nullable', 'in:asc,desc'],
        ]);

        $union = $this->unionQuery($f, $allowed, $admin);

        $sort = self::SORTS[$f['sort'] ?? 'created'];
        $dir = ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        if ($request->query('export') === 'csv') {
            return $this->export($admin, $allowed, $f, $union);
        }

        $coupons = DB::query()->fromSub($union, 'c')->orderBy($sort, $dir)->orderByDesc('coupon_id')->paginate(25)->withQueryString();

        $batches = CouponBatch::query()->whereIn('id', $coupons->pluck('batch_id')->filter())->pluck('code', 'id');
        $customers = Customer::query()->whereIn('id', $coupons->pluck('customer_id')->filter())->pluck('name', 'id');

        return view('admin.coupons.index', [
            'coupons' => $coupons,
            'f' => $f,
            'allowedTypes' => $allowed,
            'batchCodes' => $batches,
            'customerNames' => $customers,
            'order' => ($f['order'] ?? null) ? ActivationOrder::with(['batch', 'customer'])->find($f['order']) : null,
            'filterCustomers' => Customer::query()->visibleTo($admin)->orderBy('name')->get(['id', 'name']),
            'filterBatches' => CouponBatch::query()->orderByDesc('created_at')->limit(300)->get(['id', 'code', 'name']),
            'sort' => $f['sort'] ?? 'created',
            'dir' => $dir,
            'money' => fn ($v) => Format::money($v),
        ]);
    }

    /** Consulta unida (QR + barras) con los filtros aplicados; la usan el listado y la exportación. */
    private function unionQuery(array $f, $allowed, AdminUser $admin): Builder
    {
        $types = $allowed->when($f['type'] ?? null, fn ($c, $t) => $c->intersect([$t]))->values();
        $scope = $admin->allowedCustomerIds();

        $union = null;
        foreach ($types as $type) {
            $q = DB::table(self::TYPES[$type]['table'])->selectRaw("'{$type}' as ctype, coupon_id, coupon_number, status, batch_id, customer_id, coupon_date, coupon_expire_date, activated_at, coupon_amount, coupon_balance")
                ->when($f['status'] ?? null, fn (Builder $b, $v) => $b->where('status', $v))
                ->when($f['customer'] ?? null, fn (Builder $b, $v) => $b->where('customer_id', $v))
                ->when($f['batch'] ?? null, fn (Builder $b, $v) => $b->where('batch_id', $v))
                ->when($f['order'] ?? null, fn (Builder $b, $v) => $b->where('activation_order_id', $v))
                ->when($f['q'] ?? null, fn (Builder $b, $v) => $b->where('coupon_number', 'like', '%'.addcslashes($v, '%_\\').'%'))
                ->when($f['expire_to'] ?? null, fn (Builder $b, $v) => $b->whereDate('coupon_expire_date', '<=', $v))
                ->when($scope !== null, fn (Builder $b) => $b->whereIn('customer_id', $scope));
            $union = $union ? $union->unionAll($q) : $q;
        }

        return $union;
    }

    /** Exporta a CSV (Excel lo abre directo) todos los cupones que cumplen los filtros, sin paginar. */
    private function export(AdminUser $admin, $allowed, array $f, Builder $union)
    {
        // Solo los tipos exportables: cada tipo exige su propio permiso de exportación
        $exportable = $allowed->filter(fn ($t) => $admin->can(AdminRbac::actionPermissions(self::TYPES[$t]['screen'])['export']['permission'] ?? '__none__'));
        abort_if($exportable->isEmpty(), 403);
        if ($exportable->count() !== $allowed->count()) {
            $union = $this->unionQuery(array_merge($f, ['type' => $f['type'] ?? null]), $exportable, $admin);
        }

        $sort = self::SORTS[$f['sort'] ?? 'created'];
        $dir = ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query = DB::query()->fromSub($union, 'c')->orderBy($sort, $dir)->orderByDesc('coupon_id');
        $batches = CouponBatch::query()->pluck('code', 'id');
        $customers = Customer::query()->pluck('name', 'id');
        $status = ['GENERATED' => 'Generado', 'ASSIGNED' => 'Asignado', 'ACTIVE' => 'Activo', 'INACTIVE' => 'Inactivo', 'VOID' => 'Anulado'];
        $date = fn ($v, $fmt) => $v ? Carbon::parse($v)->format($fmt) : '';

        $rows = (function () use ($query, $batches, $customers, $status, $date) {
            foreach ($query->cursor() as $c) {
                yield [
                    $c->coupon_number, self::TYPES[$c->ctype]['label'], $status[$c->status] ?? $c->status,
                    $c->batch_id ? ($batches[$c->batch_id] ?? '') : '', $c->customer_id ? ($customers[$c->customer_id] ?? '') : '',
                    $date($c->coupon_date, 'Y-m-d'), $date($c->coupon_expire_date, 'Y-m-d'), $date($c->activated_at, 'Y-m-d H:i'),
                    number_format((float) $c->coupon_amount, 2, '.', ''), number_format((float) $c->coupon_balance, 2, '.', ''),
                ];
            }
        })();

        return $this->streamCsv('cupones-'.now()->format('Ymd-His').'.csv',
            ['Número', 'Tipo', 'Estado', 'Lote', 'Cliente', 'Emisión', 'Vence', 'Activado', 'Monto inicial', 'Saldo'], $rows);
    }
}
