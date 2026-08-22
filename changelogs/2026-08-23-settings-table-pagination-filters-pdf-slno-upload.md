# Fix — Settings Table: Pagination, Filters, PDF Export, Sl No & File Upload

**Date:** 2026-08-23  
**Scope:** Bug fixes and feature additions to the admin settings table — pagination/rows-per-page errors, multiple-filter TypeError, PDF export Livewire stream fix, serial number column, and image/file upload with live preview in the add/edit modal.

---

## 1. Fixed: `perPage` binding threw missing-property exception

**Root cause:** `InteractsWithDataTable` had no `$perPage` public property, so Livewire's property-binder threw a missing property error when the rows-per-page `<select>` called `wire:model="perPage"`.

**Fixes:**

| File | Change |
|---|---|
| `app/Concerns/InteractsWithDataTable.php` | Added `public int $perPage = 10;` and `updatingPerPage(): void` hook that resets to page 1. |
| `app/Concerns/WithSamplePagination.php` | Added `updatingPerPage(): void` hook; also added `$page > $lastPage` clamping in `paginateSample()` so filtered results never land on an empty out-of-bounds page. |
| `resources/views/partials/pagination.blade.php` | Changed `@if ($paginator->hasPages())` → `@if ($paginator->total() > 0)` so the rows-per-page selector always renders. Replaced `<a wire:navigate>` pagination buttons with `wire:click="previousPage()"` / `wire:click="nextPage()"` for proper Livewire pagination. Added `100` rows option. |

---

## 2. Fixed: multiple simultaneous filters threw `TypeError`

**Root cause:** Livewire calls `updatingTableFilters($value, $key)` when any sub-property of `$tableFilters` changes. When the entire array is reset (`$tableFilters = []`), `$key` is `null` — but the method had `string $key` as its type hint, causing a `TypeError`.

**Fixes:**

| File | Change |
|---|---|
| `app/Concerns/InteractsWithDataTable.php` | Changed signature to `updatingTableFilters(mixed $value = null, ?string $key = null): void`. |
| `resources/views/pages/admin/⚡settings.blade.php` | Replaced unsafe `(bool)` boolean status cast with `filter_var($value, FILTER_VALIDATE_BOOLEAN)`. |

---

## 3. Fixed: PDF export did not download in Livewire

**Root cause:** `Pdf::download()` returns `Illuminate\Http\Response`. Livewire's `SupportFileDownloads` hook only intercepts `StreamedResponse` or `BinaryFileResponse` — regular `Response` instances are silently dropped.

**Fixes:**

| File | Change |
|---|---|
| `app/Support/Export/TableExporter.php` | Changed PDF export to return `response()->streamDownload(fn () => print($pdf->output()), "{$filename}.pdf")` which produces a `StreamedResponse` that Livewire correctly intercepts and delivers to the browser. |

---

## 4. Added: Serial Number (`Sl No`) column in Settings Table

| File | Change |
|---|---|
| `resources/views/pages/admin/⚡settings.blade.php` | Added `Sl No` column header to desktop table. Row index calculated as `(($settings->currentPage() - 1) * $settings->perPage()) + $loop->iteration` to produce a continuous sequence across pages. Mobile card view updated with `#N` badge. |

---

## 5. Added: Image & File Upload with Live Preview in Add/Edit Modal

| File | Change |
|---|---|
| `resources/views/pages/admin/⚡settings.blade.php` | Added `public $settingFile = null;` Livewire property. For `TYPE_IMAGE`: file input with `accept="image/*"`, live preview via `temporaryUrl()` for new uploads, current saved thumbnail when editing, remove button. For `TYPE_FILE`: file input, file icon / name / size badge, current file download link. Inline error display for upload failures. |

---

## 6. Updated: Tests

| File | Change |
|---|---|
| `tests/Feature/SettingsCrudTest.php` | Added tests for: `perPage` change and pagination reset, multiple simultaneous filters without errors, PDF export via Livewire `assertFileDownloaded()`, image/file uploads via `UploadedFile::fake()`, Sl No display in table UI. |

**Result:** 121/121 tests passed.
