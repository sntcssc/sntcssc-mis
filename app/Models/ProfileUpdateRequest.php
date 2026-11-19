<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $request_no
 * @property int $user_id
 * @property string $section
 * @property array $payload
 * @property array|null $current_data
 * @property array|null $changes
 * @property string $reason
 * @property string|null $document_proof
 * @property string $status
 * @property string|null $admin_notes
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read User|null $reviewer
 */
class ProfileUpdateRequest extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'request_no',
        'user_id',
        'section',
        'payload',
        'current_data',
        'changes',
        'reason',
        'document_proof',
        'status',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'current_data' => 'array',
            'changes' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProfileUpdateRequest $req) {
            if (empty($req->request_no)) {
                $year = date('Y');
                $count = static::whereYear('created_at', $year)->count() + 1;
                $req->request_no = 'REQ-'.$year.'-'.str_pad((string) $count, 5, '0', STR_PAD_LEFT);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', 'rejected');
    }
}
