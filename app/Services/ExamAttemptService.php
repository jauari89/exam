<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Candidate;
use App\Models\CandidateExamToken;
use App\Models\ExamAttempt;
use App\Models\ExamSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ExamAttemptService
{
    public function __construct(
        private readonly CandidateTokenService $tokens,
        private readonly AttemptSnapshotService $snapshots,
    ) {}

    public function start(Candidate $candidate, ExamSession $session, CandidateExamToken $token, Request $request): array
    {
        $sessionKey = $this->tokens->makeSessionKey();
        $now = now();
        $expiresAt = $now->copy()->addMinutes($session->duration_minutes);

        if ($session->ends_at && $session->ends_at->lt($expiresAt)) {
            $expiresAt = $session->ends_at->copy();
        }

        return DB::transaction(function () use ($candidate, $session, $token, $request, $sessionKey, $now, $expiresAt): array {
            $attempt = ExamAttempt::query()->create([
                'uuid' => (string) Str::uuid(),
                'candidate_id' => $candidate->id,
                'exam_session_id' => $session->id,
                'candidate_exam_token_id' => $token->id,
                'attempt_no' => $candidate->attempts()->where('exam_session_id', $session->id)->count() + 1,
                'status' => 'in_progress',
                'mode' => $session->mode,
                'session_key_hash' => $this->tokens->sessionKeyHash($sessionKey),
                'started_at' => $now,
                'last_seen_at' => $now,
                'expires_at' => $expiresAt,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $this->snapshots->createForAttempt($attempt);
            $this->tokens->markUsed($token, $attempt);

            AttendanceLog::query()->create([
                'exam_session_id' => $session->id,
                'candidate_id' => $candidate->id,
                'exam_attempt_id' => $attempt->id,
                'status' => 'checked_in',
                'checked_in_at' => $now,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return ['attempt' => $attempt->fresh(['snapshot']), 'session_key' => $sessionKey];
        });
    }

    public function assertSession(ExamAttempt $attempt, ?string $sessionKey, bool $allowSubmitted = false): ExamAttempt
    {
        if (! $sessionKey || ! hash_equals($attempt->session_key_hash, $this->tokens->sessionKeyHash($sessionKey))) {
            abort(401, 'Invalid candidate session.');
        }

        if ($attempt->locked_at) {
            throw new HttpException(423, 'Attempt is locked by a proctor.');
        }

        if (! $allowSubmitted && $attempt->submitted_at) {
            abort(409, 'Attempt has already been submitted.');
        }

        return $attempt;
    }

    public function rotateSessionKey(ExamAttempt $attempt): string
    {
        $sessionKey = $this->tokens->makeSessionKey();
        $attempt->forceFill([
            'session_key_hash' => $this->tokens->sessionKeyHash($sessionKey),
            'status' => 'in_progress',
            'last_seen_at' => now(),
        ])->save();

        return $sessionKey;
    }

    public function heartbeat(ExamAttempt $attempt, array $payload = []): ExamAttempt
    {
        $status = $attempt->submitted_at ? $attempt->status : 'in_progress';

        $attempt->forceFill([
            'status' => $status,
            'last_seen_at' => now(),
            'metadata' => array_merge($attempt->metadata ?? [], ['last_heartbeat' => $payload]),
        ])->save();

        return $attempt;
    }

    public function secondsRemaining(ExamAttempt $attempt): int
    {
        return max(0, now()->diffInSeconds($attempt->expires_at, false));
    }

    public function lock(ExamAttempt $attempt, User $user, ?string $reason = null): ExamAttempt
    {
        $attempt->forceFill([
            'status' => 'locked',
            'locked_at' => now(),
            'locked_by' => $user->id,
            'lock_reason' => $reason,
        ])->save();

        return $attempt;
    }

    public function unlock(ExamAttempt $attempt): ExamAttempt
    {
        $attempt->forceFill([
            'status' => 'in_progress',
            'locked_at' => null,
            'locked_by' => null,
            'lock_reason' => null,
        ])->save();

        return $attempt;
    }

    public function isWithinWindow(ExamSession $session): bool
    {
        return now()->betweenIncluded($session->starts_at, $session->ends_at);
    }
}
