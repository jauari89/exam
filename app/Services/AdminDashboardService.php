<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Autosave;
use App\Models\Candidate;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamPackage;
use App\Models\ExamSeries;
use App\Models\ExamSession;
use App\Models\IncidentReport;
use App\Models\ProctorEvent;
use App\Models\QuestionBank;
use App\Models\QuestionBankItem;
use App\Models\Score;
use App\Models\Submission;
use App\Models\SubmissionAnswer;

class AdminDashboardService
{
    public function overview(): array
    {
        $disconnectedCutoff = now()->subSeconds(45);
        $scoreRows = Score::query()->where('max_score', '>', 0)->get(['total_score', 'max_score']);
        $averageScore = $scoreRows->isEmpty()
            ? null
            : round((float) $scoreRows->avg(fn (Score $score) => ((float) $score->total_score / max(1, (float) $score->max_score)) * 100), 2);

        return [
            'server_time' => now()->toIso8601String(),
            'summary' => [
                'series' => ExamSeries::query()->count(),
                'exams' => Exam::query()->count(),
                'sessions' => ExamSession::query()->count(),
                'active_sessions' => ExamSession::query()
                    ->where('starts_at', '<=', now())
                    ->where('ends_at', '>=', now())
                    ->count(),
                'candidates' => Candidate::query()->count(),
                'question_banks' => QuestionBank::query()->count(),
                'question_bank_items' => QuestionBankItem::query()->count(),
                'packages' => ExamPackage::query()->count(),
                'attempts' => ExamAttempt::query()->count(),
                'in_progress_attempts' => ExamAttempt::query()
                    ->whereNull('submitted_at')
                    ->whereNull('locked_at')
                    ->where(function ($query) use ($disconnectedCutoff): void {
                        $query->whereNull('last_seen_at')->orWhere('last_seen_at', '>=', $disconnectedCutoff);
                    })
                    ->count(),
                'disconnected_attempts' => ExamAttempt::query()
                    ->whereNull('submitted_at')
                    ->whereNull('locked_at')
                    ->whereNotNull('last_seen_at')
                    ->where('last_seen_at', '<', $disconnectedCutoff)
                    ->count(),
                'locked_attempts' => ExamAttempt::query()->whereNotNull('locked_at')->count(),
                'submitted_attempts' => ExamAttempt::query()->whereNotNull('submitted_at')->count(),
                'submissions' => Submission::query()->count(),
                'pending_manual_answers' => SubmissionAnswer::query()
                    ->where('requires_manual_marking', true)
                    ->whereNull('final_score')
                    ->count(),
                'open_incidents' => IncidentReport::query()->where('status', 'open')->count(),
                'warning_events_24h' => ProctorEvent::query()
                    ->whereIn('severity', ['warning', 'critical'])
                    ->where('occurred_at', '>=', now()->subDay())
                    ->count(),
                'autosaves_24h' => Autosave::query()->where('saved_at', '>=', now()->subDay())->count(),
                'average_score_percent' => $averageScore,
            ],
            'attempt_statuses' => $this->attemptStatuses(),
            'active_sessions' => $this->activeSessions(),
            'recent_attempts' => $this->recentAttempts(),
            'recent_events' => $this->recentEvents(),
            'recent_incidents' => $this->recentIncidents(),
            'recent_audit_logs' => $this->recentAuditLogs(),
        ];
    }

    private function attemptStatuses(): array
    {
        $attempts = ExamAttempt::query()->with('submission.score')->get();

        return $attempts
            ->groupBy(fn (ExamAttempt $attempt) => $this->statusFor($attempt))
            ->map(fn ($group) => $group->count())
            ->all();
    }

    private function activeSessions(): array
    {
        return ExamSession::query()
            ->with('exam')
            ->withCount(['attempts', 'rooms'])
            ->where('ends_at', '>=', now()->subHours(6))
            ->orderBy('starts_at')
            ->limit(12)
            ->get()
            ->map(function (ExamSession $session): array {
                $attempts = $session->attempts()->with('submission.score')->get();

                return [
                    'id' => $session->id,
                    'name' => $session->name,
                    'exam' => $session->exam?->title,
                    'mode' => $session->mode,
                    'status' => $session->status,
                    'starts_at' => $session->starts_at?->toIso8601String(),
                    'ends_at' => $session->ends_at?->toIso8601String(),
                    'duration_minutes' => $session->duration_minutes,
                    'attempts_count' => $attempts->count(),
                    'rooms_count' => $session->rooms_count,
                    'submitted_count' => $attempts->whereNotNull('submitted_at')->count(),
                    'locked_count' => $attempts->whereNotNull('locked_at')->count(),
                    'incidents_count' => IncidentReport::query()->where('exam_session_id', $session->id)->count(),
                    'last_event_at' => ProctorEvent::query()
                        ->where('exam_session_id', $session->id)
                        ->latest('occurred_at')
                        ->value('occurred_at'),
                    'statuses' => $attempts->groupBy(fn (ExamAttempt $attempt) => $this->statusFor($attempt))->map(fn ($group) => $group->count())->all(),
                ];
            })
            ->all();
    }

    private function recentAttempts(): array
    {
        return ExamAttempt::query()
            ->with('candidate', 'session.exam', 'submission.score')
            ->latest('updated_at')
            ->limit(10)
            ->get()
            ->map(fn (ExamAttempt $attempt): array => [
                'id' => $attempt->id,
                'candidate' => $attempt->candidate?->name,
                'candidate_number' => $attempt->candidate?->candidate_number,
                'session' => $attempt->session?->name,
                'exam' => $attempt->session?->exam?->title,
                'status' => $this->statusFor($attempt),
                'started_at' => $attempt->started_at?->toIso8601String(),
                'last_seen_at' => $attempt->last_seen_at?->toIso8601String(),
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                'score_status' => $attempt->submission?->score?->status,
            ])
            ->all();
    }

    private function recentEvents(): array
    {
        return ProctorEvent::query()
            ->with('session', 'attempt.candidate')
            ->latest('occurred_at')
            ->limit(10)
            ->get()
            ->map(fn (ProctorEvent $event): array => [
                'id' => $event->id,
                'session' => $event->session?->name,
                'candidate' => $event->attempt?->candidate?->name,
                'event_type' => $event->event_type,
                'severity' => $event->severity,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])
            ->all();
    }

    private function recentIncidents(): array
    {
        return IncidentReport::query()
            ->with('examSession', 'candidate')
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (IncidentReport $incident): array => [
                'id' => $incident->id,
                'title' => $incident->title,
                'severity' => $incident->severity,
                'status' => $incident->status,
                'session' => $incident->examSession?->name,
                'candidate' => $incident->candidate?->name,
                'created_at' => $incident->created_at?->toIso8601String(),
            ])
            ->all();
    }

    private function recentAuditLogs(): array
    {
        return AuditLog::query()
            ->with('user', 'candidate')
            ->latest('occurred_at')
            ->limit(8)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->user?->name ?? $log->candidate?->name ?? $log->actor_type,
                'occurred_at' => $log->occurred_at?->toIso8601String(),
            ])
            ->all();
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
