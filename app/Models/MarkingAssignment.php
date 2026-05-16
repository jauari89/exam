<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarkingAssignment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'assigned_at' => 'datetime',
        'due_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marker_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
