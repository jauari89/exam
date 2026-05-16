<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamPackage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'strict_mode' => 'boolean',
        'published_at' => 'datetime',
        'source_payload' => 'array',
        'validated_payload' => 'array',
    ];

    public function paper(): BelongsTo
    {
        return $this->belongsTo(ExamPaper::class, 'exam_paper_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }
}
