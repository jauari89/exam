<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamPaper extends Model
{
    protected $guarded = [];

    protected $casts = [
        'approved_at' => 'datetime',
        'content' => 'array',
        'total_marks' => 'decimal:2',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(ExamPackage::class);
    }
}
