<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    protected $guarded = [];

    protected $casts = [
        'stem' => 'array',
        'correct_answer' => 'array',
        'validation_rules' => 'array',
        'feedback' => 'array',
        'metadata' => 'array',
        'max_marks' => 'decimal:2',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(ExamPackage::class, 'exam_package_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }

    public function rubrics(): HasMany
    {
        return $this->hasMany(QuestionRubric::class);
    }
}
