<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamSession extends Model
{
    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'settings' => 'array',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function paper(): BelongsTo
    {
        return $this->belongsTo(ExamPaper::class, 'exam_paper_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(ExamRoom::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }
}
