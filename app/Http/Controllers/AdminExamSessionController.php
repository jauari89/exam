<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExamSessionRequest;
use App\Models\ExamPaper;
use App\Models\ExamSession;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminExamSessionController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', ExamSession::class);

        return ExamSession::query()
            ->with('exam', 'paper')
            ->withCount('attempts')
            ->when($request->integer('exam_id'), fn ($query, int $examId) => $query->where('exam_id', $examId))
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->latest('starts_at')
            ->paginate(50);
    }

    public function store(StoreExamSessionRequest $request, AuditLogService $audit)
    {
        $this->authorize('create', ExamSession::class);
        $payload = $this->validatedPayload($request);
        $session = ExamSession::query()->create($payload);
        $audit->record('exam_session.create', $request, $request->user(), auditable: $session, after: $session->toArray());

        return response()->json($session->load('exam', 'paper'), 201);
    }

    public function show(ExamSession $examSession)
    {
        $this->authorize('view', $examSession);

        return $examSession->load('exam', 'paper.packages', 'rooms', 'attempts.candidate');
    }

    public function update(StoreExamSessionRequest $request, ExamSession $examSession, AuditLogService $audit)
    {
        $this->authorize('update', $examSession);
        $before = $examSession->toArray();
        $examSession->forceFill($this->validatedPayload($request))->save();
        $audit->record('exam_session.update', $request, $request->user(), auditable: $examSession, before: $before, after: $examSession->toArray());

        return response()->json($examSession->fresh('exam', 'paper'));
    }

    public function destroy(Request $request, ExamSession $examSession, AuditLogService $audit)
    {
        $this->authorize('delete', $examSession);

        if ($examSession->attempts()->exists()) {
            throw ValidationException::withMessages(['exam_session' => 'Cannot delete a session that already has attempts. Close it instead.']);
        }

        $before = $examSession->toArray();
        $examSession->delete();
        $audit->record('exam_session.delete', $request, $request->user(), before: $before);

        return response()->noContent();
    }

    private function validatedPayload(StoreExamSessionRequest $request): array
    {
        $payload = $request->validated();

        if (! empty($payload['exam_paper_id'])) {
            $paper = ExamPaper::query()->findOrFail($payload['exam_paper_id']);

            if ((int) $paper->exam_id !== (int) $payload['exam_id']) {
                throw ValidationException::withMessages(['exam_paper_id' => 'Selected paper belongs to a different exam.']);
            }
        }

        return $payload + [
            'status' => 'scheduled',
            'timezone' => config('app.timezone', 'UTC'),
        ];
    }
}
