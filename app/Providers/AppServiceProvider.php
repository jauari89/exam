<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateExamToken;
use App\Models\EvidenceExport;
use App\Models\Exam;
use App\Models\ExamPackage;
use App\Models\ExamSeries;
use App\Models\ExamSession;
use App\Models\IncidentReport;
use App\Models\QuestionBank;
use App\Models\SubmissionAnswer;
use App\Policies\AuditLogPolicy;
use App\Policies\CandidateExamTokenPolicy;
use App\Policies\CandidatePolicy;
use App\Policies\EvidenceExportPolicy;
use App\Policies\ExamPackagePolicy;
use App\Policies\ExamPolicy;
use App\Policies\ExamSeriesPolicy;
use App\Policies\ExamSessionPolicy;
use App\Policies\IncidentReportPolicy;
use App\Policies\QuestionBankPolicy;
use App\Policies\SubmissionAnswerPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(ExamSeries::class, ExamSeriesPolicy::class);
        Gate::policy(Exam::class, ExamPolicy::class);
        Gate::policy(ExamPackage::class, ExamPackagePolicy::class);
        Gate::policy(QuestionBank::class, QuestionBankPolicy::class);
        Gate::policy(Candidate::class, CandidatePolicy::class);
        Gate::policy(CandidateExamToken::class, CandidateExamTokenPolicy::class);
        Gate::policy(ExamSession::class, ExamSessionPolicy::class);
        Gate::policy(IncidentReport::class, IncidentReportPolicy::class);
        Gate::policy(SubmissionAnswer::class, SubmissionAnswerPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(EvidenceExport::class, EvidenceExportPolicy::class);

        Gate::define('view-reports', fn ($user) => $user->canDo('view_reports'));
        Gate::define('export-evidence', fn ($user) => $user->canDo('export_evidence'));
        Gate::define('view-audit', fn ($user) => $user->canDo('view_audit'));
        Gate::define('view-dashboard', fn ($user) => $user->canDo('manage_exams')
            || $user->canDo('proctor_sessions')
            || $user->canDo('view_reports'));

        RateLimiter::for('admin-login', function (Request $request) {
            $key = str($request->input('email', 'guest'))->lower().'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('candidate-login', function (Request $request) {
            $key = $request->input('exam_session_id', 'none').'|'.str($request->input('name', ''))->lower()->squish().'|'.$request->ip();

            return Limit::perMinute(8)->by($key);
        });
    }
}
