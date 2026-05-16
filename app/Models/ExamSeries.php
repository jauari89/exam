<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamSeries extends Model
{
    protected $table = 'exam_series';

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }

    public function candidateGroups(): HasMany
    {
        return $this->hasMany(CandidateGroup::class);
    }
}
