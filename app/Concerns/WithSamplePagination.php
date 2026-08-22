<?php

namespace App\Concerns;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Paginates in-memory sample datasets (arrays / collections) so the design
 * preview pages behave like real Livewire tables. Swap the data source for
 * an Eloquent query once each module gets its database tables.
 */
trait WithSamplePagination
{
    public int $perPage = 10;

    public function updatingPerPage(): void
    {
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    protected function paginateSample(array $items): LengthAwarePaginator
    {
        $collection = collect($items);
        $total = $collection->count();
        $perPage = max(1, $this->perPage);
        $lastPage = max(1, (int) ceil($total / $perPage));

        $page = method_exists($this, 'getPage')
            ? max(1, (int) $this->getPage())
            : max(1, (int) request('page', 1));

        if ($page > $lastPage) {
            $page = $lastPage;
            if (method_exists($this, 'setPage')) {
                $this->setPage($page);
            }
        }

        return new LengthAwarePaginator(
            $collection->forPage($page, $perPage)->values()->all(),
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'pageName' => 'page',
            ],
        );
    }

    public function paginationView(): string
    {
        return 'partials.pagination';
    }
}
