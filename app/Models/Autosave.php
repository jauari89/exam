<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Autosave extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'normalized_answers' => 'array',
        'validation_errors' => 'array',
        'saved_at' => 'datetime',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }
}
