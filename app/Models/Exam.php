<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    protected $guarded = [];

    protected $casts = [
        'randomize_questions' => 'boolean',
        'reveal_feedback' => 'boolean',
        'metadata' => 'array',
    ];

    public function series(): BelongsTo
    {
        return $this->belongsTo(ExamSeries::class, 'exam_series_id');
    }

    public function papers(): HasMany
    {
        return $this->hasMany(ExamPaper::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ExamSession::class);
    }
}
