<?php

namespace App\Http\Resources;

use App\Models\ExaminationCenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExaminationCenter
 */
class ExaminationCenterResource extends JsonResource
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
            'code' => $this->code,
            'name' => $this->name,
            'address' => $this->address,
            'city' => $this->city,
            'district' => $this->district,
            'state' => $this->state,
            'pincode' => $this->pincode,
            'contact_person' => $this->contact_person,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'total_capacity' => $this->total_capacity,
            'is_active' => $this->is_active,
            'rooms_count' => $this->rooms_count ?? $this->rooms()->count(),
            'rooms' => $this->whenLoaded('rooms', fn () => $this->rooms->map(fn ($r) => [
                'id' => $r->id,
                'room_number' => $r->room_number,
                'floor' => $r->floor,
                'building_name' => $r->building_name,
                'capacity' => $r->capacity,
                'is_active' => $r->is_active,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
