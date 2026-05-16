<?php

namespace App\Services;

use App\Models\Score;
use App\Models\Submission;
use App\Models\SubmissionAnswer;

class ScoringService
{
    public function scoreSubmission(Submission $submission): Score
    {
        $snapshot = $submission->attempt->snapshot;
        $questions = collect($snapshot->payload['questions'] ?? [])->keyBy('external_id');

        foreach ($submission->answers as $answer) {
            $question = $questions->get($answer->question_external_id);

            if (! $question) {
                continue;
            }

            if (in_array($answer->answer_type, ['essay', 'structured'], true)) {
                $answer->forceFill([
                    'requires_manual_marking' => true,
                    'auto_score' => null,
                    'final_score' => null,
                ])->save();

                continue;
            }

            $score = $this->scoreAnswer($question, data_get($answer->normalized_answer, 'answer'));
            $answer->forceFill([
                'requires_manual_marking' => false,
                'auto_score' => $score,
                'final_score' => $score,
            ])->save();
        }

        return $this->recalculateTotals($submission->fresh('answers'));
    }

    public function recalculateTotals(Submission $submission): Score
    {
        $answers = $submission->answers()->get();
        $auto = (float) $answers->sum(fn (SubmissionAnswer $answer) => (float) ($answer->auto_score ?? 0));
        $manual = (float) $answers->sum(fn (SubmissionAnswer $answer) => (float) ($answer->manual_score ?? 0));
        $total = (float) $answers->sum(fn (SubmissionAnswer $answer) => (float) ($answer->final_score ?? 0));
        $max = (float) $answers->sum(fn (SubmissionAnswer $answer) => (float) $answer->max_marks);
        $pendingManual = $answers->contains(fn (SubmissionAnswer $answer) => $answer->requires_manual_marking && $answer->final_score === null);

        $score = Score::query()->updateOrCreate(
            ['submission_id' => $submission->id],
            [
                'exam_attempt_id' => $submission->exam_attempt_id,
                'auto_score' => $auto,
                'manual_score' => $manual,
                'total_score' => $total,
                'max_score' => $max,
                'status' => $pendingManual ? 'pending_manual' : 'final',
                'calculated_at' => now(),
                'finalized_at' => $pendingManual ? null : now(),
            ],
        );

        $score->details()->delete();

        foreach ($answers as $answer) {
            $score->details()->create([
                'question_id' => $answer->question_id,
                'submission_answer_id' => $answer->id,
                'earned_marks' => $answer->final_score ?? 0,
                'max_marks' => $answer->max_marks,
                'source' => $answer->requires_manual_marking ? 'manual' : 'auto',
            ]);
        }

        $submission->forceFill(['score_state' => $score->status])->save();

        return $score;
    }

    public function scoreAnswer(array $question, mixed $answer): float
    {
        $max = (float) $question['max_marks'];

        return max(0.0, min($max, match ($question['type']) {
            'objective' => $this->scoreObjective($question, $answer),
            'checkbox' => $this->scoreCheckbox($question, $answer),
            'numerical' => $this->scoreNumerical($question, $answer),
            default => 0.0,
        }));
    }

    private function scoreObjective(array $question, mixed $answer): float
    {
        if ($answer === null) {
            return 0.0;
        }

        $option = collect($question['options'])->firstWhere('id', (int) $answer);

        return $option && ($option['is_correct'] ?? false)
            ? (float) (($option['marks'] ?? 0) ?: $question['max_marks'])
            : 0.0;
    }

    private function scoreCheckbox(array $question, mixed $answer): float
    {
        $selected = collect(is_array($answer) ? $answer : [])->map(fn ($id) => (int) $id)->unique();

        return (float) collect($question['options'])
            ->filter(fn ($option) => $selected->contains((int) $option['id']))
            ->sum(fn ($option) => (float) ($option['marks'] ?? (($option['is_correct'] ?? false) ? 1 : 0)));
    }

    private function scoreNumerical(array $question, mixed $answer): float
    {
        if ($answer === null) {
            return 0.0;
        }

        $expected = data_get($question, 'correct_answer.value', data_get($question, 'correct_answer'));

        if ($expected === null || ! is_numeric($expected)) {
            return 0.0;
        }

        $tolerance = (float) data_get($question, 'validation_rules.tolerance', 0);

        return abs((float) $answer - (float) $expected) <= $tolerance ? (float) $question['max_marks'] : 0.0;
    }
}
