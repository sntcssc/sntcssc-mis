<?php

namespace App\Http\Resources;

use App\Models\Alumni;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Alumni
 */
class AlumniDetailResource extends JsonResource
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
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'alternate_phone' => $this->alternate_phone,
            'photo_url' => $this->photo_display_url,
            'gender' => $this->gender,
            'dob' => $this->dob ? $this->dob->format('Y-m-d') : null,
            'passing_year' => $this->passing_year,
            'service_type' => $this->service_type,
            'service_name' => $this->service_name,
            'designation' => $this->designation,
            'cadre' => $this->cadre,
            'all_india_rank' => $this->all_india_rank,
            'state_rank' => $this->state_rank,
            'exam_year' => $this->exam_year,
            'roll_number' => $this->roll_number,
            'current_posting' => [
                'city' => $this->posting_city,
                'district' => $this->posting_district,
                'state' => $this->posting_state,
                'country' => $this->posting_country,
                'department_ministry' => $this->department_ministry,
                'posting_date' => $this->posting_date ? $this->posting_date->format('Y-m-d') : null,
                'office_address' => $this->office_address,
            ],
            'addresses' => [
                'current' => $this->current_address,
                'permanent' => $this->permanent_address,
            ],
            'social_links' => [
                'linkedin' => $this->linkedin_url,
                'twitter' => $this->twitter_url,
                'website' => $this->website_url,
            ],
            'bio_details' => [
                'short_bio' => $this->short_bio,
                'detailed_bio' => $this->detailed_bio,
                'success_story' => $this->success_story,
                'testimonial' => $this->testimonial,
                'achievements' => $this->achievements,
            ],
            'is_featured' => (bool) $this->is_featured,
            'is_verified' => (bool) $this->is_verified,
            'status' => $this->status,
            'source' => $this->source,
            'courses_summary' => $this->courses_summary,
            'batches_summary' => $this->batches_summary,
            'enrolled_programs' => AlumniCourseBatchResource::collection($this->whenLoaded('courseBatches')),
            'examination_selections' => AlumniExaminationResource::collection($this->whenLoaded('examinations')),
            'posting_history' => AlumniPostingResource::collection($this->whenLoaded('postings')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
