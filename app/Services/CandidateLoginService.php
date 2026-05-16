<?php

namespace App\Services;

use App\Models\Candidate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CandidateLoginService
{
    public function __construct(
        private readonly CandidateTokenService $tokens,
        private readonly ExamAttemptService $attempts,
        private readonly AuditLogService $audit,
        private readonly ProctoringService $proctoring,
    ) {}

    public function login(array $payload, Request $request): array
    {
        $token = $this->tokens->findUsable($payload['token'], 'initial', (int) $payload['exam_session_id']);

        if (! $token || $token->used_at) {
            throw ValidationException::withMessages(['token' => 'Token is invalid, expired, revoked, or already used.']);
        }

        $candidate = $token->candidate;
        $session = $token->session;

        if (! $this->matchesCandidateIdentifier($candidate, $payload['name'])) {
            throw ValidationException::withMessages(['name' => 'Candidate name or candidate number does not match this token.']);
        }

        if (! $this->attempts->isWithinWindow($session)) {
            throw ValidationException::withMessages(['exam_session_id' => 'Exam session is not open.']);
        }

        $result = DB::transaction(fn () => $this->attempts->start($candidate, $session, $token, $request));
        $attempt = $result['attempt'];

        $this->audit->record('candidate.login', $request, candidate: $candidate, attempt: $attempt, auditable: $attempt);
        $this->proctoring->record($session, 'candidate_started', 'info', ['attempt_id' => $attempt->id], $attempt, $candidate);

        return $result + [
            'server_time' => now()->toIso8601String(),
            'expires_at' => $attempt->expires_at->toIso8601String(),
        ];
    }

    public function resume(array $payload, Request $request): array
    {
        $token = $this->tokens->findUsable($payload['resume_token'], 'resume');

        if (! $token || $token->used_at || ! $token->attempt) {
            throw ValidationException::withMessages(['resume_token' => 'Resume token is invalid, expired, revoked, or already used.']);
        }

        $attempt = $token->attempt;

        if ($attempt->submitted_at) {
            throw ValidationException::withMessages(['resume_token' => 'This attempt has already been submitted.']);
        }

        if (! $this->attempts->isWithinWindow($attempt->session)) {
            throw ValidationException::withMessages(['resume_token' => 'Exam session is not open.']);
        }

        $sessionKey = DB::transaction(function () use ($token, $attempt): string {
            $sessionKey = $this->attempts->rotateSessionKey($attempt);
            $this->tokens->markUsed($token, $attempt);

            return $sessionKey;
        });

        $this->audit->record('candidate.resume', $request, candidate: $attempt->candidate, attempt: $attempt, auditable: $attempt);
        $this->proctoring->record($attempt->session, 'candidate_resumed', 'info', ['attempt_id' => $attempt->id], $attempt, $attempt->candidate);

        return [
            'attempt' => $attempt->fresh(['snapshot']),
            'session_key' => $sessionKey,
            'server_time' => now()->toIso8601String(),
            'expires_at' => $attempt->expires_at->toIso8601String(),
        ];
    }

    private function matchesCandidateIdentifier(Candidate $candidate, string $identifier): bool
    {
        $normalizedIdentifier = Candidate::normalizeName($identifier);
        $candidateNumber = str($candidate->candidate_number)->upper()->replaceMatches('/\s+/', '')->toString();
        $submittedNumber = str($identifier)->upper()->replaceMatches('/\s+/', '')->toString();

        return $candidate->normalized_name === $normalizedIdentifier || $candidateNumber === $submittedNumber;
    }
}
