<?php

namespace App\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Reusable server-side search / filter / sort / pagination behaviour for
 * Livewire components backed by an Eloquent query.
 *
 * Usage:
 *   class MyPage extends Component {
 *       use WithPagination, InteractsWithDataTable;
 *
 *       #[Computed]
 *       public function rows() {
 *           return $this->paginateDataTable(
 *               $this->applyDataTable($this->dataTableQuery(), searchable: ['name', 'email'])
 *           );
 *       }
 *   }
 */
trait InteractsWithDataTable
{
    public string $search = '';

    /** @var array<string, mixed> keyed by filter name (wire:model="tableFilters.group") */
    public array $tableFilters = [];

    public string $sortField = '';

    public string $sortDirection = 'asc';

    public int $perPage = 10;

    /** Reset pagination whenever any table control changes. */
    public function updatingSearch(): void
    {
        $this->resetDataTablePage();
    }

    public function updatingPerPage(): void
    {
        $this->resetDataTablePage();
    }

    public function updatingTableFilters(mixed $value = null, ?string $key = null): void
    {
        $this->resetDataTablePage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortField = $field;
        $this->sortDirection = 'asc';
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'tableFilters', 'sortField', 'sortDirection');
        $this->resetDataTablePage();
    }

    /**
     * Apply the current search, filters and sort to a query.
     *
     * @param  Builder<Model>  $query
     * @param  array<int, string>  $searchable  columns searched with LIKE (add "table.column" for joins)
     * @param  array<string, string|\Closure>  $filterDefs  filter name => column (where equals) or closure(Builder, value): Builder
     * @param  array<int, string>  $sortable  whitelisted sort columns
     * @return Builder<Model>
     */
    protected function applyDataTable(Builder $query, array $searchable = [], array $filterDefs = [], array $sortable = []): Builder
    {
        $query = $this->applySearch($query, $searchable);
        $query = $this->applyFilters($query, $filterDefs);
        $query = $this->applySort($query, $sortable);

        return $query;
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<int, string>  $searchable
     */
    protected function applySearch(Builder $query, array $searchable): Builder
    {
        $term = trim($this->search);

        if ($term === '' || $searchable === []) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($searchable, $term) {
            foreach ($searchable as $column) {
                $query->orWhere($column, 'like', "%{$term}%");
            }
        });
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, string|\Closure>  $filterDefs
     */
    protected function applyFilters(Builder $query, array $filterDefs): Builder
    {
        foreach ($filterDefs as $name => $definition) {
            $value = $this->tableFilters[$name] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $query = $definition instanceof \Closure
                ? $definition($query, $value)
                : $query->where($definition, $value);
        }

        return $query;
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<int, string>  $sortable
     */
    protected function applySort(Builder $query, array $sortable): Builder
    {
        if ($this->sortField !== '' && in_array($this->sortField, $sortable, true)) {
            return $query->orderBy($this->sortField, $this->sortDirection === 'desc' ? 'desc' : 'asc');
        }

        return $query;
    }

    /**
     * Paginate whatever the component resolved as its base query.
     *
     * @param  Builder<Model>  $query
     */
    protected function paginateDataTable(Builder $query, ?int $perPage = null): LengthAwarePaginator
    {
        /* @var \Livewire\WithPagination $this */
        return $query->paginate($perPage ?? $this->perPage);
    }

    protected function resetDataTablePage(): void
    {
        /* @var \Livewire\WithPagination $this */
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }
}
