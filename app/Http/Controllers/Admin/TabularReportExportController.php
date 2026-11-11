<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Support\AdminRbac;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Base de las exportaciones Excel/CSV de reportes tabulares con los filtros de la pantalla
 * (localidad + rango de fechas). Cada reporte solo define su pantalla, títulos, cabeceras y filas.
 */
abstract class TabularReportExportController extends Controller
{
    public function __construct(protected readonly ReportService $reportService) {}

    /** Clave de la pantalla en `admin_screens` (define el permiso `.view` exigido). */
    abstract protected function screen(): string;

    abstract protected function title(): string;

    /** Prefijo del nombre de archivo, sin extensión. */
    abstract protected function filePrefix(): string;

    /** @return list<string> */
    abstract protected function headers(): array;

    /** @return list<list<mixed>> */
    abstract protected function rows(Carbon $from, Carbon $to, ?string $locationId): array;

    public function excel(Request $request): StreamedResponse|JsonResponse
    {
        [$rows, $from, $to, $error] = $this->prepare($request);
        if ($error) {
            return $error;
        }

        return response()->streamDownload(function () use ($rows, $from, $to): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(mb_substr($this->title(), 0, 31));
            $sheet->setCellValue('A1', mb_strtoupper($this->title()));
            $sheet->setCellValue('A2', 'Período: '.$from->format('Y-m-d').' hasta '.$to->format('Y-m-d'));

            foreach ($this->headers() as $i => $header) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'4', $header);
            }
            foreach ($rows as $n => $values) {
                foreach ($values as $i => $value) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).($n + 5), $value);
                }
            }

            (new Xlsx($spreadsheet))->save('php://output');
        }, $this->fileName($from, $to).'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function csv(Request $request): StreamedResponse|JsonResponse
    {
        [$rows, $from, $to, $error] = $this->prepare($request);
        if ($error) {
            return $error;
        }

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, $this->headers());
            foreach ($rows as $values) {
                fputcsv($output, $values);
            }
            fclose($output);
        }, $this->fileName($from, $to).'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    private function fileName(Carbon $from, Carbon $to): string
    {
        return $this->filePrefix().'-'.$from->format('Y-m-d').'_'.$to->format('Y-m-d');
    }

    /**
     * @return array{0: list<list<mixed>>, 1: ?Carbon, 2: ?Carbon, 3: ?JsonResponse}
     */
    private function prepare(Request $request): array
    {
        $user = auth('admin')->user();
        abort_unless($user && $user->can(AdminRbac::permissionsForScreen($this->screen())['view']), 403);

        $validator = Validator::make($request->all(), [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'location_id' => ['nullable', 'uuid', 'exists:locations,id'],
        ]);
        if ($validator->fails()) {
            return [[], null, null, response()->json(['message' => 'Datos no válidos.', 'errors' => $validator->errors()], 422)];
        }

        $data = $validator->validated();
        $from = Carbon::parse($data['date_from'])->startOfDay();
        $to = Carbon::parse($data['date_to'])->endOfDay();
        if ($from->gt($to)) {
            return [[], null, null, response()->json(['message' => 'La fecha inicial no puede ser posterior a la final.'], 422)];
        }

        return [$this->rows($from, $to, $data['location_id'] ?? null), $from, $to, null];
    }
}
