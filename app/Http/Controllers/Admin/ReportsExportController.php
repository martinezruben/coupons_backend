<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Support\AdminRbac;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsExportController extends Controller
{
    protected ReportService $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    public function productsBestSellers(Request $request): StreamedResponse|JsonResponse
    {
        $this->authorizeExport('products_best_sellers');

        $validator = Validator::make($request->all(), [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'location_id' => ['nullable', 'uuid', 'exists:locations,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos.', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $dateFrom = Carbon::parse($validated['date_from'])->startOfDay();
        $dateTo = Carbon::parse($validated['date_to'])->endOfDay();

        if ($dateFrom->gt($dateTo)) {
            return response()->json(['message' => 'La fecha inicial no puede ser posterior a la final.'], 422);
        }

        $products = $this->reportService->getProductsBestSellers($dateFrom, $dateTo, $validated['location_id'] ?? null);

        $filename = 'productos-vendidos-'.$dateFrom->format('Y-m-d').'_'.$dateTo->format('Y-m-d').'_'.now()->format('His').'.xlsx';

        return response()->streamDownload(function () use ($products, $dateFrom, $dateTo): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Productos');

            $cell = static function (Worksheet $sheet, int $col, int $row, mixed $value): void {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col).$row, $value);
            };

            $row = 1;
            $cell($sheet, 1, $row, 'PRODUCTOS MÁS VENDIDOS');
            $row++;
            $cell($sheet, 1, $row, 'Período: '.$dateFrom->format('Y-m-d').' hasta '.$dateTo->format('Y-m-d'));
            $row += 2;

            $headers = ['Producto', 'SKU', 'Cantidad Vendida', 'Ingresos Totales', 'Precio Promedio', 'IVA (%)'];
            foreach ($headers as $i => $h) {
                $cell($sheet, $i + 1, $row, $h);
            }
            $row++;

            foreach ($products as $product) {
                $cell($sheet, 1, $row, $product->name);
                $cell($sheet, 2, $row, $product->sku);
                $cell($sheet, 3, $row, $product->sold_qty);
                $cell($sheet, 4, $row, $product->total_revenue);
                $cell($sheet, 5, $row, $product->avg_price);
                $cell($sheet, 6, $row, $product->tax_rate);
                $row++;
            }

            $row++;
            $cell($sheet, 1, $row, 'TOTALES');
            $row++;
            $cell($sheet, 1, $row, 'Total de productos:');
            $cell($sheet, 2, $row, $products->count());
            $row++;
            $cell($sheet, 1, $row, 'Total de ingresos:');
            $cell($sheet, 2, $row, $products->sum('total_revenue'));

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function paymentMethods(Request $request): StreamedResponse|JsonResponse
    {
        $this->authorizeExport('payment_methods_report');

        $validator = Validator::make($request->all(), [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'location_id' => ['nullable', 'uuid', 'exists:locations,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos.', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $dateFrom = Carbon::parse($validated['date_from'])->startOfDay();
        $dateTo = Carbon::parse($validated['date_to'])->endOfDay();

        if ($dateFrom->gt($dateTo)) {
            return response()->json(['message' => 'La fecha inicial no puede ser posterior a la final.'], 422);
        }

        $methods = $this->reportService->getPaymentMethodsReport($dateFrom, $dateTo, $validated['location_id'] ?? null);

        $filename = 'pagos-metodo-'.$dateFrom->format('Y-m-d').'_'.$dateTo->format('Y-m-d').'_'.now()->format('His').'.xlsx';

        return response()->streamDownload(function () use ($methods, $dateFrom, $dateTo): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Métodos de Pago');

            $cell = static function (Worksheet $sheet, int $col, int $row, mixed $value): void {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col).$row, $value);
            };

            $row = 1;
            $cell($sheet, 1, $row, 'INGRESOS POR MÉTODO DE PAGO');
            $row++;
            $cell($sheet, 1, $row, 'Período: '.$dateFrom->format('Y-m-d').' hasta '.$dateTo->format('Y-m-d'));
            $row += 2;

            $headers = ['Método de Pago', 'Transacciones', 'Monto Total', '% del Total'];
            foreach ($headers as $i => $h) {
                $cell($sheet, $i + 1, $row, $h);
            }
            $row++;

            foreach ($methods as $method) {
                $cell($sheet, 1, $row, $method['name']);
                $cell($sheet, 2, $row, $method['total_transactions']);
                $cell($sheet, 3, $row, $method['total_amount']);
                $cell($sheet, 4, $row, $method['percentage']);
                $row++;
            }

            $row++;
            $cell($sheet, 1, $row, 'TOTALES');
            $row++;
            $cell($sheet, 1, $row, 'Total de métodos:');
            $cell($sheet, 2, $row, count($methods));
            $row++;
            $cell($sheet, 1, $row, 'Total de transacciones:');
            $cell($sheet, 2, $row, collect($methods)->sum('total_transactions'));
            $row++;
            $cell($sheet, 1, $row, 'Total de ingresos:');
            $cell($sheet, 2, $row, collect($methods)->sum('total_amount'));

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function usersPerformance(Request $request): StreamedResponse|JsonResponse
    {
        $this->authorizeExport('users_performance_report');

        $validator = Validator::make($request->all(), [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'location_id' => ['nullable', 'uuid', 'exists:locations,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos.', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $dateFrom = Carbon::parse($validated['date_from'])->startOfDay();
        $dateTo = Carbon::parse($validated['date_to'])->endOfDay();

        if ($dateFrom->gt($dateTo)) {
            return response()->json(['message' => 'La fecha inicial no puede ser posterior a la final.'], 422);
        }

        $users = $this->reportService->getUsersPerformanceReport($dateFrom, $dateTo, $validated['location_id'] ?? null);

        $filename = 'desempeno-usuarios-'.$dateFrom->format('Y-m-d').'_'.$dateTo->format('Y-m-d').'_'.now()->format('His').'.xlsx';

        return response()->streamDownload(function () use ($users, $dateFrom, $dateTo): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Desempeño Usuarios');

            $cell = static function (Worksheet $sheet, int $col, int $row, mixed $value): void {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col).$row, $value);
            };

            $row = 1;
            $cell($sheet, 1, $row, 'DESEMPEÑO DE USUARIOS');
            $row++;
            $cell($sheet, 1, $row, 'Período: '.$dateFrom->format('Y-m-d').' hasta '.$dateTo->format('Y-m-d'));
            $row += 2;

            $headers = ['Nombre Completo', 'Usuario', 'Transacciones', 'Ingresos Totales', 'Ticket Promedio', 'Última Actividad'];
            foreach ($headers as $i => $h) {
                $cell($sheet, $i + 1, $row, $h);
            }
            $row++;

            foreach ($users as $user) {
                $cell($sheet, 1, $row, $user['full_name']);
                $cell($sheet, 2, $row, $user['username']);
                $cell($sheet, 3, $row, $user['transaction_count']);
                $cell($sheet, 4, $row, $user['total_sales']);
                $cell($sheet, 5, $row, $user['avg_transaction']);
                $cell($sheet, 6, $row, $user['last_activity']);
                $row++;
            }

            $row++;
            $cell($sheet, 1, $row, 'TOTALES');
            $row++;
            $cell($sheet, 1, $row, 'Total de usuarios:');
            $cell($sheet, 2, $row, $users->count());
            $row++;
            $cell($sheet, 1, $row, 'Total de transacciones:');
            $cell($sheet, 2, $row, $users->sum('transaction_count'));
            $row++;
            $cell($sheet, 1, $row, 'Total de ingresos:');
            $cell($sheet, 2, $row, $users->sum('total_sales'));

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function productsBestSellersCSV(Request $request): StreamedResponse|JsonResponse
    {
        $this->authorizeExport('products_best_sellers');

        $validator = Validator::make($request->all(), [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'location_id' => ['nullable', 'uuid', 'exists:locations,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos.', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $dateFrom = Carbon::parse($validated['date_from'])->startOfDay();
        $dateTo = Carbon::parse($validated['date_to'])->endOfDay();

        $products = $this->reportService->getProductsBestSellers($dateFrom, $dateTo, $validated['location_id'] ?? null);

        $filename = 'productos-vendidos-'.$dateFrom->format('Y-m-d').'_'.$dateTo->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($products): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Producto', 'SKU', 'Cantidad Vendida', 'Ingresos Totales', 'Precio Promedio', 'IVA (%)']);

            foreach ($products as $product) {
                fputcsv($output, [
                    $product->name,
                    $product->sku,
                    $product->sold_qty,
                    $product->total_revenue,
                    $product->avg_price,
                    $product->tax_rate,
                ]);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function paymentMethodsCSV(Request $request): StreamedResponse|JsonResponse
    {
        $this->authorizeExport('payment_methods_report');

        $validator = Validator::make($request->all(), [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'location_id' => ['nullable', 'uuid', 'exists:locations,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos.', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $dateFrom = Carbon::parse($validated['date_from'])->startOfDay();
        $dateTo = Carbon::parse($validated['date_to'])->endOfDay();

        $methods = $this->reportService->getPaymentMethodsReport($dateFrom, $dateTo, $validated['location_id'] ?? null);

        $filename = 'pagos-metodo-'.$dateFrom->format('Y-m-d').'_'.$dateTo->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($methods): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Método de Pago', 'Transacciones', 'Monto Total', '% del Total']);

            foreach ($methods as $method) {
                fputcsv($output, [
                    $method['name'],
                    $method['total_transactions'],
                    $method['total_amount'],
                    number_format($method['percentage'], 2),
                ]);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function usersPerformanceCSV(Request $request): StreamedResponse|JsonResponse
    {
        $this->authorizeExport('users_performance_report');

        $validator = Validator::make($request->all(), [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'location_id' => ['nullable', 'uuid', 'exists:locations,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos.', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $dateFrom = Carbon::parse($validated['date_from'])->startOfDay();
        $dateTo = Carbon::parse($validated['date_to'])->endOfDay();

        $users = $this->reportService->getUsersPerformanceReport($dateFrom, $dateTo, $validated['location_id'] ?? null);

        $filename = 'desempeno-usuarios-'.$dateFrom->format('Y-m-d').'_'.$dateTo->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($users): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Nombre Completo', 'Usuario', 'Transacciones', 'Ingresos Totales', 'Ticket Promedio', 'Última Actividad']);

            foreach ($users as $user) {
                fputcsv($output, [
                    $user['full_name'],
                    $user['username'],
                    $user['transaction_count'],
                    $user['total_sales'],
                    $user['avg_transaction'],
                    $user['last_activity'],
                ]);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function productsBestSellersPDF(Request $request): StreamedResponse|JsonResponse
    {
        $this->authorizeExport('products_best_sellers');

        $validator = Validator::make($request->all(), [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'location_id' => ['nullable', 'uuid', 'exists:locations,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos.', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $dateFrom = Carbon::parse($validated['date_from'])->startOfDay();
        $dateTo = Carbon::parse($validated['date_to'])->endOfDay();

        $products = $this->reportService->getProductsBestSellers($dateFrom, $dateTo, $validated['location_id'] ?? null);

        $html = $this->renderProductsPDF($products, $dateFrom, $dateTo);

        $dompdf = new Dompdf;
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $filename = 'productos-vendidos-'.$dateFrom->format('Y-m-d').'_'.$dateTo->format('Y-m-d').'.pdf';

        return response()->streamDownload(function () use ($dompdf): void {
            echo $dompdf->output();
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function paymentMethodsPDF(Request $request): StreamedResponse|JsonResponse
    {
        $this->authorizeExport('payment_methods_report');

        $validator = Validator::make($request->all(), [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'location_id' => ['nullable', 'uuid', 'exists:locations,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos.', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $dateFrom = Carbon::parse($validated['date_from'])->startOfDay();
        $dateTo = Carbon::parse($validated['date_to'])->endOfDay();

        $methods = $this->reportService->getPaymentMethodsReport($dateFrom, $dateTo, $validated['location_id'] ?? null);

        $html = $this->renderPaymentMethodsPDF($methods, $dateFrom, $dateTo);

        $dompdf = new Dompdf;
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $filename = 'pagos-metodo-'.$dateFrom->format('Y-m-d').'_'.$dateTo->format('Y-m-d').'.pdf';

        return response()->streamDownload(function () use ($dompdf): void {
            echo $dompdf->output();
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function usersPerformancePDF(Request $request): StreamedResponse|JsonResponse
    {
        $this->authorizeExport('users_performance_report');

        $validator = Validator::make($request->all(), [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'location_id' => ['nullable', 'uuid', 'exists:locations,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos.', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $dateFrom = Carbon::parse($validated['date_from'])->startOfDay();
        $dateTo = Carbon::parse($validated['date_to'])->endOfDay();

        $users = $this->reportService->getUsersPerformanceReport($dateFrom, $dateTo, $validated['location_id'] ?? null);

        $html = $this->renderUsersPerformancePDF($users, $dateFrom, $dateTo);

        $dompdf = new Dompdf;
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $filename = 'desempeno-usuarios-'.$dateFrom->format('Y-m-d').'_'.$dateTo->format('Y-m-d').'.pdf';

        return response()->streamDownload(function () use ($dompdf): void {
            echo $dompdf->output();
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function renderProductsPDF($products, $dateFrom, $dateTo): string
    {
        return '
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    h1 { text-align: center; }
                    .period { text-align: center; color: #666; margin-bottom: 20px; }
                    table { width: 100%; border-collapse: collapse; }
                    th { background-color: #f0f0f0; border: 1px solid #ddd; padding: 8px; text-align: left; }
                    td { border: 1px solid #ddd; padding: 8px; }
                    .currency { text-align: right; }
                    .total-row { background-color: #f9f9f9; font-weight: bold; }
                </style>
            </head>
            <body>
                <h1>Productos Más Vendidos</h1>
                <div class="period">Período: '.$dateFrom->format('Y-m-d').' hasta '.$dateTo->format('Y-m-d').'</div>
                <table>
                    <tr>
                        <th>Producto</th>
                        <th>SKU</th>
                        <th>Cantidad Vendida</th>
                        <th>Ingresos Totales</th>
                        <th>Precio Promedio</th>
                        <th>IVA (%)</th>
                    </tr>
                    '.collect($products)->map(fn ($p) => '
                    <tr>
                        <td>'.e($p->name).'</td>
                        <td>'.e($p->sku).'</td>
                        <td class="currency">'.$p->sold_qty.'</td>
                        <td class="currency">$'.number_format($p->total_revenue, 2).'</td>
                        <td class="currency">$'.number_format($p->avg_price, 2).'</td>
                        <td class="currency">'.$p->tax_rate.'%</td>
                    </tr>
                    ')->implode('').'
                    <tr class="total-row">
                        <td colspan="2">TOTALES</td>
                        <td class="currency">'.collect($products)->sum('sold_qty').'</td>
                        <td class="currency">$'.number_format(collect($products)->sum('total_revenue'), 2).'</td>
                        <td></td>
                        <td></td>
                    </tr>
                </table>
            </body>
            </html>
        ';
    }

    private function renderPaymentMethodsPDF($methods, $dateFrom, $dateTo): string
    {
        return '
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    h1 { text-align: center; }
                    .period { text-align: center; color: #666; margin-bottom: 20px; }
                    table { width: 100%; border-collapse: collapse; }
                    th { background-color: #f0f0f0; border: 1px solid #ddd; padding: 8px; text-align: left; }
                    td { border: 1px solid #ddd; padding: 8px; }
                    .currency { text-align: right; }
                    .total-row { background-color: #f9f9f9; font-weight: bold; }
                </style>
            </head>
            <body>
                <h1>Ingresos por Método de Pago</h1>
                <div class="period">Período: '.$dateFrom->format('Y-m-d').' hasta '.$dateTo->format('Y-m-d').'</div>
                <table>
                    <tr>
                        <th>Método de Pago</th>
                        <th>Transacciones</th>
                        <th>Monto Total</th>
                        <th>% del Total</th>
                    </tr>
                    '.collect($methods)->map(fn ($m) => '
                    <tr>
                        <td>'.e($m['name']).'</td>
                        <td class="currency">'.$m['total_transactions'].'</td>
                        <td class="currency">$'.number_format($m['total_amount'], 2).'</td>
                        <td class="currency">'.number_format($m['percentage'], 2).'%</td>
                    </tr>
                    ')->implode('').'
                    <tr class="total-row">
                        <td>TOTALES</td>
                        <td class="currency">'.collect($methods)->sum('total_transactions').'</td>
                        <td class="currency">$'.number_format(collect($methods)->sum('total_amount'), 2).'</td>
                        <td></td>
                    </tr>
                </table>
            </body>
            </html>
        ';
    }

    private function renderUsersPerformancePDF($users, $dateFrom, $dateTo): string
    {
        return '
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    h1 { text-align: center; }
                    .period { text-align: center; color: #666; margin-bottom: 20px; }
                    table { width: 100%; border-collapse: collapse; font-size: 11px; }
                    th { background-color: #f0f0f0; border: 1px solid #ddd; padding: 6px; text-align: left; }
                    td { border: 1px solid #ddd; padding: 6px; }
                    .currency { text-align: right; }
                    .total-row { background-color: #f9f9f9; font-weight: bold; }
                </style>
            </head>
            <body>
                <h1>Desempeño de Usuarios</h1>
                <div class="period">Período: '.$dateFrom->format('Y-m-d').' hasta '.$dateTo->format('Y-m-d').'</div>
                <table>
                    <tr>
                        <th>Nombre Completo</th>
                        <th>Usuario</th>
                        <th>Transacciones</th>
                        <th>Ingresos Totales</th>
                        <th>Ticket Promedio</th>
                        <th>Última Actividad</th>
                    </tr>
                    '.collect($users)->map(fn ($u) => '
                    <tr>
                        <td>'.e($u['full_name']).'</td>
                        <td>'.e($u['username']).'</td>
                        <td class="currency">'.$u['transaction_count'].'</td>
                        <td class="currency">$'.number_format($u['total_sales'], 2).'</td>
                        <td class="currency">$'.number_format($u['avg_transaction'], 2).'</td>
                        <td>'.e($u['last_activity']).'</td>
                    </tr>
                    ')->implode('').'
                    <tr class="total-row">
                        <td colspan="2">TOTALES</td>
                        <td class="currency">'.collect($users)->sum('transaction_count').'</td>
                        <td class="currency">$'.number_format(collect($users)->sum('total_sales'), 2).'</td>
                        <td></td>
                        <td></td>
                    </tr>
                </table>
            </body>
            </html>
        ';
    }

    private function authorizeExport(string $screen): void
    {
        $user = auth('admin')->user();
        abort_unless($user, 403);
        $p = AdminRbac::permissionsForScreen($screen);
        abort_unless($user->can($p['view']), 403);
    }
}
