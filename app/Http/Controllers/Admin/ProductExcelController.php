<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\AdminRbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductExcelController extends Controller
{
    private const HEADERS = [
        'product_id',
        'sku',
        'barcode',
        'name',
        'description',
        'image_url',
        'subfamily_id',
        'price',
        'cost',
        'tax_rate',
        'is_active',
        'is_favorite',
    ];

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeExcel($request);

        $filename = 'productos-'.now()->format('Y-m-d_His').'.xlsx';

        return response()->streamDownload(function (): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $cell = static function (Worksheet $sheet, int $col, int $row, mixed $value): void {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col).$row, $value);
            };
            foreach (self::HEADERS as $col => $header) {
                $cell($sheet, $col + 1, 1, $header);
            }

            $row = 2;
            Product::query()
                ->withTrashed()
                ->orderBy('id')
                ->chunk(500, function ($products) use ($sheet, &$row, $cell): void {
                    foreach ($products as $p) {
                        $cell($sheet, 1, $row, $p->id);
                        $cell($sheet, 2, $row, $p->sku);
                        $cell($sheet, 3, $row, $p->barcode);
                        $cell($sheet, 4, $row, $p->name);
                        $cell($sheet, 5, $row, $p->description);
                        $cell($sheet, 6, $row, $p->image_url);
                        $cell($sheet, 7, $row, $p->subfamily_id);
                        $cell($sheet, 8, $row, $p->price);
                        $cell($sheet, 9, $row, $p->cost);
                        $cell($sheet, 10, $row, $p->tax_rate);
                        $cell($sheet, 11, $row, $p->is_active ? 1 : 0);
                        $cell($sheet, 12, $row, $p->is_favorite ? 1 : 0);
                        $row++;
                    }
                });

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $this->authorizeExcel($request);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $path = $request->file('file')->getRealPath();
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();
        if ($rows === []) {
            return back()->with('status', 'El archivo está vacío.');
        }

        $headerRow = array_shift($rows);
        $index = $this->headerIndexMap($headerRow);

        $required = ['product_id', 'sku', 'name'];
        foreach ($required as $h) {
            if (! isset($index[$h])) {
                return back()->withErrors(['file' => 'Falta la columna obligatoria: '.$h]);
            }
        }

        $updated = 0;
        $created = 0;
        $errors = [];

        DB::transaction(function () use ($rows, $index, &$updated, &$created, &$errors): void {
            foreach ($rows as $n => $row) {
                $line = $n + 2;
                $productId = $this->cell($row, $index, 'product_id');
                if ($productId === null || $productId === '') {
                    continue;
                }
                $productId = trim((string) $productId);
                if (! Str::isUuid($productId)) {
                    $errors[] = "Fila {$line}: product_id no es un UUID válido.";

                    continue;
                }

                $sku = $this->cell($row, $index, 'sku');
                $name = $this->cell($row, $index, 'name');
                if ($sku === '' || $name === '') {
                    $errors[] = "Fila {$line}: sku y name son obligatorios.";

                    continue;
                }

                $data = [
                    'sku' => $sku,
                    'barcode' => $this->nullableString($this->cell($row, $index, 'barcode')),
                    'name' => $name,
                    'description' => $this->nullableString($this->cell($row, $index, 'description')),
                    'image_url' => $this->nullableString($this->cell($row, $index, 'image_url')),
                    'subfamily_id' => $this->nullableUuid($this->cell($row, $index, 'subfamily_id')),
                    'price' => $this->decimalOrFail($this->cell($row, $index, 'price'), "Fila {$line}: price", $errors),
                    'tax_rate' => $this->decimalOrFail($this->cell($row, $index, 'tax_rate'), "Fila {$line}: tax_rate", $errors, true),
                    'is_active' => $this->parseBool($this->cell($row, $index, 'is_active')),
                    'is_favorite' => $this->parseBool($this->cell($row, $index, 'is_favorite')),
                ];

                if ($data['price'] === null || $data['tax_rate'] === null) {
                    continue;
                }

                // `cost` es opcional: si el archivo no trae la columna no se toca el costo existente
                if (isset($index['cost'])) {
                    $cost = $this->cell($row, $index, 'cost');
                    if ($cost === null || $cost === '') {
                        $data['cost'] = null;
                    } elseif (is_numeric($cost) && (float) $cost >= 0) {
                        $data['cost'] = round((float) $cost, 2);
                    } else {
                        $errors[] = "Fila {$line}: cost no es un número válido.";

                        continue;
                    }
                }

                if ($data['subfamily_id'] !== null) {
                    $exists = DB::table('subfamilies')->where('id', $data['subfamily_id'])->exists();
                    if (! $exists) {
                        $errors[] = "Fila {$line}: subfamily_id no existe.";

                        continue;
                    }
                }

                $product = Product::withTrashed()->whereKey($productId)->first();
                if ($product !== null) {
                    if ($product->trashed()) {
                        $product->restore();
                    }
                    $product->update($data);
                    $updated++;
                } else {
                    Product::unguarded(function () use ($productId, $data): void {
                        Product::query()->create(array_merge(['id' => $productId], $data));
                    });
                    $created++;
                }
            }
        });

        $msg = "Importación: {$created} creados, {$updated} actualizados.";
        if ($errors !== []) {
            $msg .= ' Avisos: '.implode(' ', array_slice($errors, 0, 5));
            if (count($errors) > 5) {
                $msg .= ' (y '.(count($errors) - 5).' más)';
            }
        }

        return back()->with('status', $msg);
    }

    private function authorizeExcel(Request $request): void
    {
        $user = auth('admin')->user();
        abort_unless($user, 403);
        $p = AdminRbac::permissionsForScreen('products');
        abort_unless($user->can($p['edit']), 403);
    }

    /**
     * @param  list<mixed>  $headerRow
     * @return array<string, int>
     */
    private function headerIndexMap(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $i => $h) {
            if (! is_string($h) && ! is_numeric($h)) {
                continue;
            }
            $key = strtolower(trim((string) $h));
            if ($key === 'id') {
                $key = 'product_id';
            }
            if ($key === 'codbar') {
                $key = 'barcode';
            }
            $map[$key] = $i;
        }

        return $map;
    }

    /**
     * @param  list<mixed>  $row
     */
    private function cell(array $row, array $index, string $key): mixed
    {
        if (! isset($index[$key])) {
            return null;
        }

        return $row[$index[$key]] ?? null;
    }

    private function nullableString(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return trim((string) $v);
    }

    private function nullableUuid(mixed $v): ?string
    {
        $s = $this->nullableString($v);
        if ($s === null) {
            return null;
        }

        return Str::isUuid($s) ? $s : null;
    }

    /**
     * @param  list<string>  $errors
     */
    private function decimalOrFail(mixed $v, string $context, array &$errors, bool $defaultZero = false): ?string
    {
        if ($v === null || $v === '') {
            if ($defaultZero) {
                return '0';
            }
            $errors[] = "{$context}: valor numérico obligatorio.";

            return null;
        }
        if (is_numeric($v)) {
            return (string) $v;
        }
        $errors[] = "{$context}: número no válido.";

        return null;
    }

    private function parseBool(mixed $v): bool
    {
        if ($v === null || $v === '') {
            return false;
        }
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string) $v));

        return in_array($s, ['1', 'true', 'yes', 'sí', 'si', 'x', 'on'], true);
    }
}
