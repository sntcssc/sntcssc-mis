<?php

namespace App\Http\Resources;

use App\Models\StudyMaterialFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StudyMaterialFile
 */
class StudyMaterialFileResource extends JsonResource
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
            'file_name' => $this->file_name,
            'file_size' => $this->file_size,
            'formatted_size' => $this->formatted_size,
            'file_type' => $this->file_type,
            'mime_type' => $this->mime_type,
            'download_count' => $this->download_count,
            'download_url' => $this->download_url,
            'preview_url' => route('study-materials.preview', [
                'studyMaterial' => $this->study_material_id,
                'fileIndex' => $this->id,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
