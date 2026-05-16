<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Submission extends Model
{
    protected $guarded = [];

    protected $casts = [
        'submitted_at' => 'datetime',
        'finalized_at' => 'datetime',
        'auto_submitted' => 'boolean',
        'raw_payload' => 'array',
        'normalized_answers' => 'array',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SubmissionAnswer::class);
    }

    public function score(): HasOne
    {
        return $this->hasOne(Score::class);
    }
}
