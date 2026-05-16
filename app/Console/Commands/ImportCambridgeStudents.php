<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\CandidateExamToken;
use App\Models\CandidateGroup;
use App\Models\Exam;
use App\Models\ExamPackage;
use App\Models\ExamPaper;
use App\Models\ExamSeries;
use App\Models\ExamSession;
use App\Services\CandidateImportService;
use App\Services\CandidateTokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ImportCambridgeStudents extends Command
{
    protected $signature = 'exam:import-cambridge-students
        {path? : Path to Cambridge XLSX workbook}
        {--session-id= : Existing exam session ID for the imported tokens}
        {--package-id= : Exam package ID to publish when creating a new session}
        {--exam-code= : Exam code override, otherwise taken from the Exam Name column}
        {--duration=70 : Session duration in minutes when creating a new session}
        {--series-code=CAMBRIDGE-IMPORTS : Exam series code for created records}';

    protected $description = 'Import Cambridge workbook Data Student rows into candidates and hashed candidate exam tokens.';

    public function handle(CandidateImportService $importer, CandidateTokenService $tokens): int
    {
        $path = $this->argument('path') ?: 'C:\\Users\\jauar\\Downloads\\Cambridge.xlsx';

        if (! is_file($path)) {
            $this->error("Workbook not found: {$path}");

            return self::FAILURE;
        }

        $rows = collect($importer->readRowsFromPath($path, 'xlsx'))
            ->filter(fn (array $row): bool => filled($row['candidate_number'] ?? null) && filled($row['name'] ?? null))
            ->values();

        if ($rows->isEmpty()) {
            $this->error('No candidate rows found in the workbook.');

            return self::FAILURE;
        }

        $examCode = $this->examCode((string) ($this->option('exam-code') ?: $rows->pluck('exam_name')->filter()->first() ?: 'CAMBRIDGE'));
        $duration = max(1, (int) $this->option('duration'));

        $result = DB::transaction(function () use ($rows, $tokens, $path, $examCode, $duration): array {
            $package = $this->option('package-id')
                ? ExamPackage::query()->with('paper.exam.series')->findOrFail((int) $this->option('package-id'))
                : null;

            $session = $this->resolveSession($examCode, $duration, $package);

            $series = $session->exam->series;
            $group = CandidateGroup::query()->updateOrCreate(
                ['name' => "Cambridge {$examCode} Candidates"],
                [
                    'exam_series_id' => $series?->id,
                    'metadata' => [
                        'source' => basename($path),
                        'exam_code' => $examCode,
                    ],
                ],
            );

            $imported = 0;
            $tokened = 0;
            $missingTokens = 0;
            $conflictedTokens = 0;
            $samples = [];

            foreach ($rows as $row) {
                $candidateNumber = $this->candidateNumber($examCode, (string) $row['candidate_number']);
                $name = trim((string) $row['name']);

                $candidate = Candidate::query()->updateOrCreate(
                    ['candidate_number' => $candidateNumber],
                    [
                        'candidate_group_id' => $group->id,
                        'name' => $name,
                        'normalized_name' => Candidate::normalizeName($name),
                        'metadata' => array_filter([
                            'source' => basename($path),
                            'source_sheet' => 'Data Student',
                            'source_number' => (string) $row['candidate_number'],
                            'exam_name' => $row['exam_name'] ?? $examCode,
                            'import_status' => $row['import_status'] ?? null,
                            'login_time' => $row['login_time'] ?? null,
                            'submission_time' => $row['submission_time'] ?? null,
                        ]),
                    ],
                );

                $imported++;
                $plainToken = trim((string) ($row['token'] ?? ''));

                if ($plainToken === '') {
                    $missingTokens++;
                } else {
                    $normalized = $tokens->normalizeToken($plainToken);
                    $lookupHash = $tokens->lookupHash($normalized);
                    $existing = CandidateExamToken::query()
                        ->where('token_lookup_hash', $lookupHash)
                        ->first();

                    if ($existing && ((int) $existing->candidate_id !== (int) $candidate->id || (int) $existing->exam_session_id !== (int) $session->id)) {
                        $conflictedTokens++;
                    } else {
                        CandidateExamToken::query()->updateOrCreate(
                            ['token_lookup_hash' => $lookupHash],
                            [
                                'candidate_id' => $candidate->id,
                                'exam_session_id' => $session->id,
                                'exam_attempt_id' => null,
                                'purpose' => 'initial',
                                'token_hash' => Hash::make($normalized),
                                'token_suffix' => substr($normalized, -4),
                                'expires_at' => $session->ends_at?->copy()->addDay(),
                                'used_at' => null,
                                'revoked_at' => null,
                                'metadata' => [
                                    'source' => basename($path),
                                    'source_sheet' => 'Data Student',
                                    'token_was_imported_from_workbook' => true,
                                ],
                            ],
                        );
                        $tokened++;
                    }
                }

                if (count($samples) < 5) {
                    $samples[] = [
                        $candidateNumber,
                        $name,
                        $row['import_status'] ?? '-',
                        $plainToken === '' ? '-' : substr($tokens->normalizeToken($plainToken), -4),
                    ];
                }
            }

            return [
                'session' => $session->fresh(['exam', 'paper']),
                'group' => $group,
                'imported' => $imported,
                'tokened' => $tokened,
                'missing_tokens' => $missingTokens,
                'conflicted_tokens' => $conflictedTokens,
                'samples' => $samples,
            ];
        });

        $this->info('Cambridge student workbook imported.');
        $this->table(['Field', 'Value'], [
            ['Exam', $result['session']->exam->code.' - '.$result['session']->exam->title],
            ['Session ID', (string) $result['session']->id],
            ['Session', $result['session']->name],
            ['Candidate group', $result['group']->name],
            ['Candidates imported', (string) $result['imported']],
            ['Tokens hashed', (string) $result['tokened']],
            ['Rows without token', (string) $result['missing_tokens']],
            ['Token conflicts skipped', (string) $result['conflicted_tokens']],
        ]);
        $this->table(['Candidate No', 'Name', 'Source Status', 'Token Suffix'], $result['samples']);

        if (! $result['session']->settings || ! ($result['session']->settings['published_package_id'] ?? null)) {
            $this->warn('No exam package is published to this session yet. Publish a package in the admin UI before candidate login.');
        }

        return self::SUCCESS;
    }

    private function resolveSession(string $examCode, int $duration, ?ExamPackage $package): ExamSession
    {
        if ($this->option('session-id')) {
            return ExamSession::query()->with('exam.series', 'paper')->findOrFail((int) $this->option('session-id'));
        }

        if ($package) {
            $paper = $package->paper;
            $exam = $paper->exam;

            return ExamSession::query()->updateOrCreate(
                ['exam_id' => $exam->id, 'name' => "Cambridge Import - {$exam->code}"],
                [
                    'exam_paper_id' => $paper->id,
                    'starts_at' => now()->subHour(),
                    'ends_at' => now()->addDays(14),
                    'duration_minutes' => $duration,
                    'mode' => $package->strict_mode ? 'strict' : 'tryout',
                    'status' => 'active',
                    'timezone' => config('app.timezone', 'UTC'),
                    'settings' => [
                        'source' => 'Cambridge.xlsx',
                        'published_package_id' => $package->id,
                        'published_package_checksum' => $package->checksum,
                        'published_package_version' => $package->version,
                        'shuffle_questions' => true,
                        'shuffle_options' => true,
                    ],
                ],
            )->load('exam.series', 'paper');
        }

        $series = ExamSeries::query()->updateOrCreate(
            ['code' => $this->examCode((string) $this->option('series-code'))],
            [
                'title' => 'Cambridge Imported Exams',
                'status' => 'active',
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addYear(),
                'metadata' => ['source' => 'exam:import-cambridge-students'],
            ],
        );

        $exam = Exam::query()->updateOrCreate(
            ['exam_series_id' => $series->id, 'code' => $examCode],
            [
                'title' => "Cambridge {$examCode}",
                'type' => 'mixed',
                'mode' => 'tryout',
                'status' => 'published',
                'default_duration_minutes' => $duration,
                'randomize_questions' => true,
                'reveal_feedback' => true,
                'metadata' => ['source' => 'Cambridge.xlsx'],
            ],
        );

        $paper = ExamPaper::query()->updateOrCreate(
            ['exam_id' => $exam->id, 'code' => 'PAPER-1', 'version' => 1],
            [
                'title' => "{$exam->title} Paper 1",
                'status' => 'published',
                'duration_minutes' => $duration,
                'instructions' => 'Answer all questions. Objective answers are scored automatically; essay and structured answers require manual marking.',
                'content' => ['source' => 'Cambridge.xlsx'],
            ],
        );

        $package = $paper->packages()->latest('version')->first();
        $settings = [
            'source' => 'Cambridge.xlsx',
            'shuffle_questions' => true,
            'shuffle_options' => true,
        ];

        if ($package) {
            $settings += [
                'published_package_id' => $package->id,
                'published_package_checksum' => $package->checksum,
                'published_package_version' => $package->version,
            ];
        }

        return ExamSession::query()->updateOrCreate(
            ['exam_id' => $exam->id, 'name' => "Cambridge Import - {$examCode}"],
            [
                'exam_paper_id' => $paper->id,
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addDays(14),
                'duration_minutes' => $duration,
                'mode' => 'tryout',
                'status' => 'active',
                'timezone' => config('app.timezone', 'UTC'),
                'settings' => $settings,
            ],
        )->load('exam.series', 'paper');
    }

    private function candidateNumber(string $examCode, string $number): string
    {
        $clean = trim(preg_replace('/\.0$/', '', $number) ?? $number);

        if (is_numeric($clean)) {
            return sprintf('%s-%03d', $examCode, (int) $clean);
        }

        $suffix = str($clean)->upper()->replaceMatches('/[^A-Z0-9]+/', '-')->trim('-')->toString();

        return str_starts_with($suffix, $examCode.'-') ? $suffix : "{$examCode}-{$suffix}";
    }

    private function examCode(string $value): string
    {
        return str($value)->upper()->replaceMatches('/[^A-Z0-9]+/', '-')->trim('-')->toString() ?: 'CAMBRIDGE';
    }
}
