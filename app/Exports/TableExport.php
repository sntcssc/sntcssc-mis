<?php

namespace App\Exports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Generic spreadsheet export for any Eloquent query. The query is already
 * filtered/sorted by the calling component, so the export always matches
 * exactly what the user sees in the table.
 */
class TableExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;

    /**
     * @param  Builder<Model>  $query
     * @param  array<int, string>  $headings
     * @param  Closure(mixed): array<int, mixed>  $mapper  row model => ordered cell values
     */
    public function __construct(
        protected Builder $query,
        protected array $headings,
        protected Closure $mapper,
    ) {}

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function map(mixed $row): array
    {
        return ($this->mapper)($row);
    }
}
