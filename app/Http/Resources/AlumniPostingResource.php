<?php

namespace App\Http\Resources;

use App\Models\AlumniPosting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AlumniPosting
 */
class AlumniPostingResource extends JsonResource
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
            'designation' => $this->designation,
            'department_ministry' => $this->department_ministry,
            'posting_city' => $this->posting_city,
            'posting_district' => $this->posting_district,
            'posting_state' => $this->posting_state,
            'posting_country' => $this->posting_country,
            'office_address' => $this->office_address,
            'from_date' => $this->from_date ? $this->from_date->format('Y-m-d') : null,
            'to_date' => $this->to_date ? $this->to_date->format('Y-m-d') : null,
            'is_current' => (bool) $this->is_current,
            'duration' => $this->duration,
            'order_number' => $this->order_number,
            'responsibilities' => $this->responsibilities,
            'remarks' => $this->remarks,
        ];
    }
}
