<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionBankItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'stem' => 'array',
        'correct_answer' => 'array',
        'validation_rules' => 'array',
        'feedback' => 'array',
        'media' => 'array',
        'metadata' => 'array',
        'max_marks' => 'decimal:2',
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'question_bank_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionBankOption::class)->orderBy('position');
    }

    public function rubrics(): HasMany
    {
        return $this->hasMany(QuestionBankRubric::class);
    }
}
