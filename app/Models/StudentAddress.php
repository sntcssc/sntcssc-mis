<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $address_type
 * @property string|null $state
 * @property string|null $district
 * @property string|null $subdistrict
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $post_office
 * @property string|null $police_station
 * @property string|null $pin_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class StudentAddress extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'user_id',
        'address_type',
        'state',
        'district',
        'subdistrict',
        'address_line1',
        'address_line2',
        'post_office',
        'police_station',
        'pin_code',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getFormattedAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_line1,
            $this->address_line2,
            $this->subdistrict,
            $this->post_office ? 'P.O: '.$this->post_office : null,
            $this->police_station ? 'P.S: '.$this->police_station : null,
            $this->district,
            $this->state,
            $this->pin_code ? 'PIN: '.$this->pin_code : null,
        ]);

        return implode(', ', $parts);
    }
}
