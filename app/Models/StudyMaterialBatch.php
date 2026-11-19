<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $study_material_id
 * @property int $batch_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StudyMaterial $studyMaterial
 * @property-read Batch $batch
 */
class StudyMaterialBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'study_material_id',
        'batch_id',
    ];

    public function studyMaterial(): BelongsTo
    {
        return $this->belongsTo(StudyMaterial::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
