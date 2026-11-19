<?php

namespace App\Http\Resources;

use App\Models\ExamCandidate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExamCandidate
 */
class ExamCandidateResource extends JsonResource
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
            'exam_id' => $this->exam_id,
            'roll_number' => $this->roll_number,
            'candidate_name' => $this->candidate_name,
            'candidate_email' => $this->candidate_email,
            'candidate_phone' => $this->candidate_phone,
            'center' => $this->center ? [
                'id' => $this->center->id,
                'name' => $this->center->name,
                'code' => $this->center->code,
                'address' => $this->center->address,
                'city' => $this->center->city,
            ] : null,
            'room' => $this->room ? [
                'id' => $this->room->id,
                'room_number' => $this->room->room_number,
                'floor' => $this->room->floor,
                'building_name' => $this->room->building_name,
            ] : null,
            'seat_number' => $this->seat_number,
            'admit_card_token' => $this->admit_card_token,
            'admit_card_downloaded' => ! empty($this->admit_card_downloaded_at),
            'admit_card_downloaded_at' => $this->admit_card_downloaded_at?->toIso8601String(),
            'attendance_status' => $this->attendance_status,
            'attendance_marked_at' => $this->attendance_marked_at?->toIso8601String(),
            'marks_obtained' => $this->marks_obtained !== null ? (float) $this->marks_obtained : null,
            'rank' => $this->rank,
            'is_qualified' => (bool) $this->is_qualified,
            'remarks' => $this->remarks,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
