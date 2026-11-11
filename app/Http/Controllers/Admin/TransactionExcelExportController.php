<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Support\AdminRbac;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionExcelExportController extends Controller
{
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $this->authorizeExport();

        $validator = Validator::make($request->all(), [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'location_id' => ['nullable', 'uuid', 'exists:locations,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos.', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $fromDay = Carbon::parse($validated['date_from'])->startOfDay();
        $toDay = Carbon::parse($validated['date_to'])->startOfDay();

        if ($fromDay->gt($toDay)) {
            return response()->json(['message' => 'La fecha inicial no puede ser posterior a la final.'], 422);
        }

        if ($fromDay->diffInDays($toDay) > 30) {
            return response()->json([
                'message' => 'El periodo no puede superar 31 días (desde y hasta inclusive).',
            ], 422);
        }

        $from = $fromDay->copy();
        $to = Carbon::parse($validated['date_to'])->endOfDay();

        $locationId = $validated['location_id'] ?? null;

        $transactions = Transaction::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->with(['items', 'payments', 'location', 'device', 'user', 'shift'])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $filename = 'transacciones-'.$from->format('Y-m-d').'_'.$to->format('Y-m-d').'_'.now()->format('His').'.xlsx';

        return response()->streamDownload(function () use ($transactions): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Ventas');

            $cell = static function (Worksheet $sheet, int $col, int $row, mixed $value): void {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col).$row, $value);
            };

            $row = 1;
            foreach ($transactions as $t) {
                $cell($sheet, 1, $row, 'DATOS DE LA VENTA');
                $row++;

                $txHeaders = [
                    'ID transacción',
                    'ID externo',
                    'Fecha y hora',
                    'Estado',
                    'Total',
                    'Localidad',
                    'Dispositivo',
                    'Usuario',
                    'Turno (sistema)',
                    'Nº turno (cliente)',
                    'Sincronizado',
                    'Fecha sincronización',
                ];
                foreach ($txHeaders as $i => $h) {
                    $cell($sheet, $i + 1, $row, $h);
                }
                $row++;

                $deviceLabel = $t->device !== null
                    ? (($t->device->name ?? '') !== '' ? $t->device->name : $t->device->device_fingerprint)
                    : '';
                $userLabel = $t->user !== null
                    ? (($t->user->full_name ?? '') !== '' ? $t->user->full_name : $t->user->username)
                    : '';
                $shiftLabel = '';
                if ($t->shift !== null) {
                    $shiftLabel = (string) ($t->shift->shift_number ?? '');
                }

                $values = [
                    (string) $t->getKey(),
                    $t->external_id,
                    $t->occurred_at?->format('Y-m-d H:i:s'),
                    $t->status,
                    (string) $t->total,
                    $t->location?->name ?? '',
                    $deviceLabel,
                    $userLabel,
                    $shiftLabel,
                    (string) $t->turn_number,
                    $t->is_synced ? 'Sí' : 'No',
                    $t->synced_at?->format('Y-m-d H:i:s') ?? '',
                ];
                foreach ($values as $i => $v) {
                    $cell($sheet, $i + 1, $row, $v);
                }
                $row++;

                $cell($sheet, 1, $row, 'LÍNEAS');
                $row++;

                $lineHeaders = [
                    'Producto',
                    'SKU',
                    'ID producto',
                    'Cantidad',
                    'Precio unitario',
                    'Descuento',
                    'Impuesto',
                    'Total línea',
                ];
                foreach ($lineHeaders as $i => $h) {
                    $cell($sheet, $i + 1, $row, $h);
                }
                $row++;

                foreach ($t->items as $item) {
                    $line = [
                        $item->product_name,
                        $item->product_sku,
                        $item->product_id !== null ? (string) $item->product_id : '',
                        (string) $item->qty,
                        (string) $item->unit_price,
                        (string) $item->discount,
                        (string) $item->tax,
                        (string) $item->line_total,
                    ];
                    foreach ($line as $i => $v) {
                        $cell($sheet, $i + 1, $row, $v);
                    }
                    $row++;
                }

                $cell($sheet, 1, $row, 'PAGOS');
                $row++;

                $payHeaders = ['Método', 'Importe', 'Referencia'];
                foreach ($payHeaders as $i => $h) {
                    $cell($sheet, $i + 1, $row, $h);
                }
                $row++;

                foreach ($t->payments as $pay) {
                    $cell($sheet, 1, $row, $pay->payment_method);
                    $cell($sheet, 2, $row, (string) $pay->amount);
                    $cell($sheet, 3, $row, $pay->reference ?? '');
                    $row++;
                }

                $row++;
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function authorizeExport(): void
    {
        $user = auth('admin')->user();
        abort_unless($user, 403);
        $p = AdminRbac::permissionsForScreen('transactions');
        abort_unless($user->can($p['view']), 403);
    }
}
