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

/**
 * Reporte de transacciones (menú Reportes): descarga Excel en formato plano.
 * Sin detalle: una fila por transacción. Con `include_detail`: una fila por línea
 * de venta, repitiendo las columnas de la transacción y añadiendo SKU, producto
 * y montos de la línea.
 */
class TransactionReportExportController extends Controller
{
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $this->authorizeExport();

        $validator = Validator::make($request->all(), [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'location_id' => ['nullable', 'uuid', 'exists:locations,id'],
            'include_detail' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos.', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        $from = isset($validated['date_from']) ? Carbon::parse($validated['date_from'])->startOfDay() : null;
        $to = isset($validated['date_to']) ? Carbon::parse($validated['date_to'])->endOfDay() : null;

        if ($from !== null && $to !== null && $from->gt($to)) {
            return response()->json(['message' => 'La fecha inicial no puede ser posterior a la final.'], 422);
        }

        $includeDetail = (bool) ($validated['include_detail'] ?? false);
        $locationId = $validated['location_id'] ?? null;

        $transactions = Transaction::query()
            ->when($from, fn ($q) => $q->where('occurred_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('occurred_at', '<=', $to))
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->when($includeDetail, fn ($q) => $q->with('items'))
            ->with(['location', 'device', 'user', 'payments'])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $headers = [
            'ID transacción',
            'ID externo',
            'Fecha y hora',
            'Localidad',
            'Dispositivo',
            'Usuario',
            'Turno (cliente)',
            'Estado',
            'Método de pago',
            'Total',
        ];
        $detailHeaders = ['SKU', 'Producto', 'Cantidad', 'Precio unitario', 'Descuento', 'Impuesto', 'Total línea'];
        if ($includeDetail) {
            $headers = array_merge($headers, $detailHeaders);
        }

        $filename = 'reporte-transacciones-'.now()->format('Ymd_His').'.xlsx';

        return response()->streamDownload(function () use ($transactions, $headers, $includeDetail): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Transacciones');

            $cell = static function (Worksheet $sheet, int $col, int $row, mixed $value): void {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col).$row, $value);
            };

            foreach ($headers as $i => $h) {
                $cell($sheet, $i + 1, 1, $h);
            }

            $row = 2;
            foreach ($transactions as $t) {
                $deviceLabel = $t->device !== null
                    ? (($t->device->name ?? '') !== '' ? $t->device->name : $t->device->device_fingerprint)
                    : '';
                $userLabel = $t->user !== null
                    ? (($t->user->full_name ?? '') !== '' ? $t->user->full_name : $t->user->username)
                    : '';

                $methodLabels = ['CASH' => 'Efectivo', 'CARD' => 'Tarjeta', 'TRANSFER' => 'Transferencia', 'OTHER' => 'Otro'];
                $methods = $t->payments
                    ->pluck('payment_method')
                    ->unique()
                    ->map(fn ($m) => $methodLabels[$m] ?? $m)
                    ->implode(', ');

                $base = [
                    (string) $t->getKey(),
                    $t->external_id,
                    $t->occurred_at?->format('Y-m-d H:i:s'),
                    $t->location?->name ?? '',
                    $deviceLabel,
                    $userLabel,
                    (string) $t->turn_number,
                    $t->status,
                    $methods,
                    (float) $t->total,
                ];

                if (! $includeDetail) {
                    foreach ($base as $i => $v) {
                        $cell($sheet, $i + 1, $row, $v);
                    }
                    $row++;

                    continue;
                }

                $lineValues = static fn ($item): array => [
                    $item->product_sku ?? '',
                    $item->product_name ?? '',
                    (float) $item->qty,
                    (float) $item->unit_price,
                    (float) ($item->discount ?? 0),
                    (float) ($item->tax ?? 0),
                    (float) $item->line_total,
                ];

                $lines = $t->items;
                if ($lines->isEmpty()) {
                    foreach ($base as $i => $v) {
                        $cell($sheet, $i + 1, $row, $v);
                    }
                    $row++;

                    continue;
                }

                foreach ($lines as $item) {
                    foreach (array_merge($base, $lineValues($item)) as $i => $v) {
                        $cell($sheet, $i + 1, $row, $v);
                    }
                    $row++;
                }
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
        $p = AdminRbac::permissionsForScreen('transactions-report');
        abort_unless($user->can($p['view']), 403);
    }
}
