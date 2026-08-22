<?php

namespace App\Imports;

use Closure;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

/**
 * Generic validated importer for Excel/CSV files with a heading row. Each
 * row (as an associative array keyed by snake_cased heading) is handed to
 * the row handler which persists it.
 *
 *   Excel::import(new TableImport(
 *       rules: ['key' => 'required'],
 *       rowHandler: fn (array $row) => Setting::updateOrCreate(['key' => $row['key']], $row),
 *   ), $file);
 */
class TableImport implements SkipsEmptyRows, ToCollection, WithCalculatedFormulas, WithHeadingRow, WithValidation
{
    use Importable;

    /**
     * @param  array<string, mixed>  $rules  Laravel rules keyed by heading row key
     * @param  Closure(array<string, mixed>): mixed  $rowHandler  receives one row, returns the persisted model
     */
    public function __construct(
        protected array $rules,
        protected Closure $rowHandler,
    ) {}

    public function collection(Collection $collection): void
    {
        $collection->each(function (mixed $row): void {
            ($this->rowHandler)($row->toArray());
        });
    }

    public function rules(): array
    {
        return $this->rules;
    }
}
