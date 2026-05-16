<?php

use App\Http\Controllers\AdminCandidateController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminExamController;
use App\Http\Controllers\AdminExamPackageController;
use App\Http\Controllers\AdminExamSeriesController;
use App\Http\Controllers\AdminExamSessionController;
use App\Http\Controllers\AdminQuestionBankController;
use App\Http\Controllers\AdminTokenController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CandidateAuthController;
use App\Http\Controllers\CandidateExamController;
use App\Http\Controllers\IncidentReportController;
use App\Http\Controllers\MarkingController;
use App\Http\Controllers\ProctorSessionController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:admin-login');

Route::prefix('candidate')->group(function (): void {
    Route::post('/login', [CandidateAuthController::class, 'login'])->middleware('throttle:candidate-login');
    Route::post('/resume', [CandidateAuthController::class, 'resume'])->middleware('throttle:candidate-login');
    Route::get('/attempts/{attempt}', [CandidateExamController::class, 'show']);
    Route::get('/attempts/{attempt}/time', [CandidateExamController::class, 'time']);
    Route::post('/attempts/{attempt}/heartbeat', [CandidateExamController::class, 'heartbeat']);
    Route::post('/attempts/{attempt}/autosave', [CandidateExamController::class, 'autosave']);
    Route::post('/attempts/{attempt}/submit', [CandidateExamController::class, 'submit']);
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/admin/dashboard', [AdminDashboardController::class, 'show']);

    Route::get('/admin/exam-series', [AdminExamSeriesController::class, 'index']);
    Route::post('/admin/exam-series', [AdminExamSeriesController::class, 'store']);
    Route::get('/admin/exam-series/{examSeries}', [AdminExamSeriesController::class, 'show']);
    Route::put('/admin/exam-series/{examSeries}', [AdminExamSeriesController::class, 'update']);

    Route::get('/admin/exams', [AdminExamController::class, 'index']);
    Route::post('/admin/exams', [AdminExamController::class, 'store']);
    Route::get('/admin/exams/{exam}', [AdminExamController::class, 'show']);

    Route::get('/admin/exam-sessions', [AdminExamSessionController::class, 'index']);
    Route::post('/admin/exam-sessions', [AdminExamSessionController::class, 'store']);
    Route::get('/admin/exam-sessions/{examSession}', [AdminExamSessionController::class, 'show']);
    Route::put('/admin/exam-sessions/{examSession}', [AdminExamSessionController::class, 'update']);
    Route::delete('/admin/exam-sessions/{examSession}', [AdminExamSessionController::class, 'destroy']);

    Route::get('/admin/exam-packages', [AdminExamPackageController::class, 'index']);
    Route::post('/admin/exam-packages/import', [AdminExamPackageController::class, 'import']);
    Route::get('/admin/exam-packages/{examPackage}', [AdminExamPackageController::class, 'show']);
    Route::post('/admin/exam-packages/{examPackage}/publish-session', [AdminExamPackageController::class, 'publishToSession']);

    Route::get('/admin/question-banks', [AdminQuestionBankController::class, 'index']);
    Route::post('/admin/question-banks', [AdminQuestionBankController::class, 'store']);
    Route::get('/admin/question-banks/{questionBank}', [AdminQuestionBankController::class, 'show']);
    Route::put('/admin/question-banks/{questionBank}', [AdminQuestionBankController::class, 'update']);
    Route::post('/admin/question-banks/{questionBank}/questions', [AdminQuestionBankController::class, 'storeQuestion']);
    Route::put('/admin/question-bank-items/{questionBankItem}', [AdminQuestionBankController::class, 'updateQuestion']);
    Route::delete('/admin/question-bank-items/{questionBankItem}', [AdminQuestionBankController::class, 'destroyQuestion']);
    Route::post('/admin/question-banks/{questionBank}/import', [AdminQuestionBankController::class, 'import']);
    Route::post('/admin/question-banks/{questionBank}/import-file', [AdminQuestionBankController::class, 'importFile']);
    Route::post('/admin/question-banks/{questionBank}/media', [AdminQuestionBankController::class, 'uploadMedia']);
    Route::post('/admin/question-banks/{questionBank}/build-package', [AdminQuestionBankController::class, 'buildPackage']);

    Route::get('/admin/candidates', [AdminCandidateController::class, 'index']);
    Route::post('/admin/candidates', [AdminCandidateController::class, 'store']);
    Route::post('/admin/candidates/import', [AdminCandidateController::class, 'import']);
    Route::post('/admin/tokens/generate', [AdminTokenController::class, 'generate']);

    Route::get('/proctor/sessions/{examSession}', [ProctorSessionController::class, 'show']);
    Route::post('/proctor/sessions/{examSession}/events', [ProctorSessionController::class, 'event']);
    Route::post('/proctor/attempts/{attempt}/lock', [ProctorSessionController::class, 'lock']);
    Route::post('/proctor/attempts/{attempt}/unlock', [ProctorSessionController::class, 'unlock']);
    Route::post('/proctor/attempts/{attempt}/resume-token', [ProctorSessionController::class, 'resumeToken']);

    Route::get('/incidents', [IncidentReportController::class, 'index']);
    Route::post('/incidents', [IncidentReportController::class, 'store']);

    Route::get('/marking/pending', [MarkingController::class, 'pending']);
    Route::get('/marking/assignments', [MarkingController::class, 'assignments']);
    Route::post('/marking/assignments', [MarkingController::class, 'assign']);
    Route::get('/marking/answers/{submissionAnswer}', [MarkingController::class, 'show']);
    Route::post('/marking/answers/{submissionAnswer}/marks', [MarkingController::class, 'mark']);
    Route::post('/marking/submissions/{submission}/moderate', [MarkingController::class, 'moderate']);

    Route::get('/reports/sessions/{examSession}', [ReportController::class, 'session']);
    Route::get('/reports/sessions/{examSession}/score-report.pdf', [ReportController::class, 'scorePdf']);
    Route::get('/reports/sessions/{examSession}/items', [ReportController::class, 'items']);
    Route::get('/reports/sessions/{examSession}/students/{candidate}', [ReportController::class, 'student']);
    Route::post('/reports/evidence-exports', [ReportController::class, 'exportEvidence']);
    Route::get('/reports/evidence-exports/{evidenceExport}/download', [ReportController::class, 'downloadEvidence']);
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
});
