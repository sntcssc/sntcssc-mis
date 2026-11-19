<?php

namespace App\Http\Resources;

use App\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Exam
 */
class ExamDetailResource extends JsonResource
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
            'instructions' => $this->instructions,
            'admit_card_instructions' => $this->admit_card_instructions,
            'admit_card_release_date' => $this->admit_card_release_date?->format('Y-m-d'),
            'centers' => $this->centers->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'city' => $c->city,
                'district' => $c->district,
                'capacity' => $c->total_capacity,
                'allocated_seats' => $c->pivot->allocated_seats ?? 0,
            ]),
            'statistics' => [
                'total_candidates' => $this->candidates()->count(),
                'present_candidates' => $this->candidates()->where('attendance_status', 'present')->count(),
                'absent_candidates' => $this->candidates()->where('attendance_status', 'absent')->count(),
                'admit_cards_downloaded' => $this->candidates()->whereNotNull('admit_card_downloaded_at')->count(),
            ],
            'creator' => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
