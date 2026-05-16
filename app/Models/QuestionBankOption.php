<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionBankOption extends Model
{
    protected $guarded = [];

    protected $casts = [
        'content' => 'array',
        'is_correct' => 'boolean',
        'marks' => 'decimal:2',
        'media' => 'array',
        'metadata' => 'array',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(QuestionBankItem::class, 'question_bank_item_id');
    }
}
