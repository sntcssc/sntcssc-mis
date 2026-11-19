<?php

namespace App\Services\StudyMaterial;

use App\Models\StudyMaterialType;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudyMaterialTypeService
{
    /**
     * Get all active study material types.
     *
     * @return Collection<int, StudyMaterialType>
     */
    public function getActiveTypes(): Collection
    {
        return StudyMaterialType::active()->ordered()->get();
    }

    /**
     * Get all public study material types for API / external catalog.
     *
     * @return Collection<int, StudyMaterialType>
     */
    public function getPublicTypes(): Collection
    {
        return StudyMaterialType::public()->ordered()->get();
    }

    /**
     * Create a new custom study material type.
     *
     * @param  array<string, mixed>  $data
     */
    public function createType(array $data, ?User $actor = null): StudyMaterialType
    {
        $name = trim((string) ($data['name'] ?? ''));
        if (empty($name)) {
            throw ValidationException::withMessages(['name' => __('Material type name is required.')]);
        }

        $code = ! empty($data['code'])
            ? Str::slug($data['code'], '_')
            : Str::slug($name, '_');

        if (StudyMaterialType::where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => __('A material type with code ":code" already exists.', ['code' => $code])]);
        }

        return DB::transaction(function () use ($data, $name, $code, $actor) {
            $type = StudyMaterialType::create([
                'name' => $name,
                'code' => $code,
                'description' => ! empty($data['description']) ? trim((string) $data['description']) : null,
                'icon' => $data['icon'] ?? 'file-text',
                'badge_color' => $data['badge_color'] ?? 'primary',
                'is_public' => isset($data['is_public']) ? (bool) $data['is_public'] : true,
                'is_system' => false,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
                'display_order' => isset($data['display_order']) ? (int) $data['display_order'] : (StudyMaterialType::max('display_order') + 1),
                'created_by' => $actor?->id,
            ]);

            AuditLogService::log(
                event: 'study_material_type_created',
                description: "Created custom study material type '{$type->name}' [{$type->code}].",
                userId: $actor?->id,
                auditable: $type,
                newValues: $type->toArray()
            );

            return $type;
        });
    }

    /**
     * Update an existing study material type.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateType(StudyMaterialType $type, array $data, ?User $actor = null): StudyMaterialType
    {
        $name = isset($data['name']) ? trim((string) $data['name']) : $type->name;
        if (empty($name)) {
            throw ValidationException::withMessages(['name' => __('Material type name cannot be empty.')]);
        }

        // If code is changing, ensure uniqueness
        if (! empty($data['code'])) {
            $newCode = Str::slug($data['code'], '_');
            if ($newCode !== $type->code && StudyMaterialType::where('code', $newCode)->where('id', '!=', $type->id)->exists()) {
                throw ValidationException::withMessages(['code' => __('A material type with code ":code" already exists.', ['code' => $newCode])]);
            }
        } else {
            $newCode = $type->code;
        }

        return DB::transaction(function () use ($type, $data, $name, $newCode, $actor) {
            $oldValues = $type->only(['name', 'code', 'description', 'icon', 'badge_color', 'is_public', 'is_active', 'display_order']);

            $type->update([
                'name' => $name,
                'code' => $newCode,
                'description' => array_key_exists('description', $data) ? (! empty($data['description']) ? trim((string) $data['description']) : null) : $type->description,
                'icon' => $data['icon'] ?? $type->icon,
                'badge_color' => $data['badge_color'] ?? $type->badge_color,
                'is_public' => isset($data['is_public']) ? (bool) $data['is_public'] : $type->is_public,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : $type->is_active,
                'display_order' => isset($data['display_order']) ? (int) $data['display_order'] : $type->display_order,
                'updated_by' => $actor?->id,
            ]);

            AuditLogService::log(
                event: 'study_material_type_updated',
                description: "Updated study material type '{$type->name}' [{$type->code}].",
                userId: $actor?->id,
                auditable: $type,
                oldValues: $oldValues,
                newValues: $type->only(array_keys($oldValues))
            );

            return $type;
        });
    }

    /**
     * Delete a study material type.
     */
    public function deleteType(StudyMaterialType $type, ?User $actor = null): bool
    {
        if ($type->is_system) {
            throw ValidationException::withMessages(['type' => __('System default material types cannot be deleted.')]);
        }

        if ($type->studyMaterials()->exists()) {
            throw ValidationException::withMessages(['type' => __('Cannot delete material type because it is associated with existing study materials.')]);
        }

        return DB::transaction(function () use ($type, $actor) {
            $name = $type->name;
            $code = $type->code;

            $type->deleted_by = $actor?->id;
            $type->saveQuietly();
            $deleted = (bool) $type->delete();

            AuditLogService::log(
                event: 'study_material_type_deleted',
                description: "Deleted custom study material type '{$name}' [{$code}].",
                userId: $actor?->id,
                auditable: $type
            );

            return $deleted;
        });
    }

    /**
     * Toggle active status.
     */
    public function toggleStatus(StudyMaterialType $type, ?User $actor = null): StudyMaterialType
    {
        $newStatus = ! $type->is_active;
        $type->update([
            'is_active' => $newStatus,
            'updated_by' => $actor?->id,
        ]);

        AuditLogService::log(
            event: 'study_material_type_status_toggled',
            description: "Toggled status for study material type '{$type->name}' to ".($newStatus ? 'Active' : 'Inactive').'.',
            userId: $actor?->id,
            auditable: $type
        );

        return $type;
    }

    /**
     * Toggle public visibility status (Public App/Web vs Internal Enrolled).
     */
    public function toggleVisibility(StudyMaterialType $type, ?User $actor = null): StudyMaterialType
    {
        $newVisibility = ! $type->is_public;
        $type->update([
            'is_public' => $newVisibility,
            'updated_by' => $actor?->id,
        ]);

        AuditLogService::log(
            event: 'study_material_type_visibility_toggled',
            description: "Toggled public visibility for study material type '{$type->name}' to ".($newVisibility ? 'Public' : 'Internal/Enrolled').'.',
            userId: $actor?->id,
            auditable: $type
        );

        return $type;
    }
}
