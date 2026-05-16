<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExamSeriesRequest;
use App\Models\ExamSeries;
use App\Services\AuditLogService;

class AdminExamSeriesController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', ExamSeries::class);

        return ExamSeries::query()->latest()->paginate(25);
    }

    public function store(StoreExamSeriesRequest $request, AuditLogService $audit)
    {
        $this->authorize('create', ExamSeries::class);
        $series = ExamSeries::query()->create($request->validated() + ['created_by' => $request->user()->id]);
        $audit->record('series.create', $request, $request->user(), auditable: $series, after: $series->toArray());

        return response()->json($series, 201);
    }

    public function show(ExamSeries $examSeries)
    {
        $this->authorize('view', $examSeries);

        return $examSeries->load('exams.papers', 'candidateGroups');
    }

    public function update(StoreExamSeriesRequest $request, ExamSeries $examSeries, AuditLogService $audit)
    {
        $this->authorize('update', $examSeries);
        $before = $examSeries->toArray();
        $examSeries->update($request->validated());
        $audit->record('series.update', $request, $request->user(), auditable: $examSeries, before: $before, after: $examSeries->fresh()->toArray());

        return $examSeries->fresh();
    }
}
