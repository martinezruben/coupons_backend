<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\CouponBatch;
use App\Models\Customer;
use App\Support\AdminRbac;
use App\Support\Format;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Lista única de cupones (QR y de barras) con el tipo como icono y filtro por tipo. Cada fila abre su detalle completo. */
class CouponListController extends Controller
{
    public const TYPES = ['QRCODE' => ['table' => 'coupons', 'screen' => 'coupons', 'slug' => 'qr', 'label' => 'QR'], 'CODBAR' => ['table' => 'coupons_codbar', 'screen' => 'coupon-barcodes', 'slug' => 'barcode', 'label' => 'Barras']];

    private const SORTS = ['number' => 'coupon_number', 'status' => 'status', 'expire' => 'coupon_expire_date', 'activated' => 'activated_at', 'amount' => 'coupon_amount', 'balance' => 'coupon_balance', 'created' => 'coupon_date'];

    public function index(Request $request): View
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
            'q' => ['nullable', 'string', 'max:64'],
            'expire_to' => ['nullable', 'date'],
            'sort' => ['nullable', 'in:'.implode(',', array_keys(self::SORTS))],
            'dir' => ['nullable', 'in:asc,desc'],
        ]);

        $types = $allowed->when($f['type'] ?? null, fn ($c, $t) => $c->intersect([$t]))->values();
        $scope = $admin->allowedCustomerIds();

        $union = null;
        foreach ($types as $type) {
            $q = DB::table(self::TYPES[$type]['table'])->selectRaw("'{$type}' as ctype, coupon_id, coupon_number, status, batch_id, customer_id, coupon_date, coupon_expire_date, activated_at, coupon_amount, coupon_balance")
                ->when($f['status'] ?? null, fn (Builder $b, $v) => $b->where('status', $v))
                ->when($f['customer'] ?? null, fn (Builder $b, $v) => $b->where('customer_id', $v))
                ->when($f['batch'] ?? null, fn (Builder $b, $v) => $b->where('batch_id', $v))
                ->when($f['q'] ?? null, fn (Builder $b, $v) => $b->where('coupon_number', 'like', '%'.addcslashes($v, '%_\\').'%'))
                ->when($f['expire_to'] ?? null, fn (Builder $b, $v) => $b->whereDate('coupon_expire_date', '<=', $v))
                ->when($scope !== null, fn (Builder $b) => $b->whereIn('customer_id', $scope));
            $union = $union ? $union->unionAll($q) : $q;
        }

        $sort = self::SORTS[$f['sort'] ?? 'created'];
        $dir = ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $coupons = DB::query()->fromSub($union, 'c')->orderBy($sort, $dir)->orderByDesc('coupon_id')->paginate(25)->withQueryString();

        $batches = CouponBatch::query()->whereIn('id', $coupons->pluck('batch_id')->filter())->pluck('code', 'id');
        $customers = Customer::query()->whereIn('id', $coupons->pluck('customer_id')->filter())->pluck('name', 'id');

        return view('admin.coupons.index', [
            'coupons' => $coupons,
            'f' => $f,
            'allowedTypes' => $allowed,
            'batchCodes' => $batches,
            'customerNames' => $customers,
            'filterCustomers' => Customer::query()->visibleTo($admin)->orderBy('name')->get(['id', 'name']),
            'filterBatches' => CouponBatch::query()->orderByDesc('created_at')->limit(300)->get(['id', 'code', 'name']),
            'sort' => $f['sort'] ?? 'created',
            'dir' => $dir,
            'money' => fn ($v) => Format::money($v),
        ]);
    }
}
