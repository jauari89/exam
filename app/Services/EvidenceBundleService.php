<?php

namespace App\Services;

use App\Models\AttemptSnapshot;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EvidenceExport;
use App\Models\ExamAttempt;
use App\Models\ExamSession;
use App\Models\IncidentReport;
use App\Models\ProctorEvent;
use App\Models\Score;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class EvidenceBundleService
{
    public function export(ExamSession $session, ?ExamAttempt $attempt, ?User $requestedBy = null, string $format = 'json'): EvidenceExport
    {
        $format = in_array($format, ['json', 'zip'], true) ? $format : 'json';
        $export = EvidenceExport::query()->create([
            'exam_session_id' => $session->id,
            'exam_attempt_id' => $attempt?->id,
            'requested_by' => $requestedBy?->id,
            'status' => 'processing',
            'format' => $format,
        ]);

        $bundle = $this->bundle($session, $attempt);
        $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $path = $format === 'zip'
            ? $this->writeZip($session, $export, $bundle, $json)
            : 'evidence/session-'.$session->id.'/export-'.$export->id.'.json';

        if ($format === 'json') {
            Storage::disk('local')->put($path, $json);
        }

        $export->forceFill([
            'status' => 'ready',
            'path' => $path,
            'checksum' => hash_file('sha256', Storage::disk('local')->path($path)),
            'manifest' => [
                'attendance_log' => true,
                'audit_log' => true,
                'proctor_events' => true,
                'incident_reports' => true,
                'submission_snapshot' => true,
                'final_answers' => true,
                'score_report' => true,
                'bundle_json' => true,
                'csv_exports' => $format === 'zip',
            ],
            'generated_at' => now(),
            'expires_at' => now()->addDays(30),
        ])->save();

        return $export;
    }

    public function bundle(ExamSession $session, ?ExamAttempt $attempt = null): array
    {
        $attemptIds = $attempt ? [$attempt->id] : $session->attempts()->pluck('id')->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'session' => $session->load('exam', 'paper')->toArray(),
            'attendance_log' => AttendanceLog::query()->where('exam_session_id', $session->id)->whereIn('exam_attempt_id', $attemptIds)->get()->toArray(),
            'audit_log' => AuditLog::query()->whereIn('exam_attempt_id', $attemptIds)->get()->toArray(),
            'proctor_events' => ProctorEvent::query()->where('exam_session_id', $session->id)->when($attempt, fn ($q) => $q->where('exam_attempt_id', $attempt->id))->get()->toArray(),
            'incident_reports' => IncidentReport::query()->where('exam_session_id', $session->id)->when($attempt, fn ($q) => $q->where('exam_attempt_id', $attempt->id))->get()->toArray(),
            'submission_snapshot' => AttemptSnapshot::query()->whereIn('exam_attempt_id', $attemptIds)->get()->toArray(),
            'final_answers' => Submission::query()->with('answers')->whereIn('exam_attempt_id', $attemptIds)->get()->toArray(),
            'score_report' => Score::query()->whereIn('exam_attempt_id', $attemptIds)->get()->toArray(),
        ];
    }

    private function writeZip(ExamSession $session, EvidenceExport $export, array $bundle, string $json): string
    {
        if (! class_exists(ZipArchive::class)) {
            $path = 'evidence/session-'.$session->id.'/export-'.$export->id.'.json';
            Storage::disk('local')->put($path, $json);

            return $path;
        }

        $path = 'evidence/session-'.$session->id.'/export-'.$export->id.'.zip';
        $absolutePath = Storage::disk('local')->path($path);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0755, true);
        }

        $zip = new ZipArchive;
        $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode([
            'session_id' => $session->id,
            'export_id' => $export->id,
            'generated_at' => now()->toIso8601String(),
            'sections' => array_keys($bundle),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $zip->addFromString('bundle.json', $json);

        foreach (['attendance_log', 'audit_log', 'proctor_events', 'incident_reports', 'score_report'] as $section) {
            $zip->addFromString($section.'.csv', $this->csv($bundle[$section] ?? []));
        }

        $zip->close();

        return $path;
    }

    private function csv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $headers = collect($rows)->flatMap(fn (array $row) => array_keys($row))->unique()->values()->all();
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, collect($headers)->map(function (string $header) use ($row) {
                $value = $row[$header] ?? null;

                return is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
            })->all());
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
    }
}
