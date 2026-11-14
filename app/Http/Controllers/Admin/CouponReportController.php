<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CouponTransaction;
use App\Models\Location;
use App\Support\AdminRbac;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Reporte de canjes: cobrado, reversado y recargado, agrupado por día, localidad, dispositivo, usuario o tipo. */
class CouponReportController extends Controller
{
    public const GROUPS = [
        'day' => 'Día',
        'location' => 'Localidad',
        'device' => 'Dispositivo',
        'user' => 'Usuario',
        'type' => 'Tipo de cupón',
    ];

    public function redemptions(Request $request): View|StreamedResponse
    {
        $user = auth('admin')->user();
        abort_unless($user && $user->can(AdminRbac::permissionsForScreen('coupon-reports')['view']), 403);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'group' => ['nullable', 'in:'.implode(',', array_keys(self::GROUPS))],
            'coupon_type' => ['nullable', 'in:QRCODE,CODBAR'],
            'location_id' => ['nullable', 'uuid'],
        ]);

        $from = Carbon::parse($filters['from'] ?? now()->subDays(29)->toDateString())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now()->toDateString())->endOfDay();
        $group = $filters['group'] ?? 'day';

        $rows = $this->rows($group, $from, $to, $filters['coupon_type'] ?? null, $filters['location_id'] ?? null);
        $totals = [
            'redeemed' => $rows->sum('redeemed'),
            'reversed' => $rows->sum('reversed'),
            'net' => $rows->sum('net'),
            'topups' => $rows->sum('topups'),
            'redemptions' => (int) $rows->sum('redemptions'),
        ];

        if ($request->query('export') === 'csv') {
            return $this->csv($rows, $totals, $group, $from, $to);
        }

        return view('admin.reports.redemptions', [
            'rows' => $rows,
            'totals' => $totals,
            'group' => $group,
            'groups' => self::GROUPS,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'couponType' => $filters['coupon_type'] ?? '',
            'locationId' => $filters['location_id'] ?? '',
            'locations' => Location::query()->orderBy('name')->get(['id', 'name']),
            'maxNet' => max(1.0, (float) $rows->max(fn ($r) => abs($r->net))),
            'exportUrl' => route('admin.reports.redemptions', array_merge($request->query(), ['export' => 'csv'])),
        ]);
    }

    /** @return Collection<int, object{label: string, redeemed: float, reversed: float, net: float, topups: float, redemptions: int}> */
    private function rows(string $group, Carbon $from, Carbon $to, ?string $couponType, ?string $locationId)
    {
        $t = 'coupon_transactions';
        $query = CouponTransaction::query()->whereBetween("$t.created_at", [$from, $to]);

        if ($couponType) {
            $query->where("$t.coupon_type", $couponType);
        }
        if ($locationId) {
            $query->where("$t.location_id", $locationId);
        }

        $label = match ($group) {
            'location' => (function () use ($query, $t) {
                $query->leftJoin('locations', 'locations.id', '=', "$t.location_id");

                return "COALESCE(locations.name, 'Sin localidad')";
            })(),
            'device' => (function () use ($query, $t) {
                $query->leftJoin('devices', 'devices.id', '=', "$t.device_id");

                return "COALESCE(devices.name, devices.device_fingerprint, 'Sin dispositivo')";
            })(),
            'user' => (function () use ($query, $t) {
                $query->leftJoin('users', 'users.id', '=', "$t.user_id")->leftJoin('admin_users', 'admin_users.id', '=', "$t.admin_user_id");

                return "COALESCE(users.username, admin_users.name, 'Sin usuario')";
            })(),
            'type' => "$t.coupon_type",
            default => "DATE($t.created_at)",
        };

        $rows = $query
            ->selectRaw("$label as label")
            ->selectRaw("COALESCE(SUM(CASE WHEN $t.kind = 'REDEEM' THEN $t.transaction_amount ELSE 0 END), 0) as redeemed")
            ->selectRaw("COALESCE(-SUM(CASE WHEN $t.kind = 'REVERSAL' THEN $t.transaction_amount ELSE 0 END), 0) as reversed")
            ->selectRaw("COALESCE(SUM(CASE WHEN $t.kind = 'TOPUP' THEN $t.transaction_amount ELSE 0 END), 0) as topups")
            ->selectRaw("COUNT(CASE WHEN $t.kind = 'REDEEM' THEN 1 END) as redemptions")
            ->groupByRaw($label)
            ->get();

        $rows = $rows->map(function ($r) {
            $r->redeemed = (float) $r->redeemed;
            $r->reversed = (float) $r->reversed;
            $r->topups = (float) $r->topups;
            $r->redemptions = (int) $r->redemptions;
            $r->net = round($r->redeemed - $r->reversed, 2);
            if ($r->label === 'QRCODE' || $r->label === 'CODBAR') {
                $r->label = $r->label === 'QRCODE' ? 'QR' : 'Código de barras';
            }

            return $r;
        });

        return $group === 'day' ? $rows->sortBy('label')->values() : $rows->sortByDesc('net')->values();
    }

    private function csv($rows, array $totals, string $group, Carbon $from, Carbon $to): StreamedResponse
    {
        $name = sprintf('canjes-%s-%s_%s.csv', $group, $from->format('Ymd'), $to->format('Ymd'));

        return response()->streamDownload(function () use ($rows, $totals, $group) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel abre bien los acentos
            fputcsv($out, [self::GROUPS[$group], 'Cobros (n.º)', 'Cobrado', 'Reversado', 'Neto cobrado', 'Recargado']);
            foreach ($rows as $r) {
                fputcsv($out, [$r->label, $r->redemptions, number_format($r->redeemed, 2, '.', ''), number_format($r->reversed, 2, '.', ''), number_format($r->net, 2, '.', ''), number_format($r->topups, 2, '.', '')]);
            }
            fputcsv($out, ['TOTAL', $totals['redemptions'], number_format($totals['redeemed'], 2, '.', ''), number_format($totals['reversed'], 2, '.', ''), number_format($totals['net'], 2, '.', ''), number_format($totals['topups'], 2, '.', '')]);
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
