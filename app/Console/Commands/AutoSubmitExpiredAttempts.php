<?php

namespace App\Console\Commands;

use App\Models\ExamAttempt;
use App\Services\SubmissionService;
use Illuminate\Console\Command;

class AutoSubmitExpiredAttempts extends Command
{
    protected $signature = 'exam:auto-submit-expired {--limit=200 : Max attempts to process in one run}';

    protected $description = 'Auto-submit expired in-progress attempts using their latest autosave before expiry.';

    public function handle(SubmissionService $submissions): int
    {
        $attempts = ExamAttempt::query()
            ->whereNull('submitted_at')
            ->where('expires_at', '<=', now())
            ->oldest('expires_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($attempts as $attempt) {
            $submissions->autoSubmit($attempt);
        }

        $this->info("Auto-submitted {$attempts->count()} expired attempts.");

        return self::SUCCESS;
    }
}
