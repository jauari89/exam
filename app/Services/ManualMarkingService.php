<?php

namespace App\Services;

use App\Models\ManualMark;
use App\Models\MarkingAssignment;
use App\Models\SubmissionAnswer;
use App\Models\User;

class ManualMarkingService
{
    public function __construct(private readonly ScoringService $scoring) {}

    public function pending(?User $marker = null)
    {
        $assignedSessionIds = $marker && $marker->canDo('mark_submissions') && ! $marker->hasRole('admin')
            ? MarkingAssignment::query()->where('marker_id', $marker->id)->pluck('exam_session_id')->all()
            : null;

        return SubmissionAnswer::query()
            ->with(['submission.attempt.candidate', 'question.rubrics'])
            ->where('requires_manual_marking', true)
            ->whereNull('final_score')
            ->when(is_array($assignedSessionIds), function ($query) use ($assignedSessionIds): void {
                $query->whereHas('submission.attempt', fn ($inner) => $inner->whereIn('exam_session_id', $assignedSessionIds));
            })
            ->latest()
            ->paginate(25);
    }

    public function assignments()
    {
        return MarkingAssignment::query()
            ->with('session.exam', 'marker', 'reviewer')
            ->latest()
            ->paginate(25);
    }

    public function assign(array $payload): MarkingAssignment
    {
        return MarkingAssignment::query()->updateOrCreate(
            [
                'exam_session_id' => $payload['exam_session_id'],
                'marker_id' => $payload['marker_id'],
            ],
            [
                'reviewer_id' => $payload['reviewer_id'] ?? null,
                'status' => 'assigned',
                'assigned_at' => now(),
                'due_at' => $payload['due_at'] ?? null,
            ],
        )->load('session.exam', 'marker', 'reviewer');
    }

    public function saveMark(SubmissionAnswer $answer, User $marker, array $payload): ManualMark
    {
        $earned = max(0.0, min((float) $answer->max_marks, (float) $payload['earned_marks']));

        $mark = ManualMark::query()->updateOrCreate(
            [
                'submission_answer_id' => $answer->id,
                'marker_id' => $marker->id,
                'marker_role' => $payload['marker_role'] ?? 'first_marker',
            ],
            [
                'earned_marks' => $earned,
                'max_marks' => $answer->max_marks,
                'comments' => $payload['comments'] ?? null,
                'status' => $payload['status'] ?? 'pending_review',
                'marked_at' => now(),
            ],
        );

        $answer->forceFill([
            'manual_score' => $earned,
            'final_score' => $earned,
        ])->save();

        $this->scoring->recalculateTotals($answer->submission->fresh('answers'));

        return $mark;
    }
}
