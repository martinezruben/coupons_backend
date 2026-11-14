<?php

namespace App\Http\Controllers\Admin\Concerns;

use Symfony\Component\HttpFoundation\StreamedResponse;

trait StreamsCsv
{
    /**
     * CSV en UTF-8 con BOM (Excel abre bien los acentos). Las celdas que empiezan con = + - @ se
     * neutralizan para que una hoja de cálculo no las ejecute como fórmula.
     *
     * @param  list<string>  $header
     * @param  list<list<scalar|null>>  $rows
     */
    protected function streamCsv(string $name, array $header, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) ? "'".$v : $v, $row));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
