<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CandidateGroup extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function series(): BelongsTo
    {
        return $this->belongsTo(ExamSeries::class, 'exam_series_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class);
    }
}
