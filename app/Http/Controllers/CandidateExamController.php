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
        $proctoring->record($attempt->session, 'candidate_heartbeat', 'info', $request->only('visibility', 'network'), $attempt, $attempt->candidate);

        return response()->json(['attempt' => $attempt, 'server_time' => now()->toIso8601String()]);
    }

    public function autosave(ExamAttempt $attempt, AutosaveRequest $request, ExamAttemptService $attempts, AutosaveService $autosaves)
    {
        $attempts->assertSession($attempt, $request->header('X-Candidate-Session'), true);

        if ($attempts->secondsRemaining($attempt) === 0) {
            throw ValidationException::withMessages(['attempt' => 'Exam time has expired.']);
        }

        return response()->json([
            'autosave' => $autosaves->save($attempt, $request->validated(), $request),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function submit(ExamAttempt $attempt, SubmitAttemptRequest $request, ExamAttemptService $attempts, SubmissionService $submissions)
    {
        $attempts->assertSession($attempt, $request->header('X-Candidate-Session'), true);
        $idempotencyKey = $request->header('Idempotency-Key') ?: $request->input('idempotency_key');

        if (! $idempotencyKey) {
            throw ValidationException::withMessages(['idempotency_key' => 'Final submit requires an idempotency key.']);
        }

        return response()->json([
            'submission' => $submissions->submit($attempt, $request->validated(), $idempotencyKey, $request),
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
