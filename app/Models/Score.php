<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Score extends Model
{
    protected $guarded = [];

    protected $casts = [
        'auto_score' => 'decimal:2',
        'manual_score' => 'decimal:2',
        'total_score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'calculated_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(ScoreDetail::class);
    }
}
