<?php

namespace App\Support\Export;

use App\Exports\GenericArrayExport;
use App\Exports\TableExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Single entry point for exporting a (filtered + sorted) Eloquent query to
 * Excel (xlsx), CSV or PDF from any Livewire component:
 *
 *   return TableExporter::download(
 *       $query, 'csv', 'settings', ['Key', 'Value'], fn ($s) => [$s->key, $s->rawValue()]
 *   );
 */
class TableExporter
{
    public const FORMATS = ['xlsx', 'csv', 'pdf'];

    /**
     * @param  Builder<Model>  $query
     * @param  array<int, string>  $headings
     * @param  Closure(mixed): array<int, mixed>  $mapper
     */
    public static function download(
        Builder $query,
        string $format,
        string $filename,
        array $headings,
        Closure $mapper,
        ?string $title = null,
    ): BinaryFileResponse|StreamedResponse|Response {
        $format = strtolower($format);
        $filename = str($filename)->slug().'-'.now()->format('Ymd-His');

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('exports.table', [
                'title' => $title ?? $filename,
                'generatedAt' => now(),
                'headings' => $headings,
                'rows' => $query->get()->map(fn (mixed $row) => ($mapper)($row))->all(),
            ]);

            return response()->streamDownload(
                function () use ($pdf) {
                    echo $pdf->output();
                },
                "{$filename}.pdf",
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"',
                ]
            );
        }

        $export = new TableExport($query, $headings, $mapper);

        return $format === 'csv'
            ? $export->download("{$filename}.csv", ExcelFormat::CSV, ['Content-Type' => 'text/csv'])
            : $export->download("{$filename}.xlsx");
    }

    /**
     * Download in-memory array rows as Excel (xlsx), CSV or PDF.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $headings
     */
    public static function downloadArray(
        array $rows,
        string $format,
        string $filename,
        array $headings = [],
        ?string $title = null,
    ): BinaryFileResponse|StreamedResponse|Response {
        $format = strtolower($format);
        $cleanFilename = str($filename)->slug().'-'.now()->format('Ymd-His');

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('exports.table', [
                'title' => $title ?? $cleanFilename,
                'generatedAt' => now(),
                'headings' => $headings,
                'rows' => $rows,
            ]);

            return response()->streamDownload(
                function () use ($pdf) {
                    echo $pdf->output();
                },
                "{$cleanFilename}.pdf",
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="'.$cleanFilename.'.pdf"',
                ]
            );
        }

        if ($format === 'csv') {
            return static::csv($headings, $rows, "{$cleanFilename}.csv");
        }

        return (new GenericArrayExport($rows, $headings))->download("{$cleanFilename}.xlsx");
    }

    /**
     * Stream array rows directly to a downloaded CSV file with UTF-8 BOM encoding.
     *
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, mixed>>  $rows
     */
    public static function csv(
        array $headings,
        array $rows,
        string $filename,
    ): StreamedResponse {
        $filename = trim($filename);
        if (! str_ends_with(strtolower($filename), '.csv')) {
            $filename .= '.csv';
        }

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->streamDownload(function () use ($headings, $rows) {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }

            // Write UTF-8 BOM for Microsoft Excel compatibility
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

            if (! empty($headings)) {
                fputcsv($output, $headings);
            }

            foreach ($rows as $row) {
                if (is_array($row)) {
                    fputcsv($output, $row);
                }
            }

            fclose($output);
        }, $filename, $headers);
    }
}
