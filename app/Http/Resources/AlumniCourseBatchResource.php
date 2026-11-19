<?php

namespace App\Http\Resources;

use App\Models\AlumniCourseBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AlumniCourseBatch
 */
class AlumniCourseBatchResource extends JsonResource
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
            'course_id' => $this->course_id,
            'batch_id' => $this->batch_id,
            'course_name' => $this->course_name,
            'batch_name' => $this->batch_name,
            'enrollment_no' => $this->enrollment_no,
            'roll_no' => $this->roll_no,
            'academic_year' => $this->academic_year,
            'start_year' => $this->start_year,
            'completion_year' => $this->completion_year,
            'status' => $this->status,
            'remarks' => $this->remarks,
        ];
    }
}
