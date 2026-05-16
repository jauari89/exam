<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProctorAttemptActionRequest;
use App\Http\Requests\ProctorEventRequest;
use App\Models\ExamAttempt;
use App\Models\ExamSession;
use App\Services\ProctoringService;

class ProctorSessionController extends Controller
{
    public function show(ExamSession $examSession, ProctoringService $proctoring)
    {
        $this->authorize('operate', $examSession);

        return response()->json($proctoring->dashboard($examSession));
    }

    public function event(ExamSession $examSession, ProctorEventRequest $request, ProctoringService $proctoring)
    {
        $this->authorize('operate', $examSession);
        $attempt = $request->filled('exam_attempt_id') ? ExamAttempt::query()->findOrFail($request->integer('exam_attempt_id')) : null;

        return response()->json($proctoring->record(
            $examSession,
            $request->string('event_type')->toString(),
            $request->input('severity', 'info'),
            $request->input('payload', []),
            $attempt,
            $attempt?->candidate,
            $request->user(),
        ), 201);
    }

    public function lock(ExamAttempt $attempt, ProctorAttemptActionRequest $request, ProctoringService $proctoring)
    {
        $this->authorize('operate', $attempt->session);

        return response()->json($proctoring->lockAttempt($attempt, $request->user(), $request, $request->input('reason')));
    }

    public function unlock(ExamAttempt $attempt, ProctorAttemptActionRequest $request, ProctoringService $proctoring)
    {
        $this->authorize('operate', $attempt->session);

        return response()->json($proctoring->unlockAttempt($attempt, $request->user(), $request));
    }

    public function resumeToken(ExamAttempt $attempt, ProctorAttemptActionRequest $request, ProctoringService $proctoring)
    {
        $this->authorize('operate', $attempt->session);
        $issued = $proctoring->issueResumeToken($attempt, $request->user(), $request);

        return response()->json([
            'token_id' => $issued['token']->id,
            'resume_token' => $issued['plain_token'],
            'expires_at' => $issued['token']->expires_at?->toIso8601String(),
        ], 201);
    }
}
