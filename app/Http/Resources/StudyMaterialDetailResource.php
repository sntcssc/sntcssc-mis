<?php

namespace App\Http\Resources;

use App\Models\StudyMaterial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StudyMaterial
 */
class StudyMaterialDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'slug' => $this->slug,
            'course' => [
                'id' => $this->course_id,
                'name' => $this->course?->name,
                'code' => $this->course?->code,
                'category' => $this->course?->category,
            ],
            'batches' => $this->batches->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'code' => $b->code,
                'academic_year' => $b->academic_year,
            ]),
            'batches_summary' => $this->batches_summary,
            'subject' => $this->subject,
            'topic' => $this->topic,
            'module_code' => $this->module_code,
            'material_type' => $this->material_type,
            'material_type_label' => $this->material_type_label,
            'description' => $this->description,
            'thumbnail_url' => $this->thumbnail_image_url,
            'class_date' => $this->class_date?->format('Y-m-d'),
            'display_order' => (int) $this->display_order,
            'content' => $this->content,
            'external_url' => $this->external_url,
            'access_level' => $this->access_level,
            'is_pinned' => $this->is_pinned,
            'status' => $this->status,
            'view_count' => $this->view_count,
            'download_count' => $this->download_count,
            'files' => StudyMaterialFileResource::collection($this->files),
            'total_files_count' => $this->total_files_count,
            'formatted_total_size' => $this->formatted_total_size,
            'public_share_url' => $this->public_share_url,
            'is_expired' => $this->isExpired(),
            'share_expires_at' => $this->share_expires_at?->toIso8601String(),
            'creator' => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
