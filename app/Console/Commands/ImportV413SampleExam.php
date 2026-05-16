<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\CandidateExamToken;
use App\Models\CandidateGroup;
use App\Models\Exam;
use App\Models\ExamPaper;
use App\Models\ExamSeries;
use App\Models\ExamSession;
use App\Services\CandidateTokenService;
use App\Services\ExamPackageImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ImportV413SampleExam extends Command
{
    protected $signature = 'exam:import-v413-sample
        {path=database/samples/EXAM-DEMO-01.json : Path to the V4.1.3 exam JSON}
        {--token=TOKEN-ABCDEF : Demo one-time candidate token}
        {--candidate=Sample Student : Demo candidate name}
        {--candidate-number=STU-0001 : Demo candidate number}
        {--duration=60 : Exam duration in minutes}';

    protected $description = 'Import a Google Apps Script CBT V4.1.3 sample exam JSON into the Laravel exam schema.';

    public function handle(ExamPackageImportService $importer, CandidateTokenService $tokens): int
    {
        $path = (string) $this->argument('path');
        $path = str($path)->startsWith(['/', '\\']) || preg_match('/^[A-Za-z]:\\\\/', $path)
            ? $path
            : base_path($path);

        if (! is_file($path)) {
            $this->error("Exam JSON not found: $path");

            return self::FAILURE;
        }

        $source = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $duration = (int) $this->option('duration');
        $plainToken = (string) $this->option('token');

        $result = DB::transaction(function () use ($source, $duration, $plainToken, $tokens, $importer) {
            $series = ExamSeries::query()->updateOrCreate(
                ['code' => 'V413-SAMPLES'],
                [
                    'title' => 'Google Apps Script CBT V4.1.3 Samples',
                    'status' => 'active',
                    'starts_at' => now()->subDay(),
                    'ends_at' => now()->addYear(),
                    'metadata' => ['source' => 'V4.1.3 ZIP sample'],
                ],
            );

            $exam = Exam::query()->updateOrCreate(
                ['exam_series_id' => $series->id, 'code' => $source['examName']],
                [
                    'title' => $source['title'] ?? $source['examName'],
                    'type' => 'mixed',
                    'mode' => 'tryout',
                    'status' => 'published',
                    'default_duration_minutes' => $duration,
                    'randomize_questions' => true,
                    'reveal_feedback' => true,
                    'metadata' => [
                        'subject' => $source['subject'] ?? null,
                        'shuffle_salt' => $source['shuffleSalt'] ?? null,
                        'source_format' => 'apps-script-v4.1.3',
                    ],
                ],
            );

            $paper = ExamPaper::query()->updateOrCreate(
                ['exam_id' => $exam->id, 'code' => 'PAPER-1', 'version' => 1],
                [
                    'title' => $source['title'] ?? $source['examName'],
                    'status' => 'published',
                    'duration_minutes' => $duration,
                    'total_marks' => collect($source['questions'])->sum(fn (array $question) => (float) ($question['points'] ?? 1)),
                    'instructions' => $source['instructions'] ?? null,
                    'content' => ['source_exam_name' => $source['examName']],
                ],
            );

            $package = $importer->import($paper, $this->toPackagePayload($source, $duration));

            $session = ExamSession::query()->updateOrCreate(
                ['exam_id' => $exam->id, 'name' => 'Demo Session - '.$source['examName']],
                [
                    'exam_paper_id' => $paper->id,
                    'starts_at' => now()->subHour(),
                    'ends_at' => now()->addDays(7),
                    'duration_minutes' => $duration,
                    'mode' => 'tryout',
                    'status' => 'active',
                    'timezone' => config('app.timezone', 'UTC'),
                    'settings' => [
                        'source' => 'V4.1.3 sample config',
                        'timer_mode' => 'fixed',
                        'show_results' => true,
                        'show_review' => true,
                    ],
                ],
            );

            $group = CandidateGroup::query()->updateOrCreate(
                ['name' => 'V4.1.3 Demo Candidates'],
                [
                    'exam_series_id' => $series->id,
                    'metadata' => ['source' => 'V4.1.3 sample seed'],
                ],
            );

            $candidate = Candidate::query()->updateOrCreate(
                ['candidate_number' => (string) $this->option('candidate-number')],
                [
                    'candidate_group_id' => $group->id,
                    'name' => (string) $this->option('candidate'),
                    'normalized_name' => Candidate::normalizeName((string) $this->option('candidate')),
                    'metadata' => ['source' => 'V4.1.3 sample seed'],
                ],
            );

            $normalizedToken = $tokens->normalizeToken($plainToken);
            $token = CandidateExamToken::query()->updateOrCreate(
                ['token_lookup_hash' => $tokens->lookupHash($normalizedToken)],
                [
                    'candidate_id' => $candidate->id,
                    'exam_session_id' => $session->id,
                    'purpose' => 'initial',
                    'token_hash' => Hash::make($normalizedToken),
                    'token_suffix' => substr($normalizedToken, -4),
                    'expires_at' => $session->ends_at->copy()->addDay(),
                    'used_at' => null,
                    'revoked_at' => null,
                    'metadata' => ['source' => 'V4.1.3 sample seed'],
                ],
            );

            return compact('series', 'exam', 'paper', 'package', 'session', 'group', 'candidate', 'token');
        });

        $this->info('V4.1.3 sample exam imported.');
        $this->table(
            ['Item', 'Value'],
            [
                ['Exam', $result['exam']->code.' - '.$result['exam']->title],
                ['Paper ID', $result['paper']->id],
                ['Package ID/version', $result['package']->id.' / v'.$result['package']->version],
                ['Session ID', $result['session']->id],
                ['Candidate', $result['candidate']->candidate_number.' - '.$result['candidate']->name],
                ['Candidate token', $plainToken],
            ],
        );

        return self::SUCCESS;
    }

    private function toPackagePayload(array $source, int $duration): array
    {
        return [
            'title' => $source['title'] ?? $source['examName'],
            'duration_minutes' => $duration,
            'strict_mode' => false,
            'total_marks' => collect($source['questions'])->sum(fn (array $question) => (float) ($question['points'] ?? 1)),
            'metadata' => [
                'source_exam_name' => $source['examName'],
                'subject' => $source['subject'] ?? null,
                'instructions' => $source['instructions'] ?? null,
            ],
            'questions' => collect($source['questions'])->values()->map(fn (array $question, int $index) => $this->toQuestionPayload($question, $index))->all(),
        ];
    }

    private function toQuestionPayload(array $question, int $index): array
    {
        $maxMarks = (float) ($question['points'] ?? 1);
        $correct = $question['answer'] ?? null;
        $correctIds = collect(is_array($correct) ? $correct : [$correct])->filter()->values();
        $correctCount = max(1, $correctIds->count());

        return [
            'external_id' => (string) ($question['id'] ?? 'Q'.($index + 1)),
            'type' => (string) $question['type'],
            'position' => $index + 1,
            'topic' => $question['topic'] ?? null,
            'max_marks' => $maxMarks,
            'stem' => [
                'text' => $question['question'] ?? '',
            ],
            'correct_answer' => $this->correctAnswerPayload($question),
            'validation_rules' => array_filter([
                'tolerance' => $question['tolerance'] ?? null,
                'max_length' => in_array($question['type'], ['essay', 'structured'], true) ? 12000 : null,
            ], fn ($value) => $value !== null),
            'feedback' => isset($question['explanation']) ? ['text' => $question['explanation']] : null,
            'metadata' => [
                'source' => 'V4.1.3 JSON',
                'parts' => $question['parts'] ?? null,
                'raw_type' => $question['type'] ?? null,
            ],
            'options' => collect($question['options'] ?? [])->values()->map(function (array $option, int $optionIndex) use ($question, $correctIds, $maxMarks, $correctCount): array {
                $isCorrect = $correctIds->containsStrict($option['id'] ?? null);

                return [
                    'external_id' => (string) ($option['id'] ?? Str::upper(Str::random(4))),
                    'position' => $optionIndex + 1,
                    'content' => ['text' => $option['text'] ?? ''],
                    'is_correct' => $isCorrect,
                    'marks' => $isCorrect ? ($question['type'] === 'checkbox' ? round($maxMarks / $correctCount, 2) : $maxMarks) : 0,
                ];
            })->all(),
            'rubrics' => isset($question['rubric'])
                ? [['criterion' => 'Rubric', 'max_marks' => $maxMarks, 'descriptors' => ['text' => $question['rubric']]]]
                : [],
        ];
    }

    private function correctAnswerPayload(array $question): mixed
    {
        return match ($question['type']) {
            'numerical' => ['value' => $question['answer'] ?? null],
            'objective', 'checkbox' => ['option_ids' => $question['answer'] ?? null],
            default => null,
        };
    }
}
