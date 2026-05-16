<?php

namespace App\Http\Controllers;

use App\Http\Requests\ManualMarkRequest;
use App\Http\Requests\ModerationReviewRequest;
use App\Http\Requests\StoreMarkingAssignmentRequest;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Services\AuditLogService;
use App\Services\ManualMarkingService;
use App\Services\ModerationService;

class MarkingController extends Controller
{
    public function pending(ManualMarkingService $marking)
    {
        $this->authorize('viewAny', SubmissionAnswer::class);

        return $marking->pending(request()->user());
    }

    public function assignments(ManualMarkingService $marking)
    {
        $this->authorize('moderate', SubmissionAnswer::class);

        return $marking->assignments();
    }

    public function assign(StoreMarkingAssignmentRequest $request, ManualMarkingService $marking, AuditLogService $audit)
    {
        $this->authorize('moderate', SubmissionAnswer::class);

        $assignment = $marking->assign($request->validated());
        $audit->record('marking.assign', $request, $request->user(), auditable: $assignment, after: $assignment->toArray());

        return response()->json($assignment, 201);
    }

    public function show(SubmissionAnswer $submissionAnswer)
    {
        $this->authorize('view', $submissionAnswer);

        return $submissionAnswer->load('submission.attempt.candidate', 'question.rubrics', 'manualMarks.marker');
    }

    public function mark(SubmissionAnswer $submissionAnswer, ManualMarkRequest $request, ManualMarkingService $marking, AuditLogService $audit)
    {
        $this->authorize('mark', $submissionAnswer);
        $mark = $marking->saveMark($submissionAnswer, $request->user(), $request->validated());
        $audit->record('marking.save', $request, $request->user(), auditable: $mark, after: $mark->toArray());

        return response()->json($mark, 201);
    }

    public function moderate(Submission $submission, ModerationReviewRequest $request, ModerationService $moderation, AuditLogService $audit)
    {
        $this->authorize('moderate', SubmissionAnswer::class);
        $review = $moderation->finalize($submission, $request->user(), $request->validated());
        $audit->record('marking.moderate', $request, $request->user(), auditable: $review, after: $review->toArray());

        return response()->json($review, 201);
    }
}
