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

    protected function paginateSample(array $items): LengthAwarePaginator
    {
        $page = method_exists($this, 'getPage')
            ? max(1, (int) $this->getPage())
            : max(1, (int) request('page', 1));

        $collection = collect($items);

        return new LengthAwarePaginator(
            $collection->forPage($page, $this->perPage)->values()->all(),
            $collection->count(),
            $this->perPage,
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
