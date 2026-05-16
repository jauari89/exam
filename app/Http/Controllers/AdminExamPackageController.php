<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportExamPackageRequest;
use App\Http\Requests\PublishExamPackageRequest;
use App\Models\ExamPackage;
use App\Models\ExamPaper;
use App\Models\ExamSession;
use App\Services\AuditLogService;
use App\Services\ExamPackageImportService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminExamPackageController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', ExamPackage::class);

        return ExamPackage::query()
            ->with('paper.exam')
            ->withCount('questions')
            ->when($request->integer('exam_paper_id'), fn ($query, int $paperId) => $query->where('exam_paper_id', $paperId))
            ->latest()
            ->paginate(50);
    }

    public function import(ImportExamPackageRequest $request, ExamPackageImportService $importer, AuditLogService $audit)
    {
        $this->authorize('create', ExamPackage::class);
        $paper = ExamPaper::query()->findOrFail($request->integer('exam_paper_id'));
        $package = $importer->import($paper, $request->validated(), $request->user());
        $audit->record('package.import', $request, $request->user(), auditable: $package, after: ['checksum' => $package->checksum]);

        return response()->json($package, 201);
    }

    public function show(ExamPackage $examPackage)
    {
        $this->authorize('view', $examPackage);

        return $examPackage->load('paper.exam', 'questions.options', 'questions.rubrics');
    }

    public function publishToSession(PublishExamPackageRequest $request, ExamPackage $examPackage, AuditLogService $audit)
    {
        $this->authorize('update', $examPackage);

        $package = $examPackage->load('paper.exam');
        $session = ExamSession::query()->findOrFail($request->integer('exam_session_id'));

        if ((int) $session->exam_id !== (int) $package->paper->exam_id) {
            throw ValidationException::withMessages(['exam_session_id' => 'Package paper belongs to a different exam than this session.']);
        }

        $before = $session->toArray();
        $settings = $session->settings ?? [];
        $settings['published_package_id'] = $package->id;
        $settings['published_package_checksum'] = $package->checksum;
        $settings['published_package_version'] = $package->version;

        $session->forceFill([
            'exam_paper_id' => $package->exam_paper_id,
            'status' => $request->input('status', $session->status),
            'settings' => $settings,
        ])->save();

        $audit->record('package.publish_session', $request, $request->user(), auditable: $package, before: $before, after: $session->toArray());

        return response()->json($session->fresh('exam', 'paper'));
    }
}
