<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualMark extends Model
{
    protected $guarded = [];

    protected $casts = [
        'earned_marks' => 'decimal:2',
        'max_marks' => 'decimal:2',
        'marked_at' => 'datetime',
    ];

    public function answer(): BelongsTo
    {
        return $this->belongsTo(SubmissionAnswer::class, 'submission_answer_id');
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marker_id');
    }
}
