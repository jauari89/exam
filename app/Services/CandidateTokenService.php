<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\CandidateExamToken;
use App\Models\ExamAttempt;
use App\Models\ExamSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CandidateTokenService
{
    public function issue(
        Candidate $candidate,
        ExamSession $session,
        string $purpose = 'initial',
        ?ExamAttempt $attempt = null,
        ?User $issuedBy = null,
        ?CarbonInterface $expiresAt = null,
    ): array {
        $plain = $this->makePlainToken();
        $normalized = $this->normalizeToken($plain);

        $token = CandidateExamToken::query()->create([
            'candidate_id' => $candidate->id,
            'exam_session_id' => $session->id,
            'exam_attempt_id' => $attempt?->id,
            'purpose' => $purpose,
            'token_lookup_hash' => $this->lookupHash($normalized),
            'token_hash' => Hash::make($normalized),
            'token_suffix' => substr($normalized, -4),
            'expires_at' => $expiresAt ?? $session->ends_at?->copy()->addHours(2),
            'issued_by' => $issuedBy?->id,
        ]);

        return ['token' => $token, 'plain_token' => $plain];
    }

    public function generateInitialTokens(ExamSession $session, iterable $candidates, ?User $issuedBy = null): Collection
    {
        return collect($candidates)->map(fn (Candidate $candidate) => $this->issue($candidate, $session, 'initial', null, $issuedBy));
    }

    public function findUsable(string $plainToken, string $purpose, ?int $sessionId = null): ?CandidateExamToken
    {
        $normalized = $this->normalizeToken($plainToken);

        $query = CandidateExamToken::query()
            ->with(['candidate', 'session', 'attempt'])
            ->where('purpose', $purpose)
            ->where('token_lookup_hash', $this->lookupHash($normalized))
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            });

        if ($sessionId) {
            $query->where('exam_session_id', $sessionId);
        }

        $token = $query->first();

        if (! $token || ! Hash::check($normalized, $token->token_hash)) {
            return null;
        }

        return $token;
    }

    public function markUsed(CandidateExamToken $token, ?ExamAttempt $attempt = null): void
    {
        $token->forceFill([
            'used_at' => now(),
            'exam_attempt_id' => $attempt?->id ?? $token->exam_attempt_id,
        ])->save();
    }

    public function makeSessionKey(): string
    {
        return Str::random(64);
    }

    public function sessionKeyHash(string $sessionKey): string
    {
        return hash_hmac('sha256', $sessionKey, config('app.key'));
    }

    public function lookupHash(string $normalizedToken): string
    {
        return hash_hmac('sha256', $normalizedToken, config('app.key'));
    }

    public function normalizeToken(string $token): string
    {
        return str($token)->upper()->replaceMatches('/[^A-Z0-9]/', '')->toString();
    }

    private function makePlainToken(): string
    {
        $token = strtoupper(bin2hex(random_bytes(12)));

        return implode('-', str_split($token, 4));
    }
}
