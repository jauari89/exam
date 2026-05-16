<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentReport extends Model
{
    protected $guarded = [];

    protected $casts = [
        'evidence' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function examSession(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}
