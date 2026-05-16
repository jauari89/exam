<?php

namespace App\Services;

use App\Events\ProctorEventRecorded;
use App\Models\Candidate;
use App\Models\ExamAttempt;
use App\Models\ExamSession;
use App\Models\ProctorEvent;
use App\Models\User;
use Illuminate\Http\Request;

class ProctoringService
{
    public function __construct(
        private readonly ExamAttemptService $attempts,
        private readonly CandidateTokenService $tokens,
        private readonly AuditLogService $audit,
    ) {}

    public function dashboard(ExamSession $session): array
    {
        $attempts = $session->attempts()
            ->with(['candidate', 'autosaves' => fn ($query) => $query->latest('saved_at')->limit(1), 'submission.score'])
            ->latest('started_at')
            ->get()
            ->map(fn (ExamAttempt $attempt) => [
                'id' => $attempt->id,
                'candidate' => [
                    'id' => $attempt->candidate->id,
                    'candidate_number' => $attempt->candidate->candidate_number,
                    'name' => $attempt->candidate->name,
                ],
                'status' => $this->statusFor($attempt),
                'started_at' => $attempt->started_at?->toIso8601String(),
                'last_heartbeat' => $attempt->last_seen_at?->toIso8601String(),
                'last_autosave_at' => $attempt->autosaves->first()?->saved_at?->toIso8601String(),
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                'locked_at' => $attempt->locked_at?->toIso8601String(),
                'score_status' => $attempt->submission?->score?->status,
                'suspicious_events' => $attempt->proctorEvents()->whereIn('severity', ['warning', 'critical'])->count(),
            ]);

        return [
            'session' => $session,
            'attempts' => $attempts,
            'server_time' => now()->toIso8601String(),
        ];
    }

    public function record(
        ExamSession $session,
        string $eventType,
        string $severity = 'info',
        array $payload = [],
        ?ExamAttempt $attempt = null,
        ?Candidate $candidate = null,
        ?User $recordedBy = null,
    ): ProctorEvent {
        $event = ProctorEvent::query()->create([
            'exam_session_id' => $session->id,
            'exam_attempt_id' => $attempt?->id,
            'candidate_id' => $candidate?->id ?? $attempt?->candidate_id,
            'recorded_by' => $recordedBy?->id,
            'event_type' => $eventType,
            'severity' => $severity,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);

        broadcast(new ProctorEventRecorded($event))->toOthers();

        return $event;
    }

    public function lockAttempt(ExamAttempt $attempt, User $user, Request $request, ?string $reason = null): ExamAttempt
    {
        $before = $attempt->toArray();
        $attempt = $this->attempts->lock($attempt, $user, $reason);
        $this->record($attempt->session, 'attempt_locked', 'warning', ['reason' => $reason], $attempt, $attempt->candidate, $user);
        $this->audit->record('proctor.lock_attempt', $request, $user, $attempt->candidate, $attempt, $attempt, $before, $attempt->toArray());

        return $attempt;
    }

    public function unlockAttempt(ExamAttempt $attempt, User $user, Request $request): ExamAttempt
    {
        $before = $attempt->toArray();
        $attempt = $this->attempts->unlock($attempt);
        $this->record($attempt->session, 'attempt_unlocked', 'info', [], $attempt, $attempt->candidate, $user);
        $this->audit->record('proctor.unlock_attempt', $request, $user, $attempt->candidate, $attempt, $attempt, $before, $attempt->toArray());

        return $attempt;
    }

    public function issueResumeToken(ExamAttempt $attempt, User $user, Request $request): array
    {
        $issued = $this->tokens->issue($attempt->candidate, $attempt->session, 'resume', $attempt, $user, now()->addMinutes(15));
        $this->record($attempt->session, 'resume_token_issued', 'info', ['token_id' => $issued['token']->id], $attempt, $attempt->candidate, $user);
        $this->audit->record('proctor.issue_resume_token', $request, $user, $attempt->candidate, $attempt, $issued['token']);

        return $issued;
    }

    private function statusFor(ExamAttempt $attempt): string
    {
        if ($attempt->locked_at) {
            return 'locked';
        }

        if ($attempt->submission?->score?->status === 'final') {
            return 'graded';
        }

        if ($attempt->auto_submitted) {
            return 'auto_submitted';
        }

        if ($attempt->submitted_at) {
            return 'submitted';
        }

        if ($attempt->last_seen_at && $attempt->last_seen_at->lt(now()->subSeconds(45))) {
            return 'disconnected';
        }

        return $attempt->started_at ? 'in_progress' : 'not_started';
    }
}
