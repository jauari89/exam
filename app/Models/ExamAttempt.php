<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExamAttempt extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'session_key_hash',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'submitted_at' => 'datetime',
        'expires_at' => 'datetime',
        'locked_at' => 'datetime',
        'auto_submitted' => 'boolean',
        'metadata' => 'array',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(CandidateExamToken::class, 'candidate_exam_token_id');
    }

    public function snapshot(): HasOne
    {
        return $this->hasOne(AttemptSnapshot::class);
    }

    public function autosaves(): HasMany
    {
        return $this->hasMany(Autosave::class);
    }

    public function submission(): HasOne
    {
        return $this->hasOne(Submission::class);
    }

    public function proctorEvents(): HasMany
    {
        return $this->hasMany(ProctorEvent::class);
    }
}
