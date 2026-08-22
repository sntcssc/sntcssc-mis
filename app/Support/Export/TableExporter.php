<?php

namespace App\Support\Export;

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
}
