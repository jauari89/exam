<?php

namespace App\Services;

use App\Models\Autosave;
use App\Models\ExamAttempt;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmissionService
{
    public function __construct(
        private readonly AutosaveService $autosaves,
        private readonly ScoringService $scoring,
        private readonly AuditLogService $audit,
    ) {}

    public function submit(ExamAttempt $attempt, array $payload, string $idempotencyKey, ?Request $request = null, bool $auto = false): Submission
    {
        if ($existing = $attempt->submission()->first()) {
            $hash = $this->idempotencyHash($idempotencyKey);

            if (hash_equals($existing->idempotency_key_hash, $hash)) {
                return $existing->load('answers', 'score');
            }

            throw ValidationException::withMessages(['idempotency_key' => 'Attempt has already been submitted.']);
        }

        $expired = now()->greaterThan($attempt->expires_at);
        $auto = $auto || $expired;
        $snapshot = $attempt->snapshot ?: $attempt->snapshot()->firstOrFail();
        $rawPayload = $payload;

        if ($auto && $expired) {
            $lastAutosave = Autosave::query()
                ->where('exam_attempt_id', $attempt->id)
                ->where('saved_at', '<=', $attempt->expires_at)
                ->latest('saved_at')
                ->first();
            $normalized = $lastAutosave?->normalized_answers ?? [];
            $rawPayload = $lastAutosave?->payload ?? ['answers' => []];
        } else {
            $normalized = $this->autosaves->normalize($snapshot, $payload);
        }

        return DB::transaction(function () use ($attempt, $snapshot, $normalized, $rawPayload, $idempotencyKey, $auto, $request): Submission {
            $submission = Submission::query()->create([
                'exam_attempt_id' => $attempt->id,
                'idempotency_key_hash' => $this->idempotencyHash($idempotencyKey),
                'status' => $auto ? 'auto_submitted' : 'submitted',
                'submitted_at' => now(),
                'auto_submitted' => $auto,
                'raw_payload' => $rawPayload,
                'normalized_answers' => $normalized,
                'payload_hash' => hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR)),
            ]);

            foreach ($snapshot->payload['questions'] as $question) {
                $answer = $normalized[(string) $question['id']] ?? [
                    'question_id' => (int) $question['id'],
                    'question_external_id' => $question['external_id'],
                    'type' => $question['type'],
                    'answer' => null,
                ];

                $submission->answers()->create([
                    'question_id' => $question['id'],
                    'question_external_id' => $question['external_id'],
                    'answer_type' => $question['type'],
                    'answer' => $answer['answer'],
                    'normalized_answer' => $answer,
                    'max_marks' => $question['max_marks'],
                    'requires_manual_marking' => in_array($question['type'], ['essay', 'structured'], true),
                ]);
            }

            $attempt->forceFill([
                'status' => $auto ? 'auto_submitted' : 'submitted',
                'submitted_at' => now(),
                'auto_submitted' => $auto,
                'last_seen_at' => now(),
            ])->save();

            $this->scoring->scoreSubmission($submission->load('answers', 'attempt.snapshot'));
            $this->audit->record($auto ? 'candidate.auto_submit' : 'candidate.submit', $request, candidate: $attempt->candidate, attempt: $attempt, auditable: $submission);

            return $submission->fresh(['answers', 'score']);
        });
    }

    public function autoSubmit(ExamAttempt $attempt): Submission
    {
        return $this->submit($attempt, ['answers' => []], 'auto-'.$attempt->id.'-'.$attempt->expires_at->timestamp, null, true);
    }

    private function idempotencyHash(string $idempotencyKey): string
    {
        return hash_hmac('sha256', $idempotencyKey, config('app.key'));
    }
}
