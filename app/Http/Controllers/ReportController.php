<?php

namespace App\Http\Controllers;

use App\Http\Requests\EvidenceExportRequest;
use App\Models\Candidate;
use App\Models\EvidenceExport;
use App\Models\ExamAttempt;
use App\Models\ExamSession;
use App\Services\EvidenceBundleService;
use App\Services\ReportService;
use App\Services\ScoreReportPdfService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    public function session(ExamSession $examSession, ReportService $reports)
    {
        Gate::authorize('view-reports');

        return response()->json($reports->sessionSummary($examSession));
    }

    public function items(ExamSession $examSession, ReportService $reports)
    {
        Gate::authorize('view-reports');

        return response()->json($reports->itemAnalysis($examSession));
    }

    public function student(ExamSession $examSession, Candidate $candidate, ReportService $reports)
    {
        Gate::authorize('view-reports');

        return response()->json($reports->studentAnalysis($examSession, $candidate));
    }

    public function attemptTimeline(ExamAttempt $attempt, ReportService $reports)
    {
        Gate::authorize('view-reports');

        return response()->json($reports->attemptTimeline($attempt));
    }

    public function scorePdf(ExamSession $examSession, ScoreReportPdfService $pdf)
    {
        Gate::authorize('view-reports');

        return response($pdf->render($examSession), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="score-report-session-'.$examSession->id.'.pdf"',
        ]);
    }

    public function exportEvidence(EvidenceExportRequest $request, EvidenceBundleService $evidence)
    {
        Gate::authorize('export-evidence');
        $session = ExamSession::query()->findOrFail($request->integer('exam_session_id'));
        $attempt = $request->filled('exam_attempt_id') ? ExamAttempt::query()->findOrFail($request->integer('exam_attempt_id')) : null;

        return response()->json($evidence->export($session, $attempt, $request->user(), $request->input('format', 'json')), 201);
    }

    public function downloadEvidence(EvidenceExport $evidenceExport)
    {
        Gate::authorize('export-evidence');

        abort_unless($evidenceExport->path && Storage::disk('local')->exists($evidenceExport->path), 404);

        return response()->download(
            Storage::disk('local')->path($evidenceExport->path),
            basename($evidenceExport->path),
        );
    }
}
