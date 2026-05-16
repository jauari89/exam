<?php

namespace App\Http\Controllers;

use App\Http\Requests\IncidentReportRequest;
use App\Models\ExamSession;
use App\Models\IncidentReport;
use App\Services\IncidentReportService;

class IncidentReportController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', IncidentReport::class);

        return IncidentReport::query()->with('examSession', 'candidate')->latest()->paginate(25);
    }

    public function store(IncidentReportRequest $request, IncidentReportService $incidents)
    {
        $this->authorize('create', IncidentReport::class);
        $session = ExamSession::query()->findOrFail($request->integer('exam_session_id'));

        return response()->json($incidents->create($session, $request->validated(), $request->user(), $request), 201);
    }
}
