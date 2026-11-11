<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\Transaction;
use App\Support\AdminGridQuery;
use App\Support\AdminRbac;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cierre de caja (arqueo): totales por turno con desglose por método de pago.
 *
 * `transactions.shift_id` es una cadena libre enviada por el POS (no FK), así que
 * un turno se empareja con sus transacciones por: localidad + `shift_id == shift_number`
 * + `occurred_at` dentro de la ventana [start_time, end_time ?? ahora].
 */
class CashClosingController extends Controller
{
    private const METHOD_LABELS = [
        'CASH' => 'Efectivo',
        'CARD' => 'Tarjeta',
        'TRANSFER' => 'Transferencia',
        'OTHER' => 'Otro',
    ];

    public function index(Request $request): View
    {
        $this->authorizeView();

        $cfg = config('admin_screens.shifts');
        $filterOptions = AdminGridQuery::filterOptions($cfg);

        $from = $this->dateFrom($request, now()->subDays(7)->startOfDay());
        $to = $this->dateFrom($request, now()->endOfDay(), 'date_to', true);
        $locationId = $request->input('filter.location_id');

        $shifts = Shift::query()
            ->with(['location:id,name', 'device:id,name,device_fingerprint', 'user:id,full_name,username'])
            ->when($locationId, fn (Builder $q) => $q->where('location_id', $locationId))
            ->whereBetween('start_time', [$from, $to])
            ->orderByDesc('start_time')
            ->limit(200)
            ->get();

        $rows = $shifts->map(fn (Shift $shift) => $this->shiftRow($shift));

        return view('admin.reports.cash-closing', [
            'rows' => $rows,
            'gridFilterOptions' => $filterOptions,
            'filterLocationId' => $locationId,
            'filterDateFrom' => $request->input('filter.date_from'),
            'filterDateTo' => $request->input('filter.date_to'),
            'canExport' => auth('admin')->user()?->can(AdminRbac::permissionsForScreen('cierre-caja')['view']),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeView();

        $from = $this->dateFrom($request, now()->subDays(7)->startOfDay());
        $to = $this->dateFrom($request, now()->endOfDay(), 'date_to', true);
        $locationId = $request->input('filter.location_id');

        $shifts = Shift::query()
            ->with(['location:id,name', 'device:id,name,device_fingerprint', 'user:id,full_name,username'])
            ->when($locationId, fn (Builder $q) => $q->where('location_id', $locationId))
            ->whereBetween('start_time', [$from, $to])
            ->orderByDesc('start_time')
            ->limit(1000)
            ->get();

        $filename = 'arqueo-caja-'.now()->format('Ymd_His').'.xlsx';

        return response()->streamDownload(function () use ($shifts): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Arqueo');

            $headers = ['Localidad', 'Dispositivo', 'Usuario', 'Turno', 'Inicio', 'Fin', 'Saldo inicial', 'Efectivo', 'Tarjeta', 'Transferencia', 'Otro', 'Total ventas', 'Efectivo esperado', 'Efectivo declarado', 'Diferencia'];
            foreach ($headers as $i => $h) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'1', $h);
            }

            $row = 2;
            foreach ($shifts as $shift) {
                $data = $this->shiftRow($shift);
                $values = [
                    $data['location'],
                    $data['device'],
                    $data['user'],
                    $data['shift_number'],
                    $data['start_time']?->format('Y-m-d H:i'),
                    $data['end_time']?->format('Y-m-d H:i'),
                    (float) $data['opening_balance'],
                    $data['methods']['CASH'],
                    $data['methods']['CARD'],
                    $data['methods']['TRANSFER'],
                    $data['methods']['OTHER'],
                    $data['total_sales'],
                    $data['expected_cash'],
                    $data['closing_balance'],
                    $data['difference'],
                ];
                foreach ($values as $i => $v) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$row, $v);
                }
                $row++;
            }

            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @return array{id: string, location: string, device: string, user: string, shift_number: string,
     *     start_time: ?Carbon, end_time: ?Carbon, opening_balance: float, closing_balance: ?float,
     *     methods: array{CASH: float, CARD: float, TRANSFER: float, OTHER: float}, total_sales: float,
     *     expected_cash: float, difference: ?float}
     */
    private function shiftRow(Shift $shift): array
    {
        $end = $shift->end_time ?? now();

        $methods = array_fill_keys(array_keys(self::METHOD_LABELS), 0.0);
        $total = 0.0;

        $paid = Transaction::query()
            ->where('status', 'PAID')
            ->where('location_id', $shift->location_id)
            ->where('shift_id', (string) $shift->shift_number)
            ->whereBetween('occurred_at', [$shift->start_time, $end])
            ->with('payments:id,transaction_id,payment_method,amount')
            ->select('id', 'total')
            ->get();

        foreach ($paid as $tx) {
            $total += (float) $tx->total;
            foreach ($tx->payments as $payment) {
                $methods[(string) $payment->payment_method] = ($methods[(string) $payment->payment_method] ?? 0) + (float) $payment->amount;
            }
        }

        $opening = (float) ($shift->opening_balance ?? 0);
        $expectedCash = $opening + $methods['CASH'];
        $closing = $shift->closing_balance !== null ? (float) $shift->closing_balance : null;

        return [
            'id' => (string) $shift->getKey(),
            'location' => $shift->location?->name ?? '—',
            'device' => $shift->device?->name ?: ($shift->device?->device_fingerprint ?? '—'),
            'user' => $shift->user?->full_name ?: ($shift->user?->username ?? '—'),
            'shift_number' => (string) $shift->shift_number,
            'start_time' => $shift->start_time,
            'end_time' => $shift->end_time,
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'methods' => $methods,
            'total_sales' => round($total, 2),
            'expected_cash' => round($expectedCash, 2),
            'difference' => $closing !== null ? round($closing - $expectedCash, 2) : null,
        ];
    }

    private function dateFrom(Request $request, Carbon $default, string $key = 'date_from', bool $endOfDay = false): Carbon
    {
        $raw = $request->input('filter.'.$key, $request->input($key));
        if (! $raw) {
            return $endOfDay ? $default->endOfDay() : $default->startOfDay();
        }

        $date = Carbon::parse($raw);

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    private function authorizeView(): void
    {
        $user = auth('admin')->user();
        abort_unless($user, 403);
        $p = AdminRbac::permissionsForScreen('cierre-caja');
        abort_unless($user->can($p['view']), 403);
    }
}
