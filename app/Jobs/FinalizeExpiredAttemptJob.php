<?php

namespace App\Jobs;

use App\Models\ExamAttempt;
use App\Services\SubmissionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FinalizeExpiredAttemptJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $attemptId) {}

    public function handle(SubmissionService $submissions): void
    {
        $attempt = ExamAttempt::query()->find($this->attemptId);

        if ($attempt && ! $attempt->submitted_at && now()->greaterThanOrEqualTo($attempt->expires_at)) {
            $submissions->autoSubmit($attempt);
        }
    }
}
