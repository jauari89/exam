<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttemptSnapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'published_at' => 'datetime',
        'total_marks' => 'decimal:2',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(ExamPackage::class, 'exam_package_id');
    }
}
