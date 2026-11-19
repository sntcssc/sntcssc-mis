<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $book_copy_id
 * @property string $action
 * @property string|null $old_condition
 * @property string|null $new_condition
 * @property string|null $old_status
 * @property string|null $new_status
 * @property string|null $old_shelf_location
 * @property string|null $new_shelf_location
 * @property string|null $notes
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BookAccessionHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'book_copy_id',
        'action',
        'old_condition',
        'new_condition',
        'old_status',
        'new_status',
        'old_shelf_location',
        'new_shelf_location',
        'notes',
        'created_by',
    ];

    public function copy(): BelongsTo
    {
        return $this->belongsTo(BookCopy::class, 'book_copy_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
