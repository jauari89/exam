<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\ExamSession;
use App\Models\IncidentReport;
use App\Models\Score;
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
}
