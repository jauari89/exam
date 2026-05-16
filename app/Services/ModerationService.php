<?php

namespace App\Services;

use App\Models\ModerationReview;
use App\Models\Submission;
use App\Models\User;

class ModerationService
{
    public function __construct(private readonly ScoringService $scoring) {}

    public function finalize(Submission $submission, User $reviewer, array $payload): ModerationReview
    {
        $review = ModerationReview::query()->create([
            'submission_id' => $submission->id,
            'reviewer_id' => $reviewer->id,
            'decision' => $payload['decision'] ?? 'accepted',
            'final_score' => $payload['final_score'] ?? null,
            'comments' => $payload['comments'] ?? null,
            'reviewed_at' => now(),
        ]);

        $score = $this->scoring->recalculateTotals($submission->fresh('answers'));

        if (isset($payload['final_score'])) {
            $score->forceFill([
                'total_score' => max(0.0, min((float) $score->max_score, (float) $payload['final_score'])),
                'status' => 'final',
                'finalized_at' => now(),
            ])->save();
        }

        $submission->forceFill(['finalized_at' => now(), 'score_state' => 'final'])->save();

        return $review;
    }
}
