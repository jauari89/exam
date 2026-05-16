<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExamRequest;
use App\Models\Exam;
use App\Models\ExamSession;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;

class AdminExamController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Exam::class);

        return Exam::query()->with('series', 'papers', 'sessions')->latest()->paginate(25);
    }

    public function store(StoreExamRequest $request, AuditLogService $audit)
    {
        $this->authorize('create', Exam::class);

        $exam = DB::transaction(function () use ($request) {
            $data = collect($request->validated())->except(['paper', 'session'])->all();
            $exam = Exam::query()->create($data);

            if ($paperPayload = $request->validated('paper')) {
                $paper = $exam->papers()->create($paperPayload + [
                    'created_by' => $request->user()->id,
                    'duration_minutes' => $paperPayload['duration_minutes'] ?? $exam->default_duration_minutes,
                ]);
            }

            if ($sessionPayload = $request->validated('session')) {
                ExamSession::query()->create($sessionPayload + [
                    'exam_id' => $exam->id,
                    'exam_paper_id' => isset($paper) ? $paper->id : null,
                    'duration_minutes' => $sessionPayload['duration_minutes'] ?? $exam->default_duration_minutes,
                    'mode' => $exam->mode,
                ]);
            }

            return $exam->load('papers', 'sessions');
        });

        $audit->record('exam.create', $request, $request->user(), auditable: $exam, after: $exam->toArray());

        return response()->json($exam, 201);
    }

    public function show(Exam $exam)
    {
        $this->authorize('view', $exam);

        return $exam->load('series', 'papers.packages', 'sessions');
    }
}
