<?php

namespace App\Http\Resources;

use App\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Exam
 */
class ExamResource extends JsonResource
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
            'exam_code' => $this->exam_code,
            'exam_name' => $this->exam_name,
            'academic_year' => $this->academic_year,
            'conducting_body' => $this->conducting_body,
            'notification_date' => $this->notification_date?->format('Y-m-d'),
            'apply_start' => $this->apply_start?->format('Y-m-d'),
            'apply_end' => $this->apply_end?->format('Y-m-d'),
            'exam_date' => $this->exam_date?->format('Y-m-d'),
            'exam_time' => $this->exam_time,
            'reporting_time' => $this->reporting_time,
            'duration_minutes' => $this->duration_minutes,
            'fee_general' => (float) $this->fee_general,
            'fee_reserved' => (float) $this->fee_reserved,
            'total_vacancies' => $this->total_vacancies,
            'status' => $this->status,
            'admit_card_release_date' => $this->admit_card_release_date?->format('Y-m-d'),
            'total_candidates' => $this->candidates_count ?? $this->candidates()->count(),
            'total_centers' => $this->centers_count ?? $this->centers()->count(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
