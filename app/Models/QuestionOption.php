<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionOption extends Model
{
    protected $guarded = [];

    protected $casts = [
        'content' => 'array',
        'is_correct' => 'boolean',
        'marks' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
