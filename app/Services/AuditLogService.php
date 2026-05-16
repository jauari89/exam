<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class AuditLogService
{
    public function record(
        string $action,
        ?Request $request = null,
        ?User $user = null,
        ?Candidate $candidate = null,
        ?ExamAttempt $attempt = null,
        mixed $auditable = null,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
    ): AuditLog {
        return AuditLog::query()->create([
            'user_id' => $user?->id ?? $request?->user()?->id,
            'candidate_id' => $candidate?->id,
            'exam_attempt_id' => $attempt?->id,
            'actor_type' => $user || $request?->user() ? 'user' : ($candidate ? 'candidate' : 'system'),
            'action' => $action,
            'auditable_type' => is_object($auditable) ? $auditable::class : null,
            'auditable_id' => is_object($auditable) && method_exists($auditable, 'getKey') ? $auditable->getKey() : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'before' => $before ? Arr::except($before, ['password', 'token_hash', 'token_lookup_hash', 'session_key_hash']) : null,
            'after' => $after ? Arr::except($after, ['password', 'token_hash', 'token_lookup_hash', 'session_key_hash']) : null,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
