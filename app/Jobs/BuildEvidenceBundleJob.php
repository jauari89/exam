<?php

namespace App\Jobs;

use App\Models\ExamAttempt;
use App\Models\ExamSession;
use App\Models\User;
use App\Services\EvidenceBundleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildEvidenceBundleJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $sessionId,
        public ?int $attemptId = null,
        public ?int $requestedBy = null,
    ) {}

    public function handle(EvidenceBundleService $evidence): void
    {
        $session = ExamSession::query()->findOrFail($this->sessionId);
        $attempt = $this->attemptId ? ExamAttempt::query()->findOrFail($this->attemptId) : null;
        $user = $this->requestedBy ? User::query()->find($this->requestedBy) : null;

        $evidence->export($session, $attempt, $user);
    }
}
