<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionBankRubric extends Model
{
    protected $guarded = [];

    protected $casts = [
        'descriptors' => 'array',
        'max_marks' => 'decimal:2',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(QuestionBankItem::class, 'question_bank_item_id');
    }
}
