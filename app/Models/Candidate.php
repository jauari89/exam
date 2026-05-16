<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Candidate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public static function normalizeName(string $name): string
    {
        return str($name)->lower()->squish()->toString();
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CandidateGroup::class, 'candidate_group_id');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(CandidateExamToken::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }
}
