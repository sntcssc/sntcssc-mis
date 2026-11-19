<?php

namespace App\Services\Alumni;

use App\Models\Alumni;
use App\Models\AlumniServiceCategory;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AlumniServiceCategoryService
{
    /**
     * Get all active service categories.
     *
     * @return Collection<int, AlumniServiceCategory>
     */
    public function getActiveCategories(): Collection
    {
        return AlumniServiceCategory::active()->ordered()->get();
    }

    /**
     * Get all service categories (active and inactive).
     *
     * @return Collection<int, AlumniServiceCategory>
     */
    public function getAllCategories(): Collection
    {
        return AlumniServiceCategory::ordered()->get();
    }

    /**
     * Create a new custom alumni service category.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function createCategory(array $data, ?User $actor = null): AlumniServiceCategory
    {
        $name = trim((string) ($data['name'] ?? ''));
        if (empty($name)) {
            throw ValidationException::withMessages(['name' => __('Service category name is required.')]);
        }

        $code = ! empty($data['code'])
            ? trim((string) $data['code'])
            : $name;

        if (AlumniServiceCategory::where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => __('A service category with code ":code" already exists.', ['code' => $code])]);
        }

        return DB::transaction(function () use ($data, $name, $code, $actor) {
            $category = AlumniServiceCategory::create([
                'name' => $name,
                'code' => $code,
                'short_name' => ! empty($data['short_name']) ? trim((string) $data['short_name']) : null,
                'description' => ! empty($data['description']) ? trim((string) $data['description']) : null,
                'icon' => $data['icon'] ?? 'award',
                'badge_color' => $data['badge_color'] ?? 'primary',
                'is_system' => false,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
                'display_order' => isset($data['display_order']) ? (int) $data['display_order'] : (AlumniServiceCategory::max('display_order') + 1),
                'created_by' => $actor?->id,
            ]);

            AuditLogService::log(
                event: 'alumni_service_category_created',
                description: "Created custom alumni service category '{$category->name}' [{$category->code}].",
                userId: $actor?->id,
                auditable: $category,
                newValues: $category->toArray()
            );

            return $category;
        });
    }

    /**
     * Update an existing alumni service category.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function updateCategory(AlumniServiceCategory $category, array $data, ?User $actor = null): AlumniServiceCategory
    {
        $name = isset($data['name']) ? trim((string) $data['name']) : $category->name;
        if (empty($name)) {
            throw ValidationException::withMessages(['name' => __('Service category name cannot be empty.')]);
        }

        $newCode = ! empty($data['code']) ? trim((string) $data['code']) : $category->code;

        if ($newCode !== $category->code && AlumniServiceCategory::where('code', $newCode)->where('id', '!=', $category->id)->exists()) {
            throw ValidationException::withMessages(['code' => __('A service category with code ":code" already exists.', ['code' => $newCode])]);
        }

        return DB::transaction(function () use ($category, $data, $name, $newCode, $actor) {
            $oldValues = $category->only(['name', 'code', 'short_name', 'description', 'icon', 'badge_color', 'is_active', 'display_order']);
            $oldCode = $category->code;

            $category->update([
                'name' => $name,
                'code' => $newCode,
                'short_name' => array_key_exists('short_name', $data) ? (! empty($data['short_name']) ? trim((string) $data['short_name']) : null) : $category->short_name,
                'description' => array_key_exists('description', $data) ? (! empty($data['description']) ? trim((string) $data['description']) : null) : $category->description,
                'icon' => $data['icon'] ?? $category->icon,
                'badge_color' => $data['badge_color'] ?? $category->badge_color,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : $category->is_active,
                'display_order' => isset($data['display_order']) ? (int) $data['display_order'] : $category->display_order,
                'updated_by' => $actor?->id,
            ]);

            // If the code was updated, also update alumni referencing the old code
            if ($oldCode !== $newCode) {
                Alumni::where('service_type', $oldCode)->update(['service_type' => $newCode]);
            }

            AuditLogService::log(
                event: 'alumni_service_category_updated',
                description: "Updated alumni service category '{$category->name}' [{$category->code}].",
                userId: $actor?->id,
                auditable: $category,
                oldValues: $oldValues,
                newValues: $category->only(array_keys($oldValues))
            );

            return $category;
        });
    }

    /**
     * Delete an alumni service category.
     *
     * @throws ValidationException
     */
    public function deleteCategory(AlumniServiceCategory $category, ?User $actor = null): bool
    {
        if ($category->is_system) {
            throw ValidationException::withMessages(['category' => __('System default service categories cannot be deleted.')]);
        }

        // Check if assigned to any alumni
        $hasAlumni = $category->alumni()->exists() || Alumni::where('service_type', $category->code)->exists();
        if ($hasAlumni) {
            throw ValidationException::withMessages(['category' => __('Cannot delete service category because it is currently assigned to registered alumni. Please reassign or update those alumni first.')]);
        }

        return DB::transaction(function () use ($category, $actor) {
            $name = $category->name;
            $code = $category->code;

            $category->deleted_by = $actor?->id;
            $category->saveQuietly();
            $deleted = (bool) $category->delete();

            AuditLogService::log(
                event: 'alumni_service_category_deleted',
                description: "Deleted custom alumni service category '{$name}' [{$code}].",
                userId: $actor?->id,
                auditable: $category
            );

            return $deleted;
        });
    }

    /**
     * Toggle active status.
     */
    public function toggleStatus(AlumniServiceCategory $category, ?User $actor = null): AlumniServiceCategory
    {
        $newStatus = ! $category->is_active;
        $category->update([
            'is_active' => $newStatus,
            'updated_by' => $actor?->id,
        ]);

        AuditLogService::log(
            event: 'alumni_service_category_status_toggled',
            description: "Toggled status for alumni service category '{$category->name}' to ".($newStatus ? 'Active' : 'Inactive').'.',
            userId: $actor?->id,
            auditable: $category
        );

        return $category;
    }
}
