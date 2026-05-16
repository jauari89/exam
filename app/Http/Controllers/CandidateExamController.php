<?php

namespace App\Http\Controllers;

use App\Http\Requests\AutosaveRequest;
use App\Http\Requests\SubmitAttemptRequest;
use App\Models\ExamAttempt;
use App\Services\AttemptSnapshotService;
use App\Services\AutosaveService;
use App\Services\ExamAttemptService;
use App\Services\ProctoringService;
use App\Services\SubmissionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CandidateExamController extends Controller
{
    public function show(ExamAttempt $attempt, Request $request, ExamAttemptService $attempts, AttemptSnapshotService $snapshots)
    {
        $attempts->assertSession($attempt, $request->header('X-Candidate-Session'), true);
        $snapshot = $attempt->snapshot()->firstOrFail();
        $revealFeedback = $attempt->submission && $attempt->mode === 'tryout';

        return response()->json([
            'attempt' => $attempt->load('candidate'),
            'paper' => $snapshots->candidatePayload($snapshot, $revealFeedback),
            'latest_autosave' => $attempt->autosaves()->latest('saved_at')->first(),
            'server_time' => now()->toIso8601String(),
            'seconds_remaining' => $attempts->secondsRemaining($attempt),
        ]);
    }

    public function time(ExamAttempt $attempt, Request $request, ExamAttemptService $attempts, SubmissionService $submissions)
    {
        $attempts->assertSession($attempt, $request->header('X-Candidate-Session'), true);

        if (! $attempt->submitted_at && $attempts->secondsRemaining($attempt) === 0) {
            $submissions->autoSubmit($attempt);
            $attempt = $attempt->fresh();
        }

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'expires_at' => $attempt->expires_at->toIso8601String(),
            'seconds_remaining' => $attempts->secondsRemaining($attempt),
            'status' => $attempt->status,
        ]);
    }

    public function heartbeat(ExamAttempt $attempt, Request $request, ExamAttemptService $attempts, ProctoringService $proctoring)
    {
        $attempts->assertSession($attempt, $request->header('X-Candidate-Session'), true);
        $attempt = $attempts->heartbeat($attempt, $request->all());
        $proctoring->record($attempt->session, 'candidate_heartbeat', 'info', $request->only([
            'visibility',
            'network',
            'current_question_id',
            'current_question_external_id',
            'current_question_position',
            'answered_count',
            'question_count',
            'activity',
        ]), $attempt, $attempt->candidate);

        return response()->json(['attempt' => $attempt, 'server_time' => now()->toIso8601String()]);
    }

    public function autosave(ExamAttempt $attempt, AutosaveRequest $request, ExamAttemptService $attempts, AutosaveService $autosaves, ProctoringService $proctoring)
    {
        $attempts->assertSession($attempt, $request->header('X-Candidate-Session'), true);

        if ($attempts->secondsRemaining($attempt) === 0) {
            throw ValidationException::withMessages(['attempt' => 'Exam time has expired.']);
        }

        $autosave = $autosaves->save($attempt, $request->validated(), $request);
        $proctoring->record($attempt->session, 'autosave_saved', 'info', [
            'client_sequence' => $autosave->client_sequence,
            'answered_count' => collect($autosave->normalized_answers)->filter(fn ($answer) => filled(data_get($answer, 'answer')))->count(),
            'question_count' => count($attempt->snapshot?->payload['questions'] ?? []),
        ], $attempt, $attempt->candidate);

        return response()->json([
            'autosave' => $autosave,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function submit(ExamAttempt $attempt, SubmitAttemptRequest $request, ExamAttemptService $attempts, SubmissionService $submissions, ProctoringService $proctoring)
    {
        $attempts->assertSession($attempt, $request->header('X-Candidate-Session'), true);
        $idempotencyKey = $request->header('Idempotency-Key') ?: $request->input('idempotency_key');

        if (! $idempotencyKey) {
            throw ValidationException::withMessages(['idempotency_key' => 'Final submit requires an idempotency key.']);
        }

        $submission = $submissions->submit($attempt, $request->validated(), $idempotencyKey, $request);
        $proctoring->record($attempt->session, 'final_submit', 'success', [
            'submission_id' => $submission->id,
            'answer_count' => $submission->answers()->count(),
            'payload_hash' => $submission->payload_hash,
        ], $attempt, $attempt->candidate);

        return response()->json([
            'submission' => $submission,
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
