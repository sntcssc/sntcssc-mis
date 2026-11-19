<?php

namespace App\Http\Resources;

use App\Models\Alumni;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Alumni
 */
class AlumniResource extends JsonResource
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
            'full_name' => $this->full_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'photo_url' => $this->photo_display_url,
            'service_type' => $this->service_type,
            'service_name' => $this->service_name,
            'designation' => $this->designation,
            'cadre' => $this->cadre,
            'all_india_rank' => $this->all_india_rank,
            'state_rank' => $this->state_rank,
            'exam_year' => $this->exam_year,
            'passing_year' => $this->passing_year,
            'posting_city' => $this->posting_city,
            'posting_district' => $this->posting_district,
            'posting_state' => $this->posting_state,
            'posting_country' => $this->posting_country,
            'department_ministry' => $this->department_ministry,
            'current_posting' => [
                'designation' => $this->designation,
                'city' => $this->posting_city,
                'district' => $this->posting_district,
                'state' => $this->posting_state,
                'country' => $this->posting_country,
                'department_ministry' => $this->department_ministry,
                'office_address' => $this->office_address,
                'posting_date' => $this->posting_date?->format('Y-m-d'),
            ],
            'is_featured' => (bool) $this->is_featured,
            'is_verified' => (bool) $this->is_verified,
            'status' => $this->status,
            'courses_summary' => $this->courses_summary,
            'batches_summary' => $this->batches_summary,
            'short_bio' => $this->short_bio,
            'testimonial' => $this->testimonial,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
