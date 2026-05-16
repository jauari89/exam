<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\AttendanceLog;
use App\Models\ExamAttempt;
use App\Models\ExamSession;
use App\Models\IncidentReport;
use App\Models\ProctorEvent;
use App\Models\Score;
use App\Models\Submission;
use App\Models\SubmissionAnswer;

class ReportService
{
    public function sessionSummary(ExamSession $session): array
    {
        $attemptIds = $session->attempts()->pluck('id');

        return [
            'score_report' => Score::query()
                ->with('submission.attempt.candidate')
                ->whereIn('exam_attempt_id', $attemptIds)
                ->orderByDesc('total_score')
                ->get(),
            'submission_status' => $session->attempts()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
            'incidents' => IncidentReport::query()
                ->where('exam_session_id', $session->id)
                ->selectRaw('severity, count(*) as total')
                ->groupBy('severity')
                ->pluck('total', 'severity'),
            'item_analysis' => SubmissionAnswer::query()
                ->whereHas('submission', fn ($query) => $query->whereIn('exam_attempt_id', $attemptIds))
                ->selectRaw('question_external_id, answer_type, avg(final_score) as average_score, avg(max_marks) as max_marks')
                ->groupBy('question_external_id', 'answer_type')
                ->get(),
            'topic_progress' => SubmissionAnswer::query()
                ->join('questions', 'questions.id', '=', 'submission_answers.question_id')
                ->whereHas('submission', fn ($query) => $query->whereIn('exam_attempt_id', $attemptIds))
                ->selectRaw('questions.topic, avg(submission_answers.final_score) as average_score, avg(submission_answers.max_marks) as max_marks')
                ->groupBy('questions.topic')
                ->get(),
        ];
    }

    public function itemAnalysis(ExamSession $session): array
    {
        $answers = SubmissionAnswer::query()
            ->with('question')
            ->whereHas('submission.attempt', fn ($query) => $query->where('exam_session_id', $session->id))
            ->get();

        return $answers
            ->groupBy('question_external_id')
            ->map(function ($group, string $externalId): array {
                $first = $group->first();
                $maxMarks = (float) $group->max(fn (SubmissionAnswer $answer) => (float) $answer->max_marks);
                $average = round((float) $group->avg(fn (SubmissionAnswer $answer) => (float) ($answer->final_score ?? 0)), 2);

                return [
                    'question_external_id' => $externalId,
                    'question_id' => $first?->question_id,
                    'type' => $first?->answer_type,
                    'topic' => $first?->question?->topic,
                    'attempted' => $group->filter(fn (SubmissionAnswer $answer) => filled($answer->normalized_answer))->count(),
                    'responses' => $group->count(),
                    'average_score' => $average,
                    'max_marks' => $maxMarks,
                    'facility_index' => $maxMarks > 0 ? round($average / $maxMarks, 3) : null,
                    'manual_pending' => $group->where('requires_manual_marking', true)->whereNull('final_score')->count(),
                ];
            })
            ->values()
            ->all();
    }

    public function studentAnalysis(ExamSession $session, Candidate $candidate): array
    {
        $attempts = $session->attempts()
            ->where('candidate_id', $candidate->id)
            ->with('submission.score', 'submission.answers.question', 'autosaves')
            ->get();
        $answers = $attempts->flatMap(fn ($attempt) => $attempt->submission?->answers ?? collect());

        return [
            'candidate' => $candidate,
            'attempts' => $attempts->map(fn ($attempt): array => [
                'id' => $attempt->id,
                'status' => $attempt->status,
                'started_at' => $attempt->started_at,
                'submitted_at' => $attempt->submitted_at,
                'last_seen_at' => $attempt->last_seen_at,
                'autosave_count' => $attempt->autosaves->count(),
                'last_autosave_at' => $attempt->autosaves->max('saved_at'),
                'score' => $attempt->submission?->score,
            ])->all(),
            'topic_progress' => $answers
                ->groupBy(fn (SubmissionAnswer $answer) => $answer->question?->topic ?: 'General')
                ->map(fn ($group, string $topic): array => [
                    'topic' => $topic,
                    'earned_marks' => round((float) $group->sum(fn (SubmissionAnswer $answer) => (float) ($answer->final_score ?? 0)), 2),
                    'max_marks' => round((float) $group->sum(fn (SubmissionAnswer $answer) => (float) $answer->max_marks), 2),
                    'answered' => $group->filter(fn (SubmissionAnswer $answer) => filled($answer->normalized_answer))->count(),
                    'total' => $group->count(),
                ])
                ->values()
                ->all(),
            'question_breakdown' => $answers->map(fn (SubmissionAnswer $answer): array => [
                'question_external_id' => $answer->question_external_id,
                'type' => $answer->answer_type,
                'topic' => $answer->question?->topic,
                'earned_marks' => (float) ($answer->final_score ?? 0),
                'max_marks' => (float) $answer->max_marks,
                'requires_manual_marking' => $answer->requires_manual_marking,
            ])->values()->all(),
        ];
    }

    public function attemptTimeline(ExamAttempt $attempt): array
    {
        $attempt->load([
            'candidate',
            'session.exam',
            'snapshot',
            'autosaves',
            'proctorEvents',
            'submission.answers',
            'submission.score',
        ]);

        $events = collect();

        $events->push([
            'at' => $attempt->started_at?->toIso8601String(),
            'type' => 'attempt_started',
            'severity' => 'info',
            'title' => 'Attempt dimulai',
            'description' => 'Kandidat berhasil masuk dan server timer dimulai.',
            'meta' => [
                'ip_address' => $attempt->ip_address,
                'expires_at' => $attempt->expires_at?->toIso8601String(),
                'attempt_no' => $attempt->attempt_no,
            ],
        ]);

        AttendanceLog::query()
            ->where('exam_attempt_id', $attempt->id)
            ->orderBy('checked_in_at')
            ->get()
            ->each(fn (AttendanceLog $log) => $events->push([
                'at' => $log->checked_in_at?->toIso8601String(),
                'type' => 'attendance_'.$log->status,
                'severity' => 'info',
                'title' => 'Attendance '.$log->status,
                'description' => 'Log kehadiran kandidat tercatat.',
                'meta' => [
                    'ip_address' => $log->ip_address,
                    'checked_out_at' => $log->checked_out_at?->toIso8601String(),
                ],
            ]));

        $attempt->proctorEvents
            ->sortBy('occurred_at')
            ->each(function (ProctorEvent $event) use ($events): void {
                $question = $event->payload['current_question_external_id']
                    ?? $event->payload['question_external_id']
                    ?? null;

                $events->push([
                    'at' => $event->occurred_at?->toIso8601String(),
                    'type' => $event->event_type,
                    'severity' => $event->severity,
                    'title' => $question ? 'Aktivitas di soal '.$question : str_replace('_', ' ', $event->event_type),
                    'description' => $this->eventDescription($event),
                    'meta' => $event->payload ?? [],
                ]);
            });

        $attempt->autosaves
            ->sortBy('saved_at')
            ->each(function ($autosave) use ($events): void {
                $answers = collect($autosave->normalized_answers ?? []);

                $events->push([
                    'at' => $autosave->saved_at?->toIso8601String(),
                    'type' => 'autosave',
                    'severity' => 'info',
                    'title' => 'Autosave #'.$autosave->client_sequence,
                    'description' => $answers->count().' jawaban tersimpan di server.',
                    'meta' => [
                        'client_sequence' => $autosave->client_sequence,
                        'answered_count' => $answers->filter(fn ($answer) => filled(data_get($answer, 'answer')))->count(),
                        'ip_address' => $autosave->ip_address,
                    ],
                ]);
            });

        if ($attempt->submission) {
            /** @var Submission $submission */
            $submission = $attempt->submission;
            $events->push([
                'at' => $submission->submitted_at?->toIso8601String(),
                'type' => $submission->auto_submitted ? 'auto_submit' : 'final_submit',
                'severity' => 'success',
                'title' => $submission->auto_submitted ? 'Auto-submit' : 'Final submit',
                'description' => $submission->answers->count().' jawaban final tersimpan.',
                'meta' => [
                    'status' => $submission->status,
                    'payload_hash' => $submission->payload_hash,
                    'score_state' => $submission->score_state,
                ],
            ]);

            if ($submission->score) {
                $score = $submission->score;
                $events->push([
                    'at' => $score->updated_at?->toIso8601String() ?? $score->created_at?->toIso8601String(),
                    'type' => 'score',
                    'severity' => $score->status === 'final' ? 'success' : 'info',
                    'title' => 'Skor dihitung',
                    'description' => 'Total '.$score->total_score.' / '.$score->max_score.'.',
                    'meta' => [
                        'auto_score' => $score->auto_score,
                        'manual_score' => $score->manual_score,
                        'total_score' => $score->total_score,
                        'max_score' => $score->max_score,
                        'status' => $score->status,
                    ],
                ]);
            }
        }

        return [
            'attempt' => [
                'id' => $attempt->id,
                'status' => $attempt->status,
                'mode' => $attempt->mode,
                'started_at' => $attempt->started_at?->toIso8601String(),
                'last_seen_at' => $attempt->last_seen_at?->toIso8601String(),
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                'expires_at' => $attempt->expires_at?->toIso8601String(),
                'auto_submitted' => $attempt->auto_submitted,
                'snapshot' => [
                    'package_checksum' => $attempt->snapshot?->package_checksum,
                    'duration_minutes' => $attempt->snapshot?->duration_minutes,
                    'total_marks' => $attempt->snapshot?->total_marks,
                    'question_count' => count($attempt->snapshot?->payload['questions'] ?? []),
                ],
            ],
            'candidate' => $attempt->candidate,
            'session' => [
                'id' => $attempt->session->id,
                'name' => $attempt->session->name,
                'exam' => $attempt->session->exam?->title,
            ],
            'summary' => [
                'autosaves' => $attempt->autosaves->count(),
                'heartbeats' => $attempt->proctorEvents->where('event_type', 'candidate_heartbeat')->count(),
                'warnings' => $attempt->proctorEvents->whereIn('severity', ['warning', 'critical'])->count(),
                'final_answers' => $attempt->submission?->answers->count() ?? 0,
            ],
            'events' => $events
                ->filter(fn (array $event) => filled($event['at']))
                ->sortBy('at')
                ->values()
                ->all(),
        ];
    }

    private function eventDescription(ProctorEvent $event): string
    {
        if ($event->event_type === 'candidate_heartbeat') {
            $network = $event->payload['network'] ?? 'unknown';
            $visibility = $event->payload['visibility'] ?? 'unknown';

            return "Heartbeat diterima. Network: $network, tab: $visibility.";
        }

        return 'Event proctor tercatat.';
    }
}
