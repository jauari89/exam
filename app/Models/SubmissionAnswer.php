<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubmissionAnswer extends Model
{
    protected $guarded = [];

    protected $casts = [
        'answer' => 'array',
        'normalized_answer' => 'array',
        'feedback' => 'array',
        'requires_manual_marking' => 'boolean',
        'max_marks' => 'decimal:2',
        'auto_score' => 'decimal:2',
        'manual_score' => 'decimal:2',
        'final_score' => 'decimal:2',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function manualMarks(): HasMany
    {
        return $this->hasMany(ManualMark::class);
    }
}
