<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\ExamSession;
use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Http\Request;

class IncidentReportService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ProctoringService $proctoring,
    ) {}

    public function create(ExamSession $session, array $payload, ?User $user, Request $request): IncidentReport
    {
        $attempt = isset($payload['exam_attempt_id'])
            ? ExamAttempt::query()->where('exam_session_id', $session->id)->findOrFail($payload['exam_attempt_id'])
            : null;

        $report = IncidentReport::query()->create([
            'exam_session_id' => $session->id,
            'exam_attempt_id' => $attempt?->id,
            'candidate_id' => $attempt?->candidate_id ?? $payload['candidate_id'] ?? null,
            'reported_by' => $user?->id,
            'severity' => $payload['severity'] ?? 'medium',
            'status' => 'open',
            'title' => $payload['title'],
            'description' => $payload['description'],
            'evidence' => $payload['evidence'] ?? null,
        ]);

        $this->proctoring->record($session, 'incident_reported', $report->severity, ['incident_id' => $report->id], $attempt, $attempt?->candidate, $user);
        $this->audit->record('incident.create', $request, $user, $attempt?->candidate, $attempt, $report);

        return $report;
    }
}
