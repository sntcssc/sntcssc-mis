<?php

namespace App\Http\Resources;

use App\Models\AlumniExamination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AlumniExamination
 */
class AlumniExaminationResource extends JsonResource
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
            'examination_name' => $this->examination_name,
            'exam_year' => $this->exam_year,
            'service_allotted' => $this->service_allotted,
            'all_india_rank' => $this->all_india_rank,
            'state_rank' => $this->state_rank,
            'roll_number' => $this->roll_number,
            'optional_subject' => $this->optional_subject,
            'score_or_marks' => $this->score_or_marks,
            'category_rank' => $this->category_rank,
            'is_primary' => (bool) $this->is_primary,
            'remarks' => $this->remarks,
        ];
    }
}
