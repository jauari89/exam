<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionRubric extends Model
{
    protected $guarded = [];

    protected $casts = [
        'descriptors' => 'array',
        'max_marks' => 'decimal:2',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
